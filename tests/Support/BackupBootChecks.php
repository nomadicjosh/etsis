<?php

declare(strict_types=1);

require_once __DIR__ . "/BackupFixture.php";
$binary = getenv("ETSIS_TEST_RESTIC");
if (!is_string($binary) || !is_executable($binary)) {
    throw new RuntimeException(
        "The bounded backup command fixture requires an explicit Restic test executable.",
    );
}
$f = new \Tests\Support\BackupFixture($binary);
try {
    $app->delegate(\Qubus\Expressive\Connection::class, static function () {
        throw new RuntimeException(
            "Operator recovery must not connect to the active application database.",
        );
    });
    $kernel->bootstrap();
    $command = $kernel->all()["sis:backup"];
    if (!($command instanceof \Codefy\Framework\Console\ConsoleCommand)) {
        throw new RuntimeException(
            "Backup operators must use the framework ConsoleCommand.",
        );
    }
    $test = new \Symfony\Component\Console\Tester\CommandTester($command);
    $invalid = [
        [],
        ["--action" => "capture"],
        [
            "--action" => "restore",
            "--snapshot" => "latest",
            "--destination" => "/private/new",
        ],
        ["--action" => "list", "--quiesced" => true],
        ["--action" => "init", "--snapshot" => str_repeat("a", 64)],
    ];
    foreach ($invalid as $options) {
        if (
            $test->execute(["--json" => true] + $options) !== 2 ||
            json_decode($test->getDisplay(), true)["status"] !== "invalid_input"
        ) {
            throw new RuntimeException(
                "Invalid backup options reached operational storage.",
            );
        }
    }
    $options = ["--config-file" => $f->configurationFile, "--json" => true];
    if ($test->execute(["--action" => "init"] + $options) !== 0) {
        throw new RuntimeException(
            "The real backup command failed private repository initialization.",
        );
    }
    $result = $f->service->capture(
        $f->config->plan,
        $f->config->budget(),
        true,
    );
    if (
        $test->execute($options) !== 0 ||
        count(json_decode($test->getDisplay(), true)["items"]) !== 1
    ) {
        throw new RuntimeException(
            "The real backup command did not list the complete bundle.",
        );
    }
    $f->settings["tools"] = ["pgsql" => "/lost/native/tool"];
    $f->settings["includes"] = ["/lost/original/installation"];
    $f->saveSettings();
    if (
        $test->execute(
            [
                "--action" => "restore",
                "--snapshot" => $result["snapshot"],
                "--destination" => $f->root . "/cold-restore",
            ] + $options,
        ) !== 0
    ) {
        throw new RuntimeException(
            "Cold recovery tried to resolve the live database or original source files.",
        );
    }
    $restored = json_decode($test->getDisplay(), true);
    if (
        $restored["status"] !== "restored" ||
        $restored["activated"] ||
        $restored["database_changed"] ||
        str_contains($test->getDisplay(), "fictional-never-log-this") ||
        str_contains($test->getDisplay(), $f->root)
    ) {
        throw new RuntimeException(
            "The operator command lost its private isolated-output boundary.",
        );
    }
} finally {
    $f->dispose();
}
