<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\AdminAuthController;
use App\Core\AdminAccessService;
use App\Core\AdminRepository;
use App\Core\AdminSession;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
const ADMIN_ACCESS_TEST_DATABASE = 'smk_match_g13_r1_test';

function adminAccessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function adminAccessMigrationStatements(string $path): array
{
    $contents = file_get_contents($path);
    adminAccessAssert($contents !== false, 'Migration could not be read.');

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function resetAdminAccessSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }

    session_id('');
    $_SESSION = [];
}

/** @param array<string, string> $values */
function adminAccessRequest(string $method, string $path, array $values = []): Request
{
    return new Request($method, $path, [], http_build_query($values), [], false);
}

function loginFailureResponse(
    AdminAuthController $controller,
    AdminSession $session,
    string $email,
    string $password,
): \App\Core\Response {
    resetAdminAccessSession();
    $session->start(false);

    return $controller->login(adminAccessRequest('POST', '/admin/login', [
        'csrf_token' => $session->csrfToken(),
        'email' => $email,
        'password' => $password,
    ]));
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');

adminAccessAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === ADMIN_ACCESS_TEST_DATABASE,
    'Unsafe test database configuration.',
);
adminAccessAssert(
    is_string($username) && $username !== '' && is_string($password),
    'Local database credentials are required.',
);

$adminConnection = null;
try {
    $adminConnection = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    );
    $adminConnection->exec('DROP DATABASE IF EXISTS ' . ADMIN_ACCESS_TEST_DATABASE);
    $adminConnection->exec('CREATE DATABASE ' . ADMIN_ACCESS_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        foreach (adminAccessMigrationStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }

    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Admin School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec(
        "INSERT INTO schools (public_uuid, name, created_at, updated_at)
         VALUES ('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Admin School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $schoolB = (int) $connection->lastInsertId();

    $insertAdmin = $connection->prepare(
        'INSERT INTO admins (school_id, name, email, password_hash, is_active, created_at, updated_at)
         VALUES (:school_id, :name, :email, :password_hash, :is_active, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $activePasswordHash = password_hash('correct-password', PASSWORD_DEFAULT);
    $schoolBPasswordHash = password_hash('school-b-password', PASSWORD_DEFAULT);
    $inactivePasswordHash = password_hash('inactive-password', PASSWORD_DEFAULT);
    adminAccessAssert(
        is_string($activePasswordHash)
            && is_string($schoolBPasswordHash)
            && is_string($inactivePasswordHash),
        'Fixture password hashes could not be generated.',
    );
    $insertAdmin->execute([
        'school_id' => $schoolA,
        'name' => 'Active Admin',
        'email' => 'admin@example.test',
        'password_hash' => $activePasswordHash,
        'is_active' => 1,
    ]);
    $activeAdminId = (int) $connection->lastInsertId();
    $insertAdmin->execute([
        'school_id' => $schoolB,
        'name' => 'Different School Admin',
        'email' => 'admin@example.test',
        'password_hash' => $schoolBPasswordHash,
        'is_active' => 1,
    ]);
    $schoolBAdminId = (int) $connection->lastInsertId();
    $insertAdmin->execute([
        'school_id' => $schoolA,
        'name' => 'Inactive Admin',
        'email' => 'inactive@example.test',
        'password_hash' => $inactivePasswordHash,
        'is_active' => 0,
    ]);

    $repository = new AdminRepository($database);
    $access = new AdminAccessService($repository);
    $session = new AdminSession();
    $controller = new AdminAuthController(Config::load(SMK_MATCH_ROOT), $access, $session);

    $identity = $access->authenticate(' ADMIN@EXAMPLE.TEST ', 'correct-password');
    adminAccessAssert(
        $identity === ['admin_id' => $activeAdminId, 'school_id' => $schoolA],
        'Valid active credentials did not authenticate to the persisted school scope.',
    );
    adminAccessAssert(
        $access->authenticate('admin@example.test', 'wrong-password') === null,
        'Incorrect password authenticated.',
    );
    adminAccessAssert(
        $access->authenticate('unknown@example.test', 'correct-password') === null,
        'Unknown email authenticated.',
    );
    adminAccessAssert(
        $access->authenticate('inactive@example.test', 'inactive-password') === null,
        'Inactive admin authenticated.',
    );
    adminAccessAssert(
        $access->authenticate('admin@example.test', 'school-b-password') === ['admin_id' => $schoolBAdminId, 'school_id' => $schoolB],
        'Duplicate email did not retain the matching persisted school scope.',
    );
    adminAccessAssert(
        !array_key_exists('password_hash', $identity),
        'Authentication result exposed a password hash.',
    );

    $wrongPassword = loginFailureResponse($controller, $session, 'admin@example.test', 'wrong-password');
    $unknownEmail = loginFailureResponse($controller, $session, 'unknown@example.test', 'correct-password');
    $inactiveAdmin = loginFailureResponse($controller, $session, 'inactive@example.test', 'inactive-password');
    foreach ([$wrongPassword, $unknownEmail, $inactiveAdmin] as $failure) {
        adminAccessAssert(
            $failure->status === 401
                && str_contains($failure->body, 'Email atau kata sandi tidak valid.')
                && !str_contains($failure->body, 'password_hash')
                && !str_contains($failure->body, '$2y$'),
            'Authentication failure was not generic and safe.',
        );
    }

    resetAdminAccessSession();
    $session->start(false);
    $oldSessionId = session_id();
    $validLogin = $controller->login(adminAccessRequest('POST', '/admin/login', [
        'csrf_token' => $session->csrfToken(),
        'email' => 'admin@example.test',
        'password' => 'correct-password',
        'school_id' => (string) $schoolB,
    ]));
    adminAccessAssert(
        $validLogin->status === 302
            && ($validLogin->headers['Location'] ?? null) === '/admin'
            && $oldSessionId !== session_id(),
        'Valid login did not regenerate the session and use the fixed safe redirect.',
    );
    adminAccessAssert(
        $session->identity() === ['admin_id' => $activeAdminId, 'school_id' => $schoolA],
        'Request-controlled school scope changed the persisted admin session scope.',
    );

    $invalidCsrf = $controller->logout(adminAccessRequest('POST', '/admin/logout', [
        'csrf_token' => str_repeat('0', 64),
    ]));
    adminAccessAssert(
        $invalidCsrf->status === 403 && $session->identity() !== null,
        'Invalid CSRF token changed the authenticated session.',
    );
    $missingCsrf = $controller->logout(adminAccessRequest('POST', '/admin/logout'));
    adminAccessAssert(
        $missingCsrf->status === 403 && $session->identity() !== null,
        'Missing CSRF token changed the authenticated session.',
    );
    $logout = $controller->logout(adminAccessRequest('POST', '/admin/logout', [
        'csrf_token' => $session->csrfToken(),
    ]));
    $session->start(false);
    adminAccessAssert(
        $logout->status === 302
            && ($logout->headers['Location'] ?? null) === '/admin/login'
            && $session->identity() === null,
        'Logout did not clear the authenticated admin session.',
    );

    resetAdminAccessSession();
    $session->start(false);
    $csrfFailure = $controller->login(adminAccessRequest('POST', '/admin/login', [
        'csrf_token' => 'invalid',
        'email' => 'admin@example.test',
        'password' => 'correct-password',
    ]));
    adminAccessAssert(
        $csrfFailure->status === 403 && $session->identity() === null,
        'Invalid login CSRF token authenticated an admin.',
    );

    $routes = require SMK_MATCH_ROOT . '/routes/web.php';
    $router = $routes(Config::load(SMK_MATCH_ROOT));
    $registrationRoute = $router->dispatch(new Request('GET', '/admin/register'));
    adminAccessAssert(
        $registrationRoute->status === 404,
        'Public admin registration route exists.',
    );

} finally {
    resetAdminAccessSession();
    if ($adminConnection instanceof PDO) {
        $adminConnection->exec('DROP DATABASE IF EXISTS ' . ADMIN_ACCESS_TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}

echo "Admin access integration tests passed.\n";
