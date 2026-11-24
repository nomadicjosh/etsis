<?php

declare(strict_types=1);

// Fictional storage with actual lazy command/provider wiring. No .env or institutional queries.
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app),
);
foreach (["primary-position", "position", "time-entry"] as $kind) {
    foreach (
        [
            ["--" . $kind => "Private invalid input"],
            ["--" . $kind => "00000000000000000000000000", "--streams" => "10"],
            [
                "--" . $kind => "00000000000000000000000000",
                "--cursor" => "Private cursor",
            ],
            [
                "--" . $kind => "00000000000000000000000000",
                "--person" => "00000000000000000000000000",
            ],
            [
                "--" . $kind => "00000000000000000000000000",
                "--events" => "2001",
            ],
        ] as $options
    ) {
        if (
            $early->execute(["--json" => true] + $options) !== 2 ||
            str_contains($early->getDisplay(), "Private")
        ) {
            throw new RuntimeException(
                "Invalid employment options resolved storage or exposed input.",
            );
        }
    }
}
require_once __DIR__ . "/EmploymentHistoryFixture.php";
$f = new \Tests\Support\EmploymentHistoryFixture();
$s = $f->sis;
$app->delegate(
    \Qubus\Expressive\Connection::class,
    static fn() => $s->connection,
);
$app->delegate(
    \Qubus\Expressive\Database::class,
    static fn() => $s->connection->queryBuilder(),
);
$app->delegate(
    \Infrastructure\Persistence\SqlRecords::class,
    static fn() => $s->sql,
);
$app->delegate(
    \Domain\Shared\Persistence\UnitOfWork::class,
    static fn() => $s->transactions,
);
$app->delegate(
    \Domain\Shared\Security\StudentAccess::class,
    static fn() => $s->access,
);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $s->clock);
$app->delegate(
    \Domain\Communications\Service\MessageCipher::class,
    static fn() => $s->dropCipher,
);
$app->delegate(
    \Infrastructure\EventSourcing\ProtectedEventCodec::class,
    static fn() => new \Infrastructure\EventSourcing\ProtectedEventCodec(
        EventStoreFixture::config($s->connection->queryBuilder()),
    ),
);
$kernel->bootstrap();
$test = new \Symfony\Component\Console\Tester\CommandTester(
    $kernel->all()["sis:history:verify"],
);
$before = $f->facts();
$s->connection->pdo->exec("PRAGMA query_only = ON");
foreach (
    [
        "primary-position" => $f->employee,
        "position" => $f->position,
        "time-entry" => $f->entry,
    ] as $kind => $id
) {
    if ($test->execute(["--" . $kind => $id->value, "--json" => true]) !== 0) {
        throw new RuntimeException(
            "Actual employment provider did not verify the selected fixture.",
        );
    }
    $result = json_decode(
        $test->getDisplay(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (
        $result["status"] !== "verified" ||
        $result["records_checked"] !== 1 ||
        $result["records_changed"]
    ) {
        throw new RuntimeException(
            "Employment verification lost its bounded read-only result.",
        );
    }
    foreach (
        ["Private", "Fictional", "4321", "1234", "rules", "reason", "starts_at"] as $private
    ) {
        if (str_contains($test->getDisplay(), $private)) {
            throw new RuntimeException(
                "Employment command exposed private evidence.",
            );
        }
    }
    if (
        $test->execute(["--" . $kind => $id->value]) !== 0 ||
        !str_contains($test->getDisplay(), "No records were changed.")
    ) {
        throw new RuntimeException(
            "Employment text output lost its operator boundary.",
        );
    }
    if (
        $test->execute([
            "--" . $kind => "00000000000000000000000000",
            "--json" => true,
        ]) !== 1
    ) {
        throw new RuntimeException("Missing employment identity was accepted.");
    }
}
if (
    $test->execute([
        "--primary-position" => $f->employee->value,
        "--events" => "1",
        "--json" => true,
    ]) !== 3
) {
    throw new RuntimeException(
        "The selected primary check exceeded its shared event allowance.",
    );
}
if ($before !== $f->facts()) {
    throw new RuntimeException(
        "Employment verification changed source records.",
    );
}
