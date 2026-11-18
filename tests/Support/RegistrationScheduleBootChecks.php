<?php

declare(strict_types=1);

require_once __DIR__ . "/RegistrationScheduleFixture.php";
$t = new \Tests\Support\RegistrationScheduleFixture($sis);
$student = $sis->student();
$a = $sis->section();
$b = $sis->section();
$t->publish($a, [["2026-09-01T16:00:00Z", "2026-09-01T17:00:00Z"]]);
$t->publish($b, [["2026-09-01T16:30:00Z", "2026-09-01T17:30:00Z"]]);
$sis->service->register($student->id, $a->id, true);
$input = ["student_id" => $student->id->value, "section_id" => $b->id->value];
$reviewResponse = $teachingPost("/sis/registration-schedule/review/", $input);
$review = json_decode(
    (string) $reviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $reviewResponse->getStatusCode() !== 200 ||
    ($review["blocked"] ?? null) !== true ||
    $teachingPost(
        "/sis/registrations/",
        $input + ["by_staff" => true],
    )->getStatusCode() !== 422
) {
    throw new RuntimeException(
        "A confirmed overlap bypassed the actual registration policy or provider.",
    );
}
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $sis->user();
if (
    $teachingPost(
        "/sis/registration-schedule/review/",
        $input,
    )->getStatusCode() !== 403
) {
    throw new RuntimeException("Another account read a private timetable.");
}
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
if (
    $teachingPost(
        "/sis/registration-schedule/review/",
        $input,
    )->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "The student owner could not review their own timetable.",
    );
}
$sis->access->grants = ["sis:registration:manage"];
if (
    $teachingPost("/sis/registration-policy/", [
        "mode" => "warn",
        "revision" => 0,
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Registration duty changed institutional policy.",
    );
}
$sis->access->grants[] = "sis:registration:policy";
if (
    $teachingPost("/sis/registration-policy/", [
        "mode" => "warn",
        "revision" => 0,
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "Authorized timetable policy maintenance failed.",
    );
}
$accepted = $teachingPost("/sis/registrations/", $input + ["by_staff" => true]);
if ($accepted->getStatusCode() !== 201) {
    throw new RuntimeException(
        "Warning mode did not allow reviewed registration.",
    );
}
$history = EventStoreFixture::history($sis->connection->queryBuilder());
$id = json_decode(
    (string) $accepted->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
)["id"];
$event = $history->latest(
    new \Domain\Shared\EventSourcing\StreamReference(
        new \Domain\Registration\ValueObject\EnrollmentHistoryId($id),
        "enrollment",
    ),
);
if (
    ($event?->event->metaParam("schedule_evidence")["policy"]["mode"] ??
        null) !==
    "warn"
) {
    throw new RuntimeException(
        "The actual enrollment did not retain its accepted timetable evidence.",
    );
}
$before = $t->digest();
if (
    $teachingPost(
        "/sis/registrations/",
        $input + ["by_staff" => true],
    )->getStatusCode() !== 201 ||
    $t->digest() !== $before
) {
    throw new RuntimeException(
        "A repeated registration changed its original accepted facts.",
    );
}
