<?php

declare(strict_types=1);

$student = $sis->student($sis->user());
$section = $sis->section(faculty: $sis->access->actor);
$sis->service->register($student->id, $section->id, true);
$base = "/sis/sections/" . $section->id->value;
$assignment = $teachingPost($base . "/assignments/", [
    "title" => "Fictional quiz",
    "possible_hundredths" => 1000,
    "due_on" => "2026-09-01",
]);
if ($assignment->getStatusCode() !== 201) {
    throw new RuntimeException("The real gradebook assignment route failed.");
}
$id = json_decode((string) $assignment->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$read = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("A private gradebook response exposed debugging or cache headers.");
    }
    return $response;
};
$setup = json_decode(
    (string) $read($base . "/gradebook/calculation/")->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$saved = $teachingPost($base . "/gradebook/calculation/", [
    "mode" => "weighted",
    "weights" => [$id => 10000],
    "revision" => $setup["revision"],
]);
if (
    $saved->getStatusCode() !== 200 ||
    $teachingPost("/sis/assignments/" . $id . "/scores/", [
        "student_id" => $student->id->value,
        "earned_hundredths" => 750,
        "version" => 0,
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("The real gradebook calculation or scoring route failed.");
}
$path = $base . "/students/" . $student->id->value . "/gradebook-summary/";
$summary = json_decode((string) $read($path)->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (($summary["percent_hundredths"] ?? null) !== 7500) {
    throw new RuntimeException("The real summary route returned the wrong percentage.");
}
if (
    $teachingPost($base . "/gradebook/calculation/", [
        "mode" => "weighted",
        "weights" => [$id => "10000"],
        "revision" => $setup["revision"],
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException("Malformed weights bypassed server validation.");
}
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
if (
    $teachingPost($base . "/gradebook/calculation/", [
        "mode" => "points",
        "weights" => [],
        "revision" => $setup["revision"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("A student changed faculty gradebook setup.");
}
if (
    $read($path)->getStatusCode() !== 200 ||
    $read($base . "/gradebook/calculation/")->getStatusCode() !== 403
) {
    throw new RuntimeException("Student feedback and faculty configuration authority were mixed.");
}
$portalPath = "/portal/api/students/" . $student->id->value . "/sections/" . $section->id->value . "/scores/";
$portal = $read($portalPath);
$body = json_decode((string) $portal->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $portal->getStatusCode() !== 200 ||
    ($body["summary"]["percent_hundredths"] ?? null) !== 7500 ||
    ($body["items"][0]["weight_hundredths"] ?? null) !== 10000
) {
    throw new RuntimeException("The owner portal did not share the gradebook feedback.");
}
$other = $sis->student($sis->user());
$sis->access->actor = $sis->admissions->person($other->personId)->ownerUserId;
if ($read($path)->getStatusCode() !== 403 || $read($portalPath)->getStatusCode() !== 403) {
    throw new RuntimeException("Another person read a private gradebook.");
}
