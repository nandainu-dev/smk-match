<?php
declare(strict_types=1);

namespace App\Core;

final class AdminAccessService
{
    public function __construct(private readonly AdminRepository $admins)
    {
    }

    /**
     * @return array{admin_id: int, school_id: int}|null
     */
    public function authenticate(string $email, string $password): ?array
    {
        if ($password === '') {
            return null;
        }

        $matches = [];
        foreach ($this->admins->findActiveCredentialsByNormalizedEmail($email) as $credential) {
            if (password_verify($password, $credential['password_hash'])) {
                $matches[] = $credential;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        return [
            'admin_id' => $matches[0]['id'],
            'school_id' => $matches[0]['school_id'],
        ];
    }
}
