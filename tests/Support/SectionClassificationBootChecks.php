<?php

declare(strict_types=1);

// Fictional SQLite, actual providers/router/CSRF/private transport.
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
