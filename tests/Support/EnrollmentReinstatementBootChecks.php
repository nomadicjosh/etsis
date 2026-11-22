<?php

declare(strict_types=1);

require_once __DIR__ . "/EnrollmentReinstatementFixture.php";
$f = new \Tests\Support\EnrollmentReinstatementFixture($sis, registeredQuantities: [4500]);
$path = sprintf(
    "/sis/students/%s/sections/%s/enrollments/%s/reinstatement/",
    $f->student->id->value,
    $f->section->id->value,
    $f->enrollment->id->value,
);
$postData = static function (
    string $path,
    array $data,
    int $status = 200
) use (
    $teachingPost,
): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException(
            "Reinstatement expected " . $status . ", got " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$reason = ["reason" => "Fictional reviewed withdrawal correction."];
$review = $postData($path . "review/", $reason);
if (
    !$review["can_confirm"] || $review["financial_effect"] !== "preserve_existing_postings"
    || $review['attempted_milli'] !== 3000
    || $review['registered_credit_restoration'] !== [
        'original_milli' => 3000, 'registered_milli' => 4500, 'correction_count' => 1,
    ]
) {
    throw new RuntimeException(
        "The actual reinstatement review lost its financial or eligibility contract.",
    );
}
$sis->access->staff = false;
foreach (
    ["sis:registration:reinstate", "sis:registration:manage", "sis:gradebook:closed"] as $missing
) {
    $sis->access->grants = array_values(
        array_diff(
            ["sis:registration:reinstate", "sis:registration:manage", "sis:gradebook:closed"],
            [$missing],
        ),
    );
    $postData($path . "review/", $reason, 403);
    $postData($path . "confirm/", $review, 403);
}
$sis->access->staff = true;
$before = array_map($sis->sql->rows(...), [
    "sis_student_accounts",
    "sis_account_entries",
    "sis_account_balances",
]);
$result = $postData($path . "confirm/", $review);
$sis->clock->time = $sis->clock->time->modify("+21 minutes");
if (
    $postData($path . "confirm/", $review) !== $result ||
    $result["enrollment_id"] !== $f->enrollment->id->value ||
    $before !==
        array_map($sis->sql->rows(...), [
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
        ])
) {
    throw new RuntimeException(
        "Reinstatement retry changed its original identity or existing postings.",
    );
}
$historyPath = "/sis/students/" . $f->student->id->value . "/registration-history/";
$history = $postData($historyPath, []);
if (
    array_column($history["items"][0]["items"], "action") !== [
        "registered",
        "late_drop",
        "reinstated",
    ]
) {
    throw new RuntimeException(
        "The registrar history lost the original withdrawal or restoration.",
    );
}
echo "Reinstatement route checks passed.\n";
