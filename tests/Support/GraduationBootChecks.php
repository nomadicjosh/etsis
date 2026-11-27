<?php

declare(strict_types=1);

$f = $graduationFixture;
$base =
"/sis/students/" .
$f->curriculum->student->value .
"/programs/" .
$f->curriculum->assignment->value .
"/graduation/";
$owner =
"/portal/api/students/" .
$f->curriculum->student->value .
"/programs/" .
$f->curriculum->assignment->value .
"/graduation/";
$read = static function (string $path) use ($app, $get): array {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri($path)),
    );
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "Graduation read lost private route protection.",
        );
    }
    return json_decode(
        (string) $response->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
};
if (!$read($base)["can_review"]) {
    throw new RuntimeException(
        "Authorized eligibility reviewer was not recognized.",
    );
}
$review = $teachingPost($base . "review/", [
    "action" => "eligibility",
    "request" => [
        "proposal" => $f->proposal,
        "attested" => true,
        "history_acknowledged" => true,
    ],
    "reason" => "Fictional graduation clearance.",
]);
if ($review->getStatusCode() !== 200) {
    throw new RuntimeException("Graduation eligibility review failed.");
}
$review = json_decode(
    (string) $review->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if ($teachingPost($base . "confirm/", $review)->getStatusCode() !== 200) {
    throw new RuntimeException("Graduation eligibility confirmation failed.");
}
$group = $teachingPost("/sis/graduation-conferral/review/", [
    "candidates" => [
        [
            "student_id" => $f->curriculum->student->value,
            "pursuit_id" => $f->curriculum->assignment->value,
        ],
    ],
    "reason" => "Fictional authorized ceremony.",
]);
if ($group->getStatusCode() !== 200) {
    throw new RuntimeException("Group conferral review failed.");
}
$group = json_decode(
    (string) $group->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $teachingPost(
        "/sis/graduation-conferral/confirm/",
        $group,
    )->getStatusCode() !== 200
) {
    throw new RuntimeException("Group conferral confirmation failed.");
}
$sis->access->staff = false;
$sis->access->grants = [
    "sis:student:read",
    \Domain\Graduation\Service\GraduationService::REVIEW,
];
if (
    $teachingPost("/sis/graduation-conferral/page/", [])->getStatusCode() !==
    403
) {
    throw new RuntimeException(
        "Eligibility duty incorrectly allowed group conferral.",
    );
}
$sis->access->grants = [];
$sis->access->actor = $sis->admissions->person(
    $sis->admissions->student($f->curriculum->student)->personId,
)->ownerUserId;
if (
    $read($owner)["credential"]["proposal"]["title"] !== $f->proposal["title"]
) {
    throw new RuntimeException("The owner could not read their credential.");
}
if ($teachingPost($owner . "history/", [])->getStatusCode() !== 200) {
    throw new RuntimeException(
        "The owner could not read curated credential decisions.",
    );
}
$sis->access->actor = $sis->user();
if (
    $app->router
        ->match($get->withUri(new \Laminas\Diactoros\Uri($owner)))
        ->getStatusCode() !== 403
) {
    throw new RuntimeException("An unrelated owner read a credential.");
}
