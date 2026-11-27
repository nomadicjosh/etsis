<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

// Actual providers and private transport; fictional SQLite accounts with no student record.
$values = ['office' => 'Fictional admissions', 'next_step' => 'Fictional applicant guidance.',
    'private_notes' => 'Fictional confidential staff explanation.'];
$admissionNotices->service->save($admissionNotices->application, $values, 0, 'keep');
$notice = $sis->sql->rows('sis_admission_updates')[0];
$base = '/portal/api/admissions-updates/';
$path = $base . $notice['id'] . '/';
$privateGet = static function (string $path, int $status = 200) use ($app, $get): array {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
        'https://campus.example.invalid' . $path
    )));
    if (
        $response->getStatusCode() !== $status
        || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Admissions notices lost private owner transport: ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$owner = $sis->access->actor;
$sis->access->staff = false;
$sis->access->grants = [];
$list = $privateGet($base);
$detail = $privateGet($path);
if (
    $sis->sql->rows('sis_students') !== [] || $list['items'] !== [$detail]
    || str_contains(json_encode($detail), 'Fictional applicant guidance')
    || isset($detail['person_id']) || isset($detail['owner_user_id'])
) {
    throw new RuntimeException('An applicant notice required a student or exposed private application content.');
}
$guidance = $privateGet('/portal/api/applications/' . $admissionNotices->application->value . '/next-steps/');
if (
    ($guidance['next_step'] ?? null) !== $values['next_step']
    || str_contains(json_encode($guidance), 'Fictional confidential')
) {
    throw new RuntimeException('The owner notice link did not resolve minimized current guidance.');
}
if (
    $teachingPost($path . 'read/', ['revision' => 1])->getStatusCode() !== 200
    || $teachingPost($path . 'delete/', ['revision' => 0])->getStatusCode() !== 422
) {
    throw new RuntimeException('The applicant notice revision contract failed.');
}
$sis->access->staff = true;
$values['next_step'] = 'Fictional newer guidance.';
$admissionNotices->service->save($admissionNotices->application, $values, 1, 'keep');
$sis->access->staff = false;
if ($teachingPost($path . 'delete/', ['revision' => 1])->getStatusCode() !== 409) {
    throw new RuntimeException('A stale HTTP delete removed newer applicant guidance.');
}
$tables = ['sis_applications', 'sis_admission_cases', 'sis_persons', 'users',
    'sis_history_events', 'sis_history_streams', 'sis_history_operations'];
$source = array_map($sis->sql->rows(...), $tables);
if (
    $teachingPost($path . 'delete/', ['revision' => 2])->getStatusCode() !== 200
    || $sis->sql->rows('sis_admission_updates') !== []
    || $source !== array_map($sis->sql->rows(...), $tables)
) {
    throw new RuntimeException('Notice deletion changed source facts or retained the notice.');
}
$audit = $sis->sql->rows('sis_audit');
if (
    $teachingPost($path . 'delete/', ['revision' => 2])->getStatusCode() !== 200
    || $audit !== $sis->sql->rows('sis_audit')
) {
    throw new RuntimeException('A repeated personal delete was not idempotent.');
}
$privateGet($path, 403);
$sis->access->staff = true;
$values['next_step'] = 'Fictional future guidance.';
$admissionNotices->service->save($admissionNotices->application, $values, 2, 'keep');
$fresh = $sis->sql->rows('sis_admission_updates')[0];
$other = $sis->user();
$sis->legacyPerson($other, 'Fictional', 'Other account');
$sis->access->actor = $other;
$privateGet($base);
$privateGet($base . $fresh['id'] . '/', 403);
if (
    $teachingPost($base . $fresh['id'] . '/delete/', ['revision' => 1])->getStatusCode() !== 200
    || $sis->sql->rows('sis_admission_updates') !== [$fresh]
) {
    throw new RuntimeException('A staff role bypassed personal notice ownership.');
}
$sis->access->actor = $owner;
