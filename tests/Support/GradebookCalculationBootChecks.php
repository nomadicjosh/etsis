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
$current = json_decode((string) $saved->getBody(), true, flags: JSON_THROW_ON_ERROR);
$categorySetup = $teachingPost($base . "/gradebook/calculation/", [
    "mode" => "category",
    "weights" => [],
    "revision" => $current["revision"],
    "categories" => [["title" => "Coursework", "weight_hundredths" => 10000, "assignments" => [$id]]],
]);
if ($categorySetup->getStatusCode() !== 200) {
    throw new RuntimeException("Category setup failed through the real private controller.");
}
$summary = json_decode((string) $read($path)->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (($summary["percent_hundredths"] ?? null) !== 7500) {
    throw new RuntimeException("The real summary route returned the wrong percentage.");
}
$second = $teachingPost($base . "/assignments/", [
    "title" => "Fictional second quiz",
    "possible_hundredths" => 1000,
    "bonus_hundredths" => 200,
    "due_on" => "2026-09-02",
]);
if ($second->getStatusCode() !== 201) {
    throw new RuntimeException("The second fictional assignment failed.");
}
$secondId = json_decode((string) $second->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$assignmentResponse = $teachingPost("/sis/ui/context/assignments/" . $section->id->value . "/", []);
$assignmentPage = json_decode((string) $assignmentResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ((int) (array_column($assignmentPage["items"], "bonus_hundredths", "id")[$secondId] ?? -1) !== 200) {
    throw new RuntimeException("The paged assignment directory omitted its bonus limit.");
}
if (
    $teachingPost("/sis/assignments/" . $secondId . "/scores/", [
        "student_id" => $student->id->value,
        "earned_hundredths" => 1100,
        "version" => 0,
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("The second fictional score failed.");
}
$bonusRecord = json_decode(
    (string) $read("/sis/assignments/" . $secondId . "/")->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    ($bonusRecord["bonus_hundredths"] ?? null) !== 200 ||
    ($bonusRecord["score_limit_hundredths"] ?? null) !== 1200 ||
    $teachingPost("/sis/assignments/" . $secondId . "/scores/", [
        "student_id" => $student->id->value,
        "earned_hundredths" => 1201,
        "version" => 1,
    ])->getStatusCode() !== 422 ||
    $teachingPost("/sis/assignments/" . $secondId . "/edit/", [
        "title" => "Fictional second quiz",
        "possible_hundredths" => 1000,
        "bonus_hundredths" => 50,
        "due_on" => "2026-09-02",
        "revision" => $bonusRecord["revision"],
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException(
        "The actual score/assignment routes did not protect bonus limits and recorded marks.",
    );
}
$dropSetup = json_decode(
    (string) $read($base . "/gradebook/calculation/")->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$categories = [
    [
        "title" => "Coursework",
        "weight_hundredths" => 10000,
        "assignments" => [$id, $secondId],
        "drop_lowest" => "1",
    ],
];

if (
    $teachingPost($base . "/gradebook/calculation/", [
        "mode" => "category",
        "weights" => [],
        "categories" => $categories,
        "revision" => $dropSetup["revision"],
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException("A string drop count bypassed controller/domain validation.");
}
$categories[0]["drop_lowest"] = 1;
$preserved = [
    $sis->sql->rows("sis_history_events"),
    $sis->sql->rows("sis_academic_credits"),
    $sis->sql->rows("sis_scores"),
];
if (
    $teachingPost($base . "/gradebook/calculation/", [
        "mode" => "category",
        "weights" => [],
        "categories" => $categories,
        "revision" => $dropSetup["revision"],
    ])->getStatusCode() !== 200 ||
    $preserved !== [
        $sis->sql->rows("sis_history_events"),
        $sis->sql->rows("sis_academic_credits"),
        $sis->sql->rows("sis_scores"),
    ]
) {
    throw new RuntimeException("Saving the drop rule changed authoritative facts or scores.");
}
$protected = [
    $sis->sql->rows("sis_history_events"),
    $sis->sql->rows("sis_academic_credits"),
    $sis->financialEvents(),
];
$scorePath = "/sis/assignments/" . $secondId . "/scores/";
$scoreData = ["student_id" => $student->id->value, "earned_hundredths" => 1100, "version" => 1];
if (
    $teachingPost($scorePath, $scoreData + ["excused" => "invalid"])->getStatusCode() !== 422 ||
    $teachingPost($scorePath, $scoreData + ["excused" => true])->getStatusCode() !== 200
) {
    throw new RuntimeException("The actual scoring route failed to validate or save an excusal.");
}
$scoreData["version"] = 2;
if ($teachingPost($scorePath, $scoreData)->getStatusCode() !== 200) {
    throw new RuntimeException("A mark update could not retain the existing excusal.");
}
$excused = json_decode((string) $read($path)->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    ($excused["excused"] ?? null) !== 1 ||
    ($excused["percent_hundredths"] ?? null) !== 7500 ||
    $teachingPost($scorePath, $scoreData + ["excused" => false])->getStatusCode() !== 409
) {
    throw new RuntimeException("An ordinary or stale update silently cleared an excusal.");
}
$scoreData["version"] = 3;
if ($teachingPost($scorePath, $scoreData + ["excused" => false])->getStatusCode() !== 200) {
    throw new RuntimeException("Explicit inclusion failed.");
}
$scoreData["version"] = 4;
if (
    $teachingPost($scorePath, $scoreData + ["excused" => true])->getStatusCode() !== 200 ||
    $protected !== [
        $sis->sql->rows("sis_history_events"),
        $sis->sql->rows("sis_academic_credits"),
        $sis->financialEvents(),
    ]
) {
    throw new RuntimeException("Working excusals changed authoritative academic or financial facts.");
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
$rosterPath = "/sis/assignments/" . $secondId . "/score-roster/";
$rosterResponse = $teachingPost($rosterPath, ["state" => "excused", "search" => "Alex Example"]);
$roster = json_decode((string) $rosterResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $rosterResponse->getStatusCode() !== 200 ||
    count($roster["items"] ?? []) !== 1 ||
    ($roster["items"][0]["student_id"] ?? null) !== $student->id->value ||
    ($roster["items"][0]["earned_hundredths"] ?? null) !== 1100 ||
    ($roster["items"][0]["excused"] ?? null) !== true ||
    ($roster["assignment"]["score_limit_hundredths"] ?? null) !== 1200 ||
    $teachingPost($rosterPath, ["state" => "invalid"])->getStatusCode() !== 422
) {
    throw new RuntimeException(
        "The private assignment worksheet failed filtering or retained score presentation.",
    );
}
$worksheetScore = [
    "student_id" => $student->id->value,
    "earned_hundredths" => 1100,
    "version" => 5,
    "excused" => true,
    "assignment_revision" => $roster["assignment"]["revision"],
    "enrollment_id" => $roster["items"][0]["enrollment_id"],
];
$outdated = $worksheetScore;
$outdated["assignment_revision"] = str_repeat("0", 64);
if (
    $teachingPost($scorePath, $outdated)->getStatusCode() !== 409 ||
    $teachingPost($scorePath, $worksheetScore)->getStatusCode() !== 200 ||
    $teachingPost($scorePath, $worksheetScore)->getStatusCode() !== 409 ||
    $protected !== [
        $sis->sql->rows("sis_history_events"),
        $sis->sql->rows("sis_academic_credits"),
        $sis->financialEvents(),
    ]
) {
    throw new RuntimeException("Worksheet scoring failed concurrency or changed protected academic facts.");
}
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
if ($teachingPost($rosterPath, [])->getStatusCode() !== 403) {
    throw new RuntimeException("A student owner read classmates' marks through the worksheet.");
}
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
if ($teachingPost($scorePath, $scoreData + ["excused" => false])->getStatusCode() !== 403) {
    throw new RuntimeException("A student altered their own excusal.");
}
if (
    $portal->getStatusCode() !== 200 ||
    ($body["summary"]["percent_hundredths"] ?? null) !== 7500 ||
    ($body["summary"]["bonus_earned_hundredths"] ?? null) !== 0 ||
    ($body["summary"]["dropped"] ?? null) !== 0 ||
    ($body["summary"]["excused"] ?? null) !== 1 ||
    ($body["summary"]["excused_assignments"][0]["assignment_id"] ?? null) !== $secondId ||
    (array_column($body["items"], "excused", "assignment_id")[$secondId] ?? null) !== true ||
    (array_column($body["items"], "earned_hundredths", "assignment_id")[$secondId] ?? null) !== 1100 ||
    ($body["items"][0]["category_weight_hundredths"] ?? null) !== 10000 ||
    ($body["summary"]["categories"][0]["title"] ?? null) !== "Coursework"
) {
    throw new RuntimeException("The owner portal did not share the gradebook feedback.");
}
$other = $sis->student($sis->user());
$sis->access->actor = $sis->admissions->person($other->personId)->ownerUserId;
if ($read($path)->getStatusCode() !== 403 || $read($portalPath)->getStatusCode() !== 403) {
    throw new RuntimeException("Another person read a private gradebook.");
}

// The class attendance register shares teaching authority, private responses and CSRF enforcement.
$sis->access->actor = $section->facultyUserId;
$sis->access->staff = false;
$sis->access->grants = [];
$attendancePath = $base . "/attendance-roster/";
$attendanceSetup = $teachingPost($attendancePath, []);
$attendanceSetupBody = json_decode((string) $attendanceSetup->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($attendanceSetup->getStatusCode() !== 200 || ($attendanceSetupBody["items"] ?? null) !== []) {
    throw new RuntimeException("The attendance register invented a date or class roster.");
}
$attendanceData = ["held_on" => "2026-08-11", "state" => "unmarked", "search" => "Alex Example"];
$attendanceResponse = $teachingPost($attendancePath, $attendanceData);
$attendancePage = json_decode((string) $attendanceResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $attendanceResponse->getStatusCode() !== 200 ||
    count($attendancePage["items"] ?? []) !== 1 ||
    ($attendancePage["items"][0]["student_id"] ?? null) !== $student->id->value ||
    $attendancePage["items"][0]["status"] !== null ||
    $teachingPost($attendancePath, ["held_on" => "2025-01-01"])->getStatusCode() !== 422 ||
    $teachingPost($attendancePath, ["held_on" => "2026-02-30"])->getStatusCode() !== 422 ||
    $teachingPost($attendancePath, ["state" => "invalid"])->getStatusCode() !== 422
) {
    throw new RuntimeException("Private dated attendance filtering or validation failed.");
}
$attendanceProtected = [
    $sis->sql->rows("sis_history_events"),
    $sis->sql->rows("sis_academic_credits"),
    $sis->sql->rows("sis_scores"),
    $sis->financialEvents(),
];
$attendanceMark = [
    "student_id" => $student->id->value,
    "enrollment_id" => $attendancePage["items"][0]["enrollment_id"],
    "held_on" => "2026-08-11",
    "status" => "present",
    "revision" => null,
];
$attendanceSave = $teachingPost($base . "/attendance/", $attendanceMark);
$firstAttendance = json_decode((string) $attendanceSave->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($attendanceSave->getStatusCode() !== 200 || !is_string($firstAttendance["revision"] ?? null)) {
    throw new RuntimeException("Attendance save did not return its current correction revision.");
}
$attendanceMark["revision"] = $firstAttendance["revision"];
$attendanceMark["status"] = "late";
$secondAttendance = $teachingPost($base . "/attendance/", $attendanceMark);
$secondAttendanceBody = json_decode((string) $secondAttendance->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($secondAttendance->getStatusCode() !== 200) {
    throw new RuntimeException("The attendance correction route failed.");
}
$attendanceMark["revision"] = $secondAttendanceBody["revision"];
$attendanceMark["status"] = "present";
if ($teachingPost($base . "/attendance/", $attendanceMark)->getStatusCode() !== 200) {
    throw new RuntimeException("The second attendance correction failed.");
}
$attendanceMark["revision"] = $firstAttendance["revision"];
$attendanceMark["status"] = "absent";
if (
    $teachingPost($base . "/attendance/", $attendanceMark)->getStatusCode() !== 409 ||
    $attendanceProtected !== [
        $sis->sql->rows("sis_history_events"),
        $sis->sql->rows("sis_academic_credits"),
        $sis->sql->rows("sis_scores"),
        $sis->financialEvents(),
    ]
) {
    throw new RuntimeException(
        "An old attendance correction overwrote a later mark or academic facts changed.",
    );
}
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
if (
    $teachingPost($attendancePath, $attendanceData)->getStatusCode() !== 403 ||
    $teachingPost($base . "/attendance/", $attendanceMark)->getStatusCode() !== 403
) {
    throw new RuntimeException("A student owner gained class attendance read/write access.");
}
$privateAttendance = $teachingPost($base . "/students/" . $student->id->value . "/attendance/", []);
$privateAttendanceBody = json_decode(
    (string) $privateAttendance->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $privateAttendance->getStatusCode() !== 200 ||
    ($privateAttendanceBody["items"][0]["status"] ?? null) !== "present"
) {
    throw new RuntimeException("The student's private attendance feedback lost its confirmed mark.");
}
