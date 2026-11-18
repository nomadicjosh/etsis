<?php

declare(strict_types=1);

$sis->gradeScale([
    [
        "code" => "A",
        "min_score_milli" => 90000,
        "max_score_milli" => 100000,
        "points_milli" => 4000,
        "passes" => true,
        "counts_gpa" => true,
    ],
    [
        "code" => "B",
        "min_score_milli" => 80000,
        "max_score_milli" => 89999,
        "points_milli" => 3000,
        "passes" => true,
        "counts_gpa" => true,
    ],
    [
        "code" => "W",
        "min_score_milli" => null,
        "max_score_milli" => null,
        "points_milli" => 0,
        "passes" => false,
        "counts_gpa" => false,
    ],
]);
$student = $sis->student($sis->user());
$section = $sis->section(faculty: $sis->access->actor);
$enrollment = $sis->service->register($student->id, $section->id, true);
$ledger = $sis->academicCredits();
$grades = array_column($ledger->choices($student->id, $section->id, $enrollment->id)["items"], "id", "code");
$initial = $ledger->review(
    $student->id,
    $section->id,
    $enrollment->id,
    new \Domain\Shared\ValueObject\EntityId($grades["B"]),
    0,
);
$ledger->commit($student->id, $section->id, $initial["review_token"]);
$policy = $teachingPost("/sis/grade-change-policy/", ["mode" => "approval", "revision" => 0]);
if ($policy->getStatusCode() !== 200) {
    throw new RuntimeException("The real grade-change policy route failed.");
}
$base = "/sis/sections/" . $section->id->value . "/final-grades";
$review = $teachingPost($base . "/review/", [
    "student_id" => $student->id->value,
    "grade_id" => $grades["A"],
    "version" => 1,
    "reason" => "Fictional marking correction.",
]);
$body = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($review->getStatusCode() !== 200 || ($body["requires_approval"] ?? null) !== true) {
    throw new RuntimeException("The actual grading route bypassed approval policy.");
}
$submitted = $teachingPost($base . "/commit/", [
    "student_id" => $student->id->value,
    "review_token" => $body["review_token"],
]);
$receipt = json_decode((string) $submitted->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $submitted->getStatusCode() !== 200 ||
    $sis->sql->rows("sis_academic_credits")[0]["grade_id"] !== $grades["B"]
) {
    throw new RuntimeException("Submitting a correction changed the grade before independent approval.");
}
$id = $receipt["operation_id"];
if (
    $teachingPost("/sis/grade-changes/" . $id . "/review/", [
        "outcome" => "approved",
        "reason" => "Self review.",
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("The browser route allowed self approval.");
}
$sis->access->actor = $sis->user();
$sis->access->staff = false;
$sis->access->grants = ["sis:gradebook:amend", "sis:student:read"];
if (
    $teachingPost("/sis/grade-changes/" . $id . "/review/", [
        "outcome" => "approved",
        "reason" => "Needs approval duty.",
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Grade amendment implied independent approval.");
}
$sis->access->grants[] = "sis:gradebook:approve";
$review = $teachingPost("/sis/grade-changes/" . $id . "/review/", [
    "outcome" => "approved",
    "reason" => "Fictional independent review.",
]);
$body = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $review->getStatusCode() !== 200 ||
    $teachingPost("/sis/grade-changes/" . $id . "/commit/", [
        "review_token" => $body["review_token"],
    ])->getStatusCode() !== 200 ||
    $sis->sql->rows("sis_academic_credits")[0]["grade_id"] !== $grades["A"]
) {
    throw new RuntimeException("The actual grade approval route did not post the reviewed correction.");
}
// The same real routes handle a retained late-withdrawal grade without reopening it.
$sis->access->staff = true;
$student = $sis->student($sis->user());
$section = $sis->section(faculty: $sis->access->actor);
$enrollment = $sis->service->register($student->id, $section->id, true);
$base = "/sis/sections/" . $section->id->value . "/final-grades";
$sis->publishDropPolicy(
    $sis->term,
    -1,
    new \Domain\Registration\ValueObject\DropWindows([["through" => null, "grade_code" => "W"]]),
);
$sis->clock->time = new DateTimeImmutable("2026-09-02T12:00:00-07:00");
$sis->service->drop($student->id, $section->id);
$preserved = [$sis->financialEvents(), $sis->sql->rows("sis_enrollments")];
$sis->access->staff = false;
$data = [
    "student_id" => $student->id->value,
    "grade_id" => $grades["B"],
    "version" => $sis->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value])["version"],
    "reason" => "Fictional withdrawn-grade review.",
];
if ($teachingPost($base . "/review/", $data)->getStatusCode() !== 403) {
    throw new RuntimeException("Ordinary grading duties permitted a withdrawn-grade correction.");
}
$sis->access->grants[] = "sis:gradebook:closed";
$review = $teachingPost($base . "/review/", $data);
$body = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($review->getStatusCode() !== 200 || ($body["withdrawal_grade_correction"] ?? null) !== true) {
    throw new RuntimeException("The real grade route did not identify a withdrawn-grade correction.");
}
$submitted = $teachingPost($base . "/commit/", [
    "student_id" => $student->id->value,
    "review_token" => $body["review_token"],
]);
$receipt = json_decode((string) $submitted->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($submitted->getStatusCode() !== 200) {
    throw new RuntimeException("The real grade route failed to request the withdrawn-grade correction.");
}
$sis->access->actor = $sis->user();
$id = $receipt["operation_id"];
$review = $teachingPost("/sis/grade-changes/" . $id . "/review/", [
    "outcome" => "approved",
    "reason" => "Fictional independent withdrawn-grade review.",
]);
$body = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $review->getStatusCode() !== 200 ||
    $teachingPost("/sis/grade-changes/" . $id . "/commit/", [
        "review_token" => $body["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("The actual independent withdrawn-grade approval failed.");
}
$credit = $sis->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
if (
    $credit["grade_id"] !== $grades["B"] ||
    $credit["status"] !== "W" ||
    $credit["completed_milli"] !== 0 ||
    $preserved !== [$sis->financialEvents(), $sis->sql->rows("sis_enrollments")]
) {
    throw new RuntimeException("The browser workflow changed protected withdrawal facts.");
}
$sis->access->grants = [];
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
if ($teachingPost("/sis/grade-changes/", [])->getStatusCode() !== 403) {
    throw new RuntimeException("Student ownership exposed the protected registrar worklist.");
}
