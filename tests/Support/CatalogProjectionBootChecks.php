<?php

declare(strict_types=1);

$early = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\VerifyHistoryCommand($app)
);
foreach (['course', 'section'] as $kind) {
    foreach (
        [
        ['--' . $kind => 'PRIVATE invalid'], ['--' . $kind => '00000000000000000000000000', '--streams' => '10'],
        ['--' . $kind => '00000000000000000000000000', '--cursor' => 'PRIVATE cursor'],
        ['--' . $kind => '00000000000000000000000000', '--events' => '2001'],
        ['--' . $kind => '00000000000000000000000000', '--waitlist-entry' => '00000000000000000000000000'],
        ['--' . $kind => '00000000000000000000000000', '--person' => '00000000000000000000000000'],
        ['--course' => '00000000000000000000000000', '--section' => '00000000000000000000000000'],
        ] as $options
    ) {
        if ($early->execute(['--json' => true] + $options) !== 2 || str_contains($early->getDisplay(), 'PRIVATE')) {
            throw new RuntimeException('Invalid catalog selectors resolved storage or exposed private input.');
        }
    }
}
require_once __DIR__ . '/CatalogVerificationFixture.php';
$f = new \Tests\Support\CatalogVerificationFixture();
$s = $f->sis;
$httpSis = $s;
$kernel->bootstrap();
require __DIR__ . '/SisHttpFixture.php';
$command = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$before = $f->facts();
$s->connection->pdo->exec('PRAGMA query_only = ON');
foreach (['course' => $f->course, 'section' => $f->section] as $kind => $id) {
    $options = ['--' . $kind => $id->value, '--events' => $kind === 'course' ? '1' : '4', '--json' => true];
    if ($command->execute($options) !== 0) {
        throw new RuntimeException('A real catalog provider failed.');
    }
    $result = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $result['status'] !== 'verified' || $result['records_checked'] !== 1 || $result['records_changed']
        || $result[$kind . '_reference'] !== $id->value || str_contains($command->getDisplay(), 'Fictional')
    ) {
        throw new RuntimeException('Catalog console scope or value minimization failed.');
    }
    if (
        $command->execute(['--' . $kind => $id->value]) !== 0
        || !str_contains($command->getDisplay(), 'No records were changed.')
    ) {
        throw new RuntimeException('Catalog console output did not remain plain and read-only.');
    }
}
if (
    $command->execute(['--section' => $f->section->value, '--events' => '3', '--json' => true]) !== 3
    || $command->execute(['--course' => '00000000000000000000000000', '--json' => true]) !== 1
    || $f->facts() !== $before
) {
    throw new RuntimeException('Catalog console bounds or preservation failed.');
}
$s->connection->pdo->exec('PRAGMA query_only = OFF');
$s->access->staff = false;
$permissions = ['sis:history:verify', 'sis:audit:read', 'sis:catalog:manage'];
foreach (['course' => $f->course, 'section' => $f->section] as $kind => $id) {
    $base = '/sis/history-checks/' . $kind . '/' . $id->value . '/';
    foreach ($permissions as $missing) {
        $s->access->grants = array_values(array_diff($permissions, [$missing]));
        foreach (['run/', 'history/'] as $action) {
            if ($teachingPost($base . $action, [])->getStatusCode() !== 403) {
                throw new RuntimeException('Catalog checks bypassed an independent source/support duty.');
            }
        }
    }
    $s->access->grants = $permissions;
    $noCsrf = $app->router->match($post->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $base . 'run/'
    ))->withParsedBody([])->withoutHeader('X-CSRF-TOKEN')->withoutHeader('X-XSRF-TOKEN'));
    if ($noCsrf->getStatusCode() !== 403) {
        throw new RuntimeException('Catalog support bypassed CSRF.');
    }
    $response = $teachingPost($base . 'run/', []);
    $result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $response->getStatusCode() !== 200 || ($result['result']['status'] ?? null) !== 'verified'
        || $result['event_limit'] !== 200 || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Catalog support HTTP observation or private response failed.');
    }
    $historyPage = $teachingPost($base . 'history/', []);
    $historyData = json_decode((string) $historyPage->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($historyPage->getStatusCode() !== 200 || $historyData['items'] !== [$result['receipt']]) {
        throw new RuntimeException('Catalog support receipts did not stay scoped to the chosen catalog record.');
    }
}
foreach ($s->sql->rows('sis_history_checks') as $row) {
    if ($row['person_id'] !== null || $row['owner_user_id'] !== null || $row['student_id'] !== null) {
        throw new RuntimeException('A catalog check fabricated personal routing.');
    }
}
