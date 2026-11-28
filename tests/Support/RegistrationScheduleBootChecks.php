<?php

declare(strict_types=1);

// Registration policy setup uses the actual provider/router, browser CSRF and private responses.
$windowBase = '/sis/terms/' . $sis->term->value . '/registration-window/';
$windowShow = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $windowBase)));
$windowData = json_decode((string) $windowShow->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($windowShow->getStatusCode() !== 200 || $windowData['published'] !== false ||
    !str_contains($windowShow->getHeaderLine('Cache-Control'), 'no-store') || $windowShow->hasHeader('phpdebugbar-id')) {
    throw new RuntimeException('The registration-policy provider lost private unrecorded setup.');
}
$windowInput = ['opens_at' => $sis->clock->now()->modify('-1 day')->format(DATE_ATOM),
    'closes_at' => $sis->clock->now()->modify('+1 day')->format(DATE_ATOM),
    'course_limit' => 5, 'version' => 0, 'reason' => 'Fictional HTTP calendar approval.'];
$windowReviewResponse = $teachingPost($windowBase . 'review/', $windowInput);
$windowReview = json_decode((string) $windowReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($windowReviewResponse->getStatusCode() !== 200 || !isset($windowReview['review_token'])) {
    throw new RuntimeException('The registration-policy HTTP review failed.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:registration:manage'];
if ($teachingPost($windowBase . 'publish/', ['review_token' => $windowReview['review_token']])->getStatusCode() !== 403 ||
    $teachingPost($windowBase . 'page/', [])->getStatusCode() !== 403) {
    throw new RuntimeException('Registration duty changed or read restricted catalog policy.');
}
$sis->access->staff = true;
$windowPublished = $teachingPost($windowBase . 'publish/', ['review_token' => $windowReview['review_token']]);
$windowPublishedData = json_decode((string) $windowPublished->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($windowPublished->getStatusCode() !== 200 || $windowPublishedData['version'] !== 1 ||
    $teachingPost($windowBase . 'page/', [])->getStatusCode() !== 200) {
    throw new RuntimeException('The reviewed registration policy did not publish through the provider.');
}

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
        "faculty_mode" => "warn",
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("Authorized timetable policy maintenance failed.");
}
$facultyPolicy = $app->make(\Domain\Registration\Service\RegistrationSchedulePolicyService::class)->current();
if ($facultyPolicy['faculty_mode'] !== 'warn' || $facultyPolicy['revision'] !== 1) {
    throw new RuntimeException('Faculty timetable policy did not resolve through the actual controller and provider.');
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
        "kind" => "fee",
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

// Staff and student schedule planning share the same private/CSRF-protected acceptance boundary.
foreach ([true, false] as $staffPlan) {
    $sis->access->staff = true;
    $planOwner = $sis->user();
    $planStudent = $sis->student($planOwner);
    $planSections = [$sis->section(fee: 0)->id->value, $sis->section(fee: 0)->id->value];
    $sis->access->actor = $planOwner;
    $sis->access->staff = $staffPlan;
    $sis->access->grants = [];
    $planPath = $staffPlan ? '/sis/registration-plans/' : '/portal/api/registration-plan/';
    $planInput = ['student_id' => $planStudent->id->value, 'section_ids' => $planSections];
    $unprotected = new \Qubus\Http\ServerRequest(
        [],
        [],
        'https://campus.example.invalid' . $planPath . 'review/',
        'POST',
    );
    if ($app->router->match($unprotected->withParsedBody($planInput))->getStatusCode() !== 403) {
        throw new RuntimeException('Schedule review bypassed CSRF.');
    }
    $planBefore = $t->digest();
    $response = $teachingPost($planPath . 'review/', $planInput);
    $planReview = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (
        $response->getStatusCode() !== 200 || !($planReview['can_confirm'] ?? false) ||
        !str_contains($response->getHeaderLine('Cache-Control'), 'no-store') || $t->digest() !== $planBefore
    ) {
        throw new RuntimeException('Actual schedule review failed its private read-only boundary.');
    }
    $planCommand = $planInput + array_intersect_key(
        $planReview,
        array_flip(['operation_id', 'revision', 'review_token']),
    );
    $unprotected = new \Qubus\Http\ServerRequest(
        [],
        [],
        'https://campus.example.invalid' . $planPath . 'commit/',
        'POST',
    );
    if ($app->router->match($unprotected->withParsedBody($planCommand))->getStatusCode() !== 403) {
        throw new RuntimeException('Schedule confirmation bypassed CSRF.');
    }
    $planActor = $sis->access->actor;
    $sis->access->staff = false;
    $sis->access->actor = $sis->user();
    if (!in_array($teachingPost($planPath . 'commit/', $planCommand)->getStatusCode(), [403, 404], true)) {
        throw new RuntimeException('Another account confirmed a private registration schedule.');
    }
    $sis->access->actor = $planActor;
    $sis->access->staff = $staffPlan;
    $acceptedPlan = $teachingPost($planPath . 'commit/', $planCommand);
    $retriedPlan = $teachingPost($planPath . 'commit/', $planCommand);
    if (
        $acceptedPlan->getStatusCode() !== 200 || $retriedPlan->getStatusCode() !== 200 ||
        (string) $acceptedPlan->getBody() !== (string) $retriedPlan->getBody() ||
        !str_contains($acceptedPlan->getHeaderLine('Cache-Control'), 'no-store')
    ) {
        throw new RuntimeException('Actual coordinated schedule confirmation or original-result retry failed.');
    }
}

// Staff and student section changes use the same protected application and atomic drop/add paths.
foreach ([true, false] as $staffChange) {
    $sis->access->staff = true;
    $sis->access->grants = [];
    $changeOwner = $sis->user();
    $changeStudent = $sis->student($changeOwner);
    $from = $sis->section(fee: 1000);
    $to = $sis->section(fee: 1500);
    $sis->sql->update("sis_sections", ["course_id" => $from->courseId->value], ["id" => $to->id->value]);
    $sis->service->register($changeStudent->id, $from->id, true);
    $sis->access->actor = $changeOwner;
    $sis->access->staff = $staffChange;
    $path = $staffChange ? "/sis/section-changes/" : "/portal/api/section-changes/";
    $input = [
        "student_id" => $changeStudent->id->value,
        "from_section_id" => $from->id->value,
        "to_section_id" => $to->id->value,
    ];
    $before = $t->digest();
    foreach (["choices", "review"] as $action) {
        $unprotected = new \Qubus\Http\ServerRequest(
            [],
            [],
            "https://campus.example.invalid" . $path . $action . "/",
            "POST",
        );
        if ($app->router->match($unprotected->withParsedBody($input))->getStatusCode() !== 403) {
            throw new RuntimeException("Section change bypassed framework CSRF protection.");
        }
        $response = $teachingPost($path . $action . "/", $input);
        if (
            $response->getStatusCode() !== 200 ||
            !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
            $t->digest() !== $before
        ) {
            throw new RuntimeException("Section change review lost its private read-only boundary.");
        }
    }
    $review = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if (!($review["can_confirm"] ?? false)) {
        throw new RuntimeException("Actual section-change review was not ready.");
    }
    $command =
        $input + array_intersect_key($review, array_flip(["operation_id", "revision", "review_token"]));
    $unprotected = new \Qubus\Http\ServerRequest(
        [],
        [],
        "https://campus.example.invalid" . $path . "commit/",
        "POST",
    );
    if ($app->router->match($unprotected->withParsedBody($command))->getStatusCode() !== 403) {
        throw new RuntimeException("Section-change confirmation bypassed CSRF.");
    }
    $sis->access->staff = false;
    $sis->access->actor = $sis->user();
    if ($teachingPost($path . "commit/", $command)->getStatusCode() !== 403) {
        throw new RuntimeException("Another user confirmed a section change.");
    }
    $sis->access->actor = $changeOwner;
    $sis->access->staff = $staffChange;
    $accepted = $teachingPost($path . "commit/", $command);
    $retried = $teachingPost($path . "commit/", $command);
    if (
        $accepted->getStatusCode() !== 200 ||
        $retried->getStatusCode() !== 200 ||
        (string) $accepted->getBody() !== (string) $retried->getBody() ||
        !str_contains($accepted->getHeaderLine("Cache-Control"), "no-store")
    ) {
        throw new RuntimeException("Actual section change or accepted-result retry failed.");
    }
}
