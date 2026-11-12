<?php
declare(strict_types=1);
// Included only by the isolated console process: fictional database, no institutional configuration/network.
require_once __DIR__ . "/SisFixture.php";
// Invalid options and help must work without resolving retention storage or requiring its port binding.
$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\PruneLoginSessionsCommand($app),
);
if ($early->execute(['--limit' => '0']) !== 2) {
    throw new RuntimeException('Retention options were not validated before storage resolution.');
}
$sis = new SisFixture();
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $sis->sql);
$app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $sis->transactions);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $sis->clock);
$kernel->bootstrap();
$now = $sis->clock->now()->getTimestamp();
$expired = \Domain\Shared\ValueObject\EntityId::generate();
$active = \Domain\Shared\ValueObject\EntityId::generate();
$repository = new \Infrastructure\Security\SqlLoginSessionRepository($sis->sql);
foreach ([[$expired, $now - 90000], [$active, $now + 3600]] as [$id, $deadline]) {
    $repository->add(
        new \Domain\User\LoginSession(
            $id,
            $sis->access->actor,
            hash("sha256", $id->value),
            hash("sha256", "fictional-revision"),
            $deadline,
            $now - 100000,
        ),
    );
}
if ($kernel->call("sis:auth:prune-sessions") !== 0 || count($sis->sql->rows("sis_login_sessions")) !== 2) {
    throw new RuntimeException(
        "Registered session-retention command did not default to a read-only preview.",
    );
}
$command = $app->make(\Application\Console\Commands\PruneLoginSessionsCommand::class);
$tester = new \Symfony\Component\Console\Tester\CommandTester($command);
if (
    $tester->execute([]) !== 0 ||
    !str_contains($tester->getDisplay(), "Preview only") ||
    !str_contains($tester->getDisplay(), "eligible: 1") ||
    str_contains($tester->getDisplay(), $expired->value)
) {
    throw new RuntimeException("Session-retention preview did not return fixed aggregate output.");
}
foreach (["0", "501", "1junk", "fictional-private-option"] as $value) {
    if (
        $tester->execute(["--apply" => true, "--limit" => $value]) !== 2 ||
        str_contains($tester->getDisplay(), "fictional-private-option") ||
        count($sis->sql->rows("sis_login_sessions")) !== 2
    ) {
        throw new RuntimeException("Invalid retention input reached deletion or leaked its value.");
    }
}
foreach (["0", "8761", "-1"] as $value) {
    if ($tester->execute(["--grace-hours" => $value]) !== 2) {
        throw new RuntimeException("Invalid retention grace period was accepted.");
    }
}
if (
    $tester->execute(["--apply" => true, "--limit" => "1", "--grace-hours" => "24"]) !== 0 ||
    !str_contains($tester->getDisplay(), "Sign-in references removed: 1") ||
    $repository->byId($expired) !== null ||
    $repository->byId($active) === null
) {
    throw new RuntimeException("Explicit retention application did not preserve the active sign-in.");
}
$sis->connection->pdo->exec("DROP TABLE sis_moodle_launches");
if (
    $tester->execute(["--apply" => true]) !== 1 ||
    str_contains($tester->getDisplay(), "sis_moodle_launches") ||
    !str_contains($tester->getDisplay(), "support migrations")
) {
    throw new RuntimeException("Retention storage failure did not produce a fixed, private diagnostic.");
}
