<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\AdminProgramMediaController;
use App\Core\AdminAccessService;
use App\Core\AdminProgramMediaRepository;
use App\Core\AdminRepository;
use App\Core\AdminSession;
use App\Core\Config;
use App\Core\Database;
use App\Core\ProgramMediaStorage;
use App\Core\ProgramPresentationMediaService;
use App\Core\QuizDefinition;
use App\Core\QuizVersionProgramPresentation;
use App\Core\QuizVersionProgramPresentationRepository;
use App\Core\QuizVersionRepository;
use App\Core\QuizVersionService;
use App\Core\Request;

const ADMIN_PROGRAM_MEDIA_TEST_DATABASE = 'smk_match_g13_r3_test';

function programMediaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function programMediaExpectFailure(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

/** @return list<string> */
function programMediaMigrationStatements(string $path): array
{
    $contents = file_get_contents($path);
    programMediaAssert($contents !== false, 'Migration could not be read.');

    return array_values(array_filter(array_map('trim', explode(';', $contents))));
}

function programMediaApplyMigrations(PDO $connection): void
{
    foreach (glob(SMK_MATCH_ROOT . '/database/migrations/*.sql') ?: [] as $migration) {
        foreach (programMediaMigrationStatements($migration) as $statement) {
            $connection->exec($statement);
        }
    }
}

function programMediaResetSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }

    session_id('');
    $_SESSION = [];
}

/** @param array<string, mixed> $form @param array<string, mixed> $uploads */
function programMediaRequest(string $method, string $path, array $form = [], array $uploads = []): Request
{
    return new Request($method, $path, [], '', [], false, [], $form, $uploads);
}

function programMediaDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                programMediaDirectory($path);
            } else {
                unlink($path);
            }
        }
    }

    rmdir($directory);
}

function programMediaFixture(string $directory, string $filename, string $contents): string
{
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Temporary fixture directory could not be created.');
    }

    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    programMediaAssert(file_put_contents($path, $contents) !== false, 'Image fixture could not be written.');

    return $path;
}

function programMediaPng(): string
{
    $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL7WQAAAABJRU5ErkJggg==', true);
    programMediaAssert(is_string($data), 'PNG fixture could not be decoded.');

    return $data;
}

function programMediaJpeg(): string
{
    $data = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQL/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AT//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AT//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Av/EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8h/9k=', true);
    programMediaAssert(is_string($data), 'JPEG fixture could not be decoded.');

    return $data;
}

function programMediaWebp(): string
{
    $data = base64_decode('UklGRiIAAABXRUJQVlA4TBEAAAAvAAAAAAfQ//73v/+BiOh/AAA=', true);
    programMediaAssert(is_string($data), 'WebP fixture could not be decoded.');

    return $data;
}

function programMediaDefinition(): QuizDefinition
{
    return new QuizDefinition('Media snapshot quiz', 1, ['ALPHA' => true], [[
        'id' => 'q1',
        'text' => 'Question',
        'order' => 10,
        'options' => [
            ['id' => 'q1a', 'text' => 'Option A', 'order' => 10, 'weights' => [['program' => 'ALPHA', 'weight' => 1]]],
            ['id' => 'q1b', 'text' => 'Option B', 'order' => 20, 'weights' => [['program' => 'ALPHA', 'weight' => 2]]],
        ],
    ]]);
}

$host = getenv('DB_HOST');
$port = getenv('DB_PORT');
$databaseName = getenv('DB_DATABASE');
$username = getenv('DB_USERNAME');
$password = getenv('DB_PASSWORD');
programMediaAssert(
    $host === '127.0.0.1' && $port === '3306' && $databaseName === ADMIN_PROGRAM_MEDIA_TEST_DATABASE,
    'Unsafe test database configuration.',
);
programMediaAssert(is_string($username) && $username !== '' && is_string($password), 'Local database credentials are required.');

$admin = null;
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smk-match-program-media-' . bin2hex(random_bytes(8));
try {
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $admin->exec('DROP DATABASE IF EXISTS ' . ADMIN_PROGRAM_MEDIA_TEST_DATABASE);
    $admin->exec('CREATE DATABASE ' . ADMIN_PROGRAM_MEDIA_TEST_DATABASE . ' CHARACTER SET utf8mb4');

    $database = new Database(Config::load(SMK_MATCH_ROOT));
    $connection = $database->connection();
    programMediaApplyMigrations($connection);

    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('13131313-1313-4131-8131-131313131313', 'Media School A', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolA = (int) $connection->lastInsertId();
    $connection->exec("INSERT INTO schools (public_uuid, name, created_at, updated_at) VALUES ('23232323-2323-4232-8232-232323232323', 'Media School B', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    $schoolB = (int) $connection->lastInsertId();

    $programInsert = $connection->prepare('INSERT INTO programs (school_id, name, short_name, created_at, updated_at) VALUES (:school_id, :name, :code, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $programInsert->execute(['school_id' => $schoolA, 'name' => 'Alpha Program', 'code' => 'ALPHA']);
    $programA = (int) $connection->lastInsertId();
    $programInsert->execute(['school_id' => $schoolB, 'name' => 'Beta Program', 'code' => 'BETA']);
    $programB = (int) $connection->lastInsertId();

    $adminInsert = $connection->prepare('INSERT INTO admins (school_id, name, email, password_hash, is_active, created_at, updated_at) VALUES (:school_id, :name, :email, :password_hash, :active, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $activeHash = password_hash('media-password', PASSWORD_DEFAULT);
    $inactiveHash = password_hash('inactive-password', PASSWORD_DEFAULT);
    programMediaAssert(is_string($activeHash) && is_string($inactiveHash), 'Password fixture could not be generated.');
    $adminInsert->execute(['school_id' => $schoolA, 'name' => 'Media Admin', 'email' => 'media@example.test', 'password_hash' => $activeHash, 'active' => 1]);
    $adminA = (int) $connection->lastInsertId();
    $adminInsert->execute(['school_id' => $schoolA, 'name' => 'Inactive Admin', 'email' => 'inactive@example.test', 'password_hash' => $inactiveHash, 'active' => 0]);

    $storage = new ProgramMediaStorage($temporaryRoot . DIRECTORY_SEPARATOR . 'public');
    $repository = new AdminProgramMediaRepository($database);
    $service = new ProgramPresentationMediaService($repository, $storage);
    $session = new AdminSession();
    $controller = new AdminProgramMediaController(Config::load(SMK_MATCH_ROOT), $session, $service);

    programMediaAssert((new AdminAccessService(new AdminRepository($database)))->authenticate('inactive@example.test', 'inactive-password') === null, 'Inactive admin authenticated.');
    programMediaAssert($controller->index(programMediaRequest('GET', '/admin/program-media'))->status === 302, 'Unauthenticated media page was not rejected.');

    programMediaResetSession();
    $session->start(false);
    $session->login(['admin_id' => $adminA, 'school_id' => $schoolA]);
    $csrf = $session->csrfToken();
    $page = $controller->index(programMediaRequest('GET', '/admin/program-media'));
    programMediaAssert($page->status === 200 && str_contains($page->body, 'Alpha Program') && !str_contains($page->body, 'Beta Program'), 'Program media page did not enforce school scope.');
    programMediaAssert($controller->upload(programMediaRequest('POST', '/admin/programs/' . $programA . '/mascot'), (string) $programA)->status === 403, 'Missing CSRF upload was accepted.');

    $fixtures = $temporaryRoot . DIRECTORY_SEPARATOR . 'fixtures';
    $jpeg = programMediaFixture($fixtures, 'original-upload-name.jpeg', programMediaJpeg());
    $upload = $controller->upload(
        programMediaRequest('POST', '/admin/programs/' . $programA . '/mascot', ['csrf_token' => $csrf, 'school_id' => (string) $schoolB], [
            'mascot' => ['name' => 'original-upload-name.jpeg', 'tmp_name' => $jpeg, 'error' => UPLOAD_ERR_OK, 'size' => filesize($jpeg)],
        ]),
        (string) $programA,
    );
    programMediaAssert($upload->status === 302, 'Authorized mascot upload failed.');
    $first = $repository->findProgramForSchool($schoolA, $programA);
    programMediaAssert($first !== null && is_string($first['mascot_path']) && ProgramMediaStorage::isCanonicalPublicPath($first['mascot_path']), 'Current mascot is not canonical.');
    $firstPath = $first['mascot_path'];
    programMediaAssert(!str_contains($firstPath, 'original-upload-name') && preg_match('#/uploads/programs/[a-f0-9]{64}\.jpg\z#', $firstPath) === 1, 'Original filename was reused.');
    $firstFile = $temporaryRoot . DIRECTORY_SEPARATOR . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $firstPath);
    programMediaAssert(is_file($firstFile), 'First mascot file was not stored.');

    $quizInsert = $connection->prepare('INSERT INTO quizzes (school_id, name, created_at, updated_at) VALUES (:school_id, :name, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $quizInsert->execute(['school_id' => $schoolA, 'name' => 'Media Quiz']);
    $quizId = (int) $connection->lastInsertId();
    $versions = new QuizVersionRepository($database, new QuizVersionProgramPresentationRepository($database));
    $versionService = new QuizVersionService($versions);
    $source = $versionService->getOrCreateDraft($quizId, programMediaDefinition());
    $sourceSnapshot = (new QuizVersionProgramPresentationRepository($database))->findAllByQuizVersionId($source->id)[0];
    programMediaAssert($sourceSnapshot->mascotPathSnapshot === $firstPath, 'New version did not snapshot the current mascot.');
    $versionService->publish($quizId, $source->id);

    $png = programMediaFixture($fixtures, 'replace.png', programMediaPng());
    $second = $service->uploadMascot($schoolA, $programA, $png);
    programMediaAssert($second['mascot_path'] !== $firstPath && is_string($second['mascot_path']) && str_ends_with($second['mascot_path'], '.png'), 'Replacement did not create a new PNG mascot.');
    $secondPath = $second['mascot_path'];
    programMediaAssert(is_file($firstFile), 'Replacement removed the historical media file.');
    $sourceReloaded = (new QuizVersionProgramPresentationRepository($database))->findAllByQuizVersionId($source->id)[0];
    programMediaAssert($sourceReloaded->mascotPathSnapshot === $firstPath, 'Current mascot replacement changed a historical snapshot.');
    $clone = $versionService->cloneVersionToDraft($quizId, $source->id);
    $cloneSnapshot = (new QuizVersionProgramPresentationRepository($database))->findAllByQuizVersionId($clone->id)[0];
    programMediaAssert($cloneSnapshot->mascotPathSnapshot === $firstPath, 'Clone refreshed mascot from the mutable current catalog.');

    $webp = programMediaFixture($fixtures, 'third.webp', programMediaWebp());
    $third = $service->uploadMascot($schoolA, $programA, $webp);
    programMediaAssert(is_string($third['mascot_path']) && str_ends_with($third['mascot_path'], '.webp'), 'WebP mascot was not accepted.');
    $historyCount = (int) $connection->query('SELECT COUNT(*) FROM program_media WHERE program_id = ' . $programA)->fetchColumn();
    programMediaAssert($historyCount === 3, 'Media history is not append-only.');

    $svg = programMediaFixture($fixtures, 'unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    programMediaExpectFailure(fn () => $storage->store($svg), 'SVG upload was accepted.');
    $invalid = programMediaFixture($fixtures, 'unsafe.bin', "<?php echo 'unsafe';");
    programMediaExpectFailure(fn () => $storage->store($invalid), 'Executable/non-image upload was accepted.');
    $large = programMediaFixture($fixtures, 'large.bin', str_repeat('A', (5 * 1024 * 1024) + 1));
    programMediaExpectFailure(fn () => $storage->store($large), 'Oversized upload was accepted.');
    $dimensionPng = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N', 4097) . pack('N', 1) . "\x08\x02\x00\x00\x00" . pack('N', 0) . pack('N', 0) . 'IEND' . pack('N', 0);
    $oversizedDimensions = programMediaFixture($fixtures, 'dimension.png', $dimensionPng);
    programMediaExpectFailure(fn () => $storage->store($oversizedDimensions), 'Oversized-dimension upload was accepted.');

    $foreign = $controller->upload(programMediaRequest('POST', '/admin/programs/' . $programB . '/mascot', ['csrf_token' => $csrf], []), (string) $programB);
    programMediaAssert($foreign->status === 404, 'Cross-school program access was accepted.');
$foreignProgram = $repository->findProgramForSchool($schoolB, $programB);
programMediaAssert(
    $foreignProgram !== null && $foreignProgram['mascot_path'] === null,
    'Cross-school program mascot changed.',
);

    $removed = $service->removeMascot($schoolA, $programA);
    programMediaAssert($removed['mascot_path'] === null, 'Mascot removal did not clear current reference.');
    programMediaAssert((int) $connection->query('SELECT COUNT(*) FROM program_media WHERE program_id = ' . $programA)->fetchColumn() === 3, 'Mascot removal deleted media history.');
    programMediaAssert(is_file($firstFile), 'Mascot removal deleted an old physical file.');

    new QuizVersionProgramPresentation(1, 'ALPHA', 'Alpha', null, '/assets/mascots/alpha.png', null, null, null, null, null, null, null, 'version_snapshot');
    new QuizVersionProgramPresentation(1, 'ALPHA', 'Alpha', null, '/uploads/programs/' . str_repeat('a', 64) . '.webp', null, null, null, null, null, null, null, 'version_snapshot');
    programMediaExpectFailure(fn () => new QuizVersionProgramPresentation(1, 'ALPHA', 'Alpha', null, '/uploads/programs/not-safe.svg', null, null, null, null, null, null, null, 'version_snapshot'), 'Unsafe media snapshot path was accepted.');
    programMediaExpectFailure(fn () => new QuizVersionProgramPresentation(1, 'ALPHA', 'Alpha', null, 'https://example.test/image.png', null, null, null, null, null, null, null, 'version_snapshot'), 'External media snapshot path was accepted.');
} finally {
    programMediaResetSession();
    programMediaDirectory($temporaryRoot);
    if ($admin instanceof PDO) {
        $admin->exec('DROP DATABASE IF EXISTS ' . ADMIN_PROGRAM_MEDIA_TEST_DATABASE);
    }
    putenv('DB_PASSWORD');
}

echo "Admin program media integration tests passed.\n";
