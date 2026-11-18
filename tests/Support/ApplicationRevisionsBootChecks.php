<?php

declare(strict_types=1);

// Isolated fictional DB inherited from PortalBootChecks; never bootstrap the institutional database here.
$revisionOwner = $sis->user();
$revisionPerson = $sis->legacyPerson($revisionOwner, "Fictional", "Reviser");
$revisionForms = new \Domain\Admissions\Service\ApplicationFormService(
    new \Infrastructure\Persistence\Repository\SqlApplicationFormRepository(
        $sis->sql,
    ),
    $sis->admissionsService(),
    $sis->access,
    $sis->transactions,
    $sis->audit,
    $sis->submissionService(),
);
$revisionForm = $revisionForms->save(
    null,
    $sis->program,
    "Fictional correction form",
    [
        [
            "key" => "statement",
            "label" => "Statement",
            "type" => "textarea",
            "required" => true,
        ],
    ],
    null,
);
$revisionForms->publish($revisionForm->id, 0);
$revisionApplication = $app
    ->make(\Domain\Admissions\Service\ApplicationSubmissionService::class)
    ->submit(
        \Domain\Shared\ValueObject\EntityId::generate(),
        $revisionForm->id,
        $revisionPerson->id,
        $sis->term,
        ["statement" => "Original fictional answer."],
    );
$revisionStaffBase =
"/sis/applications/" . $revisionApplication->value . "/answers/";
$revisionOwnerBase =
"/portal/api/applications/" . $revisionApplication->value . "/answers/";
$sis->access->staff = false;
$sis->access->grants = ["sis:admissions:manage"];
if (
    $teachingPost($revisionStaffBase . "review/", [
        "action" => "request",
        "instructions" => "Please clarify this fictional answer.",
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Application management bypassed independent amendment authority.",
    );
}
$sis->access->grants[] = "sis:admissions:amend";
$revisionEvents = $sis->sql->rows("sis_history_events");
$revisionRequestResponse = $teachingPost($revisionStaffBase . "review/", [
    "action" => "request",
    "instructions" => "Please clarify this fictional answer.",
]);
$revisionRequest = json_decode(
    (string) $revisionRequestResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $revisionRequestResponse->getStatusCode() !== 200 ||
    !isset($revisionRequest["review_token"]) ||
    $teachingPost($revisionStaffBase . "commit/", [
        "review_token" => $revisionRequest["review_token"],
    ])->getStatusCode() !== 200 ||
    $revisionEvents !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "A working correction request failed or appended a domain event.",
    );
}
if (
    $app->router
        ->match(
            $get->withUri(
                new \Laminas\Diactoros\Uri(
                    "https://campus.example.invalid" . $revisionOwnerBase,
                ),
            ),
        )
        ->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Staff authority bypassed strict answer ownership.",
    );
}
$sis->access->actor = $revisionOwner;
$sis->access->grants = [];
$revisionRead = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid" . $revisionOwnerBase,
        ),
    ),
);
$revisionData = json_decode(
    (string) $revisionRead->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $revisionRead->getStatusCode() !== 200 ||
    ($revisionData["can_revise"] ?? false) !== true ||
    !str_contains($revisionRead->getHeaderLine("Cache-Control"), "no-store") ||
    $revisionRead->hasHeader("phpdebugbar-id") ||
    str_contains((string) $revisionRead->getBody(), "fingerprint")
) {
    throw new RuntimeException(
        "The private owner answer view failed or exposed protected references.",
    );
}
$revisionReviewResponse = $teachingPost($revisionOwnerBase . "review/", [
    "action" => "submit",
    "answers" => ["statement" => "Revised fictional answer."],
    "reason" => "Fictional applicant clarification.",
]);
$revisionReview = json_decode(
    (string) $revisionReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $revisionReviewResponse->getStatusCode() !== 200 ||
    !isset($revisionReview["review_token"])
) {
    throw new RuntimeException(
        "The owner correction review failed through the real route.",
    );
}
$revisionCommit = $teachingPost($revisionOwnerBase . "commit/", [
    "review_token" => $revisionReview["review_token"],
]);
$revisionResult = json_decode(
    (string) $revisionCommit->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$revisionAfter = $sis->sql->rows("sis_history_events");
$revisionRetry = $teachingPost($revisionOwnerBase . "commit/", [
    "review_token" => $revisionReview["review_token"],
]);
if (
    $revisionCommit->getStatusCode() !== 200 ||
    ($revisionResult["status"] ?? null) !== "submitted" ||
    count($revisionAfter) !== count($revisionEvents) + 1 ||
    $revisionAfter !== $sis->sql->rows("sis_history_events") ||
    json_decode(
        (string) $revisionRetry->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    ) !== $revisionResult ||
    $revisionCommit->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException(
        "The actual resubmission did not retain one event and its original retry result.",
    );
}
$sis->access->actor = $originalSubmissionActor;
$sis->access->staff = true;
$revisionDecisionResponse = $teachingPost(
    "/sis/applications/" . $revisionApplication->value . "/decision/review/",
    [
        "decision" => "accept",
        "reason" => "Fictional review of corrected answers.",
        "documents" => [],
    ],
);
$revisionDecision = json_decode(
    (string) $revisionDecisionResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $revisionDecisionResponse->getStatusCode() !== 200 ||
    ($revisionDecision["answers_review"]["answers"]["statement"] ?? null) !==
        "Revised fictional answer." ||
    $teachingPost(
        "/sis/applications/" .
            $revisionApplication->value .
            "/decision/commit/",
        [
            "review_token" => $revisionDecision["review_token"],
        ],
    )->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "The real decision confirmation did not show and retain the current revised answers.",
    );
}
$sis->access->actor = $revisionOwner;
$sis->access->staff = false;
$revisionAcceptedHistory = $sis->sql->rows("sis_history_events");
$revisionAcceptedRetry = $teachingPost($revisionOwnerBase . "commit/", [
    "review_token" => $revisionReview["review_token"],
]);
if (
    json_decode(
        (string) $revisionAcceptedRetry->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    ) !== $revisionResult ||
    $revisionAcceptedHistory !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "A completed owner correction receipt was reapplied after acceptance.",
    );
}
$sis->access->actor = $originalSubmissionActor;
$sis->access->staff = true;
