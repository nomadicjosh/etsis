<?php

declare(strict_types=1);

// Isolated fictional-data worker. Only bounded protocol replies leave stdout; no payloads or keys are logged.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit();
}
require_once dirname(__DIR__, 2) . "/vendor/autoload.php";
require_once __DIR__ . "/SisFixture.php";
stream_set_timeout(STDIN, 8);
try {
    $input = json_decode((string) fgets(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
    $db = new \Qubus\Expressive\Connection\Pdo\Sqlite([
        "driver" => "pdo_sqlite",
        "dsn" => "sqlite:" . $input["path"],
    ]);
    $db->pdo->exec("PRAGMA busy_timeout = 2000");
    $config = \Qubus\Config\Collection::factory([]);
    $config->setConfigKey("event_history", $input["history"]);
    EventStoreFixture::shareConfig($db->queryBuilder(), $config);
    $worker = new SisFixture(
        $db,
        initialize: false,
        actor: new \Domain\Shared\ValueObject\EntityId($input["actor"]),
    );
    fwrite(STDOUT, "ready\n");
    if (trim((string) fgets(STDIN)) !== "go") {
        exit(2);
    }
    try {
        $enrollment = $worker->service->register(
            new \Domain\Shared\ValueObject\EntityId($input["student"]),
            new \Domain\Shared\ValueObject\EntityId($input["section"]),
            true,
        );
        $result = ["state" => "accepted", "id" => $enrollment->id->value];
    } catch (DomainException) {
        $result = ["state" => "rejected"];
    }
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable) {
    fwrite(STDOUT, "{\"state\":\"unexpected_failure\"}\n");
    exit(2);
}
