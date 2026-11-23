<?php

declare(strict_types=1);

// Real providers, private responses and framework CSRF with fictional in-memory records only.
require __DIR__ . '/SisHttpFixture.php';
$staff = $sis->access->actor;
$owner = $sis->user();
$person = $sis->legacyPerson($owner, 'Fictional', 'Applicant');
$application = $sis->legacyApplication($person->id, $sis->program, $sis->term)->id;
$base = '/sis/applications/' . $application->value . '/document-holds/';
$read = static fn(string $path) => $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($path)));
$initial = $read($base);
$context = json_decode((string) $initial->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $initial->getStatusCode() !== 200 || !str_contains($initial->getHeaderLine('Cache-Control'), 'no-store')
    || $initial->hasHeader('phpdebugbar-id') || ($context['scopes']['person']['active'] ?? null) !== false
) {
    throw new RuntimeException('Private document hold setup failed through the actual providers.');
}
$values = ['scope' => 'person', 'action' => 'place', 'revision' => $context['revision'],
    'reason' => 'Fictional private preservation reason.'];
$response = $teachingPost($base . 'review/', $values);
$proof = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 200 || !is_string($proof['review_token'] ?? null)) {
    throw new RuntimeException('The real reviewed hold route failed.');
}
$confirmed = $teachingPost($base . 'confirm/', $proof);
if (
    $confirmed->getStatusCode() !== 200 || $teachingPost($base . 'confirm/', $proof)->getBody()->__toString()
    !== (string) $confirmed->getBody()
) {
    throw new RuntimeException('The actual hold confirmation lost its original retry result.');
}
$listed = $read('/sis/applications/' . $application->value . '/documents/');
if (!str_contains((string) $listed->getBody(), 'Fictional private preservation reason.')) {
    throw new RuntimeException('The document editor did not receive authorized hold controls.');
}
foreach ([[], ['sis:admissions:manage'], ['sis:admissions:documents:retain']] as $grants) {
    $sis->access->actor = $owner;
    $sis->access->staff = false;
    $sis->access->grants = $grants;
    if ($read($base)->getStatusCode() !== 403 || $teachingPost($base . 'confirm/', $proof)->getStatusCode() !== 403) {
        throw new RuntimeException('Incomplete or applicant authority exposed document hold controls.');
    }
}
$mine = $read('/portal/api/applications/' . $application->value . '/documents/');
if (
    $mine->getStatusCode() !== 200 || str_contains((string) $mine->getBody(), 'retention_holds')
    || str_contains((string) $mine->getBody(), 'Fictional private preservation reason.')
) {
    throw new RuntimeException('An applicant read private document hold reasons.');
}
$sis->access->actor = $staff;
$sis->access->staff = true;
$latest = json_decode((string) $read($base)->getBody(), true, flags: JSON_THROW_ON_ERROR);
$release = $teachingPost($base . 'review/', ['scope' => 'person', 'action' => 'release',
    'revision' => $latest['revision'], 'reason' => 'Fictional approved release.']);
if (
    $release->getStatusCode() !== 200 || $teachingPost(
        $base . 'confirm/',
        json_decode((string) $release->getBody(), true, flags: JSON_THROW_ON_ERROR)
    )->getStatusCode() !== 200
) {
    throw new RuntimeException('The actual release workflow failed.');
}
echo "Document hold privacy, CSRF, review, release and original retries verified.\n";
