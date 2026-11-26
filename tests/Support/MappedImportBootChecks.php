<?php

declare(strict_types=1);

// One shared controller, real provider wiring, private transport and framework CSRF. Fictional SQLite only.
$importRequest = [
    "target" => "program",
    "source" => "fictional",
    "csv" => "ID,Code,Title,Campus\nnew,BA.ENG,Fictional English,MAIN\n",
    "mapping" => [
        "external_id" => 0,
        "code" => 1,
        "title" => 2,
        "campus_code" => 3,
    ],
];
$missing = $teachingPost("/sis/imports/preview/", $importRequest);
$missingData = json_decode(
    (string) $missing->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $missing->getStatusCode() !== 200 ||
    $missingData["ready"] ||
    $missingData["plan"][0]["action"] !== "conflict"
) {
    throw new RuntimeException(
        "The shared import route failed to review program dependencies.",
    );
}
$campus = $teachingPost("/sis/catalog/classifications/campus/save/", [
    "code" => "MAIN",
    "title" => "Fictional Main Campus",
    "active" => true,
]);
if ($campus->getStatusCode() !== 200) {
    throw new RuntimeException("The campus dependency could not be prepared.");
}
$before = $sis->sql->rows("sis_history_events");
$preview = $teachingPost("/sis/imports/preview/", $importRequest);
$review = json_decode(
    (string) $preview->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $preview->getStatusCode() !== 200 ||
    !$review["ready"] ||
    !str_contains($preview->getHeaderLine("Cache-Control"), "no-store") ||
    $preview->hasHeader("phpdebugbar-id")
) {
    throw new RuntimeException(
        "Program import review lost provider or private-response protection.",
    );
}
$sis->registrationService();
$sis->sql->update(
    "users",
    ["token" => "fictional-worker-token", "password" => "fictional-test-hash"],
    ["user_id" => $sis->access->actor->value],
);
$importRoles = new \Infrastructure\Persistence\Repository\SqlAccessRepository(
    $sis->sql,
);
foreach (["sis:import:manage", "sis:person:manage"] as $permissionName) {
    $permission = $sis->sql->one("sis_access_permissions", [
        "name" => $permissionName,
    ]);
    $importRoles->assign(
        \Domain\Access\Enum\GrantKind::UserPermission,
        $sis->access->actor,
        new \Domain\Shared\ValueObject\EntityId($permission["id"]),
        true,
    );
}
$command = $importRequest + ["review_token" => $review["review_token"]];
$first = $teachingPost("/sis/imports/commit/", $command);
$again = $teachingPost("/sis/imports/commit/", $command);
$result = json_decode(
    (string) $first->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $first->getStatusCode() !== 200 ||
    (string) $first->getBody() !== (string) $again->getBody() ||
    $result["state"] !== "queued" ||
    $sis->sql->rows("sis_program_import_mappings") !== [] ||
    $sis->sql->rows("sis_history_events") !== $before ||
    $sis->sql->rows("sis_program_catalogs") !== []
) {
    throw new RuntimeException(
        "Program import confirmation changed history or lost its original result.",
    );
}
$worker = $app->make(\Domain\Import\Service\ImportWorker::class);
if (
    $worker->runOne() !== "completed" ||
    count($sis->sql->rows("sis_program_import_mappings")) !== 1
) {
    throw new RuntimeException(
        "The real server worker did not complete the queued program import.",
    );
}
$program = $sis->sql->one("sis_programs", ["code" => "BA.ENG"]);
$draft = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid/sis/programs/" .
                $program["id"] .
                "/details/",
        ),
    ),
);
$data = json_decode(
    (string) $draft->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $draft->getStatusCode() !== 200 ||
    $data["definition"]["classifications"]["campus"]["code"] !== "MAIN"
) {
    throw new RuntimeException(
        "Imported program references did not reach the ordinary program editor.",
    );
}
foreach (["arbitrary_table", null] as $invalidTarget) {
    if (
        $teachingPost(
            "/sis/imports/preview/",
            array_replace($importRequest, ["target" => $invalidTarget]),
        )->getStatusCode() !== 422
    ) {
        throw new RuntimeException(
            "The browser supplied an invalid catalog destination.",
        );
    }
}
// Reference setup shares the same private, CSRF-protected coordinator and is immediately usable.
$referenceDestinations = [
    "subject",
    "department",
    "academic_level",
    "course_level",
    "award",
    "instructional_method",
    "campus",
    "school",
    "field_of_study",
];
foreach ($referenceDestinations as $destination) {
    $referenceRequest = [
        "target" => $destination,
        "source" => "fictional-references",
        "csv" => "ID,Code,Title\nref,REF.NEW,Fictional Reference\n",
        "mapping" => ["external_id" => 0, "code" => 1, "title" => 2],
    ];
    $referenceResponse = $teachingPost(
        "/sis/imports/preview/",
        $referenceRequest,
    );
    $referenceReview = json_decode(
        (string) $referenceResponse->getBody(),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (
        $referenceResponse->getStatusCode() !== 200 ||
        !$referenceReview["ready"] ||
        !str_contains(
            $referenceResponse->getHeaderLine("Cache-Control"),
            "no-store",
        ) ||
        $referenceResponse->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "The shared reference import lost routing or privacy protection.",
        );
    }
    $referenceResponse = $teachingPost(
        "/sis/imports/commit/",
        $referenceRequest + [
            "review_token" => $referenceReview["review_token"],
        ],
    );
    if (
        $referenceResponse->getStatusCode() !== 200 ||
        $worker->runOne() !== "completed" ||
        $sis->sql->rows("sis_history_events") !== $before
    ) {
        throw new RuntimeException(
            "The reference import failed or changed authoritative history.",
        );
    }
}
$sis->access->staff = false;
$sis->access->grants = ["sis:import:manage"];
if (
    $teachingPost("/sis/imports/preview/", $importRequest)->getStatusCode() !==
        200 ||
    $teachingPost("/sis/catalog/classifications/campus/save/", [
        "code" => "DENY",
        "title" => "Denied",
        "active" => true,
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Import authority widened catalog-maintenance access.",
    );
}
$sis->access->grants = ["sis:catalog:manage"];
if (
    $teachingPost("/sis/imports/preview/", $importRequest)->getStatusCode() !==
        403 ||
    $teachingPost("/sis/imports/commit/", $command)->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Catalog authority bypassed the independent import duty.",
    );
}

$sis->access->staff = true;
$peopleRequest = [
    "csv" => "Given,Family,Email\nFictional,Intake,intake@example.invalid\n",
    "mapping" => ["given_name" => 0, "family_name" => 1, "email" => 2],
    "reason" => "Fictional intake",
];
$peopleReview = $teachingPost("/sis/imports/people/preview/", $peopleRequest);
$peopleData = json_decode(
    (string) $peopleReview->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if ($peopleReview->getStatusCode() !== 200 || !$peopleData["ready"]) {
    throw new RuntimeException("The real people import could not be reviewed.");
}
$peopleQueued = $teachingPost("/sis/imports/people/commit/", [
    "review_token" => $peopleData["review_token"],
]);
if (
    $peopleQueued->getStatusCode() !== 200 ||
    $worker->runOne() !== "completed"
) {
    throw new RuntimeException(
        "The real people worker could not complete the queued import.",
    );
}
$importPaths = ["jobs/", "jobs/" . $peopleData["job_id"] . "/", "notices/"];
foreach ($importPaths as $path) {
    $response = $app->router->match(
        $get->withUri(
            new \Laminas\Diactoros\Uri(
                "https://campus.example.invalid/sis/imports/" . $path,
            ),
        ),
    );
    if (
        $response->getStatusCode() !== 200 ||
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        $response->hasHeader("phpdebugbar-id")
    ) {
        throw new RuntimeException(
            "Import activity lost private transport protection.",
        );
    }
}

$importQueue = $app->make(\Domain\Import\Service\ImportQueueService::class);
$notice = $importQueue->notices()['items'][0];
foreach (['read', 'remove'] as $action) {
    if ($teachingPost('/sis/imports/notices/' . $action . '/', ['id' => $notice['id']])->getStatusCode() !== 200) {
        throw new RuntimeException('An owner import notice mutation failed.');
    }
}
if ($sis->sql->rows('sis_import_notices', ['id' => $notice['id']]) !== []) {
    throw new RuntimeException('Deleting an import notice retained its row.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:import:manage'];
if (
    $teachingPost('/sis/imports/people/preview/', $peopleRequest)->getStatusCode() !== 403 ||
    $teachingPost(
        '/sis/imports/people/commit/',
        ['review_token' => $peopleData['review_token']],
    )->getStatusCode() !== 403
) {
    throw new RuntimeException('Import permission alone allowed person creation.');
}
$sis->access->staff = true;
$importOwner = $sis->access->actor;
$sis->access->actor = $sis->user();
$response = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/imports/jobs/' . $peopleData['job_id'] . '/'
)));
if ($response->getStatusCode() !== 403 || $importQueue->notices()['items'] !== []) {
    throw new RuntimeException('Import status or notices crossed the signed-in owner.');
}
$sis->access->actor = $importOwner;
