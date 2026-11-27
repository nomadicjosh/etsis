<?php

declare(strict_types=1);

// Actual providers over a fictional in-memory database; no .env or real mail is used.
require_once __DIR__ . '/SisFixture.php';
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\PruneAccountSetupCommand($app)
);
if ($early->execute(['--limit' => '0']) !== 2) {
    throw new RuntimeException('Invalid cleanup options reached unresolved storage.');
}
$sis = new SisFixture();
$person = $sis->registrationService()->staff(
    \Domain\Shared\ValueObject\EntityId::generate(),
    'Fictional',
    'Person',
    'cleanup@example.invalid'
);
$row = $sis->sql->one('sis_account_setup', ['person_id' => $person->id->value]);
$sis->clock->time = $sis->clock->time->modify('+73 hours');
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $sis->sql);
$app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $sis->transactions);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $sis->clock);
$app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn() => $sis->dropCipher);
$_ENV['SIS_MAIL_ENABLED'] = $_SERVER['SIS_MAIL_ENABLED'] = 'false';
$kernel->bootstrap();
$app->delegate(\Domain\Communications\Service\MailTransport::class, static function (): never {
    throw new RuntimeException('Cleanup must not resolve or send mail.');
});
$config = $app->configContainer->getConfigKey('sis', []);
$app->configContainer->setConfigKey('sis', array_replace($config, ['mail_enabled' => false]));
$command = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:accounts:prune-setup']);
$sis->connection->pdo->exec('PRAGMA query_only = ON');
if (
    $command->execute(['--json' => true]) !== 0 ||
    json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['eligible'] !== 1 ||
    $sis->sql->one('sis_account_setup', ['id' => $row['id']]) !== $row
) {
    throw new RuntimeException('Actual cleanup preview wrote data or lost its documented defaults.');
}
$sis->connection->pdo->exec('PRAGMA query_only = OFF');
foreach (
    [['--limit' => '0'], ['--limit' => '101'], ['--limit' => 'private invalid option'],
    ['--grace-hours' => '0'], ['--grace-hours' => '8761'], ['--after' => 'private invalid option']] as $options
) {
    if (
        $command->execute($options + ['--apply' => true]) !== 2 ||
        str_contains($command->getDisplay(), 'private invalid option') ||
        $sis->sql->one('sis_account_setup', ['id' => $row['id']]) !== $row
    ) {
        throw new RuntimeException('Cleanup options were not private or bounded before mutation.');
    }
}
if (
    $command->execute(['--apply' => true, '--limit' => '1', '--json' => true]) !== 0 ||
    json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['cleared'] !== 1
) {
    throw new RuntimeException('Registered cleanup did not clear the verified expired notice without SMTP.');
}
$privateValues = ['cleanup@example.invalid', $row['token_hash'], $row['credential_hash'], $row['encrypted_notice']];
foreach ($privateValues as $private) {
    if (str_contains($command->getDisplay(), $private)) {
        throw new RuntimeException('Cleanup output exposed private notification or credential data.');
    }
}
if ($command->execute([]) !== 0 || !str_contains($command->getDisplay(), 'Preview only')) {
    throw new RuntimeException('Repeated cleanup was not idempotent or preview by default.');
}
// Exercise schedule registration without executing any background process.
$schedule = $app->make(\Codefy\Framework\Scheduler\Schedule::class);
// ConsoleKernel prepares this shared schedule on construction; do not register it a second time.
$processors = $schedule->allProcessors();
$commands = array_map(static fn($processor): string => $processor->getCommand(), $processors);
if (
    count(array_filter($commands, static fn(string $name): bool =>
        str_contains($name, 'sis:accounts:prune-setup --apply --limit=50'))) !== 1 ||
    count(array_filter($commands, static fn(string $name): bool =>
        str_contains($name, 'sis:accounts:send-setup'))) !== 0
) {
    throw new RuntimeException('Cleanup incorrectly depends on mail delivery being enabled.');
}
foreach ($processors as $processor) {
    if (
        str_contains($processor->getCommand(), 'sis:accounts:prune-setup') &&
        (!$processor->canRunOnlyOneInstance() || $processor->getExpression() !== '0 * * * *')
    ) {
        throw new RuntimeException('Cleanup lost hourly single-worker scheduling.');
    }
}
$sis->sql->update('sis_account_setup', ['state' => 'queued', 'encrypted_notice' => 'fictional broken notice',
    'sealed_grant' => 'fictional broken binding'], ['id' => $row['id']]);
if (
    $command->execute(['--apply' => true, '--json' => true]) !== 3 ||
    json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR)['invalid'] !== 1 ||
    $sis->sql->one('sis_account_setup', ['id' => $row['id']])['encrypted_notice'] === null
) {
    throw new RuntimeException('Cleanup silently discarded an unverifiable record.');
}
$sis->connection->pdo->exec('DROP TABLE sis_account_setup');
if (
    $command->execute(['--apply' => true]) !== 1 ||
    str_contains($command->getDisplay(), 'sis_account_setup') ||
    !str_contains($command->getDisplay(), 'could not finish')
) {
    throw new RuntimeException('Storage failure lost its fixed private diagnostic.');
}
