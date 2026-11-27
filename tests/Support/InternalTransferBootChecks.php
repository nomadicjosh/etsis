<?php

declare(strict_types=1);

$student = $internalFixture->curriculum->student;
$target = \Domain\Shared\ValueObject\EntityId::generate();
$program = \Domain\Shared\ValueObject\EntityId::generate();
$sis->sql->insert("sis_programs", [
    "id" => $program->value,
    "code" => "RECEIVE.UG",
    "title" => "Fictional receiving program",
]);
new \Infrastructure\Persistence\Repository\SqlStudentRecordRepository(
    $sis->sql,
)->addAssignment(
    new \Domain\Student\StudentProgram($target, $student, $program, $sis->term),
);
$base =
"/sis/students/" .
$student->value .
"/programs/" .
$target->value .
"/internal-transfers/";
$decodeInternal = static function (
    \Psr\Http\Message\ResponseInterface $response,
): array {
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException(
            "Internal transfer route returned " . $response->getStatusCode(),
        );
    }
    return json_decode(
        (string) $response->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
};
$readInternal = static function (string $path) use (
    $app,
    $get,
): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid" . $path,
            ),
        ),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Internal transfer read lost privacy.");
    }
    return $response;
};
$page = $decodeInternal($teachingPost($base . "candidates/", []));
if (count($page["items"]) !== 1) {
    throw new RuntimeException(
        "Actual candidate provider lost original completed work.",
    );
}
$source = $page["items"][0];
$version = $sis->publishCourse(
    new \Domain\Shared\ValueObject\EntityId($source["course_id"]),
);
$choices = $decodeInternal(
    $teachingPost(
        $base . "courses/" . $source["course_id"] . "/publications/",
        [],
    ),
);
if (!$choices["items"]) {
    throw new RuntimeException("Published receiving choices did not resolve.");
}
$review = $decodeInternal(
    $teachingPost($base . "review/", [
        "action" => "grant",
        "request" => [
            "source_credit_id" => $source["source_credit_id"],
            "course_id" => $source["course_id"],
            "course_version_id" => $version->value,
            "term_id" => $sis->term->value,
            "credits_milli" => 3000,
            "grade_code" => "A",
        ],
        "reason" => "Fictional authorized receiving decision.",
    ]),
);
$accepted = $decodeInternal($teachingPost($base . "confirm/", $review));
$decodeInternal($teachingPost($base . "confirm/", $review));
$award = $accepted["award_id"];
$record = $decodeInternal($readInternal($base . $award . "/"));
if ($record["source"]["source_credit_id"] !== $source["source_credit_id"]) {
    throw new RuntimeException("Original lineage was lost.");
}
$decodeInternal($teachingPost($base . $award . "/history/", []));
$foreign =
"/sis/students/" .
$student->value .
"/programs/" .
$internalFixture->curriculum->assignment->value .
"/internal-transfers/" .
$award .
"/";
if ($readInternal($foreign)->getStatusCode() !== 403) {
    throw new RuntimeException("Foreign receiving pursuit read succeeded.");
}
$sis->access->staff = false;
if (
    $readInternal($base . $award . "/")->getStatusCode() !== 403 ||
    $teachingPost($base . "review/", $review)->getStatusCode() !== 403
) {
    throw new RuntimeException("Owner acquired transfer evaluation authority.");
}
$sis->access->grants = ["sis:transfer:manage"];
if ($readInternal($base . $award . "/")->getStatusCode() !== 403) {
    throw new RuntimeException("Student read duty was not required.");
}
