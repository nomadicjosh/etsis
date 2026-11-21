<?php

declare(strict_types=1);

// Continue the fictional teaching fixture through real reporting/population/recipient routes.
$sis->access->actor = $section->facultyUserId;
$sis->access->staff = false;
$sis->access->grants = ["sis:reporting:manage", "sis:reporting:run", "sis:student:read"];
$attendanceDefinition = [
    "dataset" => "attendance",
    "columns" => [
        ["field" => "student_id", "label" => "Student", "aggregate" => "none"],
        ["field" => "held_on", "label" => "Class date", "aggregate" => "none"],
        ["field" => "attendance_status", "label" => "Mark", "aggregate" => "none"],
    ],
    "filters" => [
        "condition" => "AND",
        "rules" => [
            ["id" => "student_id", "operator" => "equal", "value" => $student->id->value],
            ["id" => "attendance_status", "operator" => "equal", "value" => "absent"],
        ],
    ],
];
$attendancePreview = ["definition" => $attendanceDefinition, "offset" => 0];
if ($teachingPost("/sis/report-studio/preview/", $attendancePreview)->getStatusCode() !== 403) {
    throw new RuntimeException(
        "Assigned teaching authority widened institution-wide attendance reporting.",
    );
}
$sis->access->grants[] = "sis:gradebook:manage";
$attendanceRead = static function (
    string $path,
    array $data,
    int $status = 200
) use (
    $teachingPost,
): array {
    $response = $teachingPost($path, $data);
    if (
        $response->getStatusCode() !== $status ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store")
    ) {
        throw new RuntimeException("The private attendance reporting workflow failed: " . $path);
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$attendanceGraph = $attendanceRead('/sis/report-studio/graph/', ['dataset' => 'attendance', 'aliases' => []]);
if (isset($attendanceGraph['dataset']['fields']['registration_status'])) {
    throw new RuntimeException('Attendance reporting acquired an undeclared field.');
}
$mark = $attendanceRead($base . "/attendance/", [
    "student_id" => $student->id->value,
    "held_on" => "2026-08-12",
    "status" => "absent",
    "revision" => null,
]);
if (count($attendanceRead("/sis/report-studio/preview/", $attendancePreview)["items"]) !== 1) {
    throw new RuntimeException("The real attendance report did not resolve recorded marks.");
}
$report = \Domain\Shared\ValueObject\EntityId::generate()->value;
$attendanceRead(
    "/sis/report-studio/save/",
    [
        "report_id" => $report,
        "operation_id" => $report,
        "version" => -1,
        "name" => "Fictional attendance follow-up",
        "definition" => $attendanceDefinition,
    ],
    201,
);
$sis->access->grants = [...$sis->access->grants, "sis:communications:manage", "sis:person:manage"];
$sis->sql->update(
    "users",
    ["email" => "fictional-attendance@example.invalid"],
    [
        "user_id" => $sis->admissions->person($student->personId)->ownerUserId->value,
    ],
);
$campaignFacts = array_map($sis->sql->rows(...), [
    "sis_campaigns",
    "sis_deliveries",
    "sis_history_events",
]);
$populationPath = "/sis/report-studio/" . $report . "/population/";
$shortlist = $attendanceRead($populationPath, ["offset" => 0, "action" => "messaging"]);
if (array_column($shortlist["items"], "id") !== [$student->id->value]) {
    throw new RuntimeException("Attendance follow-up did not yield distinct current students.");
}
$selection = [
    "student_ids" => [$student->id->value],
    "report_version" => 0,
    "action" => "messaging",
];
$attendanceRead($populationPath . "select/", $selection);
$recipients = $attendanceRead("/sis/campaigns/recipients/students/", [
    "students" => [["student_id" => $student->id->value]],
]);
if (count($recipients["items"]) !== 1 || str_contains(json_encode($recipients), "@")) {
    throw new RuntimeException("The attendance message handoff widened recipient details.");
}
$attendanceRead($base . "/attendance/", [
    "student_id" => $student->id->value,
    "held_on" => "2026-08-12",
    "status" => "excused",
    "revision" => $mark["revision"],
]);
if (
    $teachingPost($populationPath . "select/", $selection)->getStatusCode() !== 409 ||
    $campaignFacts !==
        array_map($sis->sql->rows(...), ["sis_campaigns", "sis_deliveries", "sis_history_events"])
) {
    throw new RuntimeException(
        "Stale attendance follow-up changed a shortlist or created communications/history.",
    );
}
