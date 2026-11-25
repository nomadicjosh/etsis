<?php

declare(strict_types=1);

// Actual providers and private routes; all academic and identity facts are fictional SQLite fixtures.
require_once __DIR__ . '/ProgramCampusFixture.php';
$campusFixture = new \Tests\Support\ProgramCampusFixture();
$httpSis = $campusFixture->sis;
$kernel->bootstrap();
require __DIR__ . '/SisHttpFixture.php';
$base = '/sis/sections/' . $campusFixture->target->section->id->value . '/eligible-programs/';
$campusPost = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('Program campus HTTP response mismatch: ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$before = EventStoreFixture::historyDigest($sis->sql);
$current = $campusPost($base . 'current/', []);
if (!$current['require_program_campus']) {
    throw new RuntimeException('The saved campus rule was not returned.');
}
$disabled = $campusPost($base . 'save/', ['program_ids' => [],
    'require_program_campus' => false, 'revision' => $current['revision']]);
if ($disabled['require_program_campus'] || $disabled['revision'] === $current['revision']) {
    throw new RuntimeException('The campus rule did not participate in draft revisioning.');
}
$campusPost($base . 'save/', ['program_ids' => [], 'require_program_campus' => true,
    'revision' => $current['revision']], 409);
$campusPost($base . 'save/', ['program_ids' => [], 'require_program_campus' => 'invalid',
    'revision' => $disabled['revision']], 422);
$campusPost($base . 'save/', ['program_ids' => [], 'require_program_campus' => true,
    'revision' => $disabled['revision']]);
if ($before !== EventStoreFixture::historyDigest($sis->sql)) {
    throw new RuntimeException('Saving an ordinary campus draft changed authoritative history.');
}
$sis->access->staff = false;
$sis->access->grants = [];
$campusPost($base . 'current/', [], 403);
$campusPost($base . 'save/', ['program_ids' => [], 'revision' => $current['revision']], 403);
$student = $sis->admissions->student($campusFixture->student);
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
$input = ['student_id' => $campusFixture->student->value,
    'section_id' => $campusFixture->target->section->id->value];
$review = $campusPost('/portal/api/registration/review/', $input);
$checks = array_values(array_filter(
    $review['students'][0]['readiness'],
    static fn(array $check): bool => $check['code'] === 'program_campus'
));
if (
    $checks[0]['status'] !== 'met' || $review['students'][0]['issues'] !== []
    || str_contains(json_encode($review), 'program_version_id')
    || str_contains(json_encode($review), 'campus_evidence')
    || EventStoreFixture::historyDigest($sis->sql) !== $before
) {
    throw new RuntimeException('Owner campus readiness exposed protected evidence or changed facts.');
}
$owner = $sis->access->actor;
$sis->access->actor = $sis->user();
$campusPost('/portal/api/registration/review/', $input, 404);
$sis->access->actor = $owner;
$command = $input + ['operation_id' => $review['operation_id'], 'revision' => $review['revision'],
    'review_token' => $review['review_token']];
$receipt = $campusPost('/portal/api/register/', $command);
$accepted = EventStoreFixture::historyDigest($sis->sql);
if (
    $campusPost('/portal/api/register/', $command) !== $receipt
    || $accepted !== EventStoreFixture::historyDigest($sis->sql)
) {
    throw new RuntimeException('Owner registration did not preserve its original campus decision receipt.');
}
