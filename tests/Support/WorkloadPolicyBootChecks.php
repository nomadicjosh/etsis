<?php

declare(strict_types=1);

$read = static function (string $path) use ($app, $get): array {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "private, no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("A workload read lost its private response or authorization.");
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$policy = $read("/sis/time-policy/");
if ($policy["rules"]["contact_unit_minutes"] !== 50) {
    throw new RuntimeException("Default instructional contact unit was lost.");
}
$rules = array_replace($policy["rules"], [
    "daily_minutes" => 60,
    "mode" => "exception_required",
    "contact_unit_minutes" => 60,
]);
$review = $teachingPost("/sis/time-policy/review/", [
    "rules" => $rules,
    "version" => 0,
    "reason" => "Fictional institution time and contact policy.",
]);
if ($review->getStatusCode() !== 200) {
    throw new RuntimeException("Workload policy review failed.");
}
$proof = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($teachingPost("/sis/time-policy/publish/", $proof)->getStatusCode() !== 200) {
    throw new RuntimeException("Workload policy publication failed.");
}
$section = $sis->section();
$contactPath = "/sis/sections/" . $section->id->value . "/contact-hours/";
if (
    $teachingPost($contactPath, ["weekly_minutes" => 150, "version" => 0])->getStatusCode() !==
        200 ||
    $read($contactPath)["calculation"]["weekly_contact_milli"] !== 2500
) {
    throw new RuntimeException("Section contact time did not use the institution-selected unit.");
}
$student = $sis->student($sis->user());
$times = $sis->timesheetService();
$employee = $times->addEmployee($student->personId, "Fictional instructor");
$entry = $times->submit(
    $employee,
    new \Domain\Scheduling\TimeSlot(
        new \DateTimeImmutable("2026-08-10T09:00:00Z"),
        new \DateTimeImmutable("2026-08-10T11:00:00Z"),
    ),
);
$sis->access->actor = $sis->user();
$path = "/sis/timesheets/" . $entry->id->value;
$record = $read($path . "/");
if (!$record["time_review"]["requires_exception"]) {
    throw new RuntimeException("An enforced time exception was omitted.");
}
$body = [
    "approve" => true,
    "version" => 0,
    "review_revision" => $record["time_review"]["revision"],
    "exception_reason" => "Fictional evidence permits this work.",
];
$sis->access->staff = false;
$sis->access->grants = ["sis:hr:manage"];
if ($teachingPost($path . "/decision/", $body)->getStatusCode() !== 403) {
    throw new RuntimeException("HR approval bypassed the independent exception duty.");
}
$sis->access->grants[] = "sis:hr:time-exception";
if (
    $teachingPost($path . "/decision/", $body)->getStatusCode() !== 200 ||
    !$read($path . "/")["decision_recorded"]
) {
    throw new RuntimeException("Time decision did not retain verified review evidence.");
}
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
$sis->access->grants = [];
$owner = $read($path . "/");
if (
    $owner["exception_reason"] !== null ||
    $owner["time_review"]["policy"]["rules"]["contact_unit_minutes"] !== 60
) {
    throw new RuntimeException("Owner time view exposed HR reasons or lost the retained policy.");
}
echo "Workload policy checks passed.\n";
