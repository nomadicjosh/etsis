<?php

declare(strict_types=1);

$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app),
);
foreach (
    [['--waitlist-entry' => 'PRIVATE invalid'],
    ['--waitlist-entry' => '00000000000000000000000000', '--streams' => '10'],
    ['--waitlist-entry' => '00000000000000000000000000', '--cursor' => 'PRIVATE cursor'],
    ['--waitlist-entry' => '00000000000000000000000000', '--events' => '2001'],
    ['--waitlist-entry' => '00000000000000000000000000', '--credit' => '00000000000000000000000000'],
    ['--waitlist-entry' => '00000000000000000000000000', '--person' => '00000000000000000000000000'],
    ['--waitlist-entry' => '00000000000000000000000000', '--position' => '00000000000000000000000000'],
    ['--waitlist-entry' => '00000000000000000000000000', '--progress-record' => '00000000000000000000000000'],
    ] as $options
) {
    if ($early->execute(['--json' => true] + $options) !== 2 || str_contains($early->getDisplay(), 'PRIVATE')) {
        throw new RuntimeException('Invalid waitlist options resolved storage or exposed private input.');
    }
}
require_once __DIR__ . '/WaitlistVerificationFixture.php';
$f = new \Tests\Support\WaitlistVerificationFixture();
$s = $f->sis;
$app->delegate(\Qubus\Expressive\Connection::class, static fn () => $s->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn () => $s->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn () => $s->sql);
$app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn () => $s->access);
$app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn () => $s->dropCipher);
$app->delegate(\Infrastructure\EventSourcing\ProtectedEventCodec::class, static fn () =>
    new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())));
$kernel->bootstrap();
$test = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$before = $f->facts();
$s->connection->pdo->exec('PRAGMA query_only = ON');
$options = ['--waitlist-entry' => $f->entry->value, '--json' => true];
if ($test->execute($options + ['--events' => '2']) !== 0) {
    throw new RuntimeException('The actual waitlist verifier provider did not verify the selected request.');
}
$result = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $result['status'] !== 'verified' || $result['records_checked'] !== 1 || $result['events_checked'] !== 2
    || $result['records_changed'] || str_contains($test->getDisplay(), 'Fictional')
    || str_contains($test->getDisplay(), 'student_id') || str_contains($test->getDisplay(), 'reason')
) {
    throw new RuntimeException('Waitlist console results lost their scope or privacy boundary.');
}
if (
    $test->execute($options + ['--events' => '1']) !== 3
    || json_decode($test->getDisplay(), true)['status'] !== 'limited'
    || $test->execute(['--waitlist-entry' => $f->entry->value]) !== 0
    || !str_contains($test->getDisplay(), 'No records were changed.')
    || $test->execute(['--waitlist-entry' => '00000000000000000000000000', '--json' => true]) !== 1
    || $before !== $f->facts()
) {
    throw new RuntimeException('Read-only waitlist console outcomes or source facts changed.');
}
