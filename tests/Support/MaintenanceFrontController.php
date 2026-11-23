<?php

declare(strict_types=1);

// Isolated entry-point probe. Never reads the installation's .env, keys or application database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$root = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . '/etsis-maintenance-front-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
mkdir($fixture . '/config', 0700);
ini_set('session.save_path', $fixture);
foreach (glob($root . '/config/*.php') as $source) {
    copy($source, $fixture . '/config/' . basename($source));
}
foreach (['bootstrap', 'public', 'resources'] as $directory) {
    symlink($root . '/' . $directory, $fixture . '/' . $directory);
}
foreach (
    ['APP_BASE_PATH' => $fixture, 'APP_ENV' => 'testing', 'APP_DEBUG' => 'true',
    'APP_BASE_URL' => 'https://campus.example.invalid', 'AUTH_LOGIN_ROUTE' => 'staff-access',
    'DB_CONNECTION' => 'sqlite', 'DB_DSN' => 'sqlite:/fixture-database-must-not-open/database.sqlite',
    ] as $name => $value
) {
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$mode = new \Infrastructure\Operations\FileMaintenanceMode(
    $fixture . '/storage/framework/maintenance',
    new \Infrastructure\SystemClock(),
);
$mode->down(120);
$case = $argv[1] ?? 'html';
$_SERVER['DOCUMENT_ROOT'] = $fixture . '/public';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = $case === 'head' ? 'HEAD' : ($case === 'webhook' ? 'POST' : 'GET');
$_SERVER['REQUEST_URI'] = match ($case) {
    'json' => '/sis/students/fictional/',
    'webhook' => '/portal/payments/webhook/',
    'cookie' => '/admin/',
    'super', 'regular', 'expired' => '/admin/',
    default => '/portal/',
};
$_SERVER['HTTP_ACCEPT'] = $case === 'json' ? 'application/json' : 'text/html';
$_SERVER['HTTP_HOST'] = 'campus.example.invalid';
$_SERVER['HTTPS'] = 'on';
if (in_array($case, ['cookie', 'webhook'], true)) {
    $_COOKIE['USERSESSID'] = 'fictional-untrusted-cookie';
}
if (in_array($case, ['super', 'regular', 'expired'], true)) {
    chdir($root);
    $app = \Codefy\Framework\Helpers\get_fresh_bootstrap();
    \Application\Provider\MaintenanceServiceProvider::localization($app);
    require_once __DIR__ . '/SisFixture.php';
    $sis = new SisFixture();
    $sis->connection->pdo->exec('ALTER TABLE users ADD COLUMN token VARCHAR(191)');
    $sis->sql->update('users', [
        'token' => 'fictional-maintenance-token', 'email' => 'maintenance@example.invalid',
        'first_name' => 'Maintenance', 'last_name' => 'Operator', 'role' => 'super',
    ], ['user_id' => $sis->access->actor->value]);
    if ($case !== 'regular') {
        $role = $sis->sql->one('sis_access_roles', ['name' => 'super', 'is_system' => 1]);
        $sis->sql->insert('sis_access_user_roles', [
            'id' => \Domain\Shared\ValueObject\EntityId::generate()->value,
            'user_id' => $sis->access->actor->value, 'role_id' => $role['id'],
        ]);
    }
    $key = \Defuse\Crypto\Key::createNewRandomKey();
    $app->configContainer->setConfigKey('app', ['crypto_key' => $key->saveToAsciiSafeString()]);
    $app->delegate(\Qubus\Expressive\Connection::class, static fn() => $sis->connection);
    $app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $sis->sql);
    $app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $sis->transactions);
    $app->booting(static function () use ($app): void {
        $app->configContainer->getConfigKey('vihzhuo');
        $app->configContainer->setConfigKey('vihzhuo', ['router' => ['use_router' => false]]);
    });
    $_COOKIE['USERSESSID'] = \Defuse\Crypto\Crypto::encrypt(json_encode([
        'version' => 1, 'token' => 'fictional-maintenance-token',
        'expires' => time() + ($case === 'expired' ? -60 : 600),
    ], JSON_THROW_ON_ERROR), $key);
}
register_shutdown_function(static function () use ($fixture): void {
    $application = \Codefy\Framework\Application::getInstance();
    $response = $GLOBALS['maintenance'] ?? null;
    $headers = [];
    if ($response instanceof \Psr\Http\Message\ResponseInterface) {
        foreach (['Cache-Control', 'Retry-After', 'Content-Security-Policy', 'Content-Type'] as $header) {
            $headers[$header] = $response->getHeaderLine($header);
        }
    }
    fwrite(STDERR, json_encode([
        'status' => http_response_code(), 'booted' => $application->hasBeenBootstrapped(),
        'headers' => $headers,
    ], JSON_THROW_ON_ERROR));
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($fixture);
});
chdir($root);
require $root . '/public/index.php';
