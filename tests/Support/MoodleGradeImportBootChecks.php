<?php

declare(strict_types=1);

$section = $moodleGradeFixture->offering->section->id;
$base = "/sis/moodle/grade-imports/" . $section->value . "/";
$decodeMoodleGrade = static function (
    \Psr\Http\Message\ResponseInterface $response,
): array {
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(
            "Moodle grade route returned HTTP " . $response->getStatusCode(),
        );
    }
    return json_decode(
        (string) $response->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
};
$readMoodleGrade = static function (string $path) use (
    $app,
    $get,
): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid" . $path,
            ),
        ),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Private Moodle grade read lost privacy.");
    }
    return $response;
};
$page = $decodeMoodleGrade($teachingPost($base . "page/", []));
$decodeMoodleGrade(
    $teachingPost($base . "request/", [
        "request_id" => $page["request_id"],
        "revision" => $page["revision"],
        "reason" => "Fictional HTTP intake.",
    ]),
);
if ($moodleGradeFixture->remote->calls !== 0) {
    throw new RuntimeException("The browser request ran an import.");
}
$worker = $app->make(\Domain\Lms\Service\MoodleGradeImportWorker::class);
if (
    $worker->runOne() !== "grade_observed" ||
    $worker->runOne() !== "intake_complete"
) {
    throw new RuntimeException("Configured worker providers failed.");
}
$page = $decodeMoodleGrade($teachingPost($base . "page/", []));
$candidate = $page["items"][0];
$record = $decodeMoodleGrade($readMoodleGrade($base . $candidate["id"] . "/"));
$review = $decodeMoodleGrade(
    $teachingPost($base . $candidate["id"] . "/review/", [
        "action" => "accept",
        "revision" => $candidate["revision"],
        "grade_id" => $record["choices"]["items"][0]["id"],
        "reason" => str_repeat("学", 1000),
    ]),
);
$decodeMoodleGrade(
    $teachingPost($base . $candidate["id"] . "/confirm/", [
        "review_token" => $review["review_token"],
    ]),
);
$decodeMoodleGrade(
    $teachingPost($base . $candidate["id"] . "/confirm/", [
        "review_token" => $review["review_token"],
    ]),
);
$notices = $decodeMoodleGrade(
    $readMoodleGrade("/sis/moodle/grade-imports/notices/"),
);
if (count($notices["items"]) !== 1) {
    throw new RuntimeException("Completion notification missing.");
}
$notice = $notices["items"][0];
$decodeMoodleGrade(
    $teachingPost(
        "/sis/moodle/grade-imports/notices/" . $notice["id"] . "/read/",
        ["revision" => 1],
    ),
);
$decodeMoodleGrade(
    $teachingPost(
        "/sis/moodle/grade-imports/notices/" . $notice["id"] . "/delete/",
        ["revision" => 2],
    ),
);
$sis->access->staff = false;
$sis->access->grants = ["sis:moodle:manage", "sis:student:read"];
if ($readMoodleGrade($base . $candidate["id"] . "/")->getStatusCode() !== 403) {
    throw new RuntimeException(
        "Grade import lost its conjunctive staff duties.",
    );
}
