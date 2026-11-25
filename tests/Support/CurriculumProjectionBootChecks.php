<?php

declare(strict_types=1);

require_once __DIR__ . '/ReusableGroupFixture.php';
require_once __DIR__ . '/ProgramPublicationFixture.php';

$f = new ReusableGroupFixture();
$f->saveDegree();
$f->publishDegree();
$s = $f->sis;
$p = new ProgramPublicationFixture($s);
$p->service->publish($s->program, $p->service->review(
    $s->program,
    $f->year,
    'Fictional cohort approval.'
)['review_token']);
$httpSis = $s;
$kernel->bootstrap();
require __DIR__ . '/SisHttpFixture.php';
$command = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$before = EventStoreFixture::historyDigest($s->sql);
$subjects = ['program' => $s->program, 'group' => $f->group, 'curriculum' => $f->degree];
$options = ['program' => '--program', 'group' => '--course-group', 'curriculum' => '--curriculum'];
$s->connection->pdo->exec('PRAGMA query_only = ON');
foreach ($subjects as $kind => $id) {
    if ($command->execute([$options[$kind] => $id->value, '--json' => true, '--events' => '1']) !== 0) {
        throw new RuntimeException('A real curriculum verifier provider failed.');
    }
    $result = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $result['status'] !== 'verified' || $result['records_checked'] !== 1 || $result['events_checked'] !== 1
        || $result['records_changed'] || $result[$kind . '_reference'] !== $id->value
        || str_contains($command->getDisplay(), 'Fictional')
    ) {
        throw new RuntimeException('Curriculum console scope or value minimization failed.');
    }
    if (
        $command->execute([$options[$kind] => $id->value]) !== 0
        || !str_contains($command->getDisplay(), 'No records were changed.')
    ) {
        throw new RuntimeException('Curriculum plain console output failed.');
    }
    foreach (['--cursor' => 'PRIVATE-invalid', '--streams' => '1', '--person' => $id->value] as $flag => $value) {
        if (
            $command->execute([$options[$kind] => $id->value, $flag => $value, '--json' => true]) !== 2
            || str_contains($command->getDisplay(), 'PRIVATE')
        ) {
            throw new RuntimeException('Curriculum selectors did not stay exclusive.');
        }
    }
}
if ($before !== EventStoreFixture::historyDigest($s->sql)) {
    throw new RuntimeException('Read-only verification changed recorded history.');
}
$s->connection->pdo->exec('PRAGMA query_only = OFF');
$s->access->staff = false;
$permissions = ['sis:history:verify', 'sis:audit:read', 'sis:catalog:manage'];
foreach ($subjects as $kind => $id) {
    $base = '/sis/history-checks/' . $kind . '/' . $id->value . '/';
    foreach ($permissions as $missing) {
        $s->access->grants = array_values(array_diff($permissions, [$missing]));
        foreach (['run/', 'history/'] as $action) {
            if ($teachingPost($base . $action, [])->getStatusCode() !== 403) {
                throw new RuntimeException('Curriculum support bypassed an independent source/support duty.');
            }
        }
    }
    $s->access->grants = $permissions;
    $withoutCsrf = $app->router->match($post->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $base . 'run/'
    ))->withParsedBody([])->withoutHeader('X-CSRF-TOKEN')->withoutHeader('X-XSRF-TOKEN'));
    if ($withoutCsrf->getStatusCode() !== 403) {
        throw new RuntimeException('Curriculum support bypassed CSRF.');
    }
    $response = $teachingPost($base . 'run/', []);
    $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $response->getStatusCode() !== 200 || $body['result']['status'] !== 'verified'
        || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('X-Debug-Token') || str_contains(json_encode($body), 'Fictional')
    ) {
        throw new RuntimeException('Curriculum support response failed privacy or verification.');
    }
    $history = $teachingPost($base . 'history/', []);
    $rows = json_decode((string) $history->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($history->getStatusCode() !== 200 || $rows['items'] !== [$body['receipt']]) {
        throw new RuntimeException('Curriculum support receipts crossed record boundaries.');
    }
}
if ($before !== EventStoreFixture::historyDigest($s->sql) || count($s->sql->rows('sis_history_checks')) !== 3) {
    throw new RuntimeException('Support observations changed domain history or duplicated receipts.');
}
