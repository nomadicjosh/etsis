<?php

declare(strict_types=1);

// Isolated fresh alpha install. Native settings arrive by private stdin for a marked disposable database only.
require dirname(__DIR__, 2) . "/vendor/autoload.php";
use Symfony\Component\Process\Process;
use Tests\Support\NativeQualificationDatabase;
$root = dirname(__DIR__, 2);
$mask = umask(0077);
$fixture = sys_get_temp_dir() . "/etsis-alpha-install-" . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
foreach (["config", "bootstrap", "database", "storage"] as $dir) {
    mkdir($fixture . "/" . $dir);
}
foreach (["config", "bootstrap"] as $dir) {
    foreach (glob($root . "/" . $dir . "/*.php") as $file) {
        copy($file, $fixture . "/" . $dir . "/" . basename($file));
    }
}
foreach (["routes", "resources", "public", "vendor"] as $dir) {
    symlink($root . "/" . $dir, $fixture . "/" . $dir);
}
symlink($root . "/database/migrations", $fixture . "/database/migrations");
copy($root . "/.env.example", $fixture . "/.env.example");
$env = [
    "APP_BASE_PATH" => $fixture,
    "APP_BASE_URL" => "https://campus.example.invalid",
    "APP_ENV" => "testing",
    "APP_TIMEZONE" => "UTC",
    "DB_CONNECTION" => "sqlite",
    "DB_DSN" => "sqlite:" . $fixture . "/database/alpha.sqlite",
    "DB_USER" => "",
    "DB_PASSWORD" => "",
    "SIS_MOODLE_ENABLED" => "false",
    "SIS_MOODLE_LAUNCH_ENABLED" => "false",
    "SIS_MOODLE_PROVISIONING_ENABLED" => "false",
    "SIS_MOODLE_ACCOUNT_REQUESTS_ENABLED" => "false",
    "SIS_AI_ENABLED" => "false",
    "PORTAL_PAYMENTS_ENABLED" => "false",
    "EVENT_HISTORY_KEYS_FILE" => "",
    "EVENT_HISTORY_ACTIVE_KEY_ID" => "app-v1",
    "MAILER_DSN" => "null://null",
    "XDEBUG_MODE" => "off",
];
try {
    if (($argv[1] ?? '') === 'native') {
        try {
            stream_set_timeout(STDIN, 3);
            $settings = json_decode((string) fgets(STDIN, 16384), true, 8, JSON_THROW_ON_ERROR);
            require_once __DIR__ . '/NativeQualificationDatabase.php';
            $probe = NativeQualificationDatabase::workerConnection($settings);
            if ($probe->listTables() !== ['etsis_qualification_owner']) {
                throw new RuntimeException();
            }
            $env['DB_CONNECTION'] = (string) $probe->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $env['DB_DSN'] = $settings['dsn'];
            $env['DB_USER'] = $settings['username'];
            $env['DB_PASSWORD'] = $settings['password'];
            unset($probe, $settings);
        } catch (Throwable) {
            // No connection exception, supplied credential or endpoint may reach test output.
            throw new RuntimeException('External installation checks require an owned empty temporary database.');
        }
    }
    $run = static function (array $args, string $stage) use ($env, $fixture): void {
        $process = new Process([
            PHP_BINARY, "-d", "memory_limit=128M", "-d", "zend.exception_ignore_args=1", ...$args,
        ], $fixture, $env);
        $process->setTimeout($debug ? 70 : 30);
        $process->run();
        if (!$process->isSuccessful()) {
            $last = "none";
            if (preg_match_all("/== [0-9]+ ([A-Za-z][A-Za-z0-9]+) migrating/", $process->getOutput(), $matches)) {
                $last = end($matches[1]);
            }
            $state = preg_match("/SQLSTATE\[([A-Z0-9]{5})\]/", $process->getErrorOutput(), $match)
            ? $match[1]
            : "none";
            throw new RuntimeException(
                "Fresh alpha setup failed at " .
                $stage .
                ". Last migration: " .
                $last .
                "; SQLSTATE: " .
                $state .
                ".",
            );
        }
    };
    $run([$fixture . "/bootstrap/setup-environment.php"], "environment setup");
    if (!str_contains(file_get_contents($fixture . "/.env"), 'APP_BASE_PATH="' . $fixture . '"')) {
        throw new RuntimeException("Fresh alpha base path was not filled in.");
    }
    $before = hash_file("sha256", $fixture . "/.env");
    $run([$fixture . "/bootstrap/setup-environment.php"], "repeat environment setup");
    if ($before !== hash_file("sha256", $fixture . "/.env")) {
        throw new RuntimeException("Environment setup overwrote an existing configured path.");
    }
    $run([$root . "/codex", "generate:key:file", "--no-interaction"], "private key generation");
    $key = file_get_contents($fixture . "/.enc.key");
    \Defuse\Crypto\Key::loadFromAsciiSafeString($key);
    $run([$root . "/codex", "migrate", "--no-interaction"], "fresh database migration");
    $run([$root . "/codex", "migrate", "--no-interaction"], "repeat migration");
    $run(
        [$root . "/tests/Support/AlphaInstallWebChecks.php", $fixture],
        "registration and first administrator access",
    );
    if (!hash_equals($key, file_get_contents($fixture . '/.enc.key'))) {
        throw new RuntimeException('The installation workflow replaced its existing encryption key.');
    }
    echo "Fresh alpha installation, registration and initial administrator access passed.\n";
} finally {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        if ($entry->isLink() || $entry->isFile()) {
            unlink($entry->getPathname());
        } elseif ($entry->isDir()) {
            rmdir($entry->getPathname());
        }
    }
    rmdir($fixture);
    umask($mask);
}
