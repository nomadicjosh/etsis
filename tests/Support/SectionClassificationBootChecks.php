<?php

declare(strict_types=1);

// Fictional SQLite, actual providers/router/CSRF/private transport.
// Connected section creation and private current-enrollment review, through the real provider/router.
$targetCourse = $sis->section(20, 0);
$targetInput = ['course_id' => $targetCourse->courseId->value, 'term_id' => $sis->term->value,
    'code' => 'FICTIONAL.TARGET', 'capacity' => 20, 'minimum_enrollment' => 12,
    'web_registration' => true, 'fee_minor' => 0, 'currency' => 'USD', 'delivery_mode' => 'online'];
$targetCreated = $teachingPost('/sis/sections/', $targetInput);
if (
    $targetCreated->getStatusCode() !== 201 ||
    $teachingPost('/sis/sections/', array_replace(
        $targetInput,
        ['code' => 'FICTIONAL.BADTARGET', 'minimum_enrollment' => 21]
    ))->getStatusCode() !== 422
) {
    throw new RuntimeException('Section target validation did not resolve through its provider.');
}
$targetEvents = $sis->sql->rows('sis_history_events');
// Match the drawer's opening payload and a submitted form with cleared optional filters.
$emptyTargetScopes = [
    ['state' => 'below', 'search' => '', 'include_inactive' => false],
    ['state' => 'below', 'search' => '   ', 'include_inactive' => false, 'term_id' => '', 'after' => ''],
    ['state' => 'below'],
];
foreach ($emptyTargetScopes as $emptyTargetScope) {
    $emptyTargetPage = $teachingPost('/sis/sections/enrollment-review/', $emptyTargetScope);
    $emptyTargetRows = json_decode((string) $emptyTargetPage->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $emptyTargetPage->getStatusCode() !== 200 ||
        count($emptyTargetRows['items'] ?? []) !== 1 ||
        $emptyTargetRows['items'][0]['code'] !== 'FICTIONAL.TARGET'
    ) {
        throw new RuntimeException('Enrollment targets rejected an empty optional search.');
    }
}
foreach ([str_repeat('x', 81), "invalid\nsearch", ['invalid']] as $invalidTargetSearch) {
    $invalidTargetPage = $teachingPost('/sis/sections/enrollment-review/', ['search' => $invalidTargetSearch]);
    if ($invalidTargetPage->getStatusCode() !== 422) {
        throw new RuntimeException('Enrollment targets accepted an invalid search.');
    }
}
$targetScope = ['state' => 'below', 'term_id' => $sis->term->value, 'search' => 'FICTIONAL.TARGET'];
$targetPage = $teachingPost('/sis/sections/enrollment-review/', $targetScope);
$targetRows = json_decode((string) $targetPage->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $targetPage->getStatusCode() !== 200 || count($targetRows['items'] ?? []) !== 1 ||
    $targetRows['items'][0]['minimum_enrollment'] !== 12 || $targetRows['items'][0]['enrolled'] !== 0 ||
    $sis->sql->rows('sis_history_events') !== $targetEvents
) {
    throw new RuntimeException('The private section-target review lost its actual counts or wrote history.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:registration:manage'];
if ($teachingPost('/sis/sections/enrollment-review/', $targetScope)->getStatusCode() !== 200) {
    throw new RuntimeException('Registration duty could not review section enrollment targets.');
}
$sis->access->grants = ['sis:student:read'];
if ($teachingPost('/sis/sections/enrollment-review/', $targetScope)->getStatusCode() !== 403) {
    throw new RuntimeException('Student-read duty acquired section planning access.');
}
$sis->access->staff = true;
$sis->access->grants = [];

$classificationSection = $sis->section();
$classificationBase =
"/sis/sections/" . $classificationSection->id->value . "/classifications/";
$classificationResponse = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid" . $classificationBase,
        ),
    ),
);
$classificationSetup = json_decode(
    (string) $classificationResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $classificationResponse->getStatusCode() !== 200 ||
    !is_string($classificationSetup["revision"] ?? null) ||
    !str_contains(
        $classificationResponse->getHeaderLine("Cache-Control"),
        "no-store",
    ) ||
    $classificationResponse->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException(
        "The private section setup provider did not resolve.",
    );
}
$methodResponse = $teachingPost(
    "/sis/catalog/classifications/instructional_method/save/",
    [
        "code" => "LAB",
        "title" => "Fictional laboratory",
        "active" => true,
        "id" => null,
        "revision" => null,
    ],
);
$methodRow = json_decode(
    (string) $methodResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $methodResponse->getStatusCode() !== 200 ||
    !is_string($methodRow["id"] ?? null)
) {
    throw new RuntimeException("Instructional method setup failed.");
}
$eventsBeforeClassification = $sis->sql->rows("sis_history_events");
$classificationCommand = [
    "instructional_method_id" => $methodRow["id"],
    "campus_id" => null,
    "revision" => $classificationSetup["revision"],
];
if (
    $teachingPost(
        $classificationBase,
        array_diff_key($classificationCommand, ["campus_id" => true]),
    )->getStatusCode() !== 422 ||
    $teachingPost(
        $classificationBase,
        $classificationCommand,
    )->getStatusCode() !== 200 ||
    $sis->sql->rows("sis_history_events") !== $eventsBeforeClassification ||
    $teachingPost(
        $classificationBase,
        $classificationCommand,
    )->getStatusCode() !== 409
) {
    throw new RuntimeException(
        "Section draft setup bypassed explicit choices, auditing boundaries or concurrency.",
    );
}
$coursePublisher = $app->make(
    \Domain\Catalog\Service\CoursePublicationService::class,
);
$courseProof = $coursePublisher->review(
    $classificationSection->courseId,
    "Fictional course approval.",
);
$courseReceipt = $coursePublisher->publish(
    $classificationSection->courseId,
    $courseProof["review_token"],
);
$bindingService = $app->make(
    \Domain\Registration\Service\SectionCourseBindingService::class,
);
$bindingReview = $bindingService->review(
    $classificationSection->id,
    new \Domain\Shared\ValueObject\EntityId($courseReceipt["operation_id"]),
    "Fictional course binding.",
);
$bindingService->commit(
    $classificationSection->id,
    $bindingReview["review_token"],
);
$sectionPublisher = $app->make(
    \Domain\Registration\Service\SectionPublicationService::class,
);
$publicationProof = $sectionPublisher->review(
    $classificationSection->id,
    "2026-08-01",
    "2026-08-31",
    "Fictional offering approval.",
);
if (
    ($publicationProof["classifications"]["instructional_method"]["id"] ??
        null) !==
    $methodRow["id"]
) {
    throw new RuntimeException(
        "Publication providers omitted the saved instructional method.",
    );
}
$sectionPublisher->publish(
    $classificationSection->id,
    $publicationProof["review_token"],
);
if (
    ($sectionPublisher->versions($classificationSection->id)["items"][0][
        "classifications"
    ]["instructional_method"]["title"] ??
        null) !==
    "Fictional laboratory"
) {
    throw new RuntimeException(
        "The real publication provider did not retain instructional method history.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:registration:manage"];
if (
    $teachingPost(
        $classificationBase,
        $classificationCommand,
    )->getStatusCode() !== 403 ||
    $app->router
        ->match(
            $get->withUri(
                new \Laminas\Diactoros\Uri(
                    "https://campus.example.invalid" . $classificationBase,
                ),
            ),
        )
        ->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Registration authority implicitly granted academic reference management.",
    );
}
