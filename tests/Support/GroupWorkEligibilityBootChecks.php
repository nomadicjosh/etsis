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
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $sis->user();
if (
    $readEligibility()->getStatusCode() !== 403 ||
    $teachingPost($base . "save/", $save)->getStatusCode() !== 403
) {
    throw new RuntimeException("An unauthorized account acquired curriculum authoring access.");
}
