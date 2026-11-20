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
$policySection = $sis->section(20, 0);
$sectionPolicyBase = '/sis/terms/' . $sis->term->value . '/sections/' . $policySection->id->value . '/drop-policy/';
$sectionPolicyRead = $policyGet($sectionPolicyBase);
$sectionPolicy = json_decode((string) $sectionPolicyRead->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $sectionPolicyRead->getStatusCode() !== 200 || !($sectionPolicy['uses_term_policy'] ?? false) ||
    !str_contains($sectionPolicyRead->getHeaderLine('Cache-Control'), 'no-store') ||
    $sectionPolicyRead->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The section policy did not inherit privately through actual providers.');
}
$sectionReviewResponse = $teachingPost($sectionPolicyBase . 'review/', [
    'version' => -1, 'windows' => [['through' => null, 'grade_code' => 'W']],
    'uses_term_policy' => false, 'grace_ends_on' => '2026-08-09', 'reason' => 'Fictional short section deadline.',
]);
$sectionReview = json_decode((string) $sectionReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $sectionReviewResponse->getStatusCode() !== 200 ||
    ($sectionReview['definition']['section_id'] ?? null) !== $policySection->id->value
) {
    throw new RuntimeException('A section override did not retain its own section and deadline.');
}
$sectionCommand = ['review_token' => $sectionReview['review_token']];
$unsafePolicyPost = $post->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid' . $sectionPolicyBase . 'commit/'
))->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
$unsafePolicyPost->getBody()->write(http_build_query(['_workspace_json' => json_encode($sectionCommand)]));
$unsafePolicyPost->getBody()->rewind();
if ($app->router->match($unsafePolicyPost)->getStatusCode() !== 403) {
    throw new RuntimeException('A section policy publication bypassed framework CSRF.');
}
$sectionCommit = $teachingPost($sectionPolicyBase . 'commit/', $sectionCommand);
if ($sectionCommit->getStatusCode() !== 200) {
    throw new RuntimeException('A section policy failed its actual-provider confirmation.');
}
$sectionResetResponse = $teachingPost($sectionPolicyBase . 'review/', [
    'version' => 0, 'windows' => [['through' => null, 'grade_code' => 'W']],
    'uses_term_policy' => true, 'reason' => 'Fictional return to term policy.',
]);
$sectionReset = json_decode((string) $sectionResetResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $sectionResetResponse->getStatusCode() !== 200 ||
    $teachingPost($sectionPolicyBase . 'commit/', [
        'review_token' => $sectionReset['review_token'],
    ])->getStatusCode() !== 200 ||
    (string) $teachingPost($sectionPolicyBase . 'commit/', $sectionCommand)->getBody() !==
        (string) $sectionCommit->getBody()
) {
    throw new RuntimeException('Returning to inheritance changed an original section-policy receipt.');
}
$sectionHistory = $policyGet($sectionPolicyBase . 'history/');
$sectionRows = json_decode((string) $sectionHistory->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    count($sectionRows['items'] ?? []) !== 2 ||
    str_contains((string) $sectionHistory->getBody(), 'Fictional short section deadline.')
) {
    throw new RuntimeException('Section policy history lost its original definitions or exposed a private reason.');
}
$sis->access->staff = false;
$sis->access->grants = ["sis:registration:manage"];
if (
    $policyGet($sectionPolicyBase)->getStatusCode() !== 403 ||
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
