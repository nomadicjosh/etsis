<?php

declare(strict_types=1);

use Application\Console\Commands\RecoverAccountProjectionsCommand;
use Domain\Finance\Repository\AccountProjectionStageStorage;
use Domain\Shared\ValueObject\EntityId;
use Symfony\Component\Console\Tester\CommandTester;

// Invalid input must fail before providers, storage, maintenance or any database connection is resolved.
$early = new CommandTester(new RecoverAccountProjectionsCommand($app));
foreach (
    [[], ['--action' => 'stage'], ['--action' => 'stage', '--account' => 'PRIVATE invalid'],
    ['--action' => 'stage', '--account' => '00000000000000000000000000', '--stage' => 'PRIVATE invalid'],
    ['--action' => 'apply', '--stage' => '00000000000000000000000000', '--events' => '2001'],
    ['--action' => 'check', '--stage' => '00000000000000000000000000', '--events' => '0'],
    ['--action' => 'discard', '--stage' => '00000000000000000000000000', '--events' => '500'],
    ] as $options
) {
    if ($early->execute(['--json' => true] + $options) !== 2 || str_contains($early->getDisplay(), 'PRIVATE')) {
        throw new RuntimeException('Account recovery invalid input resolved dependencies or leaked input.');
    }
}
require_once __DIR__ . '/AccountRecoveryFixture.php';
$f = new \Tests\Support\AccountRecoveryFixture();
try {
    $s = $f->sis;
    $kernel->bootstrap();
    $app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn() => $s->dropCipher);
    $app->delegate(\Qubus\Expressive\Database::class, static function (): never {
        throw new RuntimeException('Fictional unavailable database; discard must not resolve it.');
    });
    $storage = $app->make(AccountProjectionStageStorage::class);
    $disposable = $f->stage();
    $storage->write($f->storage->read($disposable));
    $withoutDatabase = new CommandTester($kernel->all()['sis:projections:account']);
    if ($withoutDatabase->execute(['--action' => 'discard', '--stage' => $disposable->value]) !== 0) {
        throw new RuntimeException('Private artifact discard unnecessarily resolved a database.');
    }
    $app->delegate(\Qubus\Expressive\Database::class, static fn() => $s->connection->queryBuilder());
    $app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $s->transactions);
    $app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $s->clock);
    // Exercise the real provider's shared maintenance instance only inside this isolated temporary application.
    $mode = $app->make(\Domain\Shared\Operations\MaintenanceMode::class);
    $mode->down();
    $app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn() => $s->dropCipher);
    $app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $s->sql);
    $app->delegate(
        \Infrastructure\EventSourcing\ProtectedEventCodec::class,
        static fn() => new \Infrastructure\EventSourcing\ProtectedEventCodec(
            \EventStoreFixture::config($s->connection->queryBuilder())
        )
    );
    $storage = $app->make(AccountProjectionStageStorage::class);
    if (!$storage instanceof \Infrastructure\Operations\LocalAccountProjectionStageStorage) {
        throw new RuntimeException('Recovery storage provider is not configured.');
    }
    $command = new CommandTester($kernel->all()['sis:projections:account']);
    $f->damage();
    $facts = $f->facts();
    $rows = $f->projections();
    $s->connection->pdo->exec('PRAGMA query_only = ON');
    if (
        $command->execute(
            ['--action' => 'stage', '--account' => (string) $f->account->aggregateId(), '--json' => true]
        )
    ) {
        throw new RuntimeException('Actual recovery stage provider failed.');
    }
    $result = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    $id = new EntityId($result['stage_reference']);
    foreach (['check', 'apply'] as $action) {
        if ($action === 'apply') {
            $s->connection->pdo->exec('PRAGMA query_only = OFF');
        }
        if ($command->execute(['--action' => $action, '--stage' => $id->value, '--json' => true])) {
            throw new RuntimeException('Actual recovery check/apply provider failed.');
        }
        $result = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        if ($result['status'] !== ($action === 'check' ? 'ready' : 'applied')) {
            throw new RuntimeException('Recovery returned an unexpected outcome.');
        }
        foreach (['Fictional', '12000', 'student_id', 'amount_minor', 'sealed_'] as $private) {
            if (str_contains($command->getDisplay(), $private)) {
                throw new RuntimeException('Recovery output exposed private source details.');
            }
        }
    }
    if ($facts !== $f->facts() || $rows === $f->projections()) {
        throw new RuntimeException('Recovery changed source facts or failed to promote.');
    }
    if (
        $command->execute(['--action' => 'discard', '--stage' => $id->value]) !== 0
        || !str_contains($command->getDisplay(), 'Private stage discarded.')
    ) {
        throw new RuntimeException('Recovery discard failed.');
    }
    $mode->up();
    if (
        $command->execute(['--action' => 'apply', '--stage' => $id->value, '--json' => true]) !== 0
        || json_decode($command->getDisplay(), true)['status'] !== 'already_applied'
    ) {
        throw new RuntimeException('Completed promotion was not safely retryable.');
    }
    if (
        $command->execute(['--action' => 'stage', '--account' => (string) $f->account->aggregateId(),
        '--events' => '1', '--json' => true]) !== 3
        || json_decode($command->getDisplay(), true)['status'] !== 'limited'
    ) {
        throw new RuntimeException('Recovery command failed to distinguish a replay limit.');
    }
    $id = EntityId::generate();
    if (
        $command->execute(['--action' => 'check', '--stage' => $id->value, '--json' => true]) !== 1
        || $command->getDisplay() !== "{\"status\":\"not_verified\"}\n"
    ) {
        throw new RuntimeException('Recovery failed to minimize an unavailable stage error.');
    }
} finally {
    $f->close();
}
