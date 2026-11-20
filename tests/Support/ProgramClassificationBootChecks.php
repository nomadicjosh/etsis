<?php

declare(strict_types=1);

// Actual providers/router and CSRF; all records and classifications are fictional SQLite fixtures.
require_once __DIR__ . '/ProgramPublicationFixture.php';
$setup = new ProgramPublicationFixture($sis);
$year = $setup->calendar->year(2024);
$base = '/sis/programs/' . $sis->program->value . '/';
$read = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path))
    );
    if (
        !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') ||
        $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Program classifications lost their private read boundary.');
    }
    return $response;
};
$draftResponse = $read($base . 'details/');
$draft = json_decode((string) $draftResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $draftResponse->getStatusCode() !== 200 ||
    !array_key_exists('school', $draft['definition']['classifications']) ||
    !array_key_exists('field_of_study', $draft['definition']['classifications'])
) {
    throw new RuntimeException('Program draft references were not resolved through actual providers.');
}
$references = [];
foreach (
    [
    'school' => ['ARTS', 'Fictional College of Arts'],
    'field_of_study' => ['11.0701', 'Fictional Computer Science'],
    ] as $kind => [$code, $title]
) {
    $response = $teachingPost('/sis/catalog/classifications/' . $kind . '/save/', [
        'code' => $code, 'title' => $title, 'active' => true,
    ]);
    $references[$kind] = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $page = $teachingPost('/sis/catalog/classifications/' . $kind . '/page/', ['search' => '']);
    if ($response->getStatusCode() !== 200 || $page->getStatusCode() !== 200) {
        throw new RuntimeException('The new classification libraries bypassed their typed private routes.');
    }
}
$input = ['code' => $draft['definition']['code'], 'title' => $draft['definition']['title'], 'description' => null,
    'department_id' => null, 'academic_level_id' => null, 'award_id' => null, 'revision' => $draft['revision']];
if ($teachingPost($base . 'details/', $input)->getStatusCode() !== 422) {
    throw new RuntimeException('An omitted program classification silently cleared a draft choice.');
}
$input += ['school_id' => $references['school']['id'], 'field_of_study_id' => $references['field_of_study']['id']];
if ($teachingPost($base . 'details/', $input)->getStatusCode() !== 200) {
    throw new RuntimeException('Program references could not be saved through actual providers.');
}
$reviewInput = ['academic_year_id' => $year['id'], 'reason' => 'Fictional reviewed classifications'];
$reviewResponse = $teachingPost($base . 'publication/review/', $reviewInput);
$review = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $reviewResponse->getStatusCode() !== 200 ||
    ($review['definition']['classifications']['field_of_study']['code'] ?? null) !== '11.0701'
) {
    throw new RuntimeException('The program publication omitted a reviewed classification.');
}
$school = $references['school'];
$rename = $teachingPost('/sis/catalog/classifications/school/save/', ['id' => $school['id'], 'code' => 'ARTS',
    'title' => 'Revised fictional college', 'revision' => $school['revision'], 'active' => true]);
if (
    $rename->getStatusCode() !== 200 ||
    $teachingPost($base . 'publication/commit/', ['review_token' => $review['review_token']])->getStatusCode() !== 409
) {
    throw new RuntimeException('A stale program reference was accepted.');
}
$review = json_decode(
    (string) $teachingPost($base . 'publication/review/', $reviewInput)->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR
);
$confirmed = $teachingPost($base . 'publication/commit/', ['review_token' => $review['review_token']]);
$retried = $teachingPost($base . 'publication/commit/', ['review_token' => $review['review_token']]);
if ($confirmed->getStatusCode() !== 200 || (string) $confirmed->getBody() !== (string) $retried->getBody()) {
    throw new RuntimeException('Program classification publication or its accepted retry failed.');
}
$page = json_decode(
    (string) $teachingPost($base . 'publication/page/', [])->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR
);
if (($page['items'][0]['definition']['classifications']['school']['title'] ?? null) !== 'Revised fictional college') {
    throw new RuntimeException('Published program details lost their retained school labels.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:student:read'];
if (
    $read($base . 'details/')->getStatusCode() !== 403 ||
    $teachingPost('/sis/catalog/classifications/school/page/', ['search' => ''])->getStatusCode() !== 403 ||
    $teachingPost($base . 'details/', $input)->getStatusCode() !== 403
) {
    throw new RuntimeException('Student-read authority unexpectedly granted catalog setup access.');
}
