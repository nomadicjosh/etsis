<?php

declare(strict_types=1);

// Fictional SQLite data, real providers, private routes and framework CSRF.
require __DIR__ . "/AdvisingBootChecks.php";
$loadPost = static function (string $path, array $data) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store")
    ) {
        throw new RuntimeException(
            "The private enrollment-load route failed: " . $path . " status " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$loadPolicy = "/sis/enrollment-load/policy/";
$loadDraft = $loadPost($loadPolicy . "view/", ["program_id" => null]);
$loadBands = [
    "bands" => [
        ["code" => "PART", "label" => "Part time", "min_credits_milli" => 0],
        ["code" => "FULL", "label" => "Full time", "min_credits_milli" => 12000],
    ],
];
$loadInvalidInputs = [
    [],
    ["program_id" => null, "revision" => $loadDraft["revision"], "reason" => "Fictional missing bands."],
];
foreach ($loadInvalidInputs as $invalid) {
    if ($teachingPost($loadPolicy . "review/", $invalid)->getStatusCode() !== 422) {
        throw new RuntimeException("Policy clearing must require explicit scope and definition.");
    }
}
$loadProof = $loadPost($loadPolicy . "review/", [
    "program_id" => null,
    "definition" => $loadBands,
    "revision" => $loadDraft["revision"],
    "reason" => "Fictional credit bands.",
]);
$loadReceipt = $loadPost($loadPolicy . "publish/", $loadProof);
if ($loadReceipt !== $loadPost($loadPolicy . "publish/", $loadProof)) {
    throw new RuntimeException("Load publication lost its original receipt.");
}
$loadSection = $sis->section();
$sis->service->register($advisingStudent->id, $loadSection->id);
$loadBase = "/students/" . $advisingStudent->id->value . "/programs/" . $advisingPursuit . "/load/";
$loadEvents = $sis->sql->rows("sis_history_events");
$loadTerms = $loadPost("/sis" . $loadBase . "terms/", ["search" => ""]);
if (count($loadTerms["items"]) < 1) {
    throw new RuntimeException("The load term picker did not resolve.");
}
$loadReview = $loadPost("/sis" . $loadBase . "review/", ["term_id" => $sis->term->value]);
if (
    $loadReview["credits_milli"] !== 3000 ||
    $loadReview["classification"]["code"] !== "PART" ||
    $loadReview["official"]
) {
    throw new RuntimeException("The staff load review did not use accepted registration quantities.");
}
$sis->access->actor = $advisingPerson->ownerUserId;
$sis->access->staff = false;
$loadPost("/portal/api/advising" . $loadBase . "review/", ["term_id" => $sis->term->value]);
if (
    $teachingPost("/portal/api" . $loadBase . "review/", [
        "term_id" => $sis->term->value,
    ])->getStatusCode() !== 403 ||
    $teachingPost($loadPolicy . "view/", ["program_id" => null])->getStatusCode() !== 403
) {
    throw new RuntimeException("Advisor scope widened owner or policy authority.");
}
$sis->access->actor = $sis->admissions->person($advisingStudent->personId)->ownerUserId;
$loadPost("/portal/api" . $loadBase . "review/", ["term_id" => $sis->term->value]);
$sis->access->actor = $sis->user();
if (
    $teachingPost("/portal/api" . $loadBase . "review/", [
        "term_id" => $sis->term->value,
    ])->getStatusCode() !== 403 ||
    $teachingPost("/portal/api/advising" . $loadBase . "review/", [
        "term_id" => $sis->term->value,
    ])->getStatusCode() !== 403 ||
    $loadEvents !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException("Load review widened access or appended business events.");
}
$sis->access->actor = $advisingActor;
$sis->access->staff = true;
