<?php

declare(strict_types=1);

$student = $sis->student($sis->user());
$section = $sis->section();
$enrollment = $sis->service->register($student->id, $section->id);
$base =
"/sis/students/" .
$student->id->value .
"/sections/" .
$section->id->value .
"/enrollments/" .
$enrollment->id->value .
"/registered-credits/";
$send = static function (
    string $suffix,
    array $data = [],
    int $status = 200
) use (
    $teachingPost,
    $base,
): array {
    $response = $teachingPost($base . $suffix . "/", $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException(
            "Registered-credit route expected " . $status . ", got " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$view = $send("view");
$body = [
    "expected_version" => $view["expected_version"],
    "expected_position" => $view["expected_position"],
    "registered_milli" => 4500,
    "reason" => "Fictional institutional registration evidence.",
    "acknowledged" => true,
];
$before = [$sis->sql->rows("sis_enrollments"), $sis->sql->rows("sis_student_accounts")];
$send("review", array_replace($body, ["acknowledged" => false]), 422);
$r = $send("review", $body);
$confirm = array_intersect_key(
    $r,
    array_flip([
        "operation_id",
        "expected_version",
        "expected_position",
        "previous_registered_milli",
        "registered_milli",
        "reason",
        "acknowledged",
        "review_token",
    ]),
);
$original = $send("confirm", $confirm);
$events = $sis->sql->rows("sis_history_events");
if ($original !== $send("confirm", $confirm) || $events !== $sis->sql->rows("sis_history_events")) {
    throw new RuntimeException("A correction retry duplicated a decision.");
}
$after = $send("view");
if (
    $after["original_registered_milli"] !== 3000 ||
    $after["current_registered_milli"] !== 4500 ||
    $after["awarded_attempted_milli"] !== 3000
) {
    throw new RuntimeException("Registered and awarded quantities were conflated.");
}
if ($before !== [$sis->sql->rows("sis_enrollments"), $sis->sql->rows("sis_student_accounts")]) {
    throw new RuntimeException("Registered-credit correction changed enrollment or billing.");
}
$history = $send("history");
if (
    count($history["items"]) !== 1 ||
    isset($history["items"][0]["reason"]) ||
    isset($history["items"][0]["actor_id"])
) {
    throw new RuntimeException("Correction history lost its curated boundary.");
}
$pursuit = $sis->sql->rows("sis_student_programs", ["student_id" => $student->id->value], 1)[0][
"id"
];
$path = "/sis/students/" . $student->id->value . "/programs/" . $pursuit . "/load/review/";
$response = $teachingPost($path, ["term_id" => $sis->term->value]);
$load = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $response->getStatusCode() !== 200 ||
    $load["credits_milli"] !== 4500 ||
    $load["items"][0]["enrollment_id"] !== $enrollment->id->value
) {
    throw new RuntimeException(
        "The staff load review lost corrected quantities or its source handoff.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read", "sis:registration:manage", "sis:gradebook:amend"];
$send("view", [], 403);
$send("confirm", $confirm, 403);
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
$sis->access->grants = [];
$ownerResponse = $teachingPost(
    "/portal/api/students/" . $student->id->value . "/programs/" . $pursuit . "/load/review/",
    ["term_id" => $sis->term->value],
);
$owner = json_decode((string) $ownerResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $ownerResponse->getStatusCode() !== 200 ||
    $owner["credits_milli"] !== 4500 ||
    isset($owner["items"][0]["enrollment_id"]) ||
    isset($owner["items"][0]["section_id"])
) {
    throw new RuntimeException(
        "The owner load response exposed staff routing or lost corrected quantities.",
    );
}
$send("view", [], 403);
echo "Registered-credit route checks passed.\n";
