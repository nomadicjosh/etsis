<?php

declare(strict_types=1);

// Reuse the existing private admissions HTTP fixture; no application database or real applicant is used.
$programTarget = \Domain\Shared\ValueObject\EntityId::generate();
$sis->sql->insert('sis_programs', ['id' => $programTarget->value,
    'code' => 'ARTS-CHANGE', 'title' => 'Fictional target program']);
$programPerson = $sis->legacyPerson($sis->access->actor, 'Fictional', 'Program correction');
$programApplication = \Domain\Shared\ValueObject\EntityId::generate();
$sis->submissionService()->apply($programApplication, $programPerson->id, $sis->program, $entryTerm);
$programBase = '/sis/applications/' . $programApplication->value . '/program/';
$sis->access->staff = false;
$sis->access->grants = ['sis:admissions:manage'];
if (
    $teachingPost($programBase . 'review/', ['target_program' => $programTarget->value,
    'reason' => 'Fictional correction.'])->getStatusCode() !== 403
) {
    throw new RuntimeException('Ordinary admissions management bypassed program correction authority.');
}
$sis->access->grants[] = 'sis:admissions:correct';
$programChoices = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $programBase . 'choices/'
))->withQueryParams(['search' => 'ARTS-CHANGE']));
$programChoicesData = json_decode((string) $programChoices->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $programChoices->getStatusCode() !== 200
    || ($programChoicesData['items'][0]['id'] ?? null) !== $programTarget->value
    || !str_contains($programChoices->getHeaderLine('Cache-Control'), 'no-store')
    || $programChoices->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The bounded private program chooser failed through actual providers.');
}
$programNoCsrf = $app->router->match($post->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $programBase . 'review/'
))->withParsedBody(['target_program' => $programTarget->value, 'reason' => 'Fictional correction.'])
    ->withoutHeader('X-CSRF-TOKEN')->withoutHeader('X-XSRF-TOKEN'));
if ($programNoCsrf->getStatusCode() !== 403) {
    throw new RuntimeException('Program correction bypassed browser CSRF protection.');
}
$programEvents = $sis->sql->rows('sis_history_events');
$programResponse = $teachingPost($programBase . 'review/', ['target_program' => $programTarget->value,
    'reason' => 'Fictional correction.']);
$programReview = json_decode((string) $programResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $programResponse->getStatusCode() !== 200 || !isset($programReview['review_token'])
    || $programEvents !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('A program review failed or changed academic facts.');
}
$programResult = $teachingPost($programBase . 'commit/', ['review_token' => $programReview['review_token']]);
$programAccepted = $sis->sql->rows('sis_history_events');
$programRetry = $teachingPost($programBase . 'commit/', ['review_token' => $programReview['review_token']]);
if (
    $programResult->getStatusCode() !== 200 || $programRetry->getStatusCode() !== 200
    || (string) $programResult->getBody() !== (string) $programRetry->getBody()
    || $sis->admissions->application($programApplication)->programId->value !== $programTarget->value
    || $programAccepted !== $sis->sql->rows('sis_history_events')
    || !str_contains($programResult->getHeaderLine('Cache-Control'), 'no-store')
    || $programResult->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('Reviewed program correction, protected retry or response privacy failed.');
}
$sis->access->staff = true;
