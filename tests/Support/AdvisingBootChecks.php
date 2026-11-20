<?php

declare(strict_types=1);

// Fictional SQLite data through real providers, router, private transport and framework CSRF.
$advisingStudent = $sis->student();
$advisingPursuit = $sis->sql->rows(
    "sis_student_programs",
    ["student_id" => $advisingStudent->id->value],
    1,
)[0]["id"];
$advisingPerson = $sis->legacyPerson($sis->user(), "Fictional", "Advisor");
$sis->sql->insert("sis_employees", [
    "id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "person_id" => $advisingPerson->id->value,
    "job_title" => "Fictional advisor",
    "active" => 1,
]);
$advisingBase =
"/sis/students/" . $advisingStudent->id->value . "/programs/" . $advisingPursuit . "/advisors/";
$advisingDraftResponse = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $advisingBase)),
);
$advisingDraft = json_decode((string) $advisingDraftResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($advisingDraftResponse->getStatusCode() !== 200 || !($advisingDraft["editable"] ?? false)) {
    throw new RuntimeException("The current-pursuit advising provider did not resolve.");
}
$advisingCandidates = $teachingPost("/sis/advisors/candidates/", ["search" => ""]);
if (
    $advisingCandidates->getStatusCode() !== 200 ||
    $teachingPost($advisingBase . "review/", [
        "revision" => $advisingDraft["revision"],
        "reason" => "Fictional missing choices.",
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException("Advising candidate search or explicit choices were rejected incorrectly.");
}
$advisingReview = $teachingPost($advisingBase . "review/", [
    "rows" => [
        [
            "id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
            "advisor_person_id" => $advisingPerson->id->value,
            "role" => "primary",
            "starts_on" => "2026-01-01",
            "ends_on" => null,
        ],
    ],
    "revision" => $advisingDraft["revision"],
    "reason" => "Fictional registrar assignment.",
]);
$advisingProof = json_decode((string) $advisingReview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($advisingReview->getStatusCode() !== 200) {
    throw new RuntimeException("Advising review failed.");
}
$overlap = $advisingProof['rows'];
$overlap[] = array_replace($overlap[0], ['id' => \Domain\Shared\ValueObject\EntityId::generate()->value]);
$overlapResponse = $teachingPost($advisingBase . 'review/', [
    'rows' => $overlap, 'revision' => $advisingDraft['revision'], 'reason' => 'Fictional overlap check.',
]);
if (
    $overlapResponse->getStatusCode() !== 422 ||
    !str_contains((string) $overlapResponse->getBody(), 'Primary advisors')
) {
    throw new RuntimeException('Advising overlap validation lost its actionable field message.');
}
$advisingCommand = array_intersect_key(
    $advisingProof,
    array_flip(["rows", "revision", "reason", "operation_id", "review_token"]),
);
$advisingDone = $teachingPost($advisingBase . "commit/", $advisingCommand);
if (
    $advisingDone->getStatusCode() !== 200 ||
    (string) $teachingPost($advisingBase . "commit/", $advisingCommand)->getBody() !==
        (string) $advisingDone->getBody()
) {
    throw new RuntimeException("Advising confirmation or its original receipt failed.");
}
$advisingEvents = $sis->sql->rows("sis_history_events");
$advisingActor = $sis->access->actor;
$sis->access->actor = $advisingPerson->ownerUserId;
$sis->access->staff = false;
$advisingList = $teachingPost("/portal/api/advising/", ["search" => ""]);
$advisingListData = json_decode((string) $advisingList->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($advisingList->getStatusCode() !== 200 || count($advisingListData["items"] ?? []) !== 1) {
    throw new RuntimeException("An assigned advisor could not find their current advisee.");
}
$advisingProgressUrl =
"/portal/api/advising/students/" .
$advisingStudent->id->value .
"/programs/" .
$advisingPursuit .
"/progress/";
$advisingProgress = $teachingPost($advisingProgressUrl, []);
$advisingProgressData = json_decode((string) $advisingProgress->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $advisingProgress->getStatusCode() !== 200 ||
    ($advisingProgressData["status"] ?? null) !== "needs_selection"
) {
    throw new RuntimeException("Assigned-advisor progress did not reach the canonical degree reader.");
}
if (
    $teachingPost(
        "/portal/api/students/" .
            $advisingStudent->id->value .
            "/programs/" .
            $advisingPursuit .
            "/advisors/",
        [],
    )->getStatusCode() !== 403 ||
    $teachingPost($advisingBase . "commit/", $advisingCommand)->getStatusCode() !== 403 ||
    $advisingEvents !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException("Scoped advising widened owner/editing authority or changed academic facts.");
}
$sis->access->actor = $sis->user();
if ($teachingPost($advisingProgressUrl, [])->getStatusCode() !== 403) {
    throw new RuntimeException("An unrelated user could read advisor degree progress.");
}
$sis->access->actor = $advisingActor;
$sis->access->staff = true;
