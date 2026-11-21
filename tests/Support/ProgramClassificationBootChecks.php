<?php

declare(strict_types=1);

// Actual providers/router and CSRF; all records and classifications are fictional SQLite fixtures.
require_once __DIR__ . "/ProgramPublicationFixture.php";
$setup = new ProgramPublicationFixture($sis);
$year = $setup->calendar->year(2024);
$base = "/sis/programs/" . $sis->program->value . "/";
$read = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $response = $app->router->match(
        $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path)),
    );
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException("Program classifications lost their private read boundary.");
    }
    return $response;
};
$draftResponse = $read($base . "details/");
$draft = json_decode((string) $draftResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $draftResponse->getStatusCode() !== 200 ||
    !array_key_exists("school", $draft["definition"]["classifications"]) ||
    !array_key_exists("field_of_study", $draft["definition"]["classifications"])
) {
    throw new RuntimeException("Program draft references were not resolved through actual providers.");
}
$references = [];
foreach (
    [
        "school" => ["ARTS", "Fictional College of Arts"],
        "field_of_study" => ["11.0701", "Fictional Computer Science"],
        "campus" => ["MAIN", "Fictional Main Campus"],
    ] as $kind => [$code, $title]
) {
    $response = $teachingPost("/sis/catalog/classifications/" . $kind . "/save/", [
        "code" => $code,
        "title" => $title,
        "active" => true,
    ]);
    $references[$kind] = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    $page = $teachingPost("/sis/catalog/classifications/" . $kind . "/page/", ["search" => ""]);
    if ($response->getStatusCode() !== 200 || $page->getStatusCode() !== 200) {
        throw new RuntimeException("The new classification libraries bypassed their typed private routes.");
    }
}
$input = [
    "code" => $draft["definition"]["code"],
    "title" => $draft["definition"]["title"],
    "description" => null,
    "department_id" => null,
    "academic_level_id" => null,
    "award_id" => null,
    "revision" => $draft["revision"],
];
if ($teachingPost($base . "details/", $input)->getStatusCode() !== 422) {
    throw new RuntimeException("An omitted program classification silently cleared a draft choice.");
}
$input += [
    "school_id" => $references["school"]["id"],
    "field_of_study_id" => $references["field_of_study"]["id"],
];
if ($teachingPost($base . "details/", $input)->getStatusCode() !== 422) {
    throw new RuntimeException("An omitted campus silently cleared a program location.");
}
$input["campus_id"] = $references["campus"]["id"];
if ($teachingPost($base . "details/", $input)->getStatusCode() !== 422) {
    throw new RuntimeException("Omitting additional-site choices silently cleared existing program setup.");
}
$extraResponse = $teachingPost("/sis/catalog/classifications/campus/save/", [
    "code" => "EAST", "title" => "Fictional East Campus", "active" => true,
]);
$extra = json_decode((string) $extraResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($extraResponse->getStatusCode() !== 200) {
    throw new RuntimeException("An additional campus could not be created through the shared library.");
}
$input["additional_campus_ids"] = [$extra["id"]];
if ($teachingPost($base . "details/", $input)->getStatusCode() !== 200) {
    throw new RuntimeException("Program references could not be saved through actual providers.");
}
$reviewInput = ["academic_year_id" => $year["id"], "reason" => "Fictional reviewed classifications"];
$reviewResponse = $teachingPost($base . "publication/review/", $reviewInput);
$review = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $reviewResponse->getStatusCode() !== 200 ||
    ($review["definition"]["classifications"]["field_of_study"]["code"] ?? null) !== "11.0701" ||
    ($review["definition"]["classifications"]["campus"]["code"] ?? null) !== "MAIN"
) {
    throw new RuntimeException("The program publication omitted a reviewed classification.");
}
$school = $references["school"];
$rename = $teachingPost("/sis/catalog/classifications/school/save/", [
    "id" => $school["id"],
    "code" => "ARTS",
    "title" => "Revised fictional college",
    "revision" => $school["revision"],
    "active" => true,
]);
if (
    $rename->getStatusCode() !== 200 ||
    $teachingPost($base . "publication/commit/", [
        "review_token" => $review["review_token"],
    ])->getStatusCode() !== 409
) {
    throw new RuntimeException("A stale program reference was accepted.");
}
$review = json_decode(
    (string) $teachingPost($base . "publication/review/", $reviewInput)->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$confirmed = $teachingPost($base . "publication/commit/", ["review_token" => $review["review_token"]]);
$retried = $teachingPost($base . "publication/commit/", ["review_token" => $review["review_token"]]);
if ($confirmed->getStatusCode() !== 200 || (string) $confirmed->getBody() !== (string) $retried->getBody()) {
    throw new RuntimeException("Program classification publication or its accepted retry failed.");
}
$page = json_decode(
    (string) $teachingPost($base . "publication/page/", [])->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    ($page["items"][0]["definition"]["classifications"]["school"]["title"] ?? null) !==
    "Revised fictional college"
) {
    throw new RuntimeException("Published program details lost their retained school labels.");
}
// The same retained/current fields flow through the real report authoring boundary.
$person = $sis->legacyPerson($sis->user(), "Fictional", "Campus Student");
$application = $sis->legacyApplication(
    $person->id,
    $sis->program,
    new \Domain\Shared\ValueObject\EntityId($year["terms"][0]["id"]),
);
$sis->legacyAdmissionDecision($application->id, true);
$sis->matriculate($application->id);
$campus = $references["campus"];
$renameCampus = $teachingPost("/sis/catalog/classifications/campus/save/", [
    "id" => $campus["id"],
    "code" => "NORTH",
    "title" => "Fictional North Campus",
    "revision" => $campus["revision"],
    "active" => true,
]);
if ($renameCampus->getStatusCode() !== 200) {
    throw new RuntimeException("The shared campus reference could not be renamed.");
}
$campusDefinition = [
    "dataset" => "programs",
    "columns" => array_map(
        static fn(string $field): array => ["field" => $field, "label" => $field, "aggregate" => "none"],
        [
            "program_campus_code",
            "program_campus_title",
            "program_campus_recorded",
            "current_program_campus_title",
        ],
    ),
    "filters" => null,
    "having" => null,
    "sort" => [],
];
$campusEvents = $sis->sql->rows("sis_history_events");
$campusPreview = $teachingPost("/sis/report-studio/preview/", [
    "definition" => $campusDefinition,
    "offset" => 0,
]);
$campusRows = json_decode((string) $campusPreview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $campusPreview->getStatusCode() !== 200 ||
    count($campusRows["items"] ?? []) !== 1 ||
    $campusRows["items"][0]["c0"] !== "MAIN" ||
    $campusRows["items"][0]["c1"] !== "Fictional Main Campus" ||
    $campusRows["items"][0]["c3"] !== "Fictional North Campus" ||
    $campusEvents !== $sis->sql->rows("sis_history_events")
) {
    throw new RuntimeException(
        "Program campus reports lost retained labels, row grain or read-only behavior.",
    );
}
$catalog = json_decode(
    (string) $read("/sis/report-studio/catalog/")->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$programSchema = array_values(
    array_filter($catalog["datasets"], static fn(array $row): bool => $row["id"] === "programs"),
)[0];
if (
    !isset(
        $programSchema["fields"]["program_campus_recorded"],
        $programSchema["fields"]["current_program_campus_title"],
    )
) {
    throw new RuntimeException("Program campus fields were not available to the visual report builder.");
}
$siteDefinition = ["dataset" => "programs", "columns" => [
    ["field" => "program_additional_campus_title", "label" => "Published site", "aggregate" => "none"],
    ["field" => "program_additional_campuses_recorded", "label" => "Sites recorded", "aggregate" => "none"],
]];
$siteResponse = $teachingPost("/sis/report-studio/preview/", ["definition" => $siteDefinition, "offset" => 0]);
$siteRows = json_decode((string) $siteResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $siteResponse->getStatusCode() !== 200 ||
    ($siteRows["items"][0] ?? null) !== ["c0" => "Fictional East Campus", "c1" => true] ||
    !str_contains($siteResponse->getHeaderLine("Cache-Control"), "no-store") ||
    $siteResponse->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException("Published offering-site reports lost retained facts or transport protections.");
}
$currentSites = ["dataset" => "catalog_program_sites", "columns" => [
    ["field" => "program_code", "label" => "Program", "aggregate" => "none"],
    ["field" => "additional_campus_title", "label" => "Current additional site", "aggregate" => "none"],
]];
$currentSiteResponse = $teachingPost("/sis/report-studio/preview/", ["definition" => $currentSites, "offset" => 0]);
$currentSiteRows = json_decode((string) $currentSiteResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $currentSiteResponse->getStatusCode() !== 200 ||
    ($currentSiteRows["items"][0]["c1"] ?? null) !== "Fictional East Campus" ||
    $sis->sql->rows("sis_history_events") !== $campusEvents
) {
    throw new RuntimeException("Current offering-site reports failed or changed academic history.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:reporting:manage", "sis:reporting:run"];
if (
    $teachingPost("/sis/report-studio/preview/", [
        "definition" => $campusDefinition,
        "offset" => 0,
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Reporting access bypassed the independent student-read duty.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read"];
if (
    $read($base . "details/")->getStatusCode() !== 403 ||
    $teachingPost("/sis/catalog/classifications/school/page/", ["search" => ""])->getStatusCode() !== 403 ||
    $teachingPost($base . "details/", $input)->getStatusCode() !== 403
) {
    throw new RuntimeException("Student-read authority unexpectedly granted catalog setup access.");
}
