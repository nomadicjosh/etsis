<?php

declare(strict_types=1);

// Isolated fictional database; no institutional configuration or network.
require_once __DIR__ . '/SisFixture.php';
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\PruneReportsCommand($app),
);
if ($early->execute(['--limit' => '0']) !== 2) {
    throw new RuntimeException('Report cleanup did not validate options before resolving storage.');
}
$sis = new SisFixture();
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $sis->sql);
$app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $sis->transactions);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $sis->clock);
$kernel->bootstrap();
$id = \Domain\Shared\ValueObject\EntityId::generate();
$reports = new \Infrastructure\Persistence\Repository\SqlReportRepository($sis->sql, $sis->clock);
$reports->add(new \Domain\Reporting\StudentReport($id, $sis->access->actor, 'Fictional private name'));
$sis->reportLifecycles()->configure($id, \Domain\Shared\ValueObject\EntityId::generate(), -1, 'dynamic', 1);
$sis->clock->time = $sis->clock->time->modify('+1 day');
if ($kernel->call('sis:reports:prune') !== 0 || $sis->sql->rows('sis_report_queries') === []) {
    throw new RuntimeException('Registered report cleanup must default to a read-only preview.');
}
$tester = new \Symfony\Component\Console\Tester\CommandTester(
    $app->make(\Application\Console\Commands\PruneReportsCommand::class),
);
foreach (['0', '101', '1junk', 'Fictional private option'] as $limit) {
    if (
        $tester->execute(['--apply' => true, '--limit' => $limit]) !== 2 ||
        str_contains($tester->getDisplay(), 'Fictional private option') || $sis->sql->rows('sis_report_queries') === []
    ) {
        throw new RuntimeException('Invalid cleanup options must not reach removal or leak input.');
    }
}
if (
    $tester->execute(['--apply' => true, '--limit' => '1']) !== 0 ||
    !str_contains($tester->getDisplay(), '1 eligible reports; 1 removed.') ||
    str_contains($tester->getDisplay(), $id->value) ||
    str_contains($tester->getDisplay(), 'Fictional private name') || $sis->sql->rows('sis_report_queries') !== []
) {
    throw new RuntimeException('Explicit cleanup must remove content and report only aggregate counts.');
}
$sis->connection->pdo->exec('DROP TABLE sis_report_lifecycles');
if (
    $tester->execute(['--apply' => true]) !== 1 ||
    str_contains($tester->getDisplay(), 'sis_report_lifecycles')
) {
    throw new RuntimeException('Report cleanup storage failures must remain private.');
}
