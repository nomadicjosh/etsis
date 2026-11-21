<?php

declare(strict_types=1);

require_once __DIR__ . "/StudentSpecializationFixture.php";
$post = static function (string $path, array $data, int $status = 200) use ($teachingPost): array {
    $r = $teachingPost($path, $data);
    if ($r->getStatusCode() !== $status) {
        throw new RuntimeException(
            "Component-major route expected " . $status . ", got " . $r->getStatusCode(),
        );
    }
    return json_decode((string) $r->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$base = "/sis/programs/" . $sis->program->value . "/specializations/";
$save = static fn(array $choice, string $kind, ?string $parent): array => [
    "id" => $choice["id"],
    "revision" => $choice["revision"],
    "code" => $choice["code"],
    "title" => $choice["title"],
    "description" => null,
    "active" => true,
    "kind" => $kind,
    "department_id" => null,
    "parent_major_id" => $parent,
];
$major = $child = $childBody = null;
$f = new StudentSpecializationFixture($sis, static function (StudentSpecializationFixture $f) use (
    $post,
    $save,
    $base,
    &$major,
    &$child,
    &$childBody,
): void {
    $major = $post($base . "save/", $save($f->choices[0], "major", null));
    $childBody = $save($f->choices[1], "concentration", $major["id"]);
    $missing = $childBody;
    unset($missing["parent_major_id"]);
    $post($base . "save/", $missing, 422);
    $child = $post($base . "save/", $childBody);
    $f->choices[0] = $major;
    $f->choices[1] = $child;
    if ($child["parent_major"]["id"] !== $major["id"]) {
        throw new RuntimeException("The required major was lost by the controller.");
    }
});
$page = $post($base . "page/", ["search" => "", "after" => null, "kind" => "major"]);
if (array_column($page["items"], "id") !== [$major["id"]]) {
    throw new RuntimeException("The same-program major filter included other component types.");
}
$post($base . "page/", ["search" => "", "after" => null, "kind" => "invalid"], 422);
$selection =
"/sis/students/" .
$f->student->value .
"/programs/" .
$f->assignment->value .
"/specializations/";
$view = $f->service->view($f->student, $f->assignment);
$reviewBody = [
    "revision" => $view["revision"],
    "specialization_ids" => [$child["id"]],
    "reason" => "Fictional reviewed curriculum.",
];
$post($selection . "review/", $reviewBody, 422);
$reviewBody["specialization_ids"] = [$major["id"], $child["id"]];
$r = $post($selection . "review/", $reviewBody);
$post($selection . "commit/", ["review_token" => $r["review_token"]]);
$before = $sis->sql->rows("sis_history_events");
$post($selection . "commit/", ["review_token" => $r["review_token"]]);
if ($before !== $sis->sql->rows("sis_history_events")) {
    throw new RuntimeException("A linked-selection retry duplicated an event.");
}
$sis->access->staff = false;
$post($base . "page/", ["search" => "", "after" => null, "kind" => "major"], 403);
$post($base . "save/", array_replace($childBody, ["revision" => 2]), 403);
echo "Component-major route checks passed.\n";
