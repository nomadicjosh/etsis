<?php

declare(strict_types=1);

require_once __DIR__ . "/StudentSpecializationRequirementsFixture.php";
$f = new StudentSpecializationRequirementsFixture();
$f->r->saveDegree();
$version = new \Domain\Shared\ValueObject\EntityId($f->r->publishDegree()["operation_id"]);
$requirements = $f->requirements();
$selection = $requirements->review(
    $f->student,
    $f->assignment,
    $f->r->degree,
    $version,
    new \Domain\DegreeAudit\ValueObject\PursuitStart("2026-01-31"),
    "Fictional degree selection.",
);
$requirements->assign($f->student, $f->assignment, $selection["review_token"]);
$f->assign();
$httpSis = $f->r->sis;
require __DIR__ . "/SisHttpFixture.php";
$pursuitPath = "/students/" . $f->student->value . "/programs/" . $f->assignment->value;
$base = "/sis" . $pursuitPath . "/degree-requirements/records/";
$ownerBase = "/portal/api" . $pursuitPath . "/degree-progress-records/";
$reviewResponse = $teachingPost($base . "review/", [
    "reason" => "Fictional private advising reason.",
]);
if ($reviewResponse->getStatusCode() !== 200) {
    throw new RuntimeException("The real recorded progress review failed.");
}
$review = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$before = $sis->sql->rows("sis_history_events");
if ($teachingPost($base . "confirm/", $review)->getStatusCode() !== 200) {
    throw new RuntimeException("The real recorded progress confirmation failed.");
}
$accepted = $sis->sql->rows("sis_history_events");
if (
    count($accepted) !== count($before) + 1 ||
    $teachingPost($base . "confirm/", $review)->getStatusCode() !== 200 ||
    $accepted !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "Recorded progress lost its original receipt or wrote duplicate history.",
    );
}
$read = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri($path)));
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Recorded progress exposed caches or debugging.");
    }
    return $response;
};
$page = $teachingPost($base . "page/", []);
if (
    $page->getStatusCode() !== 200 ||
    $read($base . $review["operation_id"] . "/")->getStatusCode() !== 200
) {
    throw new RuntimeException("Recorded progress could not be reopened by authorized staff.");
}
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $sis->admissions->person(
    $sis->admissions->student($f->student)->personId,
)->ownerUserId;
$owner = $read($ownerBase . $review["operation_id"] . "/");
if (
    $owner->getStatusCode() !== 200 ||
    $teachingPost($ownerBase . "page/", [])->getStatusCode() !== 200 ||
    $teachingPost($base . "review/", [
        "reason" => "Fictional unauthorized attempt.",
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Owner recorded progress scope or independent staff authority failed.",
    );
}
foreach (
    [
        "Fictional private advising reason",
        "source_references",
        "inputs",
        "placements",
        "course_review",
        "requirements_version_id",
    ] as $private
) {
    if (str_contains((string) $owner->getBody(), $private)) {
        throw new RuntimeException(
            "Recorded progress exposed private evidence through the owner reader.",
        );
    }
}
$sis->access->actor = $sis->user();
if ($read($ownerBase . $review["operation_id"] . "/")->getStatusCode() !== 403) {
    throw new RuntimeException("An unrelated owner opened another student progress record.");
}
