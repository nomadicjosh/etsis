<?php

declare(strict_types=1);

// Actual providers and private routes, using fictional SQLite records only.
require_once __DIR__ . '/StandingScopeFixture.php';
$standingScopeFixture = new \Tests\Support\StandingScopeFixture();
$standingScopeFixture->publish([
    $standingScopeFixture->override('academic_level', $standingScopeFixture->undergraduate),
]);
$standingFixture = $standingScopeFixture->base;
$httpSis = $standingFixture->sis;
$kernel->bootstrap();
require __DIR__ . '/SisHttpFixture.php';
$standingPost = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException(
            'Standing proposal route returned an unexpected status: ' . $response->getStatusCode()
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$f = $standingFixture;
$base = '/sis/students/' . $f->student->value . '/programs/' . $f->pursuit->value . '/standing/';
$policyBase = '/sis/academic-standing/proposal-policy/';
$actor = $sis->access->actor;
$sis->access->staff = false;
foreach ([['sis:standing:configure'], ['sis:student:read'], ['sis:standing:manage', 'sis:student:read']] as $grants) {
    $sis->access->grants = $grants;
    $standingPost($policyBase, [], 403);
    $standingPost($policyBase . 'targets/', ['kind' => 'program'], 403);
}
$sis->access->grants = ['sis:standing:configure', 'sis:student:read'];
$levels = $standingPost($policyBase . 'targets/', ['kind' => 'academic_level', 'search' => 'UG']);
$programs = $standingPost($policyBase . 'targets/', ['kind' => 'program']);
if (
    ($levels['items'][0]['id'] ?? null) !== $standingScopeFixture->undergraduate->value
    || !in_array($sis->program->value, array_column($programs['items'], 'id'), true)
) {
    throw new RuntimeException('Scoped targets required unrelated catalog authority or lost their type.');
}
$standingPost($policyBase . 'targets/', ['kind' => 'department'], 422);
$standingPost($policyBase . 'targets/', ['kind' => 'academic_level', 'after' => 'invalid'], 422);
$current = $standingPost($policyBase, []);
$review = $standingPost($policyBase . 'review/', ['rules' => $current['rules'], 'version' => $current['version'],
    'reason' => 'Fictional configured policy HTTP review.']);
$standingPost($policyBase . 'publish/', $review);
$standingPost($base . 'propose/', [], 403);
$sis->access->grants = ['sis:standing:manage', 'sis:student:read', 'sis:audit:read'];
$before = $f->facts();
$proposal = $standingPost($base . 'propose/', []);
if (
    $proposal['gpa_milli'] !== 2000 || $proposal['standing']['code'] !== 'REVIEW'
    || ($proposal['scope']['kind'] ?? null) !== 'academic_level' || ($proposal['scope']['code'] ?? null) !== 'UG'
    || str_contains(json_encode($proposal), 'level_source')
    || str_contains(json_encode($proposal), 'program_version_id')
    || str_contains(json_encode($proposal), 'source_positions') || str_contains(json_encode($proposal), 'attempts')
    || $f->facts() !== $before
) {
    throw new RuntimeException('The proposal exposed protected inputs or wrote a decision.');
}
$viewUri = new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $base);
$viewResponse = $app->router->match($get->withUri($viewUri));
$view = json_decode((string) $viewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$review = $standingPost($base . 'review/', ['rows' => [$f->row($proposal)], 'revision' => $view['revision'],
    'reason' => 'Fictional HTTP proposal acceptance.', 'proposal' => $proposal['proposal']]);
$saved = $standingPost($base . 'commit/', $review);
if ($standingPost($base . 'commit/', $review) !== $saved) {
    throw new RuntimeException('An accepted proposal did not preserve its original receipt.');
}
$history = $standingPost($base . 'history/', []);
if (
    ($history['items'][0]['proposal']['gpa_milli'] ?? null) !== 2000
    || ($history['items'][0]['proposal']['scope']['code'] ?? null) !== 'UG'
    || str_contains(json_encode($history), 'level_source') || str_contains(json_encode($history), 'program_version_id')
    || str_contains(json_encode($history), 'source_positions') || str_contains(json_encode($history), 'attempts')
) {
    throw new RuntimeException('Staff history lost the minimized GPA evidence boundary.');
}
$sis->access->grants = [];
$sis->access->actor = $sis->admissions->person($sis->admissions->student($f->student)->personId)->ownerUserId;
$standingPost($base . 'propose/', [], 403);
$ownerBase = '/portal/api/students/' . $f->student->value . '/programs/' . $f->pursuit->value . '/standing/';
$owner = $standingPost($ownerBase, []);
if (str_contains(json_encode($owner), 'proposal') || str_contains(json_encode($owner), 'gpa_milli')) {
    throw new RuntimeException('Staff calculation evidence leaked into the owner standing response.');
}
$sis->access->actor = $actor;
