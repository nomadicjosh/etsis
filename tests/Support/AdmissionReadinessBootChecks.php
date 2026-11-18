<?php

declare(strict_types=1);

// Isolated fictional application, real Codefy providers/router/CSRF. No institutional database connection.
$readinessOwner = $sis->user();
$readinessPerson = $sis->legacyPerson(
    $readinessOwner,
    "Fictional",
    "Checklist",
);
$readinessApplication = \Domain\Shared\ValueObject\EntityId::generate();
$app->make(
    \Domain\Admissions\Service\ApplicationSubmissionService::class,
)->apply(
    $readinessApplication,
    $readinessPerson->id,
    $sis->program,
    $sis->term,
);
$readinessBase = "/sis/applications/" . $readinessApplication->value . "/";
$readinessItem = [
    "key" => "private_review",
    "label" => "Private fictional review",
    "instructions" => "Never in applicant guidance.",
    "needed" => true,
    "visible" => false,
    "status" => "received",
    "due_on" => "",
];
$readinessSetupResponse = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid/sis/admission-policy/",
        ),
    ),
);
$readinessSetup = json_decode(
    (string) $readinessSetupResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $readinessSetupResponse->getStatusCode() !== 200 ||
    ($readinessSetup["checklist"]["required"] ?? null) !== false
) {
    throw new RuntimeException(
        "Checklist policy did not default to advisory in the actual setup route.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:admissions:manage"];
if (
    $teachingPost("/sis/admission-policy/", [
        "area" => "checklist",
        "required" => true,
        "revision" => $readinessSetup["checklist"]["revision"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Ordinary admissions duties changed institutional checklist policy.",
    );
}
$sis->access->staff = true;
if (
    $teachingPost("/sis/admission-policy/", [
        "area" => "checklist",
        "required" => true,
        "revision" => $readinessSetup["checklist"]["revision"],
    ])->getStatusCode() !== 200 ||
    $teachingPost($readinessBase . "casework/", [
        "version" => 0,
        "values" => ["items" => [$readinessItem]],
        "claim" => "keep",
        "assignee_id" => null,
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "Required checklist setup or its working-case save failed.",
    );
}
$readinessEvents = $sis->sql->rows("sis_history_events");
if (
    $teachingPost($readinessBase . "decision/review/", [
        "decision" => "accept",
        "reason" => "Fictional acceptance.",
        "documents" => [],
    ])->getStatusCode() !== 422 ||
    $readinessEvents !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "The actual acceptance endpoint ignored an incomplete required checklist.",
    );
}
$sis->access->actor = $readinessOwner;
$sis->access->staff = false;
$sis->access->grants = [];
$readinessOwnerResponse = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid/portal/api/applications/" .
                $readinessApplication->value .
                "/next-steps/",
        ),
    ),
);
$readinessOwnerData = json_decode(
    (string) $readinessOwnerResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $readinessOwnerResponse->getStatusCode() !== 200 ||
    ($readinessOwnerData["readiness"]["required"] ?? null) !== true ||
    str_contains(
        (string) $readinessOwnerResponse->getBody(),
        "Private fictional review",
    ) ||
    str_contains(
        (string) $readinessOwnerResponse->getBody(),
        "Never in applicant guidance",
    ) ||
    array_keys($readinessOwnerData["readiness"]) !== ["required", "message"] ||
    !str_contains(
        $readinessOwnerResponse->getHeaderLine("Cache-Control"),
        "no-store",
    ) ||
    $readinessOwnerResponse->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException(
        "Applicant checklist guidance leaked private tasks or lost its privacy contract.",
    );
}
$sis->access->actor = $originalSubmissionActor;
$sis->access->staff = true;
$readinessItem["status"] = "complete";
if (
    $teachingPost($readinessBase . "casework/", [
        "version" => 1,
        "values" => ["items" => [$readinessItem]],
        "claim" => "keep",
        "assignee_id" => null,
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("The checklist could not be completed.");
}
$readinessReviewResponse = $teachingPost($readinessBase . "decision/review/", [
    "decision" => "accept",
    "reason" => "Fictional reviewed acceptance.",
    "documents" => [],
]);
$readinessReview = json_decode(
    (string) $readinessReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $readinessReviewResponse->getStatusCode() !== 200 ||
    ($readinessReview["checklist"]["ready"] ?? false) !== true ||
    $teachingPost($readinessBase . "decision/commit/", [
        "review_token" => $readinessReview["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "The actual acceptance did not retain its completed checklist proof.",
    );
}
$readinessHistoryResponse = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid" .
                $readinessBase .
                "decision/history/",
        ),
    ),
);
$readinessHistory = json_decode(
    (string) $readinessHistoryResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$readinessRecorded = array_slice($readinessHistory["items"], -1)[0][
"checklist"
];
if (
    $readinessHistoryResponse->getStatusCode() !== 200 ||
    $readinessRecorded !== [
        "required" => true,
        "ready" => true,
        "needed" => 1,
        "complete" => 1,
        "not_needed" => 0,
    ] ||
    str_contains(
        (string) $readinessHistoryResponse->getBody(),
        "Private fictional review",
    ) ||
    $readinessHistoryResponse->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException(
        "The private decision summary lost frozen evidence or exposed item names.",
    );
}
