<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

final class ParticipantRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(
        int $schoolId,
        string $publicUuid,
        string $fullName,
        ?string $originSchool,
        ?string $className,
        ?string $phone,
        bool $marketingConsent,
        ?DateTimeImmutable $marketingConsentAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): Participant {
        $this->assertPositiveId($schoolId, 'Participant school identity');
        $this->assertNonBlankString($publicUuid, 'Participant public UUID');
        $this->assertNonBlankString($fullName, 'Participant full name');
        $this->assertConsentInvariant($marketingConsent, $marketingConsentAt);

        $statement = $this->connection()->prepare(
            'INSERT INTO participants (
                school_id,
                public_uuid,
                full_name,
                origin_school,
                class_name,
                phone,
                marketing_consent,
                marketing_consent_at,
                created_at,
                updated_at
            ) VALUES (
                :school_id,
                :public_uuid,
                :full_name,
                :origin_school,
                :class_name,
                :phone,
                :marketing_consent,
                :marketing_consent_at,
                :created_at,
                :updated_at
            )'
        );
        $statement->execute([
            'school_id' => $schoolId,
            'public_uuid' => $publicUuid,
            'full_name' => $fullName,
            'origin_school' => $originSchool,
            'class_name' => $className,
            'phone' => $phone,
            'marketing_consent' => $marketingConsent ? 1 : 0,
            'marketing_consent_at' => $marketingConsentAt === null ? null : $this->formatUtc($marketingConsentAt),
            'created_at' => $this->formatUtc($createdAt),
            'updated_at' => $this->formatUtc($updatedAt),
        ]);

        $participant = $this->findById((int) $this->connection()->lastInsertId());
        if ($participant === null) {
            throw new RuntimeException('Persisted participant could not be reloaded.');
        }

        return $participant;
    }

    public function findById(int $participantId): ?Participant
    {
        $this->assertPositiveId($participantId, 'Participant identity');

        $statement = $this->connection()->prepare($this->selectSql() . ' WHERE id = :id');
        $statement->execute(['id' => $participantId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByPublicUuid(string $publicUuid): ?Participant
    {
        $this->assertNonBlankString($publicUuid, 'Participant public UUID');

        $statement = $this->connection()->prepare($this->selectSql() . ' WHERE public_uuid = :public_uuid');
        $statement->execute(['public_uuid' => $publicUuid]);
        $row = $statement->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    private function connection(): PDO
    {
        return $this->database->connection();
    }

    private function selectSql(): string
    {
        return 'SELECT id, school_id, public_uuid, full_name, origin_school, class_name, phone,
                       marketing_consent, marketing_consent_at, created_at, updated_at
                FROM participants';
    }

    private function assertPositiveId(int $value, string $label): void
    {
        if ($value < 1) {
            throw new \InvalidArgumentException($label . ' must be positive.');
        }
    }

    private function assertNonBlankString(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($label . ' must not be blank.');
        }
    }

    private function assertConsentInvariant(bool $marketingConsent, ?DateTimeImmutable $marketingConsentAt): void
    {
        if ($marketingConsent && $marketingConsentAt === null) {
            throw new \InvalidArgumentException('Marketing consent timestamp is required when consent is granted.');
        }

        if (!$marketingConsent && $marketingConsentAt !== null) {
            throw new \InvalidArgumentException('Marketing consent timestamp must be null when consent is not granted.');
        }
    }

    private function formatUtc(DateTimeImmutable $timestamp): string
    {
        return $timestamp->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Participant
    {
        return new Participant(
            $this->rowInt($row, 'id'),
            $this->rowInt($row, 'school_id'),
            $this->rowString($row, 'public_uuid'),
            $this->rowString($row, 'full_name'),
            $this->rowNullableString($row, 'origin_school'),
            $this->rowNullableString($row, 'class_name'),
            $this->rowNullableString($row, 'phone'),
            $this->rowBool($row, 'marketing_consent'),
            $this->rowNullableString($row, 'marketing_consent_at'),
            $this->rowString($row, 'created_at'),
            $this->rowString($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private function rowInt(array $row, string $key): int
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted participant data.');
        }

        return (int) $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        if (!array_key_exists($key, $row) || !is_string($row[$key])) {
            throw new RuntimeException('Invalid persisted participant data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row) || ($row[$key] !== null && !is_string($row[$key]))) {
            throw new RuntimeException('Invalid persisted participant data.');
        }

        return $row[$key];
    }

    /** @param array<string, mixed> $row */
    private function rowBool(array $row, string $key): bool
    {
        if (!array_key_exists($key, $row) || !is_numeric($row[$key])) {
            throw new RuntimeException('Invalid persisted participant data.');
        }

        $value = (int) $row[$key];
        if ($value !== 0 && $value !== 1) {
            throw new RuntimeException('Invalid persisted participant data.');
        }

        return $value === 1;
    }
}
