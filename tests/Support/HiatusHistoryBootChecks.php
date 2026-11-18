<?php

declare(strict_types=1);

// Actual providers/router/CSRF with fictional records only.
$hiatusStudent = $sis->student();
$hiatusSection = $sis->section();
$sis->service->register($hiatusStudent->id, $hiatusSection->id);
$hiatusBase = "/sis/students/" . $hiatusStudent->id->value;
$hiatusDraft = ["code" => "W", "starts_on" => "2026-08-10", "ends_on" => null];
$hiatusReviewResponse = $teachingPost(
    $hiatusBase . "/hiatus-workflow/review/",
    $hiatusDraft,
);
$hiatusReview = json_decode(
    (string) $hiatusReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $hiatusReviewResponse->getStatusCode() !== 200 ||
    !is_string($hiatusReview["review_token"] ?? null)
) {
    throw new RuntimeException(
        "The actual hiatus provider did not issue its reviewed context.",
    );
}
$hiatusCommand = $hiatusDraft + [
    "operation_id" => $hiatusReview["operation_id"],
    "revision" => $hiatusReview["revision"],
    "review_token" => $hiatusReview["review_token"],
    "deactivate" => true,
    "section_ids" => [$hiatusSection->id->value],
    "note" => "Fictional private case explanation.",
];
$hiatusBefore = hash(
    "sha256",
    json_encode($sis->sql->rows("sis_history_events"), JSON_THROW_ON_ERROR),
);
if (
    $teachingPost(
        $hiatusBase . "/hiatus-workflow/commit/",
        array_diff_key($hiatusCommand, ["review_token" => true]),
    )->getStatusCode() !== 422 ||
    $hiatusBefore !==
        hash(
            "sha256",
            json_encode(
                $sis->sql->rows("sis_history_events"),
                JSON_THROW_ON_ERROR,
            ),
        )
) {
    throw new RuntimeException(
        "A case confirmation bypassed its review proof.",
    );
}
$hiatusSaved = $teachingPost(
    $hiatusBase . "/hiatus-workflow/commit/",
    $hiatusCommand,
);
$hiatusRetry = $teachingPost(
    $hiatusBase . "/hiatus-workflow/commit/",
    $hiatusCommand,
);
$hiatusPath = $hiatusBase . "/hiatuses/" . $hiatusReview["operation_id"];
$hiatusGet = static function (string $path) use (
    $app,
    $get,
): \Psr\Http\Message\ResponseInterface {
    return $app->router->match(
        $get->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid" . $path,
            ),
        ),
    );
};
$hiatusViewResponse = $hiatusGet($hiatusPath . "/");
$hiatusView = json_decode(
    (string) $hiatusViewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $hiatusSaved->getStatusCode() !== 201 ||
    $hiatusRetry->getStatusCode() !== 201 ||
    (string) $hiatusSaved->getBody() !== (string) $hiatusRetry->getBody() ||
    ($hiatusView["history_tracked"] ?? null) !== true ||
    $hiatusViewResponse->hasHeader("phpdebugbar-id") ||
    !str_contains(
        $hiatusViewResponse->getHeaderLine("Cache-Control"),
        "no-store",
    )
) {
    throw new RuntimeException(
        "Protected case history failed provider, retry or privacy checks.",
    );
}
$sis->clock->time = $sis->clock->now()->modify("+1 day");
$returnResponse = $teachingPost($hiatusPath . "/return/review/", [
    "return_on" => "2026-08-11",
]);
$returnReview = json_decode(
    (string) $returnResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$returnCommand = [
    "return_on" => "2026-08-11",
    "operation_id" => $returnReview["operation_id"],
    "revision" => $returnReview["revision"],
    "review_token" => $returnReview["review_token"],
    "reactivate" => true,
    "note" => "Fictional private return explanation.",
];
$returnCommit = $teachingPost($hiatusPath . "/return/commit/", $returnCommand);
$hiatusHistoryResponse = $teachingPost($hiatusPath . "/history/", []);
$hiatusHistory = json_decode(
    (string) $hiatusHistoryResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $returnResponse->getStatusCode() !== 200 ||
    $returnCommit->getStatusCode() !== 201 ||
    !$sis->admissions->student($hiatusStudent->id)->active ||
    count($hiatusHistory["items"] ?? []) !== 2 ||
    array_column($hiatusHistory["items"], "history_tracked") !== [true, true]
) {
    throw new RuntimeException(
        "The actual return and curated case history providers did not retain the decisions.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read"];
if (
    $hiatusGet($hiatusPath . "/")->getStatusCode() !== 403 ||
    $teachingPost($hiatusPath . "/history/", [])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "General student read access disclosed registrar case evidence.",
    );
}
