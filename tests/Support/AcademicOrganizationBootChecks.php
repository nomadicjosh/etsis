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
            "Organization route expected " . $status . ", got " . $response->getStatusCode(),
        );
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$school = $postData("/sis/catalog/classifications/school/save/", [
    "id" => null,
    "revision" => null,
    "code" => "ARTS",
    "title" => "Fictional Arts college",
    "active" => true,
]);
$department = $postData("/sis/catalog/classifications/department/save/", [
    "id" => null,
    "revision" => null,
    "code" => "ENG",
    "title" => "Fictional English",
    "active" => true,
    "school_id" => $school["id"],
]);
$choice = $f->choices[0];
$jointSchool = $postData('/sis/catalog/classifications/school/save/', [
    'id' => null, 'revision' => null, 'code' => 'LANG', 'title' => 'Fictional Languages college', 'active' => true,
]);
$jointDepartment = $postData('/sis/catalog/classifications/department/save/', [
    'id' => null, 'revision' => null, 'code' => 'LING', 'title' => 'Fictional Linguistics',
    'active' => true, 'school_id' => $jointSchool['id'],
]);
$base = "/sis/programs/" . $sis->program->value . "/specializations/";
$save = [
    "id" => $choice["id"],
    "revision" => 1,
    "code" => $choice["code"],
    "title" => $choice["title"],
    "description" => null,
    "active" => true,
    "kind" => "major",
    "department_id" => $department["id"],
    "parent_major_id" => null,
    'additional_department_ids' => [$jointDepartment['id']],
];
$accepted = $postData($base . "save/", $save);
if ($accepted["department"]["school"]["title"] !== "Fictional Arts college") {
    throw new RuntimeException("The actual component controller lost its department.");
}
if ($accepted['additional_departments'][0]['school']['id'] !== $jointSchool['id']) {
    throw new RuntimeException('The component controller lost the joint owner or its own college.');
}
$postData($base . 'save/', array_replace($save, ['revision' => 2,
    'additional_department_ids' => [$department['id']]]), 422);
$postData($base . 'save/', array_replace($save, ['revision' => 2,
    'additional_department_ids' => [$jointSchool['id']]]), 422);
$postData($base . 'save/', array_replace($save, ['revision' => 2,
    'additional_department_ids' => array_fill(0, 6, $jointDepartment['id'])]), 422);
$publication = "/sis/programs/" . $sis->program->value . "/publication/";
$r = $postData($publication . "review/", [
    "academic_year_id" => $f->year["id"],
    "reason" => "Fictional reviewed organization.",
]);
if ($r["specializations"]["offered"][0]["department"]["id"] !== $department["id"]) {
    throw new RuntimeException("The reviewed owner was not retained.");
}
if (
    $r['specializations']['offered'][0]['additional_departments'][0]['school']['title']
    !== 'Fictional Languages college'
) {
    throw new RuntimeException('The review lost a joint department college definition.');
}
$postData($publication . "commit/", ["review_token" => $r["review_token"]]);
$postData("/sis/catalog/classifications/school/save/", [
    "id" => $school["id"],
    "revision" => $school["revision"],
    "code" => "ARTS",
    "title" => "Current renamed college",
    "active" => true,
]);
$original = $postData($publication . "commit/", ["review_token" => $r["review_token"]]);
$sis->access->staff = false;
$postData($base . "save/", array_replace($save, ["revision" => 2]), 403);
$postData(
    "/sis/catalog/classifications/department/save/",
    [
        "id" => $department["id"],
        "revision" => $department["revision"],
        "code" => "ENG",
        "title" => "Fictional English",
        "active" => true,
        "school_id" => null,
    ],
    403,
);
echo "Academic organization route checks passed.\n";
