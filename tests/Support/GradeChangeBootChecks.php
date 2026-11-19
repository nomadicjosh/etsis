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
$unitMoney = $sis->financialEvents();
$review = $teachingPost($base . "/review/", [
    "student_id" => $student->id->value,
    "enrollment_id" => $enrollment->id->value,
    "grade_id" => $grades["A"],
    "version" => 1,
    "attempted_milli" => 4000,
    "reason" => "Fictional marking correction.",
]);
$body = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $review->getStatusCode() !== 200 || ($body["requires_approval"] ?? null) !== true ||
    ($body["credit_unit_summary"]["completed_milli"] ?? null) !== 4000
) {
    throw new RuntimeException("The actual grading route bypassed approval policy.");
}
$submitted = $teachingPost($base . "/commit/", [
    "student_id" => $student->id->value,
    "review_token" => $body["review_token"],
]);
$receipt = json_decode((string) $submitted->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $submitted->getStatusCode() !== 200 ||
    $sis->sql->rows("sis_academic_credits")[0]["grade_id"] !== $grades["B"] ||
    $sis->sql->rows("sis_academic_credits")[0]["attempted_milli"] !== 3000
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
if (
    $teachingPost("/sis/grade-changes/" . $id . "/review/", [
    "outcome" => "approved", "reason" => "Fictional review without the independent credit duty.",
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Ordinary grade approval granted credit-unit correction authority.");
}
$sis->access->grants[] = "sis:academic:credits:amend";
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
    $sis->sql->rows("sis_academic_credits")[0]["grade_id"] !== $grades["A"] ||
    $sis->sql->rows("sis_academic_credits")[0]["attempted_milli"] !== 4000 ||
    $sis->sql->rows("sis_academic_credits")[0]["completed_milli"] !== 4000 ||
    $sis->sql->rows("sis_academic_credits")[0]["grade_points_milli"] !== 16000 ||
    $unitMoney !== $sis->financialEvents()
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
    "enrollment_id" => $enrollment->id->value,
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
// Source corrections share the same withdrawn record and existing private, CSRF-protected routes.
$sourceGrantsBefore = $sis->access->grants;
$sis->access->staff = true;
$sourcePublication = $sis->publishCourse($section->courseId);
$sis->access->staff = false;
$sis->access->grants = ["sis:gradebook:amend", "sis:student:read", "sis:gradebook:closed"];
$sourceBase = "/sis/sections/" . $section->id->value . "/students/" . $student->id->value;
foreach (["course-publication", "academic-dates"] as $sourceKind) {
    $sourceCredit = $sis->sql->one("sis_academic_credits", ["source_id" => $enrollment->id->value]);
    $sourceData = [
        "enrollment_id" => $enrollment->id->value,
        "version" => $sourceCredit["version"],
        "reason" => "Fictional withdrawn source evidence.",
    ];
    if ($sourceKind === "course-publication") {
        $sourceData += ["course_version_id" => $sourcePublication->value, "effective_on" => "2026-09-02"];
    } else {
        $sourceData += ["academic_on" => "2026-08-01", "completed_on" => null];
        if (
            $teachingPost(
                $sourceBase . "/academic-dates/review/",
                array_replace($sourceData, [
                    "completed_on" => "2026-08-30",
                ]),
            )->getStatusCode() !== 422
        ) {
            throw new RuntimeException("A withdrawn record was marked completed through the date route.");
        }
    }
    $sourcePath = $sourceBase . "/" . $sourceKind . "/";
    $unprotectedSource = new \Qubus\Http\ServerRequest(
        [],
        [],
        "https://campus.example.invalid" . $sourcePath . "review/",
        "POST",
    );
    if ($app->router->match($unprotectedSource->withParsedBody($sourceData))->getStatusCode() !== 403) {
        throw new RuntimeException("A withdrawn source review bypassed CSRF.");
    }
    $sis->access->grants = ["sis:gradebook:amend", "sis:student:read"];
    if ($teachingPost($sourcePath . "review/", $sourceData)->getStatusCode() !== 403) {
        throw new RuntimeException("The source review bypassed closed-record authority.");
    }
    $sis->access->grants[] = "sis:gradebook:closed";
    $sourceResponse = $teachingPost($sourcePath . "review/", $sourceData);
    $sourceReview = json_decode((string) $sourceResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $sourceResponse->getStatusCode() !== 200 ||
        !isset($sourceReview["review_token"]) ||
        !str_contains($sourceResponse->getHeaderLine("Cache-Control"), "no-store")
    ) {
        throw new RuntimeException("The withdrawn source review failed its private provider boundary.");
    }
    $sourceCommit = ["review_token" => $sourceReview["review_token"]];
    $unprotectedCommit = new \Qubus\Http\ServerRequest(
        [],
        [],
        "https://campus.example.invalid" . $sourcePath . "commit/",
        "POST",
    );
    if ($app->router->match($unprotectedCommit->withParsedBody($sourceCommit))->getStatusCode() !== 403) {
        throw new RuntimeException("A withdrawn source confirmation bypassed CSRF.");
    }
    $sis->access->grants = ["sis:gradebook:amend", "sis:student:read"];
    if ($teachingPost($sourcePath . "commit/", $sourceCommit)->getStatusCode() !== 403) {
        throw new RuntimeException("Source confirmation ignored revoked closed-record authority.");
    }
    $sis->access->grants[] = "sis:gradebook:closed";
    $sourceSaved = $teachingPost($sourcePath . "commit/", $sourceCommit);
    $sourceRetry = $teachingPost($sourcePath . "commit/", $sourceCommit);
    if (
        $sourceSaved->getStatusCode() !== 200 ||
        $sourceRetry->getStatusCode() !== 200 ||
        (string) $sourceSaved->getBody() !== (string) $sourceRetry->getBody() ||
        !str_contains($sourceSaved->getHeaderLine("Cache-Control"), "no-store") ||
        $preserved !== [$sis->financialEvents(), $sis->sql->rows("sis_enrollments")]
    ) {
        throw new RuntimeException(
            "The withdrawn source confirmation changed protected facts or its retry result.",
        );
    }
}
$sis->access->grants = $sourceGrantsBefore;
// Older withdrawn projections may have a W marker without a retained definition.
$sis->access->staff = true;
$student = $sis->student($sis->user());
$section = $sis->section();
$enrollmentId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$creditId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$sis->sql->insert("sis_enrollments", [
    "id" => $enrollmentId,
    "student_id" => $student->id->value,
    "section_id" => $section->id->value,
    "term_id" => $sis->term->value,
    "status" => "W",
    "registered_at" => "2026-08-01T17:00:00.000000Z",
    "status_at" => "2026-09-02T17:00:00.000000Z",
    "version" => 3,
]);
$sis->sql->insert("sis_academic_credits", [
    "id" => $creditId,
    "student_id" => $student->id->value,
    "course_id" => $section->courseId->value,
    "term_id" => $sis->term->value,
    "source_id" => $enrollmentId,
    "credit_type" => "institutional",
    "course_code" => "TEST.101",
    "course_title" => "Fictional earlier withdrawal",
    "attempted_milli" => 3000,
    "completed_milli" => 1000,
    "grade_points_milli" => 9999,
    "grade_id" => null,
    "status" => "W",
    "version" => 4,
]);
$sis->access->staff = false;
$preserved = [$sis->financialEvents(), $sis->sql->rows("sis_enrollments")];
$base = "/sis/sections/" . $section->id->value . "/final-grades";
$data = [
    "student_id" => $student->id->value,
    "enrollment_id" => $enrollmentId,
    "grade_id" => $grades["B"],
    "version" => 4,
    "reason" => "Fictional institutional records establish B; earlier definition unknown.",
];
if ($teachingPost($base . "/review/", $data)->getStatusCode() !== 422) {
    throw new RuntimeException("Missing withdrawal evidence confirmation was not required.");
}
$data["grade_evidence"] = true;
if ($teachingPost($base . "/review/", $data)->getStatusCode() !== 422) {
    throw new RuntimeException("Grade evidence silently authorized credit-quantity reconciliation.");
}
$data["reconcile_withdrawal_quantities"] = true;
$review = $teachingPost($base . "/review/", $data);
$body = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $review->getStatusCode() !== 200 ||
    ($body["missing_withdrawal_grade"] ?? null) !== true ||
    ($body["withdrawal_quantity_reconciliation"] ?? null) !== true ||
    ($body["withdrawal_quantities"]["expected_grade_points_milli"] ?? null) !== 9000 ||
    ($body["requires_approval"] ?? null) !== true
) {
    throw new RuntimeException("The real route did not preserve missing-grade scope and approval policy.");
}
$submitted = $teachingPost($base . "/commit/", [
    "student_id" => $student->id->value,
    "review_token" => $body["review_token"],
]);
$receipt = json_decode((string) $submitted->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $submitted->getStatusCode() !== 200 ||
    $sis->sql->one("sis_academic_credits", ["id" => $creditId])["grade_id"] !== null ||
    $sis->sql->one("sis_academic_credits", ["id" => $creditId])["completed_milli"] !== 1000
) {
    throw new RuntimeException("Missing-grade submission bypassed independent approval.");
}
$id = $receipt["operation_id"];
$sis->access->actor = $sis->user();
$review = $teachingPost("/sis/grade-changes/" . $id . "/review/", [
    "outcome" => "approved",
    "reason" => "Fictional independent verification of retained evidence.",
]);
$body = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $review->getStatusCode() !== 200 ||
    $teachingPost("/sis/grade-changes/" . $id . "/commit/", [
        "review_token" => $body["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("The real missing-grade approval failed.");
}
$credit = $sis->sql->one("sis_academic_credits", ["id" => $creditId]);
if (
    $credit["grade_id"] !== $grades["B"] ||
    $credit["status"] !== "W" ||
    $credit["completed_milli"] !== 0 ||
    $credit["grade_points_milli"] !== 9000 ||
    $preserved !== [$sis->financialEvents(), $sis->sql->rows("sis_enrollments")]
) {
    throw new RuntimeException("Resolving the missing grade changed protected withdrawal facts.");
}
$sis->access->grants = [];
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
if ($teachingPost("/sis/grade-changes/", [])->getStatusCode() !== 403) {
    throw new RuntimeException("Student ownership exposed the protected registrar worklist.");
}
