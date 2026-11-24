<?php

declare(strict_types=1);

// Isolated fictional bootstrap only; never reads the institution's configuration or database.
require_once __DIR__ . '/HistoryVerificationFixture.php';

$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\HistoryAnchorCommand($app),
);
foreach (
    [[], ['--action' => 'compare'], ['--action' => 'capture', '--key-file' => 'private', '--rows' => '10001'],
    ['--action' => 'keygen', '--key-file' => 'private', '--scope' => 'not-allowed']] as $options
) {
    if (
        $early->execute(['--json' => true] + $options) !== 2 ||
        json_decode($early->getDisplay(), true)['status'] !== 'invalid_input'
    ) {
        throw new RuntimeException('Invalid checkpoint options did not stop before storage resolution.');
    }
}
$f = new HistoryVerificationFixture();
$f->stream(3);
$s = $f->sis;
$databaseAllowed = false;
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $s->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $s->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static function () use ($s, &$databaseAllowed) {
    if (!$databaseAllowed) {
        throw new RuntimeException('Checkpoint key generation must not resolve database storage.');
    }
    return $s->sql;
});
$app->delegate(\Infrastructure\EventSourcing\ProtectedEventCodec::class, static fn() => $f->codec);
$app->delegate(\Infrastructure\EventSourcing\SqlHistoricalEventStore::class, static fn() => $f->store);
$kernel->bootstrap();
$commands = $kernel->all();
$command = $commands['sis:history:anchor'];
if (!$command instanceof \Codefy\Framework\Console\ConsoleCommand) {
    throw new RuntimeException('The checkpoint command does not use ConsoleCommand.');
}
$test = new \Symfony\Component\Console\Tester\CommandTester($command);
$private = $fixture . '/anchors';
mkdir($private, 0700);
$key = $private . '/independent.key';
$path = $private . '/checkpoint';
if ($test->execute(['--action' => 'keygen', '--key-file' => $key, '--json' => true]) !== 0) {
    throw new RuntimeException('The actual private checkpoint key provider did not resolve.');
}
$options = ['--key-file' => $key, '--file' => $path, '--scope' => 'fictional-installation', '--json' => true];
if (
    $test->execute(['--action' => 'compare'] + $options) !== 1 ||
    json_decode($test->getDisplay(), true)['status'] !== 'not_verified'
) {
    throw new RuntimeException('A missing private artifact did not fail before database resolution.');
}
$databaseAllowed = true;
$before = $f->digest();
$s->connection->pdo->exec('PRAGMA query_only = ON');
if ($test->execute(['--action' => 'capture'] + $options) !== 0) {
    throw new RuntimeException('The actual checkpoint service did not capture a verified read-only inventory.');
}
$capture = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $capture['status'] !== 'captured' || $capture['events'] !== 3 || $capture['operations'] !== 1 ||
    $capture['streams'] !== 1 || array_diff(
        array_keys($capture),
        ['status', 'checkpoint_id', 'captured_at', 'streams', 'events', 'operations']
    ) !== []
) {
    throw new RuntimeException('The checkpoint command returned an incomplete or non-minimized result.');
}
if (
    $test->execute(['--action' => 'compare'] + $options) !== 0 ||
    json_decode($test->getDisplay(), true)['status'] !== 'matched' || $before !== $f->digest()
) {
    throw new RuntimeException('The checkpoint comparison changed storage or failed its read-only check.');
}
if (
    $test->execute(['--action' => 'compare', '--scope' => 'other-installation'] + $options) !== 1 ||
    json_decode($test->getDisplay(), true)['status'] !== 'mismatch'
) {
    throw new RuntimeException('A different installation scope matched the checkpoint.');
}
if (
    $test->execute(['--action' => 'capture'] + $options) !== 1 ||
    json_decode($test->getDisplay(), true)['status'] !== 'not_verified'
) {
    throw new RuntimeException('Checkpoint capture overwrote an existing artifact.');
}
if (
    $test->execute(['--action' => 'capture', '--file' => $private . '/limited', '--rows' => '1'] + $options) !== 1 ||
    is_file($private . '/limited')
) {
    throw new RuntimeException('A limited inventory was written as a complete checkpoint.');
}
$sealed = file_get_contents($path);
$keyBytes = trim(file_get_contents($key));
file_put_contents($path, 'fictional-private-invalid-checkpoint');
if (
    $test->execute(['--action' => 'compare'] + $options) !== 1 ||
    str_contains($test->getDisplay(), 'fictional-private-invalid-checkpoint') ||
    str_contains($test->getDisplay(), $private) || str_contains($test->getDisplay(), $keyBytes) ||
    str_contains($test->getDisplay(), $sealed)
) {
    throw new RuntimeException('Invalid private material was accepted or exposed by the command.');
}
file_put_contents($path, $sealed);
$text = $options;
unset($text['--json']);
if (
    $test->execute(['--action' => 'compare'] + $text) !== 0 ||
    !str_contains($test->getDisplay(), 'matches the retained checkpoint') || $before !== $f->digest()
) {
    throw new RuntimeException('Plain checkpoint output or database preservation failed.');
}
