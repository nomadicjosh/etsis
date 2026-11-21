<?php

declare(strict_types=1);

// Real providers and existing private report/population transport, using fictional check-ins only.
$followupDefinition = [
    "dataset" => "advising_followups",
    "columns" => [
        ["field" => "held_on", "label" => "Held on", "aggregate" => "none"],
        ["field" => "followup_status", "label" => "Status", "aggregate" => "none"],
    ],
    "filters" => [
        "condition" => "AND",
        "rules" => [
            ["id" => "student_id", "operator" => "equal", "value" => $advisingStudent->id->value],
            ["id" => "followup_status", "operator" => "equal", "value" => "closed"],
        ],
    ],
];
$followupPreview = $checkInPost("/sis/report-studio/preview/", [
    "definition" => $followupDefinition,
    "offset" => 0,
]);
if (
    count($followupPreview["items"]) !== 1 ||
    ($followupPreview["items"][0]["c1"] ?? null) !== "closed"
) {
    throw new RuntimeException("Advising reporting did not use the current minimized metadata.");
}
$followupGraph = $checkInPost("/sis/report-studio/graph/", [
    "dataset" => "advising_followups",
    "aliases" => [],
]);
foreach (
    [
        "private_note",
        "next_step",
        "response",
        "resolution",
        "sealed_data",
        "owner_user_id",
        "actor_id",
    ] as $forbidden
) {
    if (isset($followupGraph["dataset"]["fields"][$forbidden])) {
        throw new RuntimeException(
            "The advising report graph exposed a private conversation field.",
        );
    }
}
$followupId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$followupSaved = $teachingPost("/sis/report-studio/save/", [
    "report_id" => $followupId,
    "operation_id" => $followupId,
    "version" => -1,
    "name" => "Fictional follow-up report",
    "definition" => $followupDefinition,
]);
if ($followupSaved->getStatusCode() !== 201) {
    throw new RuntimeException("The protected advising report could not be saved.");
}
$followupPath = "/sis/report-studio/" . $followupId . "/population/";
$followupPopulation = $checkInPost($followupPath, ["offset" => 0, "action" => "messaging"]);
if (array_column($followupPopulation["items"], "id") !== [$advisingStudent->id->value]) {
    throw new RuntimeException("Advising messaging lost its current distinct student population.");
}
foreach (["billing", "registration"] as $forbiddenAction) {
    if (
        $teachingPost($followupPath, [
            "offset" => 0,
            "action" => $forbiddenAction,
        ])->getStatusCode() !== 422
    ) {
        throw new RuntimeException(
            "Advising follow-up widened a consequential population handoff.",
        );
    }
}
$sis->access->staff = false;
$sis->access->grants = ["sis:reporting:run", "sis:student:read"];
if (
    $teachingPost("/sis/report-studio/preview/", [
        "definition" => $followupDefinition,
        "offset" => 0,
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Student-read alone granted advising reporting.");
}
$sis->access->staff = true;
$sis->access->grants = [];
