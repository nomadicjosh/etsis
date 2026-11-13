<?php

declare(strict_types=1);

// Runs only in the isolated bootstrap process, without institutional configuration or external services.
require_once __DIR__ . "/HistoryVerificationFixture.php";
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app),
);
if ($early->execute(["--events" => "0"]) !== 2) {
    throw new RuntimeException("Invalid verification options resolved storage.");
}
$f = new HistoryVerificationFixture();
$f->stream(3);
$before = $f->digest();
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $f->sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $f->sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $f->sis->sql);
$app->delegate(\Infrastructure\EventSourcing\ProtectedEventCodec::class, static fn() => $f->codec);
$app->delegate(\Infrastructure\EventSourcing\SqlHistoricalEventStore::class, static fn() => $f->store);
$kernel->bootstrap();
$commands = $kernel->all();
if (!isset($commands["sis:history:verify"])) {
    throw new RuntimeException("History verification was not registered.");
}
$test = new \Symfony\Component\Console\Tester\CommandTester($commands["sis:history:verify"]);
if ($test->execute(["--json" => true, "--events" => "1"]) !== 3) {
    throw new RuntimeException("A bounded history check claimed completion.");
}
$first = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if ($first["status"] !== "limited" || $first["events_checked"] !== 1 || !is_string($first["next_cursor"])) {
    throw new RuntimeException("Verification continuation was missing.");
}
if ($test->execute(["--json" => true, "--cursor" => $first["next_cursor"]]) !== 0) {
    throw new RuntimeException("History verification did not resume.");
}
$next = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $next["status"] !== "complete" ||
    $next["events_checked"] !== 2 ||
    $next["streams_completed"] !== 1 ||
    $next["next_cursor"] !== null
) {
    throw new RuntimeException("History verification returned incorrect bounded counts.");
}
if (
    $test->execute([]) !== 0 ||
    !str_contains($test->getDisplay(), "Read-only check") ||
    str_contains($test->getDisplay(), "Fictional confidential approval")
) {
    throw new RuntimeException("Text verification output leaked facts or failed its defaults.");
}
if ($before !== $f->digest()) {
    throw new RuntimeException("Read-only verification changed stored facts.");
}
foreach (
    [
        ["--streams" => "51"],
        ["--events" => "2001"],
        ["--events" => "fictional-private-input"],
        ["--cursor" => "fictional-private-input"],
    ]
    as $options
) {
    if (
        $test->execute(["--json" => true] + $options) !== 2 ||
        str_contains($test->getDisplay(), "fictional-private-input")
    ) {
        throw new RuntimeException("Invalid verification input was accepted or exposed.");
    }
}
$row = $f->sis->sql->rows("sis_history_events", ["position" => 2], 1)[0];
$f->sis->sql->update(
    "sis_history_events",
    ["sealed_event" => "fictional-confidential-error"],
    ["id" => $row["id"]],
);
$broken = $f->digest();
if ($test->execute(["--json" => true]) !== 1) {
    throw new RuntimeException("Unverified history returned a success code.");
}
$failed = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $failed["error"] !== "stream_unverified" ||
    $failed["stream_reference"] !== $row["stream_id"] ||
    str_contains($test->getDisplay(), "fictional-confidential-error") ||
    $broken !== $f->digest()
) {
    throw new RuntimeException("Failure output leaked data or changed history.");
}
