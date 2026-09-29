<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\PhpVersion;

PhpVersion::assertSupported();
$config = Config::load(SMK_MATCH_ROOT);
$database = new Database($config);
$pdo = $database->connection();
$pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

foreach (glob(__DIR__ . '/migrations/*.sql') ?: [] as $file) {
    $migration = basename($file);
    if (in_array($migration, $applied, true)) {
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration: ' . $migration);
    }
    $pdo->beginTransaction();
    try {
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $pdo->exec($statement);
        }
        $statement = $pdo->prepare('INSERT INTO migrations (migration, applied_at) VALUES (:migration, UTC_TIMESTAMP())');
        $statement->execute(['migration' => $migration]);
        $pdo->commit();
        echo "Applied {$migration}\n";
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}
