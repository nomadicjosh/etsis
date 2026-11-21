<?php

declare(strict_types=1);

$student = $sis->student($sis->user());
$section = $sis->section(faculty: $sis->access->actor);
$sis->service->register($student->id, $section->id);
$sis->gradeScale([
    [
        "code" => "A",
        "min_score_milli" => 0,
        "max_score_milli" => 100000,
        "points_milli" => 4000,
        "passes" => true,
        "counts_gpa" => true,
    ],
]);
$grade = new \Domain\Shared\ValueObject\EntityId($sis->sql->rows("sis_grades")[0]["id"]);
$r = $sis->gradebook()->reviewFinalGrade($student->id, $section->id, $grade, 0);
$sis->gradebook()->commitFinalGrade($student->id, $section->id, $r["review_token"]);
$base = "/sis/sections/" . $section->id->value . "/grade-certification/";
$read = static function (string $path) use ($app, $get): array {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "private, no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "A private class-certification route lost authorization, cache or debug protections.",
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
if (!$read($base)["ready"]) {
    throw new RuntimeException("Posted class grades were not ready.");
}
$reviewResponse = $teachingPost($base . "review/", [
    "reason" => "Fictional class-wide grade certification.",
    "acknowledged_history_gaps" => true,
]);
if ($reviewResponse->getStatusCode() !== 200) {
    throw new RuntimeException("Class certification review failed.");
}
$review = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$confirm = $teachingPost($base . "confirm/", $review);
if ($confirm->getStatusCode() !== 200 || count($read($base . "history/")["items"]) !== 1) {
    throw new RuntimeException("Class certification was not retained.");
}
$detailPath = $base . "history/" . $review["operation_id"] . "/";
if ($read($detailPath)["rows"][0]["grade_code"] !== "A") {
    throw new RuntimeException("Retained class grade lost.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:gradebook:certify"];
$response = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $detailPath)),
);
if ($response->getStatusCode() !== 403) {
    throw new RuntimeException("Faculty reopened restricted archived student facts.");
}
if (!$read($base)["ready"]) {
    throw new RuntimeException("Assigned certifier lost readiness scope.");
}
$sis->access->grants = [];
$response = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $base)),
);
if ($response->getStatusCode() !== 403) {
    throw new RuntimeException("Unassigned certification duty was bypassed.");
}
echo "Class certification checks passed.\n";
