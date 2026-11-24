<?php

declare(strict_types=1);

// Continue the existing fictional intake/program HTTP fixture, with no institutional data.
$ownerHistoryPath = '/portal/api/applications/' . $programApplication->value . '/history/';
$ownerHistoryBefore = $sis->sql->rows('sis_history_events');
$ownerHistoryResponse = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $ownerHistoryPath
)));
$ownerHistoryData = json_decode((string) $ownerHistoryResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $ownerHistoryResponse->getStatusCode() !== 200
    || ($ownerHistoryData['current']['program']['code'] ?? null) !== 'ARTS-CHANGE'
    || !isset($ownerHistoryData['original']['program']['code'])
    || !str_contains($ownerHistoryResponse->getHeaderLine('Cache-Control'), 'no-store')
    || $ownerHistoryResponse->hasHeader('phpdebugbar-id')
    || $ownerHistoryBefore !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Owner application history failed through private actual HTTP providers.');
}
foreach (['review_token', 'decision_id', 'owner_id', 'reason', 'shared_form', 'fingerprint', 'evidence'] as $private) {
    if (str_contains((string) $ownerHistoryResponse->getBody(), $private)) {
        throw new RuntimeException('Owner application history exposed a protected staff field.');
    }
}
$historyActor = $sis->access->actor;
$sis->access->actor = $sis->user();
$ownerHistoryDenied = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $ownerHistoryPath
)));
if (
    $ownerHistoryDenied->getStatusCode() !== 403
    || !str_contains($ownerHistoryDenied->getHeaderLine('Cache-Control'), 'no-store')
    || str_contains((string) $ownerHistoryDenied->getBody(), 'ARTS-CHANGE')
) {
    throw new RuntimeException('Staff access bypassed owner-only application history.');
}
$sis->access->actor = $historyActor;
if ($ownerHistoryBefore !== $sis->sql->rows('sis_history_events')) {
    throw new RuntimeException('An owner history read created or altered a business event.');
}
