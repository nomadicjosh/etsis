<?php

declare(strict_types=1);

use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Shared\ValueObject\EntityId;

$id = EntityId::generate()->value;
$base = "/sis/course-groups/" . $id . "/";
$definition = CourseGroupDefinitionV2::blank("Fictional restricted coursework")->definition;
$policy = [
    "credit_origin" => "institutional",
    "completed_from" => "2020-01-01",
    "completed_through" => "2026-08-10",
    'completed_since_pursuit_start' => true,
];
$definition["work_eligibility"] = $policy;
$invalid = $definition;
$invalid["work_eligibility"]["completed_from"] = "2023-02-29";
if (
    $teachingPost("/sis/course-groups/create/", [
        "id" => $id,
        "definition" => $invalid,
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException(
        "Coursework eligibility accepted an invalid actual-completion boundary.",
    );
}
$before = $sis->sql->rows("sis_history_events");
$created = $teachingPost("/sis/course-groups/create/", ["id" => $id, "definition" => $definition]);
if ($created->getStatusCode() !== 201) {
    throw new RuntimeException("The real coursework eligibility draft route failed.");
}
$readEligibility = static function () use ($app, $get, $base): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($base)));
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Private coursework eligibility exposed caches or debugging.");
    }
    return $response;
};
$read = $readEligibility();
$current = json_decode((string) $read->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($read->getStatusCode() !== 200 || $current["definition"]["work_eligibility"] != $policy) {
    throw new RuntimeException("Coursework eligibility did not survive the private draft read.");
}
$definition["work_eligibility"]["credit_origin"] = "any";
$save = ["revision" => $current["revision"], "definition" => $definition];
if (
    $teachingPost($base . "save/", $save)->getStatusCode() !== 200 ||
    $teachingPost($base . "save/", [
        "revision" => $current["revision"],
        "definition" => $current["definition"],
    ])->getStatusCode() !== 409 ||
    $before !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "Eligibility working saves failed concurrency or wrote academic history.",
    );
}
$latest = json_decode((string) $readEligibility()->getBody(), true, flags: JSON_THROW_ON_ERROR);
$recency = $latest['definition'];
$recency['work_eligibility']['max_age_months_at_pursuit_start'] = 0;
$ageCommand = ['revision' => $latest['revision'], 'definition' => $recency];
if ($teachingPost($base . 'save/', $ageCommand)->getStatusCode() !== 422) {
    throw new RuntimeException('A zero-month course-age rule was accepted.');
}
$recency['work_eligibility']['max_age_months_at_pursuit_start'] = 60;
$ageCommand['definition'] = $recency;
if ($teachingPost($base . 'save/', $ageCommand)->getStatusCode() !== 200) {
    throw new RuntimeException('A valid course-age rule could not be saved through the real provider.');
}
$aged = json_decode((string) $readEligibility()->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $aged['definition']['work_eligibility']['max_age_months_at_pursuit_start'] !== 60
    || $before !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('The actual coursework age rule lost its policy or rewrote academic history.');
}
$source = ['id' => EntityId::generate()->value, 'code' => 'GROUP.EXT', 'name' => 'Fictional source college'];
$sis->sql->insert('sis_external_institutions', $source);
$directoryPath = '/sis/course-groups/transfer-institutions/';
$directory = $teachingPost($directoryPath, ['search' => 'GROUP.EXT']);
$choices = json_decode((string) $directory->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $directory->getStatusCode() !== 200 || $choices['items'] !== [$source]
    || !str_contains($directory->getHeaderLine('Cache-Control'), 'no-store')
    || $directory->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The private metadata-only sending-institution directory failed.');
}
$restricted = $aged['definition'];
$restricted['work_eligibility']['transfer_institutions'] = [$source];
$sourceSave = ['revision' => $aged['revision'], 'definition' => $restricted];
if ($teachingPost($base . 'save/', $sourceSave)->getStatusCode() !== 200) {
    throw new RuntimeException('The real course-group route failed to retain the source restriction.');
}
$expirationDraft = json_decode((string) $readEligibility()->getBody(), true, flags: JSON_THROW_ON_ERROR);
$expiration = $expirationDraft['definition'];
$expiration['work_eligibility']['max_age_months_at_evaluation'] = 0;
$expirationSave = ['revision' => $expirationDraft['revision'], 'definition' => $expiration];
if ($teachingPost($base . 'save/', $expirationSave)->getStatusCode() !== 422) {
    throw new RuntimeException('An invalid review-date expiration rule was accepted.');
}
$expirationSave['definition']['work_eligibility']['max_age_months_at_evaluation'] = 12;
if ($teachingPost($base . 'save/', $expirationSave)->getStatusCode() !== 200) {
    throw new RuntimeException('The actual course-group route failed to save review-date expiration.');
}
$expirationRead = json_decode((string) $readEligibility()->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $expirationRead['definition']['work_eligibility']['max_age_months_at_evaluation'] !== 12
    || $before !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('The saved expiration policy changed history or lost its duration.');
}
$cardDraft = $expirationRead['definition'];
$cardDraft['rules'] = [['id' => EntityId::generate()->value, 'mode' => 'pool', 'minimum_courses' => 0,
    'course_ids' => [], 'credit_origin' => 'institutional']];
$cardSave = ['revision' => $expirationRead['revision'], 'definition' => $cardDraft];
$invalidCard = $cardSave;
$invalidCard['definition']['rules'][0]['credit_origin'] = 'mixed';
if (
    $teachingPost($base . 'save/', $invalidCard)->getStatusCode() !== 422
    || $teachingPost($base . 'save/', $cardSave)->getStatusCode() !== 200
) {
    throw new RuntimeException('The actual group route failed requirement origin validation/save.');
}
$cardRead = json_decode((string) $readEligibility()->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $cardRead['definition']['rules'][0]['credit_origin'] !== 'institutional'
    || $before !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('Requirement origin lost its value or fabricated academic history.');
}
$gradeSave = ['revision' => $cardRead['revision'], 'definition' => $cardRead['definition']];
$gradeSave['definition']['rules'][0]['min_course_grade_points_milli'] = 4001;
if ($teachingPost($base . 'save/', $gradeSave)->getStatusCode() !== 422) {
    throw new RuntimeException('An above-scale requirement grade floor entered a draft.');
}
$gradeSave['definition']['rules'][0]['min_course_grade_points_milli'] = 2675;
if ($teachingPost($base . 'save/', $gradeSave)->getStatusCode() !== 200) {
    throw new RuntimeException('A valid requirement grade floor could not be saved.');
}
$gradeRead = json_decode((string) $readEligibility()->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $gradeRead['definition']['rules'][0]['min_course_grade_points_milli'] !== 2675
    || $before !== $sis->sql->rows('sis_history_events')
) {
    throw new RuntimeException('A requirement grade floor changed history or lost precision.');
}
foreach ([null, 0, 1, 'true', 'false'] as $invalidChoice) {
    $sinceStart = ['revision' => $gradeRead['revision'], 'definition' => $gradeRead['definition']];
    $sinceStart['definition']['work_eligibility']['completed_since_pursuit_start'] = $invalidChoice;
    if (
        $teachingPost($base . 'save/', $sinceStart)->getStatusCode() !== 422
        || $readEligibility()->getStatusCode() !== 200
        || $before !== $sis->sql->rows('sis_history_events')
    ) {
        throw new RuntimeException('A malformed pursuit completion choice changed a draft or academic history.');
    }
}
$sinceStart['definition']['work_eligibility']['completed_since_pursuit_start'] = false;
if ($teachingPost($base . 'save/', $sinceStart)->getStatusCode() !== 200) {
    throw new RuntimeException('The pursuit completion restriction could not be explicitly disabled.');
}
$disabled = json_decode((string) $readEligibility()->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($disabled['definition']['work_eligibility']['completed_since_pursuit_start'] !== false) {
    throw new RuntimeException('An explicit false pursuit completion rule was lost.');
}
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $sis->user();
if (
    $teachingPost($directoryPath, ['search' => 'GROUP.EXT'])->getStatusCode() !== 403 ||
    $readEligibility()->getStatusCode() !== 403 ||
    $teachingPost($base . "save/", $save)->getStatusCode() !== 403
) {
    throw new RuntimeException("An unauthorized account acquired curriculum authoring access.");
}
