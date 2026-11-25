<?php

declare(strict_types=1);

// Runs only in the isolated bootstrap process, without institutional configuration or external services.
require_once __DIR__ . "/HistoryVerificationFixture.php";
$bindVerification = static function (HistoryVerificationFixture $fixture) use ($app): void {
    $app->delegate(\Qubus\Expressive\Connection::class, static fn() => $fixture->sis->connection);
    $app->delegate(\Qubus\Expressive\Database::class, static fn() => $fixture->sis->connection->queryBuilder());
    $app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $fixture->sis->sql);
    $app->delegate(\Infrastructure\EventSourcing\ProtectedEventCodec::class, static fn() => $fixture->codec);
    $app->delegate(\Infrastructure\EventSourcing\SqlHistoricalEventStore::class, static fn() => $fixture->store);
};
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app),
);
if ($early->execute(["--events" => "0"]) !== 2) {
    throw new RuntimeException("Invalid verification options resolved storage.");
}
if (($argv[1] ?? '') === 'check-academic-projections') {
    foreach (
        [
        ['--student' => 'fictional-private-input'],
        ['--student' => '00000000000000000000000000', '--streams' => '10'],
        ['--student' => '00000000000000000000000000', '--cursor' => 'fictional-private-input'],
        ['--student' => '00000000000000000000000000', '--account' => '00000000000000000000000000'],
        ] as $options
    ) {
        if (
            $early->execute(['--json' => true] + $options) !== 2 ||
            str_contains($early->getDisplay(), 'fictional-private-input')
        ) {
            throw new RuntimeException('Invalid academic verification resolved storage or leaked input.');
        }
    }
    foreach (
        [['--credit' => 'fictional-private-input'],
        ['--credit' => '00000000000000000000000000', '--streams' => '1'],
        ['--credit' => '00000000000000000000000000', '--cursor' => 'fictional-private-input'],
        ['--credit' => '00000000000000000000000000', '--student' => '00000000000000000000000000'],
        ['--credit' => '00000000000000000000000000', '--account' => '00000000000000000000000000'],
        ] as $options
    ) {
        if (
            $early->execute(['--json' => true] + $options) !== 2
            || str_contains($early->getDisplay(), 'fictional-private-input')
        ) {
            throw new RuntimeException('Invalid selected academic check resolved storage or exposed input.');
        }
    }
    $f = new HistoryVerificationFixture();
    $s = $f->sis;
    $student = $s->student()->id;
    $enrollment = $s->service->register($student, $s->section()->id);
    $credit = $s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id'];
    $bindVerification($f);
    $kernel->bootstrap();
    $test = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
    $before = $f->digest();
    $s->connection->pdo->exec('PRAGMA query_only = ON');
    $selectedOptions = ['--credit' => $credit, '--json' => true];
    if ($test->execute($selectedOptions) !== 0) {
        throw new RuntimeException('The actual selected academic provider failed a read-only check.');
    }
    $selected = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $selected['scope'] !== 'academic_credit' || $selected['records_checked'] !== 1
        || $selected['current_records'] !== 1 || $selected['records_changed'] !== false
        || str_contains($test->getDisplay(), $enrollment->id->value)
    ) {
        throw new RuntimeException('The selected credit check exposed sources or lost its scope.');
    }
    if (
        $test->execute(['--events' => '1'] + $selectedOptions) !== 3
        || json_decode($test->getDisplay(), true)['records_checked'] !== null
        || $test->execute(['--credit' => '00000000000000000000000000', '--json' => true]) !== 1
        || $test->execute(['--credit' => $credit]) !== 0
        || !str_contains($test->getDisplay(), 'No records were changed') || $before !== $f->digest()
    ) {
        throw new RuntimeException('The selected check lost its limits, text output or read-only guarantee.');
    }
    $options = ['--student' => $student->value, '--json' => true];
    if ($test->execute($options) !== 0) {
        throw new RuntimeException('Actual academic verification provider failed its read-only check.');
    }
    $result = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $result['status'] !== 'verified' || $result['current_records'] !== 1 ||
        $result['records_changed'] !== false || str_contains($test->getDisplay(), $enrollment->id->value)
    ) {
        throw new RuntimeException('Academic verification returned incorrect or private source details.');
    }
    if (
        $test->execute(['--events' => '1'] + $options) !== 3 ||
        json_decode($test->getDisplay(), true)['status'] !== 'limited'
    ) {
        throw new RuntimeException('Academic verification ignored its replay bound.');
    }
    if (
        $test->execute(['--student' => '00000000000000000000000000', '--json' => true]) !== 1 ||
        json_decode($test->getDisplay(), true)['issues'] !== ['student_identity']
    ) {
        throw new RuntimeException('Missing student was certified as an empty record.');
    }
    if (
        $test->execute(['--student' => $student->value]) !== 0 ||
        !str_contains($test->getDisplay(), 'No records were changed') || $before !== $f->digest()
    ) {
        throw new RuntimeException('Text academic verification failed or changed recorded history.');
    }
    return;
}
if (($argv[1] ?? '') === 'check-account-projections') {
    $invalidAccounts = [
        ['--account' => 'fictional-private-input'],
        ['--account' => '00000000000000000000000000', '--streams' => '10'],
        ['--account' => '00000000000000000000000000', '--cursor' => 'fictional-private-input'],
    ];
    foreach ($invalidAccounts as $options) {
        if (
            $early->execute(['--json' => true] + $options) !== 2 ||
            str_contains($early->getDisplay(), 'fictional-private-input')
        ) {
            throw new RuntimeException('Invalid account-verification input touched storage or leaked input.');
        }
    }
    $f = new HistoryVerificationFixture();
    $s = $f->sis;
    $root = \Domain\Finance\StudentAccount::open(
        new \Domain\Finance\ValueObject\AccountId(),
        $s->student()->id,
        $s->term,
        'USD'
    );
    $root->post(
        \Domain\Shared\ValueObject\EntityId::generate(),
        \Domain\Finance\Enum\EntryType::Payment,
        new \Domain\Finance\ValueObject\Money(12345, 'USD'),
        $s->access->actor
    );
    $s->saveAccount($root);
    $bindVerification($f);
    $kernel->bootstrap();
    $commands = $kernel->all();
    $test = new \Symfony\Component\Console\Tester\CommandTester($commands['sis:history:verify']);
    $before = $f->digest();
    $options = ['--account' => (string) $root->aggregateId(), '--json' => true];
    $s->connection->pdo->exec('PRAGMA query_only = ON');
    if (
        $test->execute(['--account' => '00000000000000000000000000', '--json' => true]) !== 1 ||
        json_decode($test->getDisplay(), true)['issues'] !== ['journal', 'account_identity'] ||
        $before !== $f->digest()
    ) {
        throw new RuntimeException('A missing canonical account was misclassified or changed records.');
    }
    if ($test->execute($options) !== 0) {
        throw new RuntimeException('The actual provider failed a healthy read-only financial check.');
    }
    $result = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $result['status'] !== 'verified' || $result['expected_postings'] !== 1 ||
        $result['events_checked'] !== 2 || $result['records_changed'] !== false ||
        str_contains($test->getDisplay(), 'USD') || str_contains($test->getDisplay(), 'Fictional attributed')
    ) {
        throw new RuntimeException('The account verification result was incorrect or exposed private facts.');
    }
    if (
        $test->execute(['--events' => '1'] + $options) !== 3 ||
        json_decode($test->getDisplay(), true)['status'] !== 'limited' || $before !== $f->digest()
    ) {
        throw new RuntimeException('A limited account check changed records or claimed success.');
    }
    $s->connection->pdo->exec('PRAGMA query_only = OFF');
    $s->sql->update('sis_account_balances', ['balance_minor' => 999], ['id' => (string) $root->aggregateId()]);
    $before = $f->digest();
    if (
        $test->execute($options) !== 1 ||
        json_decode($test->getDisplay(), true)['issues'] !== ['balance'] || $before !== $f->digest()
    ) {
        throw new RuntimeException('The account check did not preserve and report projection disagreement.');
    }
    if (
        $test->execute(['--account' => (string) $root->aggregateId()]) !== 1 ||
        !str_contains($test->getDisplay(), 'Unverified components: balance') ||
        str_contains($test->getDisplay(), 'USD')
    ) {
        throw new RuntimeException('Text account output was missing or leaked financial facts.');
    }
    return;
}
$f = new HistoryVerificationFixture();
$f->stream(3);
$before = $f->digest();
$bindVerification($f);
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
    $next["next_cursor"] !== null ||
    $next["inventory_rows_checked"] !== 3 ||
    $next["receipts_checked"] !== 1 ||
    $next["receipt_events_checked"] !== 3
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
$invalidOptions = [
        ["--streams" => "51"],
        ["--events" => "2001"],
        ["--events" => "fictional-private-input"],
        ["--cursor" => "fictional-private-input"],
    ];
foreach ($invalidOptions as $options) {
    if (
        $test->execute(["--json" => true] + $options) !== 2 ||
        str_contains($test->getDisplay(), "fictional-private-input")
    ) {
        throw new RuntimeException("Invalid verification input was accepted or exposed.");
    }
}
$operation = $f->sis->sql->rows("sis_history_operations", [], 1)[0];
$f->sis->sql->update(
    "sis_history_operations",
    ["sealed_receipt" => "fictional-private-receipt"],
    ["id" => $operation["id"]],
);
$badReceipt = $f->digest();
if ($test->execute(["--json" => true]) !== 1) {
    throw new RuntimeException("An unverified receipt returned success.");
}
$failed = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $failed["error"] !== "stream_unverified" ||
    $failed["stream_reference"] !== null ||
    str_contains($test->getDisplay(), $operation["id"]) ||
    str_contains($test->getDisplay(), "fictional-private-receipt") ||
    $badReceipt !== $f->digest()
) {
    throw new RuntimeException("Receipt failure exposed contents or changed history.");
}
$f->sis->sql->update(
    "sis_history_operations",
    ["sealed_receipt" => $operation["sealed_receipt"]],
    ["id" => $operation["id"]],
);
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
$f->sis->sql->execute("PRAGMA foreign_keys = OFF");
$f->sis->sql->execute('DELETE FROM "sis_history_streams"');
$orphans = $f->digest();
if ($test->execute(["--json" => true]) !== 1) {
    throw new RuntimeException("Orphan events were accepted as an empty journal.");
}
$failed = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $failed["error"] !== "stream_unverified" ||
    $failed["stream_reference"] !== null ||
    str_contains($test->getDisplay(), $row["stream_id"]) ||
    str_contains($test->getDisplay(), "fictional-confidential-error") ||
    $orphans !== $f->digest()
) {
    throw new RuntimeException("Orphan failure exposed damaged references or changed history.");
}
