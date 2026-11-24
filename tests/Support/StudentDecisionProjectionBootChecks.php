<?php

declare(strict_types=1);

// Actual lazy console/provider wiring; all records and keys are isolated fictional fixtures.
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app),
);
foreach (['standing', 'load', 'advisors', 'requirements'] as $kind) {
    foreach (
        [[$kind => 'PRIVATE invalid input'],
        [$kind => '00000000000000000000000000', 'streams' => '10'],
        [$kind => '00000000000000000000000000', 'cursor' => 'PRIVATE cursor'],
        [$kind => '00000000000000000000000000', 'person' => '00000000000000000000000000'],
        [$kind => '00000000000000000000000000', 'events' => '2001'],
        ['standing' => '00000000000000000000000000', 'load' => '00000000000000000000000000'],
        ] as $options
    ) {
        $options = array_combine(
            array_map(static fn (string $key): string => '--' . $key, array_keys($options)),
            array_values($options)
        );
        if ($early->execute(['--json' => true] + $options) !== 2 || str_contains($early->getDisplay(), 'PRIVATE')) {
            throw new RuntimeException('Invalid student-decision options resolved storage or exposed input.');
        }
    }
}
require_once __DIR__ . '/StudentDecisionFixture.php';
$f = new \Tests\Support\StudentDecisionFixture();
$s = $f->sis;
$app->delegate(\Qubus\Expressive\Connection::class, static fn () => $s->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn () => $s->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn () => $s->sql);
$app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn () => $s->access);
$app->delegate(\Infrastructure\EventSourcing\ProtectedEventCodec::class, static fn () =>
    new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())));
$kernel->bootstrap();
$test = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$before = $f->facts();
$s->connection->pdo->exec('PRAGMA query_only = ON');
foreach (['standing' => $f->pursuit, 'load' => $f->determination] as $kind => $id) {
    $options = ['--' . $kind => $id->value, '--json' => true, '--events' => '1'];
    if ($test->execute($options) !== 0) {
        throw new RuntimeException('Actual student-decision provider did not verify fictional retained evidence.');
    }
    $result = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $result['status'] !== 'verified' || $result['records_checked'] !== 1 || $result['events_checked'] !== 1
        || $result['records_changed'] || str_contains($test->getDisplay(), 'Private')
        || str_contains($test->getDisplay(), 'Fictional') || str_contains($test->getDisplay(), 'credits_milli')
    ) {
        throw new RuntimeException('Student-decision command returned incorrect or private facts.');
    }
    if (
        $test->execute(['--' . $kind => $id->value]) !== 0
        || !str_contains($test->getDisplay(), 'No records were changed.')
    ) {
        throw new RuntimeException('Student-decision text output lost its operator boundary.');
    }
    if ($test->execute(['--' . $kind => '00000000000000000000000000', '--json' => true]) !== 1) {
        throw new RuntimeException('Missing student-decision identity was accepted.');
    }
}
foreach (['advisors', 'requirements'] as $kind) {
    if (
        $test->execute(['--' . $kind => $f->pursuit->value, '--json' => true]) !== 3 ||
        json_decode($test->getDisplay(), true)['status'] !== 'observed_empty'
    ) {
        throw new RuntimeException('An empty curriculum observation was treated as verified prior history.');
    }
    if ($test->execute(['--' . $kind => '00000000000000000000000000', '--json' => true]) !== 1) {
        throw new RuntimeException('A missing curriculum pursuit was accepted.');
    }
}
if ($before !== $f->facts()) {
    throw new RuntimeException('Read-only student-decision verification changed recorded facts.');
}
