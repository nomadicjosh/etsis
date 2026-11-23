<?php

declare(strict_types=1);

// Isolated console providers and fictional records only; no institutional .env or database.
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app),
);
foreach (
    [
    ['--application' => 'Fictional private invalid identifier'],
    ['--application' => '00000000000000000000000000', '--person' => '00000000000000000000000000'],
    ['--application' => '00000000000000000000000000', '--streams' => '10'],
    ['--application' => '00000000000000000000000000', '--cursor' => 'Fictional private cursor'],
    ['--application' => '00000000000000000000000000', '--events' => '2001'],
    ] as $options
) {
    if ($early->execute(['--json' => true] + $options) !== 2 || str_contains($early->getDisplay(), 'Fictional')) {
        throw new RuntimeException('Invalid application verification resolved storage or exposed private input.');
    }
}
require_once __DIR__ . '/SisFixture.php';
$s = new SisFixture();
$person = $s->legacyPerson($s->access->actor, 'Fictional', 'Applicant');
$application = $s->submissionService()->apply(
    \Domain\Shared\ValueObject\EntityId::generate(),
    $person->id,
    $s->program,
    $s->term,
);
$untracked = $s->legacyApplication($person->id, $s->program, $s->term);
$app->delegate(\Qubus\Expressive\Connection::class, static fn () => $s->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn () => $s->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn () => $s->sql);
$app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn () => $s->access);
$app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn () => $s->dropCipher);
$app->delegate(\Infrastructure\EventSourcing\ProtectedEventCodec::class, static fn () =>
    new \Infrastructure\EventSourcing\ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())));
$kernel->bootstrap();
$test = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$digest = static fn (): array => array_map($s->sql->rows(...), [
    'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_applications',
    'sis_application_intake', 'sis_form_submissions', 'sis_application_revisions', 'sis_students', 'sis_audit',
]);
$before = $digest();
$s->connection->pdo->exec('PRAGMA query_only = ON');
foreach ([[$application->id, 0, 'verified'], [$untracked->id, 3, 'history_gaps']] as [$id, $exit, $status]) {
    if ($test->execute(['--application' => $id->value, '--json' => true]) !== $exit) {
        throw new RuntimeException('Application verification returned the wrong command status.');
    }
    $data = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    if ($data['status'] !== $status || $data['scope'] !== 'admissions_application' || $data['records_changed']) {
        throw new RuntimeException('Application verification returned the wrong minimized evidence.');
    }
    if (str_contains($test->getDisplay(), 'Fictional') || str_contains($test->getDisplay(), 'encrypted')) {
        throw new RuntimeException('Application verification exposed private source data.');
    }
}
if (
    $test->execute(['--application' => $application->id->value]) !== 0
    || !str_contains($test->getDisplay(), 'No records were changed.')
) {
    throw new RuntimeException('Text application verification lost its read-only explanation.');
}
if ($digest() !== $before) {
    throw new RuntimeException('Application verification changed source records.');
}
$s->connection->pdo->exec('PRAGMA query_only = OFF');
$s->sql->update('sis_application_intake', ['channel' => 'Fictional changed source'], ['id' => $application->id->value]);
if ($test->execute(['--application' => $application->id->value, '--json' => true]) !== 1) {
    throw new RuntimeException('Application verification accepted an altered intake projection.');
}
