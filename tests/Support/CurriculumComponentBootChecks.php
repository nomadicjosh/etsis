<?php

declare(strict_types=1);

require_once __DIR__ . "/StudentSpecializationFixture.php";
$f = new StudentSpecializationFixture($sis);
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
            "Curriculum mutation expected " . $status . ", got " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$read = static function (string $path) use ($app, $get): array {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "private, no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Curriculum read lost private response or authorization.");
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$base = "/sis/programs/" . $sis->program->value . "/specializations/";
$choice = $f->choices[0];
$save = [
    "id" => $choice["id"],
    "revision" => 1,
    "code" => $choice["code"],
    "title" => $choice["title"],
    "description" => null,
    "active" => true,
    "kind" => "major",
    "parent_major_id" => null,
];
$bad = $save;
unset($bad["kind"]);
$postData($base . "save/", $bad, 422);
$postData($base . "save/", array_replace($save, ["kind" => "unsupported"]), 422);
$postData($base . "save/", $save);
if ($read($base . $choice["id"] . "/")["kind"] !== "major") {
    throw new RuntimeException("A component type was lost.");
}
$postData($base . "policy/", [
    "revision" => 1,
    "minimum" => 0,
    "maximum" => 2,
    "component_sharing" => "multiple_components",
    "kind_limits" => ["major" => ["minimum" => 1, "maximum" => 1]],
]);
$programBase = "/sis/programs/" . $sis->program->value . "/publication/";
$review = $postData($programBase . "review/", [
    "academic_year_id" => $f->year["id"],
    "reason" => "Fictional typed curriculum publication.",
]);
$published = $postData($programBase . "commit/", ["review_token" => $review["review_token"]]);
$cohorts = $sis->studentCatalogs();
$reason = "Fictional reviewed component cohort.";
$review = $cohorts->review(
    $f->student,
    $f->assignment,
    new \Domain\Shared\ValueObject\EntityId($published["operation_id"]),
    $cohorts->view($f->student, $f->assignment)["version"],
    $reason,
);
$cohorts->commit(
    $f->student,
    $f->assignment,
    new \Domain\Shared\ValueObject\EntityId($published["operation_id"]),
    new \Domain\Shared\ValueObject\EntityId($review["operation_id"]),
    $review["version"],
    $reason,
    $review["review_token"],
);
$studentPath =
"/sis/students/" .
$f->student->value .
"/programs/" .
$f->assignment->value .
"/specializations/";
$view = $read($studentPath);
$input = [
    "revision" => $view["revision"],
    "specialization_ids" => [$f->choices[1]["id"]],
    "reason" => "Fictional registrar choice.",
];
$postData($studentPath . "review/", $input, 422);
$input["specialization_ids"] = [$choice["id"]];
$review = $postData($studentPath . "review/", $input);
$postData($studentPath . "commit/", ["review_token" => $review["review_token"]]);
if ($read($studentPath)["selection"]["specializations"][0]["kind"] !== "major") {
    throw new RuntimeException("The selected major was not retained.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read"];
$postData($base . "save/", array_replace($save, ["revision" => 2]), 403);
echo "Curriculum component route checks passed.\n";
