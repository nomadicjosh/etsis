<?php
declare(strict_types=1);
// Isolated fresh alpha install. No institutional .env, database, credentials, network or mail.
require dirname(__DIR__, 2) . "/vendor/autoload.php";
use Symfony\Component\Process\Process;
$root = dirname(__DIR__, 2);
$fixture = sys_get_temp_dir() . "/etsis-alpha-install-" . bin2hex(random_bytes(8));
mkdir($fixture);
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
    "DB_DSN" => getenv("ETSIS_TEST_INSTALL_DSN") ?: "sqlite:" . $fixture . "/database/alpha.sqlite",
    "DB_USER" => getenv("ETSIS_TEST_INSTALL_USER") ?: "",
    "DB_PASSWORD" => getenv("ETSIS_TEST_INSTALL_PASSWORD") ?: "",
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
if (getenv("ETSIS_TEST_INSTALL_DSN")) {
    $env["DB_CONNECTION"] = "mysql";
}
$run = static function (array $args, string $stage) use ($env, $fixture): void {
    $process = new Process([PHP_BINARY, "-d", "memory_limit=128M", ...$args], $fixture, $env);
    $process->setTimeout(($env["DB_CONNECTION"] ?? "") === "mysql" ? 180 : 25);
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
try {
    if ($env['DB_CONNECTION'] === 'mysql') {
        if (!str_starts_with($env['DB_DSN'], 'mysql:') || preg_match('/(?:mysql:|;)dbname=etsis_alpha_install_[a-f0-9]{12}(?:;|$)/D', $env['DB_DSN']) !== 1) {
            throw new RuntimeException('External installation checks require an empty temporary database.');
        }
        try {
            $probe = new PDO($env['DB_DSN'], $env['DB_USER'], $env['DB_PASSWORD'], [PDO::ATTR_TIMEOUT => 5]);
            if ($probe->query('SHOW TABLES')->fetchColumn() !== false) {
                throw new RuntimeException();
            }
            unset($probe);
        } catch (Throwable) {
            throw new RuntimeException('External installation checks require an empty temporary database.');
        }
    }
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
}
