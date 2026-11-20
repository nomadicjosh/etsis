<?php

declare(strict_types=1);

// Reuse the connected advising fixture, including a real current pursuit and its assigned advisor.
require __DIR__ . "/AdvisingBootChecks.php";
$standingBase =
"/sis/students/" . $advisingStudent->id->value . "/programs/" . $advisingPursuit . "/standing/";
$standingDefinitionResponse = $teachingPost("/sis/academic-standing/save/", [
    "id" => null,
    "version" => null,
    "code" => "GOOD",
    "title" => "Fictional good standing",
    "active" => true,
]);
$standingDefinition = json_decode(
    (string) $standingDefinitionResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if ($standingDefinitionResponse->getStatusCode() !== 200 || !isset($standingDefinition["id"])) {
    throw new RuntimeException("Standing definition provider or validation failed.");
}
$standingDraftResponse = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $standingBase)),
);
$standingDraft = json_decode((string) $standingDraftResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($standingDraftResponse->getStatusCode() !== 200 || !($standingDraft["editable"] ?? false)) {
    throw new RuntimeException("Standing editor provider did not resolve.");
}
if (
    $teachingPost($standingBase . "review/", [
        "revision" => $standingDraft["revision"],
        "reason" => "Fictional missing rows.",
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException("Standing review requires explicit periods.");
}
$standingRow = [
    "id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "definition_id" => $standingDefinition["id"],
    "code" => "GOOD",
    "title" => "Fictional good standing",
    "starts_on" => "2026-08-01",
    "ends_on" => null,
    "policy_reference" => "Fictional private handbook",
];
$standingReviewResponse = $teachingPost($standingBase . "review/", [
    "rows" => [$standingRow],
    "revision" => $standingDraft["revision"],
    "reason" => "Fictional private registrar reason.",
]);
$standingProof = json_decode((string) $standingReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($standingReviewResponse->getStatusCode() !== 200) {
    throw new RuntimeException("Standing review failed.");
}
$standingCommand = array_intersect_key(
    $standingProof,
    array_flip(["rows", "revision", "reason", "operation_id", "review_token"]),
);
$standingSaved = $teachingPost($standingBase . "commit/", $standingCommand);
if (
    $standingSaved->getStatusCode() !== 200 ||
    (string) $standingSaved->getBody() !==
        (string) $teachingPost($standingBase . "commit/", $standingCommand)->getBody()
) {
    throw new RuntimeException("Standing acceptance or original receipt failed.");
}
$standingOwnerPath =
"/portal/api/students/" . $advisingStudent->id->value . "/programs/" . $advisingPursuit . "/standing/";
$standingAdvisorPath =
"/portal/api/advising/students/" .
$advisingStudent->id->value .
"/programs/" .
$advisingPursuit .
"/standing/";
$standingEvents = $sis->sql->rows("sis_history_events");
$sis->access->actor = $advisingPerson->ownerUserId;
$sis->access->staff = false;
$standingAdvisorRead = $teachingPost($standingAdvisorPath, []);
$standingRead = json_decode((string) $standingAdvisorRead->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $standingAdvisorRead->getStatusCode() !== 200 ||
    count($standingRead["items"] ?? []) !== 1 ||
    array_keys($standingRead["items"][0]) !== ["code", "title", "starts_on", "ends_on"] ||
    $teachingPost($standingOwnerPath, [])->getStatusCode() !== 403 ||
    $teachingPost($standingBase . "commit/", $standingCommand)->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Standing advisor scope widened owner/editing access or exposed private evidence.",
    );
}
$sis->access->actor = $sis->admissions->person($advisingStudent->personId)->ownerUserId;
if ($teachingPost($standingOwnerPath, [])->getStatusCode() !== 200) {
    throw new RuntimeException("The student cannot read their own standing.");
}
$sis->access->actor = $sis->user();
if (
    $teachingPost($standingOwnerPath, [])->getStatusCode() !== 403 ||
    $teachingPost($standingAdvisorPath, [])->getStatusCode() !== 403 ||
    $standingEvents !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException("Standing private reads bypassed scope or changed academic history.");
}
