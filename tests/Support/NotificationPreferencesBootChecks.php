<?php

declare(strict_types=1);

// Real providers/private transport/CSRF, isolated fictional SQLite only.
$student = $sis->student($sis->user());
$person = $sis->admissions->person($student->personId);
$preferencesPath = "/sis/people/" . $person->id->value . "/notification-preferences/";
$getNotice = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Notification reads lost private transport protection.");
    }
    return $response;
};
if ($getNotice($preferencesPath)->getStatusCode() !== 200) {
    throw new RuntimeException("Person preference provider did not resolve.");
}
$saved = $teachingPost($preferencesPath, [
    "revision" => 0,
    "preferences" => ["sound" => true, "sound_id" => "piano", "keep_visible" => true],
]);
if ($saved->getStatusCode() !== 200) {
    throw new RuntimeException("Person preferences could not be saved through the private route.");
}
$staff = $sis->access->actor;
$sis->access->actor = $person->ownerUserId;
$sis->access->staff = false;
$own = $getNotice("/portal/api/notification-preferences/");
$data = json_decode((string) $own->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($own->getStatusCode() !== 200 || $data["preferences"]["sound_id"] !== "piano" || !$data["own"]) {
    throw new RuntimeException("The current person preferences did not follow the owner.");
}
if (
    $teachingPost("/portal/api/notification-preferences/", [
        "revision" => 0,
        "preferences" => ["sound" => false, "sound_id" => "tone", "keep_visible" => false],
    ])->getStatusCode() !== 409
) {
    throw new RuntimeException("A stale owner preference save overwrote current settings.");
}
$sis->access->actor = $staff;
if ($getNotice($preferencesPath)->getStatusCode() !== 403) {
    throw new RuntimeException("An unrelated account could view private preferences.");
}
$sis->access->staff = true;
$sis->gradeScale([
    [
        "code" => "B",
        "min_score_milli" => 0,
        "max_score_milli" => 100000,
        "points_milli" => 3000,
        "passes" => true,
        "counts_gpa" => true,
    ],
]);
$section = $sis->section(faculty: $staff);
$enrollment = $sis->service->register($student->id, $section->id, true);
$ledger = $app->make(\Domain\AcademicRecord\Service\InstitutionalCreditLedger::class);
$grades = $ledger->choices($student->id, $section->id, $enrollment->id);
$review = $ledger->review(
    $student->id,
    $section->id,
    $enrollment->id,
    new \Domain\Shared\ValueObject\EntityId($grades["items"][0]["id"]),
    0,
);
$ledger->commit($student->id, $section->id, $review["review_token"]);
$row = $sis->sql->rows("sis_academic_updates")[0];
$sis->access->actor = $person->ownerUserId;
$sis->access->staff = false;
$list = $getNotice("/portal/api/academic-updates/");
$items = json_decode((string) $list->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($list->getStatusCode() !== 200 || count($items["items"]) !== 1 || isset($items["items"][0]["grade"])) {
    throw new RuntimeException("Academic notices lost their minimized owner scope.");
}
$detail = $getNotice("/portal/api/academic-updates/" . $row["id"] . "/");
if ($detail->getStatusCode() !== 200 || json_decode((string) $detail->getBody(), true)["grade"] !== "B") {
    throw new RuntimeException("An academic notice did not resolve its current protected record.");
}
foreach (["read", "delete"] as $action) {
    if (
        $teachingPost("/portal/api/academic-updates/" . $row["id"] . "/" . $action . "/", [
            "revision" => (int) $row["revision"],
        ])->getStatusCode() !== 200
    ) {
        throw new RuntimeException("A private academic notice mutation failed.");
    }
}
if ($sis->sql->rows("sis_academic_updates") !== []) {
    throw new RuntimeException("Deleting an academic notice retained its row.");
}
$sis->access->actor = $staff;
$sis->access->staff = true;
