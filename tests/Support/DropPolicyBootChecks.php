<?php

declare(strict_types=1);

// Actual providers/controllers, private JSON transport and framework CSRF; fictional SQLite only.
$sis->legacyGrade(
    new \Domain\Gradebook\GradeDefinition(
        \Domain\Shared\ValueObject\EntityId::generate(),
        "W",
        0,
        false,
        false,
    ),
);
$policyBase = "/sis/terms/" . $sis->term->value . "/drop-policy/";
$policyGet = static function (string $path) use (
    $app,
    $get,
): \Psr\Http\Message\ResponseInterface {
    $query = [];
    parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
    return $app->router->match(
        $get
            ->withUri(
                new \Laminas\Diactoros\Uri(
                    "https://campus.example.invalid" . $path,
                ),
            )
            ->withQueryParams($query),
    );
};
$policyShow = $policyGet($policyBase);
if (
    $policyShow->getStatusCode() !== 200 ||
    !str_contains($policyShow->getHeaderLine("Cache-Control"), "no-store") ||
    $policyShow->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException(
        "The private term policy provider did not resolve.",
    );
}
$policyData = [
    "version" => -1,
    "windows" => [["through" => null, "grade_code" => "W"]],
    "reason" => "Fictional reviewed policy.",
];
if (
    $teachingPost(
        $policyBase . "review/",
        array_diff_key($policyData, ["reason" => true]),
    )->getStatusCode() !== 422
) {
    throw new RuntimeException("A policy review omitted its reason.");
}
$policyReviewResponse = $teachingPost($policyBase . "review/", $policyData);
$policyReview = json_decode(
    (string) $policyReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $policyReviewResponse->getStatusCode() !== 200 ||
    !is_string($policyReview["review_token"] ?? null) ||
    $sis->sql->rows("sis_term_drop_policies") !== []
) {
    throw new RuntimeException(
        "The policy review failed or wrote a premature publication.",
    );
}
$policyCommit = $teachingPost($policyBase . "commit/", [
    "review_token" => $policyReview["review_token"],
]);
$policyReceipt = json_decode(
    (string) $policyCommit->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $policyCommit->getStatusCode() !== 200 ||
    ($policyReceipt["version"] ?? null) !== 0
) {
    throw new RuntimeException(
        "The reviewed policy did not publish through actual providers.",
    );
}
$policyRetry = $teachingPost($policyBase . "commit/", [
    "review_token" => $policyReview["review_token"],
]);
if (
    $policyRetry->getStatusCode() !== 200 ||
    (string) $policyRetry->getBody() !== (string) $policyCommit->getBody()
) {
    throw new RuntimeException("A policy retry changed its original result.");
}
$policyHistoryResponse = $policyGet($policyBase . "history/");
$policyHistory = json_decode(
    (string) $policyHistoryResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $policyHistoryResponse->getStatusCode() !== 200 ||
    count($policyHistory["items"] ?? []) !== 1 ||
    str_contains(
        (string) $policyHistoryResponse->getBody(),
        "Fictional reviewed policy.",
    ) ||
    $policyHistoryResponse->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException(
        "Policy history failed or exposed protected command context.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:registration:manage"];
if (
    $policyGet($policyBase . "history/")->getStatusCode() !== 403 ||
    $teachingPost($policyBase . "commit/", [
        "review_token" => $policyReview["review_token"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Registration duties implicitly granted policy publication or history access.",
    );
}
$sis->access->staff = true;
if (
    $policyGet(
        $policyBase .
            "history/?cursor=" .
            rawurlencode("fictional-invalid-cursor"),
    )->getStatusCode() !== 500
) {
    throw new RuntimeException(
        "A malformed policy history cursor was accepted.",
    );
}
