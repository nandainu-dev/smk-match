<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

final class QuizVersionProgramPresentationRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(QuizVersionProgramPresentation $presentation): QuizVersionProgramPresentation
    {
        $presentation->validate();
        $statement = $this->connection()->prepare(
            'INSERT INTO quiz_version_program_presentations (
                quiz_version_program_id, program_code_snapshot, program_name_snapshot,
                personality_title_snapshot, mascot_path_snapshot, primary_color_snapshot,
                accent_color_snapshot, tagline_snapshot, description_snapshot,
                superpower_snapshot, skills_snapshot, careers_snapshot, snapshot_provenance,
                created_at, updated_at
            ) VALUES (
                :membership_id, :program_code, :program_name, :personality_title,
                :mascot_path, :primary_color, :accent_color, :tagline, :description,
                :superpower, :skills, :careers, :provenance, UTC_TIMESTAMP(), UTC_TIMESTAMP()
            )'
        );
        $statement->execute([
            'membership_id' => $presentation->quizVersionProgramId,
            'program_code' => $presentation->programCodeSnapshot,
            'program_name' => $presentation->programNameSnapshot,
            'personality_title' => $presentation->personalityTitleSnapshot,
            'mascot_path' => $presentation->mascotPathSnapshot,
            'primary_color' => $presentation->primaryColorSnapshot,
            'accent_color' => $presentation->accentColorSnapshot,
            'tagline' => $presentation->taglineSnapshot,
            'description' => $presentation->descriptionSnapshot,
            'superpower' => $presentation->superpowerSnapshot,
            'skills' => $presentation->skillsSnapshot,
            'careers' => $presentation->careersSnapshot,
            'provenance' => $presentation->snapshotProvenance,
        ]);

        return $this->requireByMembershipId($presentation->quizVersionProgramId);
    }

    public function findByQuizVersionProgramId(int $quizVersionProgramId): ?QuizVersionProgramPresentation
    {
        if ($quizVersionProgramId < 1) {
            throw new \InvalidArgumentException('Quiz version program identity must be positive.');
        }

        $statement = $this->connection()->prepare($this->selectSql() . ' WHERE qvpp.quiz_version_program_id = :membership_id');
        $statement->execute(['membership_id' => $quizVersionProgramId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<QuizVersionProgramPresentation> */
    public function findAllByQuizVersionId(int $quizVersionId): array
    {
        if ($quizVersionId < 1) {
            throw new \InvalidArgumentException('Quiz version identity must be positive.');
        }

        $statement = $this->connection()->prepare($this->selectSql() . ' WHERE qvp.quiz_version_id = :version_id ORDER BY qvpp.quiz_version_program_id ASC');
        $statement->execute(['version_id' => $quizVersionId]);

        return array_map(fn(array $row): QuizVersionProgramPresentation => $this->hydrate($row), $statement->fetchAll());
    }

    public function copyToMembership(QuizVersionProgramPresentation $source, int $targetMembershipId): QuizVersionProgramPresentation
    {
        return $this->create(new QuizVersionProgramPresentation(
            $targetMembershipId,
            $source->programCodeSnapshot,
            $source->programNameSnapshot,
            $source->personalityTitleSnapshot,
            $source->mascotPathSnapshot,
            $source->primaryColorSnapshot,
            $source->accentColorSnapshot,
            $source->taglineSnapshot,
            $source->descriptionSnapshot,
            $source->superpowerSnapshot,
            $source->skillsSnapshot,
            $source->careersSnapshot,
            'version_snapshot',
        ));
    }

    private function requireByMembershipId(int $membershipId): QuizVersionProgramPresentation
    {
        $presentation = $this->findByQuizVersionProgramId($membershipId);
        if ($presentation === null) {
            throw new RuntimeException('Persisted presentation snapshot could not be reloaded.');
        }

        return $presentation;
    }

    private function selectSql(): string
    {
        return 'SELECT qvpp.quiz_version_program_id, qvpp.program_code_snapshot, qvpp.program_name_snapshot,
                       qvpp.personality_title_snapshot, qvpp.mascot_path_snapshot, qvpp.primary_color_snapshot,
                       qvpp.accent_color_snapshot, qvpp.tagline_snapshot, qvpp.description_snapshot,
                       qvpp.superpower_snapshot, qvpp.skills_snapshot, qvpp.careers_snapshot, qvpp.snapshot_provenance
                FROM quiz_version_program_presentations AS qvpp
                INNER JOIN quiz_version_programs AS qvp ON qvp.id = qvpp.quiz_version_program_id';
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): QuizVersionProgramPresentation
    {
        return new QuizVersionProgramPresentation(
            $this->int($row, 'quiz_version_program_id'),
            $this->string($row, 'program_code_snapshot'),
            $this->string($row, 'program_name_snapshot'),
            $this->nullableString($row, 'personality_title_snapshot'),
            $this->nullableString($row, 'mascot_path_snapshot'),
            $this->nullableString($row, 'primary_color_snapshot'),
            $this->nullableString($row, 'accent_color_snapshot'),
            $this->nullableString($row, 'tagline_snapshot'),
            $this->nullableString($row, 'description_snapshot'),
            $this->nullableString($row, 'superpower_snapshot'),
            $this->nullableString($row, 'skills_snapshot'),
            $this->nullableString($row, 'careers_snapshot'),
            $this->string($row, 'snapshot_provenance'),
        );
    }

    /** @param array<string, mixed> $row */
    private function int(array $row, string $key): int { if (!isset($row[$key]) || !is_numeric($row[$key])) throw new RuntimeException('Invalid presentation snapshot data.'); return (int) $row[$key]; }
    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string { if (!isset($row[$key]) || !is_string($row[$key])) throw new RuntimeException('Invalid presentation snapshot data.'); return $row[$key]; }
    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string { if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) throw new RuntimeException('Invalid presentation snapshot data.'); return $row[$key]; }
}
