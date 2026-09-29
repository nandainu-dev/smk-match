<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection === null) {
            $this->connection = new PDO(self::dsn($this->config), $this->config->string('DB_USERNAME'), $this->config->string('DB_PASSWORD'), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->connection;
    }

    public static function dsn(Config $config): string
    {
        $host = $config->string('DB_HOST');
        $port = $config->string('DB_PORT');
        $database = $config->string('DB_DATABASE');
        $charset = $config->string('DB_CHARSET');

        foreach ([$host, $port, $database, $charset] as $value) {
            if ($value === '' || str_contains($value, ';')) {
                throw new \InvalidArgumentException('Invalid database configuration.');
            }
        }

        return "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    }
}
