<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class QuizVersionRepository
{
    private readonly QuizVersionProgramPresentationRepository $presentations;

    public function __construct(private readonly Database $database, ?QuizVersionProgramPresentationRepository $presentations = null)
    {
        $this->presentations = $presentations ?? new QuizVersionProgramPresentationRepository($database);
    }

    public function findByQuizAndVersion(int $quizId, int $versionNumber): ?QuizVersion
    {
        $this->assertPositiveId($quizId, 'Quiz identity');
        $this->assertPositiveId($versionNumber, 'Version number');

        $statement = $this->connection()->prepare(
            'SELECT id, quiz_id, version_number, status, name
             FROM quiz_versions
             WHERE quiz_id = :quiz_id AND version_number = :version_number'
        );
        $statement->execute([
            'quiz_id' => $quizId,
            'version_number' => $versionNumber,
        ]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateVersion($row);
    }

    public function findById(int $versionId): ?QuizVersion
    {
        $this->assertPositiveId($versionId, 'Quiz version identity');

        $statement = $this->connection()->prepare(
            'SELECT id, quiz_id, version_number, status, name
             FROM quiz_versions
             WHERE id = :id'
        );
        $statement->execute(['id' => $versionId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrateVersion($row);
    }

    public function findDraftForQuiz(int $quizId): ?QuizVersion
    {
        $this->assertPositiveId($quizId, 'Quiz identity');

        $statement = $this->connection()->prepare(
            'SELECT id, quiz_id, version_number, status, name
             FROM quiz_versions
             WHERE quiz_id = :quiz_id AND status = :status
             ORDER BY id ASC'
        );
        $statement->execute([
            'quiz_id' => $quizId,
            'status' => QuizVersion::STATUS_DRAFT,
        ]);
        $rows = $statement->fetchAll();

        if (count($rows) > 1) {
            throw new RuntimeException('Persistence invariant violation: multiple editable drafts exist.');
        }

        return $rows === [] ? null : $this->hydrateVersion($rows[0]);
    }

    public function hasPersistedUsage(int $quizVersionId): bool
    {
        $this->assertPositiveId($quizVersionId, 'Quiz version identity');

        $statement = $this->connection()->prepare(
            'SELECT EXISTS(
                SELECT 1
                FROM attempts
                WHERE quiz_version_id = :quiz_version_id
            )'
        );
        $statement->execute(['quiz_version_id' => $quizVersionId]);

        return (bool) $statement->fetchColumn();
    }

    public function createDraftSnapshot(int $quizId, QuizDefinition $approvedSnapshot, ?int $sourceVersionId = null): QuizVersion
    {
        $this->assertPositiveId($quizId, 'Quiz identity');
        $approvedSnapshot->validate();

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $schoolId = $this->lockQuizIdentity($connection, $quizId);
            $this->assertNoEditableDraftLocked($connection, $quizId);
            $versionNumber = $this->nextVersionNumberLocked($connection, $quizId);
            $programIds = $sourceVersionId === null
                ? $this->resolveProgramIds($connection, $schoolId, $approvedSnapshot->programs)
                : $this->resolveSourceProgramIds($connection, $sourceVersionId, $approvedSnapshot->programs);

            $statement = $connection->prepare(
                'INSERT INTO quiz_versions (quiz_id, version_number, status, name, created_at)
                 VALUES (:quiz_id, :version_number, :status, :name, UTC_TIMESTAMP())'
            );
            $statement->execute([
                'quiz_id' => $quizId,
                'version_number' => $versionNumber,
                'status' => QuizVersion::STATUS_DRAFT,
                'name' => $approvedSnapshot->name,
            ]);

            $versionId = (int) $connection->lastInsertId();
            $membershipIds = $this->insertProgramMemberships($connection, $versionId, $programIds);
            if ($sourceVersionId === null) {
                $this->createCurrentProgramPresentations($connection, $membershipIds, $programIds);
            } else {
                $this->copySourcePresentations($sourceVersionId, $membershipIds);
            }
            $this->insertQuestions($connection, $versionId, $approvedSnapshot->questions, $programIds);
            $version = $this->requireHydratedVersion($versionId);
            $connection->commit();

            return $version;
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }
    }

    public function replaceDraftDefinition(
        int $schoolId,
        int $quizId,
        int $versionId,
        QuizDefinition $definition,
    ): QuizVersion {
        $this->assertPositiveId($schoolId, 'School identity');
        $this->assertPositiveId($quizId, 'Quiz identity');
        $this->assertPositiveId($versionId, 'Quiz version identity');
        $definition->validate();

        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $programIds = $this->lockEditableDraftForSchool(
                $connection,
                $schoolId,
                $quizId,
                $versionId,
            );
            $this->assertNoPersistedUsageLocked($connection, $versionId);
            $this->assertExactProgramMembership($definition->programs, $programIds);
            $this->deleteQuestionStructure($connection, $versionId);
            $this->updateDraftName($connection, $versionId, $definition->name);
            $this->insertQuestions($connection, $versionId, $definition->questions, $programIds);

            $version = $this->requireHydratedVersion($versionId);
            $connection->commit();

            return $version;
        } catch (\Throwable $throwable) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $throwable;
        }
    }

    public function updateStatus(
        int $versionId,
        string $expectedCurrentStatus,
        string $newStatus,
        ?DateTimeImmutable $publishedAt = null,
    ): bool {
        $this->assertPositiveId($versionId, 'Quiz version identity');
        $this->assertKnownStatus($expectedCurrentStatus);
        $this->assertKnownStatus($newStatus);

        if ($newStatus === QuizVersion::STATUS_PUBLISHED && $publishedAt === null) {
            throw new \InvalidArgumentException('Published versions require a publication timestamp.');
        }

        if ($newStatus !== QuizVersion::STATUS_PUBLISHED && $publishedAt !== null) {
            throw new \InvalidArgumentException('Only published versions may have a publication timestamp.');
        }

        $statement = $this->connection()->prepare(
            'UPDATE quiz_versions
             SET status = :new_status, published_at = :published_at
             WHERE id = :id AND status = :expected_status'
        );
        $statement->execute([
            'id' => $versionId,
            'expected_status' => $expectedCurrentStatus,
            'new_status' => $newStatus,
            'published_at' => $publishedAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount() === 1;
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    private function lockQuizIdentity(PDO $connection, int $quizId): int
    {
        $statement = $connection->prepare(
            'SELECT id, school_id
             FROM quizzes
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $quizId]);

        $row = $statement->fetch();
        if ($row === false) {
            throw new \InvalidArgumentException('Quiz identity does not exist.');
        }

        return $this->rowInt($row, 'school_id');
    }

    /** @return array<string, int> */
    private function lockEditableDraftForSchool(
        PDO $connection,
        int $schoolId,
        int $quizId,
        int $versionId,
    ): array {
        $statement = $connection->prepare(
            'SELECT qv.id, qv.status
             FROM quiz_versions AS qv
             INNER JOIN quizzes AS q ON q.id = qv.quiz_id
             WHERE qv.id = :version_id
               AND qv.quiz_id = :quiz_id
               AND q.school_id = :school_id
             FOR UPDATE'
        );
        $statement->execute([
            'version_id' => $versionId,
            'quiz_id' => $quizId,
            'school_id' => $schoolId,
        ]);
        $row = $statement->fetch();

        if ($row === false) {
            throw new \InvalidArgumentException('Quiz version is not available in the authenticated school scope.');
        }

        if ($this->rowString($row, 'status') !== QuizVersion::STATUS_DRAFT) {
            throw new RuntimeException('Only unused draft quiz versions can be edited.');
        }

        return $this->hydrateProgramIds($versionId);
    }

    private function assertNoPersistedUsageLocked(PDO $connection, int $versionId): void
    {
        $statement = $connection->prepare(
            'SELECT id
             FROM attempts
             WHERE quiz_version_id = :quiz_version_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['quiz_version_id' => $versionId]);

        if ($statement->fetch() !== false) {
            throw new RuntimeException('Quiz versions with persisted usage are immutable.');
        }
    }

    /** @param array<string, bool> $programs @param array<string, int> $programIds */
    private function assertExactProgramMembership(array $programs, array $programIds): void
    {
        $providedCodes = array_keys($programs);
        $snapshotCodes = array_keys($programIds);
        sort($providedCodes, SORT_STRING);
        sort($snapshotCodes, SORT_STRING);

        if ($providedCodes !== $snapshotCodes) {
            throw new RuntimeException('Draft definition programs must match the version snapshot membership.');
        }
    }

    private function deleteQuestionStructure(PDO $connection, int $versionId): void
    {
        $weights = $connection->prepare(
            'DELETE ow
             FROM option_weights AS ow
             INNER JOIN question_options AS qo ON qo.id = ow.question_option_id
             INNER JOIN questions AS q ON q.id = qo.question_id
             WHERE q.quiz_version_id = :quiz_version_id'
        );
        $weights->execute(['quiz_version_id' => $versionId]);

        $options = $connection->prepare(
            'DELETE qo
             FROM question_options AS qo
             INNER JOIN questions AS q ON q.id = qo.question_id
             WHERE q.quiz_version_id = :quiz_version_id'
        );
        $options->execute(['quiz_version_id' => $versionId]);

        $questions = $connection->prepare(
            'DELETE FROM questions
             WHERE quiz_version_id = :quiz_version_id'
        );
        $questions->execute(['quiz_version_id' => $versionId]);
    }

    private function updateDraftName(PDO $connection, int $versionId, string $name): void
    {
        $statement = $connection->prepare(
            'UPDATE quiz_versions
             SET name = :name
             WHERE id = :id AND status = :status'
        );
        $statement->execute([
            'id' => $versionId,
            'name' => $name,
            'status' => QuizVersion::STATUS_DRAFT,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Quiz version lifecycle state changed concurrently.');
        }
    }

    private function nextVersionNumberLocked(PDO $connection, int $quizId): int
    {
        if (!$connection->inTransaction()) {
            throw new \LogicException('Version number allocation requires an active transaction.');
        }

        $statement = $connection->prepare(
            'SELECT COALESCE(MAX(version_number), 0)
             FROM quiz_versions
             WHERE quiz_id = :quiz_id'
        );
        $statement->execute(['quiz_id' => $quizId]);

        return ((int) $statement->fetchColumn()) + 1;
    }

    private function assertNoEditableDraftLocked(PDO $connection, int $quizId): void
    {
        $statement = $connection->prepare(
            'SELECT id
             FROM quiz_versions
             WHERE quiz_id = :quiz_id AND status = :status
             ORDER BY id ASC'
        );
        $statement->execute([
            'quiz_id' => $quizId,
            'status' => QuizVersion::STATUS_DRAFT,
        ]);
        $rows = $statement->fetchAll();

        if (count($rows) > 1) {
            throw new RuntimeException('Persistence invariant violation: multiple editable drafts exist.');
        }

        if ($rows !== []) {
            throw new RuntimeException('An editable draft already exists for this quiz.');
        }
    }

    /** @param array<string, bool> $programs @return array<string, int> */
    private function resolveProgramIds(PDO $connection, int $schoolId, array $programs): array
    {
        $statement = $connection->prepare(
            'SELECT id
             FROM programs
             WHERE school_id = :school_id AND short_name = :short_name'
        );
        $programIds = [];

        foreach (array_keys($programs) as $programCode) {
            $code = (string) $programCode;
            $statement->execute([
                'school_id' => $schoolId,
                'short_name' => $code,
            ]);
            $rows = $statement->fetchAll();

            if (count($rows) !== 1) {
                throw new RuntimeException('Configured program does not belong to the quiz school: ' . $code);
            }

            $programIds[$code] = $this->rowInt($rows[0], 'id');
        }

        return $programIds;
    }

    /** @param array<string, bool> $programs @return array<string, int> */
    private function resolveSourceProgramIds(PDO $connection, int $sourceVersionId, array $programs): array
    {
        $statement = $connection->prepare(
            'SELECT qvpp.program_code_snapshot, qvp.program_id
             FROM quiz_version_programs AS qvp
             INNER JOIN quiz_version_program_presentations AS qvpp ON qvpp.quiz_version_program_id = qvp.id
             WHERE qvp.quiz_version_id = :version_id'
        );
        $statement->execute(['version_id' => $sourceVersionId]);
        $programIds = [];
        foreach ($statement->fetchAll() as $row) {
            $code = $this->rowString($row, 'program_code_snapshot');
            if (isset($programIds[$code])) {
                throw new RuntimeException('Persistence invariant violation: duplicate source presentation code.');
            }
            $programIds[$code] = $this->rowInt($row, 'program_id');
        }
        $expectedCodes = array_keys($programs);
        $actualCodes = array_keys($programIds);
        sort($expectedCodes, SORT_STRING);
        sort($actualCodes, SORT_STRING);
        if ($expectedCodes !== $actualCodes) {
            throw new RuntimeException('Persistence invariant violation: source program snapshot is incomplete.');
        }

        return $programIds;
    }

    /** @param array<string, int> $programIds @return array<string, int> */
    private function insertProgramMemberships(PDO $connection, int $versionId, array $programIds): array
    {
        $statement = $connection->prepare(
            'INSERT INTO quiz_version_programs (quiz_version_id, program_id, created_at)
             VALUES (:quiz_version_id, :program_id, UTC_TIMESTAMP())'
        );

        $membershipIds = [];
        foreach ($programIds as $programCode => $programId) {
            $statement->execute([
                'quiz_version_id' => $versionId,
                'program_id' => $programId,
            ]);
            $membershipIds[$programCode] = (int) $connection->lastInsertId();
        }

        return $membershipIds;
    }

    /** @param array<string, int> $membershipIds @param array<string, int> $programIds */
    private function createCurrentProgramPresentations(PDO $connection, array $membershipIds, array $programIds): void
    {
        $statement = $connection->prepare(
            'SELECT short_name, name, personality_title, mascot_path, description, skills_json
             FROM programs
             WHERE id = :program_id'
        );
        foreach ($membershipIds as $programCode => $membershipId) {
            $statement->execute(['program_id' => $programIds[$programCode]]);
            $row = $statement->fetch();
            if ($row === false) {
                throw new RuntimeException('Configured program disappeared during snapshot creation.');
            }
            $this->presentations->create(new QuizVersionProgramPresentation(
                $membershipId,
                $this->rowString($row, 'short_name'),
                $this->rowString($row, 'name'),
                $this->nullableRowString($row, 'personality_title'),
                $this->nullableRowString($row, 'mascot_path'),
                null,
                null,
                null,
                $this->nullableRowString($row, 'description'),
                null,
                $this->nullableRowString($row, 'skills_json'),
                null,
                'version_snapshot',
            ));
        }
    }

    /** @param array<string, int> $targetMembershipIds */
    private function copySourcePresentations(int $sourceVersionId, array $targetMembershipIds): void
    {
        $sourceByCode = [];
        foreach ($this->presentations->findAllByQuizVersionId($sourceVersionId) as $presentation) {
            if (isset($sourceByCode[$presentation->programCodeSnapshot])) {
                throw new RuntimeException('Persistence invariant violation: duplicate source presentation code.');
            }
            $sourceByCode[$presentation->programCodeSnapshot] = $presentation;
        }
        $sourceCodes = array_keys($sourceByCode);
        $targetCodes = array_keys($targetMembershipIds);
        sort($sourceCodes, SORT_STRING);
        sort($targetCodes, SORT_STRING);
        if ($sourceCodes !== $targetCodes) {
            throw new RuntimeException('Persistence invariant violation: source presentation snapshot membership is incomplete.');
        }
        foreach ($targetMembershipIds as $programCode => $membershipId) {
            $this->presentations->copyToMembership($sourceByCode[$programCode], $membershipId);
        }
    }

    /** @param list<array<string, mixed>> $questions @param array<string, int> $programIds */
    private function insertQuestions(PDO $connection, int $versionId, array $questions, array $programIds): void
    {
        $questionStatement = $connection->prepare(
            'INSERT INTO questions (quiz_version_id, prompt, image_path, sort_order, created_at, updated_at)
             VALUES (:quiz_version_id, :prompt, :image_path, :sort_order, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );

        foreach ($questions as $question) {
            $questionStatement->execute([
                'quiz_version_id' => $versionId,
                'prompt' => $question['text'],
                'image_path' => $question['image_path'] ?? null,
                'sort_order' => $question['order'],
            ]);
            $this->insertOptions($connection, (int) $connection->lastInsertId(), $question['options'], $programIds);
        }
    }

    /** @param list<array<string, mixed>> $options @param array<string, int> $programIds */
    private function insertOptions(PDO $connection, int $questionId, array $options, array $programIds): void
    {
        $optionStatement = $connection->prepare(
            'INSERT INTO question_options (question_id, option_text, sort_order, created_at)
             VALUES (:question_id, :option_text, :sort_order, UTC_TIMESTAMP())'
        );

        foreach ($options as $option) {
            $optionStatement->execute([
                'question_id' => $questionId,
                'option_text' => $option['text'],
                'sort_order' => $option['order'],
            ]);
            $this->insertWeights($connection, (int) $connection->lastInsertId(), $option['weights'], $programIds);
        }
    }

    /** @param list<array<string, mixed>> $weights @param array<string, int> $programIds */
    private function insertWeights(PDO $connection, int $optionId, array $weights, array $programIds): void
    {
        $statement = $connection->prepare(
            'INSERT INTO option_weights (question_option_id, program_id, weight, created_at)
             VALUES (:question_option_id, :program_id, :weight, UTC_TIMESTAMP())'
        );

        foreach ($weights as $assignment) {
            $programCode = $assignment['program'];
            if (!is_string($programCode) || !isset($programIds[$programCode])) {
                throw new RuntimeException('Weight program is outside configured version membership.');
            }

            $statement->execute([
                'question_option_id' => $optionId,
                'program_id' => $programIds[$programCode],
                'weight' => sprintf('%.17g', (float) $assignment['weight']),
            ]);
        }
    }

    private function requireHydratedVersion(int $versionId): QuizVersion
    {
        $version = $this->findById($versionId);
        if ($version === null) {
            throw new RuntimeException('Persisted quiz version could not be reloaded.');
        }

        return $version;
    }

    /** @param array<string, mixed> $row */
    private function hydrateVersion(array $row): QuizVersion
    {
        $versionId = $this->rowInt($row, 'id');
        $quizId = $this->rowInt($row, 'quiz_id');
        $versionNumber = $this->rowInt($row, 'version_number');
        $status = $this->rowString($row, 'status');
        $name = $this->rowString($row, 'name');

        $definition = $this->hydrateDefinition($versionId, $name, $versionNumber);

        return new QuizVersion(
            $quizId,
            $versionId,
            $versionNumber,
            $status,
            $name,
            $definition,
        );
    }

    private function hydrateDefinition(int $versionId, string $name, int $versionNumber): QuizDefinition
    {
        $programIds = $this->hydrateProgramIds($versionId);
        $programs = array_fill_keys(array_keys($programIds), true);
        $questionsStatement = $this->connection()->prepare(
            'SELECT id, prompt, image_path, sort_order
             FROM questions
             WHERE quiz_version_id = :quiz_version_id
             ORDER BY sort_order ASC, id ASC'
        );
        $questionsStatement->execute(['quiz_version_id' => $versionId]);
        $questionRows = $questionsStatement->fetchAll();

        $questions = [];
        foreach ($questionRows as $questionRow) {
            $questionId = $this->rowInt($questionRow, 'id');
            $options = $this->hydrateOptions($questionId, $programIds);

            $questions[] = [
                'id' => 'question-' . $questionId,
                'text' => $this->rowString($questionRow, 'prompt'),
                'image_path' => $this->nullableRowString($questionRow, 'image_path'),
                'order' => $this->rowInt($questionRow, 'sort_order'),
                'options' => $options,
            ];
        }

        return new QuizDefinition($name, $versionNumber, $programs, $questions);
    }

    /** @return array<string, int> */
    private function hydrateProgramIds(int $versionId): array
    {
        $statement = $this->connection()->prepare(
            'SELECT p.id, qvpp.program_code_snapshot, p.school_id, q.school_id AS quiz_school_id
             FROM quiz_version_programs AS qvp
             INNER JOIN quiz_version_program_presentations AS qvpp ON qvpp.quiz_version_program_id = qvp.id
             INNER JOIN quiz_versions AS qv ON qv.id = qvp.quiz_version_id
             INNER JOIN quizzes AS q ON q.id = qv.quiz_id
             INNER JOIN programs AS p ON p.id = qvp.program_id
             WHERE qvp.quiz_version_id = :quiz_version_id
             ORDER BY qvpp.program_code_snapshot ASC, p.id ASC'
        );
        $statement->execute(['quiz_version_id' => $versionId]);
        $rows = $statement->fetchAll();
        $programIds = [];

        foreach ($rows as $row) {
            if ($this->rowInt($row, 'school_id') !== $this->rowInt($row, 'quiz_school_id')) {
                throw new RuntimeException('Persistence invariant violation: version program belongs to another school.');
            }

            $programCode = $this->rowString($row, 'program_code_snapshot');
            if (isset($programIds[$programCode])) {
                throw new RuntimeException('Persistence invariant violation: duplicate version program code.');
            }

            $programIds[$programCode] = $this->rowInt($row, 'id');
        }

        return $programIds;
    }

    /**
     * @param array<string, int> $programIds
     * @return list<array<string, mixed>>
     */
    private function hydrateOptions(int $questionId, array $programIds): array
    {
        $optionsStatement = $this->connection()->prepare(
            'SELECT id, option_text, sort_order
             FROM question_options
             WHERE question_id = :question_id
             ORDER BY sort_order ASC, id ASC'
        );
        $optionsStatement->execute(['question_id' => $questionId]);
        $optionRows = $optionsStatement->fetchAll();

        $options = [];
        foreach ($optionRows as $optionRow) {
            $optionId = $this->rowInt($optionRow, 'id');
            $options[] = [
                'id' => 'option-' . $optionId,
                'text' => $this->rowString($optionRow, 'option_text'),
                'order' => $this->rowInt($optionRow, 'sort_order'),
                'weights' => $this->hydrateWeights($optionId, $programIds),
            ];
        }

        return $options;
    }

    /**
     * @param array<string, int> $programIds
     * @return list<array{program: string, weight: float}>
     */
    private function hydrateWeights(int $optionId, array $programIds): array
    {
        $weightsStatement = $this->connection()->prepare(
            'SELECT ow.program_id, qvpp.program_code_snapshot, ow.weight
             FROM option_weights AS ow
             INNER JOIN question_options AS qo ON qo.id = ow.question_option_id
             INNER JOIN questions AS q ON q.id = qo.question_id
             LEFT JOIN quiz_version_programs AS qvp
                ON qvp.program_id = ow.program_id
               AND qvp.quiz_version_id = q.quiz_version_id
             LEFT JOIN quiz_version_program_presentations AS qvpp
                ON qvpp.quiz_version_program_id = qvp.id
             WHERE ow.question_option_id = :question_option_id
             ORDER BY ow.id ASC'
        );
        $weightsStatement->execute(['question_option_id' => $optionId]);
        $weightRows = $weightsStatement->fetchAll();

        $weights = [];
        foreach ($weightRows as $weightRow) {
            $program = $this->rowString($weightRow, 'program_code_snapshot');
            if (!isset($programIds[$program])) {
                throw new RuntimeException('Persistence invariant violation: weight program is not a version member.');
            }

            if ($this->rowInt($weightRow, 'program_id') !== $programIds[$program]) {
                throw new RuntimeException('Persistence invariant violation: weight program does not match version membership.');
            }

            $weights[] = [
                'program' => $program,
                'weight' => $this->rowFloat($weightRow, 'weight'),
            ];
        }

        return $weights;
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

    private function assertKnownStatus(string $status): void
    {
        if (!in_array($status, [
            QuizVersion::STATUS_DRAFT,
            QuizVersion::STATUS_PUBLISHED,
            QuizVersion::STATUS_DISCARDED,
        ], true)) {
            throw new \InvalidArgumentException('Invalid quiz version status.');
        }
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted quiz version data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted quiz version data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function nullableRowString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted quiz version data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowFloat(array $row, string $key): float
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key]) || !is_finite((float) $row[$key])) {
            throw new RuntimeException('Invalid persisted quiz version data.');
        }

        return (float) $row[$key];
    }
}
