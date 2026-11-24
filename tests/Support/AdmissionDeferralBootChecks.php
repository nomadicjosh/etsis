<?php

declare(strict_types=1);

// Included by PortalBootChecks in its isolated fictional database; no institutional connection.
$entryYear = \Domain\Shared\ValueObject\EntityId::generate();
$entryTerm = \Domain\Shared\ValueObject\EntityId::generate();
$sis->sql->insert('sis_catalog_years', ['id' => $entryYear->value, 'code' => '2090',
    'title' => 'Fictional 2090-2091', 'starts_on' => '2090-08-01', 'ends_on' => '2091-07-31', 'published' => 0]);
$sis->sql->insert('sis_academic_years', ['id' => $entryYear->value, 'code' => '2090-2091',
    'start_year' => 2090, 'end_year' => 2091, 'starts_on' => '2090-08-01', 'ends_on' => '2091-07-31', 'legacy' => 0]);
$sis->sql->insert('sis_terms', ['id' => $entryTerm->value, 'code' => '90/FA',
    'starts_on' => '2090-08-15', 'ends_on' => '2090-12-15', 'add_drop_ends_on' => '2090-08-29']);
$sis->sql->insert('sis_term_calendar', ['id' => $entryTerm->value, 'academic_year_id' => $entryYear->value,
    'term_type_id' => null, 'term_type_version' => null, 'kind' => 'non_standard',
        'name' => 'Fictional fall 2090', 'version' => 0]);
$entryPerson = $sis->legacyPerson($sis->user(), 'Fictional', 'Deferred');
$entryApplication = \Domain\Shared\ValueObject\EntityId::generate();
$sis->submissionService()->apply($entryApplication, $entryPerson->id, $sis->program, $sis->term);
$entryDecision = $sis->decisionService();
$entryDecision->commit($entryApplication, $entryDecision->review(
    $entryApplication,
    true,
    'Fictional admission.'
)['review_token']);
$entryBase = '/sis/applications/' . $entryApplication->value . '/entry/';
$sis->access->staff = false;
$sis->access->grants = ['sis:admissions:manage'];
if (
    $teachingPost($entryBase . 'review/', ['target_term' => $entryTerm->value, 'kind' => 'defer',
    'reason' => 'Fictional later intake.'])->getStatusCode() !== 403
) {
    throw new RuntimeException('General admissions management bypassed independent intake approval authority.');
}
$sis->access->grants[] = 'sis:admissions:defer';
$entryChoices = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $entryBase . 'terms/'
))->withQueryParams(['search' => '90/FA']));
$entryChoicesData = json_decode((string) $entryChoices->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $entryChoices->getStatusCode() !== 200 || ($entryChoicesData['items'][0]['id'] ?? null) !== $entryTerm->value
    || !str_contains(
        $entryChoices->getHeaderLine('Cache-Control'),
        'no-store'
    ) || $entryChoices->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Private bounded intake search did not resolve through the real route.');
}
$entryEvents = $sis->sql->rows('sis_history_events');
$entryReviewResponse = $teachingPost($entryBase . 'review/', ['target_term' => $entryTerm->value,
    'kind' => 'defer', 'reason' => 'Fictional later intake.']);
$entryReview = json_decode((string) $entryReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $entryReviewResponse->getStatusCode() !== 200 || !isset($entryReview['review_token'])
    || $entryEvents !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('The real intake review failed or wrote domain events before confirmation.');
}
$entryCommit = $teachingPost($entryBase . 'commit/', ['review_token' => $entryReview['review_token']]);
$entryResult = json_decode((string) $entryCommit->getBody(), true, flags: JSON_THROW_ON_ERROR);
$entryRetry = $teachingPost($entryBase . 'commit/', ['review_token' => $entryReview['review_token']]);
if (
    $entryCommit->getStatusCode() !== 200 || ($entryResult['after']['id'] ?? null) !== $entryTerm->value
    || $sis->admissions->application($entryApplication)->entryTermId->value !== $entryTerm->value
    || json_decode((string) $entryRetry->getBody(), true, flags: JSON_THROW_ON_ERROR) !== $entryResult
    || $entryCommit->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The real intake approval or its original retry result failed.');
}
$sis->access->staff = true;

// Submitted-entry correction reuses the same private pipeline with independent correction authority.
$correctionApplication = \Domain\Shared\ValueObject\EntityId::generate();
$sis->submissionService()->apply($correctionApplication, $entryPerson->id, $sis->program, $sis->term);
$correctionBase = '/sis/applications/' . $correctionApplication->value . '/entry/';
$sis->access->staff = false;
$sis->access->grants = ['sis:admissions:manage', 'sis:admissions:defer'];
if (
    $teachingPost($correctionBase . 'review/', [
    'target_term' => $entryTerm->value, 'kind' => 'correct', 'reason' => 'Fictional correction.',
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException('Deferment authority granted a submitted-entry correction through HTTP.');
}
$sis->access->grants = ['sis:admissions:manage', 'sis:admissions:correct'];
$correctionCurrent = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $correctionBase
)));
$correctionData = json_decode((string) $correctionCurrent->getBody(), true, flags: JSON_THROW_ON_ERROR);
$correctionEvents = $sis->sql->rows('sis_history_events');
$correctionReview = $teachingPost($correctionBase . 'review/', [
    'target_term' => $entryTerm->value, 'kind' => 'correct', 'reason' => 'Fictional private correction.',
]);
$correctionPlan = json_decode((string) $correctionReview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $correctionCurrent->getStatusCode() !== 200 || $correctionData['application_status'] !== 'submitted'
    || $correctionReview->getStatusCode() !== 200 || !isset($correctionPlan['review_token'])
    || $correctionEvents !== $sis->sql->rows('sis_history_events')
    || !str_contains($correctionReview->getHeaderLine('Cache-Control'), 'no-store')
    || $correctionReview->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The submitted-entry private review wrote facts or failed to boot.');
}
$correctionCommit = $teachingPost($correctionBase . 'commit/', ['review_token' => $correctionPlan['review_token']]);
$correctionResult = json_decode((string) $correctionCommit->getBody(), true, flags: JSON_THROW_ON_ERROR);
$correctionRetry = $teachingPost($correctionBase . 'commit/', ['review_token' => $correctionPlan['review_token']]);
if (
    $correctionCommit->getStatusCode() !== 200 || ($correctionResult['status'] ?? null) !== 'submitted'
    || ($correctionResult['kind'] ?? null) !== 'correct'
    || $sis->admissions->application($correctionApplication)->entryTermId->value !== $entryTerm->value
    || json_decode((string) $correctionRetry->getBody(), true, flags: JSON_THROW_ON_ERROR) !== $correctionResult
    || !str_contains($correctionCommit->getHeaderLine('Cache-Control'), 'no-store')
    || $correctionCommit->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The submitted-entry correction or original retry failed through HTTP.');
}
$sis->access->grants = ['sis:admissions:manage', 'sis:admissions:defer'];
if (
    $teachingPost($correctionBase . 'commit/', [
    'review_token' => $correctionPlan['review_token'],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException('An original correction retry bypassed current correction authority.');
}
$sis->access->staff = true;
