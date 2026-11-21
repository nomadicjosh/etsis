<?php

declare(strict_types=1);

require_once __DIR__ . "/StudentSpecializationFixture.php";
$f = new StudentSpecializationFixture($sis, static function (
    StudentSpecializationFixture $f,
): void {
    foreach ($f->choices as $i => $c) {
        $f->choices[$i] = $f
            ->choiceService()
            ->save(
                $f->sis->program,
                new \Domain\Shared\ValueObject\EntityId($c["id"]),
                1,
                $c["code"],
                $c["title"],
                null,
                true,
                $i === 1
                    ? \Domain\Catalog\ValueObject\CurriculumComponentKind::Concentration
                    : \Domain\Catalog\ValueObject\CurriculumComponentKind::Major,
                parentMajor: $i === 1
                    ? new \Domain\Shared\ValueObject\EntityId($f->choices[0]["id"])
                    : null,
            );
    }
});
$f->select([$f->choices[0]["id"], $f->choices[1]["id"]]);
$post = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $response = $teachingPost($path, $data);
    if ($response->getStatusCode() !== $status) {
        throw new RuntimeException(
            "Curriculum reporting route expected " .
                $status .
                ", got " .
                $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$before = $sis->sql->rows("sis_history_events");
$definition = [
    "dataset" => "selected_components",
    "columns" => [
        ["field" => "student_id", "label" => "Student", "aggregate" => "none"],
        ["field" => "pursuit_id", "label" => "Pursuit", "aggregate" => "none"],
        ["field" => "component_code", "label" => "Component", "aggregate" => "none"],
        ["field" => "cohort_matches", "label" => "Matches cohort", "aggregate" => "none"],
    ],
];
$result = $post("/sis/report-studio/preview/", ["definition" => $definition, "offset" => 0]);
if (count($result["items"]) !== 2 || array_column($result["items"], "c3") !== [true, true]) {
    throw new RuntimeException(
        "The actual report source lost its selected curriculum or cohort match.",
    );
}
$id = \Domain\Shared\ValueObject\EntityId::generate()->value;
$post(
    "/sis/report-studio/save/",
    [
        "report_id" => $id,
        "operation_id" => $id,
        "version" => -1,
        "name" => "Fictional selected curriculum",
        "definition" => $definition,
    ],
    201,
);
$post("/sis/report-studio/" . $id . "/run/", ["offset" => 0]);
$viewResponse = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid/sis/students/" .
                $result["items"][0]["c0"] .
                "/programs/" .
                $result["items"][0]["c1"] .
                "/specializations/",
        ),
    ),
);
if (
    $viewResponse->getStatusCode() !== 200 ||
    !str_contains($viewResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("The report curriculum handoff lost its private access boundary.");
}
$view = json_decode((string) $viewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (count($view["selection"]["specializations"]) !== 2) {
    throw new RuntimeException("The report pursuit did not resolve its retained choices.");
}
$graph = $post("/sis/report-studio/graph/", ["dataset" => "selected_components", "aliases" => []]);
foreach (["reason", "actor_id", "selected_json", "reference_json", "owner_user_id"] as $key) {
    if (isset($graph["dataset"]["fields"][$key])) {
        throw new RuntimeException("The report exposed a protected curriculum field.");
    }
}
$catalog = [
    "dataset" => "catalog_components",
    "columns" => [["field" => "component_code", "label" => "Component", "aggregate" => "none"]],
];
$post("/sis/report-studio/preview/", ["definition" => $catalog, "offset" => 0]);
$sis->access->staff = false;
$sis->access->grants = ["sis:reporting:run", "sis:student:read"];
$post("/sis/report-studio/" . $id . "/run/", ["offset" => 0]);
$post("/sis/report-studio/preview/", ["definition" => $catalog, "offset" => 0], 403);
$sis->access->grants = ["sis:reporting:run"];
$post("/sis/report-studio/" . $id . "/run/", ["offset" => 0], 403);
if ($before !== $sis->sql->rows("sis_history_events")) {
    throw new RuntimeException("Reporting appended an academic decision.");
}
echo "Curriculum reporting route checks passed.\n";
