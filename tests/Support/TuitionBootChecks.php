<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;
use Domain\Tuition\Service\TuitionLedger;

$decode = static function (\Psr\Http\Message\ResponseInterface $r): array {
    if (
        $r->getStatusCode() !== 200 ||
        !str_contains($r->getHeaderLine("Cache-Control"), "no-store") ||
        $r->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "Tuition HTTP privacy or response check failed: " .
                $r->getStatusCode(),
        );
    }
    return json_decode(
        (string) $r->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
};
$read = static fn(
    string $path,
): \Psr\Http\Message\ResponseInterface => $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path),
    ),
);
$student = $sis->student();
$oldTuition = $teachingPost("/sis/billing-rules/", [
    "operation_id" => EntityId::generate()->value,
    "term_id" => $sis->term->value,
    "kind" => "tuition",
    "name" => "Fictional obsolete tuition",
    "currency" => "USD",
    "amount_minor" => 100,
    "reason" => "Fictional unsupported tuition authoring.",
]);
if (
    $oldTuition->getStatusCode() !== 422 ||
    $sis->sql->rows("sis_billing_rules") !== []
) {
    throw new RuntimeException(
        "New tuition must use the reviewed schedule workflow.",
    );
}
$r = $decode(
    $teachingPost("/sis/tuition/schedule/review/", [
        "id" => null,
        "version" => 0,
        "name" => "Fictional HTTP tuition",
        "currency" => "USD",
        "income" => $tuitionFixture->income->value,
        "brackets" => [
            [
                "from_milli" => 0,
                "through_milli" => 999999,
                "flat_minor" => 0,
                "rate_minor" => 10000,
                "rate_from_milli" => 0,
            ],
        ],
        "reason" => "Approve fictional HTTP schedule.",
    ]),
);
$schedule = $decode(
    $teachingPost("/sis/tuition/confirm/", [
        "review_token" => $r["review_token"],
    ]),
);
$r = $decode(
    $teachingPost("/sis/tuition/assignment/review/", [
        "scope" => "term",
        "scope_id" => $sis->term->value,
        "schedule_id" => $schedule["id"],
        "version" => 0,
        "reason" => "Assign fictional HTTP schedule.",
    ]),
);
$decode(
    $teachingPost("/sis/tuition/confirm/", [
        "review_token" => $r["review_token"],
    ]),
);
$sis->service->register($student->id, $sis->section(fee: 1000)->id);
$id = TuitionLedger::billId($student->id, $sis->term)->value;
$bill = $decode($read("/sis/tuition/bills/" . $id . "/"));
$r = $decode(
    $teachingPost("/sis/tuition/bills/" . $id . "/review/", [
        "version" => $bill["version"],
        "lines" => array_map(
            static fn(array $l): array => [
                "key" => $l["key"],
                "minor" => $l["minor"],
            ],
            $bill["lines"],
        ),
        "due_on" => "2026-08-31",
        "reason" => "Issue fictional HTTP invoice.",
    ]),
);
$invoice = $decode(
    $teachingPost("/sis/tuition/confirm/", [
        "review_token" => $r["review_token"],
    ]),
);
$owner = $decode($read("/portal/api/tuition/invoices/" . $invoice["id"] . "/"));
if (
    $owner["total_minor"] !== 31000 ||
    isset($owner["lines"][0]["account"]) ||
    isset($owner["recipient"]["core_reference"])
) {
    throw new RuntimeException(
        "Owner invoice exposed staff pricing configuration or lost its original amount.",
    );
}
$decode($read("/portal/api/tuition/bills/"));
$decode($read("/sis/tuition/schedules/"));
foreach (["/sis/tuition/invoices/", "/portal/api/tuition/invoices/"] as $base) {
    $print = $read($base . $invoice["id"] . "/print/");
    if (
        $print->getStatusCode() !== 200 ||
        !str_contains($print->getHeaderLine("Cache-Control"), "no-store") ||
        $print->hasHeader("phpdebugbar-id") ||
        !str_contains((string) $print->getBody(), "INV-")
    ) {
        throw new RuntimeException(
            "Invoice print lost its original content or privacy.",
        );
    }
}
$sis->access->actor = $sis->user();
if (
    $read(
        "/portal/api/tuition/invoices/" . $invoice["id"] . "/",
    )->getStatusCode() !== 403 ||
    $read(
        "/portal/api/tuition/invoices/" . $invoice["id"] . "/print/",
    )->getStatusCode() !== 403 ||
    $decode($read("/portal/api/tuition/bills/"))["items"] !== []
) {
    throw new RuntimeException(
        "Staff authority bypassed the portal invoice owner check.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:finance:read"];
if ($read("/sis/tuition/bills/")->getStatusCode() !== 403) {
    throw new RuntimeException(
        "Student-read duty was not required for staff bills.",
    );
}
$sis->access->actor = $originalSubmissionActor;
$sis->access->staff = true;
