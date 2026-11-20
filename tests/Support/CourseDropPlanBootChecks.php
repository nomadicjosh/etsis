<?php

declare(strict_types=1);

// Actual providers, routes, CSRF and privacy; only fictional records in isolated SQLite.
$sis->access->staff = true;
$dropOwner = $sis->user();
$dropStudent = $sis->student($dropOwner);
$dropSections = [$sis->section(20, 0), $sis->section(20, 0)];
foreach ($dropSections as $dropSection) {
    $sis->service->register($dropStudent->id, $dropSection->id, true);
}
$dropInput = ['student_id' => $dropStudent->id->value,
    'section_ids' => array_map(static fn($section): string => $section->id->value, $dropSections)];
$dropUntrusted = $post->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/drop-plans/review/'
))->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$dropUntrusted->getBody()->write(http_build_query(['_workspace_json' => json_encode($dropInput)]));
$dropUntrusted->getBody()->rewind();
if ($app->router->match($dropUntrusted)->getStatusCode() !== 403) {
    throw new RuntimeException('Grouped course drops bypassed CSRF.');
}
$dropChoices = $teachingPost('/sis/drop-plans/choices/', ['student_id' => $dropStudent->id->value, 'search' => '']);
if (
    $dropChoices->getStatusCode() !== 200 ||
    count(json_decode((string) $dropChoices->getBody(), true)['items']) !== 2
) {
    throw new RuntimeException('Grouped drop choices failed their actual private route.');
}
$dropActor = $sis->access->actor;
$sis->access->actor = $dropOwner;
$sis->access->staff = false;
$sis->access->grants = [];
$dropReviewed = $teachingPost('/portal/api/drop-plan/review/', $dropInput);
$dropReview = json_decode((string) $dropReviewed->getBody(), true);
if ($dropReviewed->getStatusCode() !== 200 || !isset($dropReview['review_token'])) {
    throw new RuntimeException('The owner course-drop review did not resolve through actual providers.');
}
$dropInput += ['operation_id' => $dropReview['operation_id'], 'revision' => $dropReview['revision'],
    'review_token' => $dropReview['review_token']];
$dropConfirmed = $teachingPost('/portal/api/drop-plan/commit/', $dropInput);
$dropBeforeRetry = $sis->sql->rows('sis_history_events');
$dropRepeated = $teachingPost('/portal/api/drop-plan/commit/', $dropInput);
if (
    $dropConfirmed->getStatusCode() !== 200 || $dropRepeated->getStatusCode() !== 200 ||
    (string) $dropConfirmed->getBody() !== (string) $dropRepeated->getBody() ||
    !str_contains($dropConfirmed->getHeaderLine('Cache-Control'), 'no-store') ||
    $dropConfirmed->hasHeader('phpdebugbar-id') || $dropBeforeRetry !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Grouped owner drops lost atomic confirmation, privacy or original retries.');
}
$sis->access->actor = $dropActor;
$sis->access->staff = true;
if (
    $teachingPost('/portal/api/drop-plan/choices/', ['student_id' => $dropStudent->id->value])->getStatusCode() !== 403
) {
    throw new RuntimeException('Administrative authority bypassed the owner-only drop route.');
}
