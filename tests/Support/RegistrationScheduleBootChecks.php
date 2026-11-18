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
$review = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $reviewResponse->getStatusCode() !== 200 ||
    ($review["blocked"] ?? null) !== true ||
    $registrationPost($input + ["by_staff" => true])->getStatusCode() !== 422
) {
    throw new RuntimeException("A confirmed overlap bypassed the actual registration policy or provider.");
}
$sis->access->staff = false;
$sis->access->grants = [];
$sis->access->actor = $sis->user();
if ($teachingPost("/sis/registration-schedule/review/", $input)->getStatusCode() !== 403) {
    throw new RuntimeException("Another account read a private timetable.");
}
$sis->access->actor = $sis->admissions->person($student->personId)->ownerUserId;
if ($teachingPost("/sis/registration-schedule/review/", $input)->getStatusCode() !== 200) {
    throw new RuntimeException("The student owner could not review their own timetable.");
}
$sis->access->grants = ["sis:registration:manage"];
if (
    $teachingPost("/sis/registration-policy/", [
        "mode" => "warn",
        "revision" => 0,
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Registration duty changed institutional policy.");
}
$sis->access->grants[] = "sis:registration:policy";
if (
    $teachingPost("/sis/registration-policy/", [
        "mode" => "warn",
        "revision" => 0,
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("Authorized timetable policy maintenance failed.");
}
$accepted = $registrationPost($input + ["by_staff" => true]);
if ($accepted->getStatusCode() !== 201) {
    throw new RuntimeException("Warning mode did not allow reviewed registration.");
}
$history = EventStoreFixture::history($sis->connection->queryBuilder());
$id = json_decode((string) $accepted->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$event = $history->latest(
    new \Domain\Shared\EventSourcing\StreamReference(
        new \Domain\Registration\ValueObject\EnrollmentHistoryId($id),
        "enrollment",
    ),
);
if (($event?->event->metaParam("schedule_evidence")["policy"]["mode"] ?? null) !== "warn") {
    throw new RuntimeException("The actual enrollment did not retain its accepted timetable evidence.");
}
$before = $t->digest();
if ($registrationPost($input + ["by_staff" => true])->getStatusCode() !== 201 || $t->digest() !== $before) {
    throw new RuntimeException("A repeated registration changed its original accepted facts.");
}

// Student checkout shares the registrar/group price review and atomic registration command.
$sis->access->staff = true;
$portalOwner = $sis->user();
$portalStudent = $sis->student($portalOwner);
$pricedSection = $sis->section(fee: 1000);
foreach ([[500, "term"], [125, "registered_credit"]] as [$minor, $basis]) {
    $defined = $teachingPost("/sis/billing-rules/", [
        "operation_id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
        "term_id" => $sis->term->value,
        "name" => "Fictional " . $basis,
        "kind" => "tuition",
        "pricing_basis" => $basis,
        "amount_minor" => $minor,
        "currency" => "USD",
        "reason" => "Fictional approved registration pricing.",
    ]);
    if ($defined->getStatusCode() !== 201) {
        throw new RuntimeException("The real billing rule route could not define registration prices.");
    }
}
$sis->access->actor = $portalOwner;
$sis->access->staff = false;
$sis->access->grants = [];
$checkoutInput = ["student_id" => $portalStudent->id->value, "section_id" => $pricedSection->id->value];
$beforeCheckout = $t->digest();
$checkoutResponse = $teachingPost("/portal/api/registration/review/", $checkoutInput);
$checkoutReview = json_decode((string) $checkoutResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $checkoutResponse->getStatusCode() !== 200 ||
    ($checkoutReview["students"][0]["charges"]["total_minor"] ?? null) !== 1875 ||
    str_contains((string) $checkoutResponse->getBody(), "balance_before") ||
    str_contains((string) $checkoutResponse->getBody(), "account_id") ||
    $t->digest() !== $beforeCheckout ||
    $teachingPost("/portal/api/register/", $checkoutInput)->getStatusCode() !== 422
) {
    throw new RuntimeException(
        "The student registration review lost its exact price, privacy or no-write boundary.",
    );
}
$checkoutCommit = $checkoutInput + [
    "operation_id" => $checkoutReview["operation_id"],
    "revision" => $checkoutReview["revision"],
    "review_token" => $checkoutReview["review_token"],
];
$checkoutSaved = $teachingPost("/portal/api/register/", $checkoutCommit);
$afterCheckout = $t->digest();
$checkoutRetry = $teachingPost("/portal/api/register/", $checkoutCommit);
$checkoutAccount = $sis->sql->one("sis_student_accounts", ["student_id" => $portalStudent->id->value]);
if (
    $checkoutSaved->getStatusCode() !== 200 ||
    (string) $checkoutSaved->getBody() !== (string) $checkoutRetry->getBody() ||
    $t->digest() !== $afterCheckout ||
    $sis->accounts->get(new \Domain\Finance\ValueObject\AccountId($checkoutAccount["id"]))->balance()
        ->minor !== 1875 ||
    $sis->registration->enrollment($portalStudent->id, $pricedSection->id)?->status->value !== "N" ||
    $teachingPost("/sis/registration-batches/review/", [
        "section_id" => $pricedSection->id->value,
        "student_ids" => [$portalStudent->id->value],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Student confirmation, original receipt retry or staff separation failed.");
}
$expiredSection = $sis->section(fee: 0);
$expiredInput = ["student_id" => $portalStudent->id->value, "section_id" => $expiredSection->id->value];
$expiredResponse = $teachingPost("/portal/api/registration/review/", $expiredInput);
$expiredReview = json_decode((string) $expiredResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($expiredResponse->getStatusCode() !== 200) {
    throw new RuntimeException("The second student review failed.");
}
$sis->clock->time = $sis->clock->time->modify("+21 minutes");
$beforeExpired = $t->digest();
if (
    $teachingPost(
        "/portal/api/register/",
        $expiredInput + [
            "operation_id" => $expiredReview["operation_id"],
            "revision" => $expiredReview["revision"],
            "review_token" => $expiredReview["review_token"],
        ],
    )->getStatusCode() !== 409 ||
    $t->digest() !== $beforeExpired
) {
    throw new RuntimeException("An expired portal review appended registration or financial facts.");
}
