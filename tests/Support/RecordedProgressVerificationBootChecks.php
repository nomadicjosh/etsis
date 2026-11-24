<?php

declare(strict_types=1);

// Reuse the actual recorded-progress HTTP fixture and its accepted observation.
$sis->access->staff = true;
$command = new \Symfony\Component\Console\Tester\CommandTester($kernel->all()['sis:history:verify']);
$recordId = $review['operation_id'];
$before = \EventStoreFixture::historyDigest($sis->sql);
$projection = $sis->sql->rows('sis_degree_progress_records');
$sis->connection->pdo->exec('PRAGMA query_only = ON');
if ($command->execute(['--progress-record' => $recordId, '--events' => '1', '--json' => true]) !== 0) {
    throw new RuntimeException('The real provider did not verify the saved progress observation.');
}
$result = json_decode($command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
if (
    $result['status'] !== 'verified' || $result['position'] !== 1 || $result['records_checked'] !== 1 ||
    $result['events_checked'] !== 1 || $result['progress_record_reference'] !== $recordId || $result['records_changed']
) {
    throw new RuntimeException('Recorded-progress verification returned an inconsistent result.');
}
if (
    $command->execute(['--progress-record' => $recordId]) !== 0 ||
    !str_contains($command->getDisplay(), 'fresh progress reproduction') ||
    str_contains($command->getDisplay(), 'Fictional')
) {
    throw new RuntimeException('The plain console output omitted its scope or exposed private values.');
}
$sis->connection->pdo->exec('PRAGMA query_only = OFF');
if (
    $before !== \EventStoreFixture::historyDigest($sis->sql) ||
    $projection !== $sis->sql->rows('sis_degree_progress_records')
) {
    throw new RuntimeException('The read-only command changed business records.');
}
$support = '/sis/history-checks/progress/' . $recordId . '/';
$csrf = $post->withUri(new \Laminas\Diactoros\Uri($support . 'run/'))
    ->withParsedBody(['_workspace_json' => '{}']);
if ($app->router->match($csrf)->getStatusCode() !== 403) {
    throw new RuntimeException('Progress support checks lost CSRF protection.');
}
$response = $teachingPost($support . 'run/', ['events' => 2000]);
$check = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $response->getStatusCode() !== 200 || ($check['result']['status'] ?? null) !== 'verified' ||
    $check['event_limit'] !== 200 || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') ||
    $response->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Progress support lost its bounded private response.');
}
foreach (['Fictional', 'source_hash', 'source_references', 'inputs', 'snapshot', 'reason', 'component'] as $private) {
    if (str_contains((string) $response->getBody(), $private)) {
        throw new RuntimeException('Progress support exposed protected review data.');
    }
}
$history = $teachingPost($support . 'history/', []);
if (
    $history->getStatusCode() !== 200 ||
    count(json_decode((string) $history->getBody(), true, flags: JSON_THROW_ON_ERROR)['items']) !== 1
) {
    throw new RuntimeException('The progress support receipt could not be reopened.');
}
$count = count($sis->sql->rows('sis_history_checks'));
$sis->access->staff = false;
$duties = ['sis:history:verify', 'sis:audit:read', 'sis:student:read', 'sis:degree-audit:record'];
foreach ($duties as $missing) {
    $sis->access->grants = array_values(array_diff($duties, [$missing]));
    foreach (['run/', 'history/'] as $action) {
        if ($teachingPost($support . $action, [])->getStatusCode() !== 403) {
            throw new RuntimeException('A required progress support duty was bypassed.');
        }
    }
}
$sis->access->grants = [];
if (
    $count !== count($sis->sql->rows('sis_history_checks')) ||
    $before !== \EventStoreFixture::historyDigest($sis->sql) ||
    $projection !== $sis->sql->rows('sis_degree_progress_records')
) {
    throw new RuntimeException('Support checks changed the saved progress fact or bypassed authorization.');
}
