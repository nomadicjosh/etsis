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

$loadDetermination = $loadPost('/sis' . $loadBase . 'record-review/', [
    'term_id' => $sis->term->value,
    'policy_selection' => null,
    'reason' => 'Fictional retained current load.',
]);
$loadInstruction = $loadDetermination + ['term_id' => $sis->term->value];
$loadAccepted = $loadPost('/sis' . $loadBase . 'record-confirm/', $loadInstruction);
if ($loadAccepted !== $loadPost('/sis' . $loadBase . 'record-confirm/', $loadInstruction)) {
    throw new RuntimeException('The load determination lost its original confirmation receipt.');
}
$loadStaffRecords = $loadPost('/sis' . $loadBase . 'determinations/', ['term_id' => $sis->term->value]);
if (count($loadStaffRecords['items']) !== 1 || $loadStaffRecords['items'][0]['credits_milli'] !== 3000) {
    throw new RuntimeException('The retained load did not resolve through the real providers.');
}
$sis->access->staff = false;
$sis->access->actor = $advisingPerson->ownerUserId;
$loadPost('/portal/api/advising' . $loadBase . 'determinations/', ['term_id' => $sis->term->value]);
$sis->access->actor = $sis->admissions->person($advisingStudent->personId)->ownerUserId;
$loadOwnerRecords = $loadPost('/portal/api' . $loadBase . 'determinations/', ['term_id' => $sis->term->value]);
$loadPost('/portal/api' . $loadBase . 'terms/', ['for_records' => true]);
foreach (['reason', 'actor_id', 'enrollment_id', 'credit_id', 'version_id'] as $privateField) {
    if (str_contains(json_encode($loadOwnerRecords), $privateField)) {
        throw new RuntimeException('Private source evidence escaped the retained-load reader.');
    }
}
if ($teachingPost('/sis' . $loadBase . 'record-confirm/', $loadInstruction)->getStatusCode() !== 403) {
    throw new RuntimeException('Owner access widened registrar confirmation authority.');
}
$sis->access->actor = $sis->user();
if (
    $teachingPost('/portal/api' . $loadBase . 'determinations/', [
        'term_id' => $sis->term->value,
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException('Another account read private load determinations.');
}
$sis->access->actor = $advisingActor;
$sis->access->staff = true;

// Historical selection is an explicit student-scoped policy duty, never an audit-note reader.
$choices = $loadPost('/sis' . $loadBase . 'policy-choices/', ['source' => 'global', 'cursor' => null]);
if (
    count($choices['items']) !== 1 ||
    array_keys($choices['items'][0]) !== ['version_id', 'publication_version', 'published_at', 'definition']
) {
    throw new RuntimeException('The publication chooser was not minimized or provider-wired.');
}
$selected = ['source' => 'global', 'version_id' => $choices['items'][0]['version_id']];
foreach (
    [
    ['term_id' => $sis->term->value, 'reason' => 'Fictional missing selection.'],
    ['term_id' => $sis->term->value, 'reason' => 'Fictional malformed selection.', 'policy_selection' => []],
    ['term_id' => $sis->term->value, 'reason' => 'Fictional invalid identifier.',
        'policy_selection' => ['source' => 'global', 'version_id' => 'malformed']],
    ] as $invalid
) {
    if ($teachingPost('/sis' . $loadBase . 'record-review/', $invalid)->getStatusCode() !== 422) {
        throw new RuntimeException('Determination review accepted an implicit or malformed policy choice.');
    }
}
$selectedReview = $loadPost('/sis' . $loadBase . 'record-review/', [
    'term_id' => $sis->term->value, 'reason' => 'Fictional institution-authorized publication.',
    'policy_selection' => $selected,
]);
$selectedInstruction = $selectedReview + ['term_id' => $sis->term->value];
$sis->access->staff = false;
$sis->access->grants = ['sis:student:read', 'sis:load:determine'];
if (
    $teachingPost('/sis' . $loadBase . 'policy-choices/', ['source' => 'global'])->getStatusCode() !== 403 ||
    $teachingPost('/sis' . $loadBase . 'record-confirm/', $selectedInstruction)->getStatusCode() !== 403
) {
    throw new RuntimeException('Historical policy choice bypassed independent policy authority.');
}
$sis->access->grants[] = 'sis:load:policy';
$selectedReceipt = $loadPost('/sis' . $loadBase . 'record-confirm/', $selectedInstruction);
if ($selectedReceipt !== $loadPost('/sis' . $loadBase . 'record-confirm/', $selectedInstruction)) {
    throw new RuntimeException('Historical selection lost its original receipt.');
}
$changedInstruction = array_replace($selectedInstruction, ['policy_selection' => null]);
if ($teachingPost('/sis' . $loadBase . 'record-confirm/', $changedInstruction)->getStatusCode() !== 403) {
    throw new RuntimeException('An accepted determination allowed its selection basis to change.');
}
$sis->access->actor = $sis->admissions->person($advisingStudent->personId)->ownerUserId;
$sis->access->grants = [];
$ownerSelected = $loadPost('/portal/api' . $loadBase . 'determinations/', ['term_id' => $sis->term->value]);
if ($ownerSelected['items'][0]['policy']['basis'] !== 'selected_publication') {
    throw new RuntimeException('The owner could not see the retained selection basis.');
}
if ($teachingPost('/sis' . $loadBase . 'policy-choices/', ['source' => 'global'])->getStatusCode() !== 403) {
    throw new RuntimeException('An owner opened the protected policy chooser.');
}
$sis->access->actor = $advisingActor;
$sis->access->staff = true;
