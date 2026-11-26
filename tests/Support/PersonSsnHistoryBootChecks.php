<?php

declare(strict_types=1);

$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app)
);
foreach (
    [
    ['--ssn-person' => 'private-invalid-input'],
    ['--ssn-person' => '00000000000000000000000000', '--events' => '501'],
    ['--ssn-person' => '00000000000000000000000000', '--events' => '0'],
    ['--ssn-person' => '00000000000000000000000000', '--streams' => '1'],
    ['--ssn-person' => '00000000000000000000000000', '--cursor' => 'private-invalid-input'],
    ['--ssn-person' => '00000000000000000000000000', '--person' => '00000000000000000000000000'],
    ['--ssn-person' => '00000000000000000000000000', '--graduate-policy' => true],
    ] as $options
) {
    if (
        $early->execute(['--json' => true] + $options) !== 2
        || str_contains($early->getDisplay(), 'private-invalid-input')
    ) {
        throw new RuntimeException('SSN selector validation resolved storage or exposed input.');
    }
}
require_once __DIR__ . '/PersonSsnFixture.php';
$f = new \Tests\Support\PersonSsnFixture();
$f->grant('sis:history:verify', true);
$f->confirm($f->review());
$f->confirm($f->review('000991234', 1), '000991234');
$httpSis = $f->sis;
$kernel->bootstrap();
require __DIR__ . '/SisHttpFixture.php';
$test = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$options = ['--ssn-person' => $f->person->id->value, '--json' => true];
$before = $f->facts();
$sis->connection->pdo->exec('PRAGMA query_only = ON');
if ($test->execute($options) !== 0) {
    throw new RuntimeException('Actual SSN verifier did not resolve read-only.');
}
$result = json_decode($test->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if ($result['status'] !== 'verified' || $result['events_checked'] !== 2 || $result['scope'] !== 'person_ssn') {
    throw new RuntimeException('SSN verification lost its selected scope or evidence.');
}
foreach (['000001234', '000991234', '1234', 'number', 'sealed', 'nonce', 'intent', 'Fictional'] as $secret) {
    if (str_contains($test->getDisplay(), $secret)) {
        throw new RuntimeException('SSN diagnostics exposed a value.');
    }
}
if (
    $test->execute(['--events' => '1'] + $options) !== 3
    || json_decode($test->getDisplay(), true)['status'] !== 'limited'
) {
    throw new RuntimeException('A limited SSN replay was certified complete.');
}
if (
    $test->execute(['--ssn-person' => $f->person->id->value]) !== 0
    || !str_contains($test->getDisplay(), 'No records were changed') || $before !== $f->facts()
) {
    throw new RuntimeException('SSN operator verification changed facts or lost plain output.');
}
$sis->connection->pdo->exec('PRAGMA query_only = OFF');
$base = '/sis/history-checks/ssn/' . $f->person->id->value . '/';
$call = static function (string $action, array $body = [], int $status = 200) use ($teachingPost, $base): array {
    $response = $teachingPost($base . $action . '/', $body);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('SSN support returned HTTP ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$result = $call('run');
if (
    $result['result']['status'] !== 'verified' || $result['event_limit'] !== 200
    || count($call('history')['items']) !== 1 || $before !== $f->facts()
) {
    throw new RuntimeException('Actual SSN support changed source facts or lost its restricted receipt.');
}
$f->grant('sis:person:ssn:read', false);
$call('run', status: 403);
$call('history', status: 403);
$f->grant('sis:person:ssn:read', true);
$sis->sql->update(
    'sis_person_ssns',
    ['sealed_data' => 'fictional damaged projection'],
    ['id' => $f->person->id->value]
);
$damaged = $f->facts();
if (
    $test->execute($options) !== 1
    || json_decode($test->getDisplay(), true)['issues'] !== ['ssn_projection']
    || $call('run')['result']['issues'] !== ['ssn_projection'] || $damaged !== $f->facts()
) {
    throw new RuntimeException('Damaged SSN projection was accepted or repaired by diagnostics.');
}
