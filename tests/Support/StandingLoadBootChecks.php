<?php

declare(strict_types=1);

require_once __DIR__ . '/StandingLoadFixture.php';
$loadFixture = new \Tests\Support\StandingLoadFixture();
$f = $loadFixture->base;
$httpSis = $f->sis;
require __DIR__ . '/SisHttpFixture.php';
$base = '/sis/students/' . $f->student->value . '/programs/' . $f->pursuit->value . '/standing/';
$call = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException('Standing load route returned ' . $response->getStatusCode());
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$policyBase = '/sis/academic-standing/proposal-policy/';
$policy = $call($policyBase, []);
$policyReview = $call($policyBase . 'review/', ['rules' => $policy['rules'], 'version' => $policy['version'],
    'reason' => 'Fictional HTTP load requirement publication.']);
$call($policyBase . 'publish/', $policyReview);
if (($call($policyBase, [])['rules']['load_requirement']['classification_code'] ?? null) !== 'FULL') {
    throw new RuntimeException('The published load requirement lost its typed classification.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:student:read', 'sis:standing:manage', 'sis:audit:read'];
$call($base . 'propose/', ['load_id' => $loadFixture->record->value], 403);
$sis->access->grants[] = 'sis:load:determine';
$before = $f->facts();
$call($base . 'propose/', [], 422);
$call($base . 'propose/', ['load_id' => 'bad'], 422);
$proposal = $call($base . 'propose/', ['load_id' => $loadFixture->record->value]);
if (
    ($proposal['load']['credits_milli'] ?? null) !== 3000 || $f->facts() !== $before
    || str_contains(json_encode($proposal), 'sources') || str_contains(json_encode($proposal), 'snapshot')
) {
    throw new RuntimeException('Standing load proposal lost its source or privacy boundary.');
}
$listing = $call(str_replace('/standing/', '/load/determinations/', $base), ['term_id' => $sis->term->value]);
if (($listing['items'][0]['id'] ?? null) !== $loadFixture->record->value) {
    throw new RuntimeException('The existing load chooser lost its scoped accepted record.');
}
$view = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $base)));
$revision = json_decode((string) $view->getBody(), true, flags: JSON_THROW_ON_ERROR)['revision'];
$review = $call($base . 'review/', ['rows' => [$f->row($proposal)], 'revision' => $revision,
    'reason' => 'Fictional HTTP retained-load standing.', 'proposal' => $proposal['proposal']]);
$sis->access->grants = ['sis:student:read', 'sis:standing:manage', 'sis:audit:read'];
$before = $f->facts();
$call($base . 'commit/', $review, 403);
if ($f->facts() !== $before) {
    throw new RuntimeException('A load-denied confirmation changed standing facts.');
}
$sis->access->grants[] = 'sis:load:determine';
$accepted = $call($base . 'commit/', $review);
if ($call($base . 'commit/', $review) !== $accepted) {
    throw new RuntimeException('An accepted load-backed standing retry changed its result.');
}
$history = $call($base . 'history/', []);
if (($history['items'][0]['proposal']['load']['classification']['code'] ?? null) !== 'FULL') {
    throw new RuntimeException('Accepted standing history lost its load classification.');
}
$sis->access->grants = ['sis:student:read', 'sis:standing:manage', 'sis:audit:read'];
$history = $call($base . 'history/', []);
if (($history['items'][0]['proposal']['load'] ?? null) !== ['restricted' => true]) {
    throw new RuntimeException('Retained load details were not restricted after duty loss.');
}
$sis->access->actor = $sis->admissions->person($sis->admissions->student($f->student)->personId)->ownerUserId;
$sis->access->grants = [];
$owner = $call(str_replace('/sis/', '/portal/api/', $base), []);
if (str_contains(json_encode($owner), 'proposal') || str_contains(json_encode($owner), 'load_assessment')) {
    throw new RuntimeException('Private standing calculation evidence leaked to the owner.');
}
