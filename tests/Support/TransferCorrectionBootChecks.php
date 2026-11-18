<?php

declare(strict_types=1);

require_once __DIR__ . "/TransferAwardFixture.php";
$transfer = new TransferAwardFixture($sis, $sis->dropCipher);
$base = "/sis/students/" . $transfer->student->value . "/transfer-awards/" . $transfer->award->value . "/";
$data = [
    "action" => "amend",
    "credits_milli" => 4000,
    "awarded_grade" => "B",
    "reason" => "Fictional institutional transfer review.",
    "effective_on" => $sis->clock->now()->format("Y-m-d"),
];
$decode = static fn($response): array => json_decode(
    (string) $response->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$response = $teachingPost($base . "preview/", $data);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException("The actual transfer review route failed.");
}
$review = $decode($response);
$payload = $data + ["revision" => $review["revision"], "operation_id" => $review["operation_id"]];
$before = [$sis->sql->rows("sis_history_events"), $sis->sql->rows("sis_academic_credits")];
if ($teachingPost($base . "confirm/", $payload)->getStatusCode() !== 422) {
    throw new RuntimeException("The actual transfer route accepted an unconfirmed older review hash.");
}
$payload["review_token"] = $review["review_token"];
$actor = $sis->access->actor;
$sis->access->actor = $sis->user();
if ($teachingPost($base . "confirm/", $payload)->getStatusCode() !== 403) {
    throw new RuntimeException("A different staff member confirmed the browser review.");
}
$sis->access->actor = $actor;
$sis->clock->time = $sis->clock->time->modify("+20 minutes");
if (
    $teachingPost($base . "confirm/", $payload)->getStatusCode() !== 409 ||
    $before !== [$sis->sql->rows("sis_history_events"), $sis->sql->rows("sis_academic_credits")]
) {
    throw new RuntimeException("An expired transfer confirmation changed academic history.");
}
$review = $decode($teachingPost($base . "preview/", $data));
$payload = $data + [
    "revision" => $review["revision"],
    "operation_id" => $review["operation_id"],
    "review_token" => $review["review_token"],
];
$response = $teachingPost($base . "confirm/", $payload);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException("The current transfer review failed to confirm.");
}
$receipt = $decode($response);
$finance = $sis->financialEvents();
$void = [
    "action" => "void",
    "reason" => "Fictional subsequent void.",
    "effective_on" => $data["effective_on"],
];
$review = $decode($teachingPost($base . "preview/", $void));
if (
    $teachingPost(
        $base . "confirm/",
        $void + [
            "revision" => $review["revision"],
            "operation_id" => $review["operation_id"],
            "review_token" => $review["review_token"],
        ],
    )->getStatusCode() !== 200
) {
    throw new RuntimeException("The reviewed transfer void failed.");
}
$preserved = [$sis->sql->rows("sis_history_events"), $sis->sql->rows("sis_academic_credits")];
$sis->clock->time = $sis->clock->time->modify("+1 hour");
unset($payload["review_token"]);
$response = $teachingPost($base . "confirm/", $payload);
if (
    $response->getStatusCode() !== 200 ||
    $decode($response) !== $receipt ||
    $finance !== $sis->financialEvents() ||
    $preserved !== [$sis->sql->rows("sis_history_events"), $sis->sql->rows("sis_academic_credits")]
) {
    throw new RuntimeException("An accepted older-contract retry overwrote later academic facts.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:transfer:manage"];
if ($teachingPost($base . "preview/", $data)->getStatusCode() !== 403) {
    throw new RuntimeException("Transfer management bypassed the independent academic-read duty.");
}
