<?php

declare(strict_types=1);

// Included only by the isolated bootstrap smoke process, never by a public entry point.
require_once __DIR__ . "/CampusFixture.php";
$campus = new CampusFixture();
$sis = $campus->sis;
$teachingStudent = $sis->student();
$teachingSection = $sis->section(20, 0, $sis->access->actor);
$sis->service->register($teachingStudent->id, $teachingSection->id, true);
$publicId = $campus->content->save(
    null,
    "news",
    "fixture-public",
    "Public fixture",
    "A fictional update.",
    "public",
    null,
);
$campus->posts->publish($publicId, true, 0);
$privateId = $campus->content->save(
    null,
    "news",
    "fixture-private",
    "Campus-only fixture",
    "Private fixture.",
    "campus",
    null,
);
$campus->posts->publish($privateId, true, 0);
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $sis->sql);
$app->delegate(
    \Infrastructure\EventSourcing\ProtectedEventCodec::class,
    static fn() => new \Infrastructure\EventSourcing\ProtectedEventCodec(
        EventStoreFixture::config($sis->connection->queryBuilder()),
    ),
);
$app->delegate(\Domain\Admissions\Repository\AdmissionsRepository::class, static fn() => $sis->admissions);
$app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $sis->transactions);
$app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn() => $sis->access);
$app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn() => $campus->cipher);
$app->delegate(
    \Domain\Transcript\ValueObject\TranscriptIssuer::class,
    static fn() => new \Domain\Transcript\ValueObject\TranscriptIssuer(
        "Fictional University",
        "Fictional campus",
    ),
);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $sis->clock);
$app->delegate(\Domain\Portal\Service\CampusService::class, static fn() => $campus->service);
$app->delegate(\Domain\Portal\Service\PortalPaymentService::class, static fn() => $campus->checkout);
$app->delegate(
    \Codefy\Framework\Auth\Gate::class,
    static fn() => new class implements \Codefy\Framework\Auth\Gate {
        public function can(string $permissionName, array $ruleParams = []): bool
        {
            return false;
        }
        public function current(): object|bool|null
        {
            return false;
        }
        public function guest(): bool
        {
            return true;
        }
        public function isLoggedIn(): bool
        {
            return false;
        }
    },
);
$app->router->baseMiddleware = [
    "sis.privacy",
    "sis.json",
    "firewall",
    "csrf.protection",
    "bind.request",
    "php.debugbar",
];
$get = new \Qubus\Http\ServerRequest([], [], "https://campus.example.invalid/portal/", "GET")->withAttribute(
    \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
    "fictionalcsrftoken",
);
$response = $app->router->match($get);
if (
    $response->getStatusCode() !== 200 ||
    !str_contains((string) $response->getBody(), "Your next chapter") ||
    !str_contains($response->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException(
        "Portal route expected HTML 200, received " .
            $response->getStatusCode() .
            " " .
            $response->getHeaderLine("Content-Type"),
    );
}
$post = $get
    ->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid/portal/api/contact/"))
    ->withMethod("POST")
    ->withHeader("Content-Type", "application/x-www-form-urlencoded")
    ->withHeader("Accept", "application/json")
    ->withBody(new \Laminas\Diactoros\Stream("php://temp", "w+"));
$post->getBody()->write(
    http_build_query([
        "_workspace_json" => json_encode(
            [
                "preferred_name" => "Fictional",
                "phone" => "",
                "address" => "",
                "version" => 0,
            ],
            JSON_THROW_ON_ERROR,
        ),
    ]),
);
$post->getBody()->rewind();
if ($app->router->match($post)->getStatusCode() !== 403 || $sis->sql->rows("sis_portal_contacts") !== []) {
    throw new RuntimeException("A portal mutation bypassed CSRF.");
}
$post->getBody()->rewind();
if ($app->router->match($post->withHeader("X-CSRF-Token", "fictionalcsrftoken"))->getStatusCode() !== 200) {
    throw new RuntimeException("A protected portal mutation did not accept its typed form envelope.");
}

// Resolve the new staff controllers through the real provider and protect their browser mutations.
$course = $sis->sql->rows("sis_courses")[0];
$catalogController = $app->make(\Application\Http\Controller\SisCatalogEditorController::class);
if ($catalogController->show("courses", $course["id"])->getStatusCode() !== 200) {
    throw new RuntimeException("Catalog controller resolution failed.");
}
$catalogRequest = $get->withUri(
    new \Laminas\Diactoros\Uri(
        "https://campus.example.invalid/sis/ui/catalog/courses/" . $course["id"] . "/",
    ),
);
$catalogResponse = $app->router->match($catalogRequest);
$catalogRecord = json_decode((string) $catalogResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $catalogResponse->getStatusCode() !== 200 ||
    !isset($catalogRecord["revision"]) ||
    !str_contains($catalogResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("The protected catalog editor could not resolve through the provider.");
}
$section = $sis->sql->rows("sis_sections")[0];
$contextRequest = $post
    ->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid/sis/ui/context/assignments/" . $section["id"] . "/",
        ),
    )
    ->withBody(new \Laminas\Diactoros\Stream("php://temp", "w+"));
$contextRequest->getBody()->write(http_build_query(["_workspace_json" => json_encode(["after" => ""])]));
$contextRequest->getBody()->rewind();
if ($app->router->match($contextRequest)->getStatusCode() !== 403) {
    throw new RuntimeException("A staff context mutation bypassed CSRF.");
}
$contextRequest->getBody()->rewind();
$contextResponse = $app->router->match($contextRequest->withHeader("X-CSRF-Token", "fictionalcsrftoken"));
if (
    $contextResponse->getStatusCode() !== 200 ||
    !str_contains($contextResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Staff operations could not resolve through the provider or CSRF pipeline.");
}

// New teaching routes resolve through the real providers, keeping typed bodies and CSRF protection.
$teachingPost = static function (
    string $path,
    array $data
) use (
    $app,
    $post,
): \Psr\Http\Message\ResponseInterface {
    $request = $post
        ->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $path))
        ->withBody(new \Laminas\Diactoros\Stream("php://temp", "w+"));
    $request
        ->getBody()
        ->write(http_build_query(["_workspace_json" => json_encode((object) $data, JSON_THROW_ON_ERROR)]));
    $request->getBody()->rewind();
    if ($app->router->match($request)->getStatusCode() !== 403) {
        throw new RuntimeException("A teaching request bypassed CSRF.");
    }
    $request->getBody()->rewind();
    $response = $app->router->match($request->withHeader("X-CSRF-Token", "fictionalcsrftoken"));
    if (!str_contains($response->getHeaderLine("Cache-Control"), "no-store")) {
        throw new RuntimeException("A teaching response allowed caching.");
    }
    return $response;
};
$sectionPath = "/sis/sections/" . $teachingSection->id->value;
$created = $teachingPost($sectionPath . "/assignments/", [
    "title" => "Fictional assignment",
    "possible_hundredths" => 1000,
    "due_on" => "2026-08-11",
]);
if ($created->getStatusCode() !== 201) {
    throw new RuntimeException("The assignment create route failed.");
}
$assignmentId = json_decode((string) $created->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$assignmentPath = "/sis/assignments/" . $assignmentId;
$assignmentResponse = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $assignmentPath . "/")),
);
$assignmentRecord = json_decode((string) $assignmentResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $assignmentResponse->getStatusCode() !== 200 ||
    !isset($assignmentRecord["revision"]) ||
    $teachingPost($assignmentPath . "/edit/", [
        "title" => "Revised fictional assignment",
        "possible_hundredths" => 1200,
        "due_on" => "2026-08-12",
        "revision" => $assignmentRecord["revision"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("The assignment editor routes failed.");
}
if (
    $teachingPost($sectionPath . "/attendance/", [
        "student_id" => $teachingStudent->id->value,
        "held_on" => "2026-08-11",
        "status" => "present",
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("The attendance write route failed.");
}
foreach (["score-page", "attendance"] as $feed) {
    $response = $teachingPost(
        $sectionPath . "/students/" . $teachingStudent->id->value . "/" . $feed . "/",
        [],
    );
    $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    if ($response->getStatusCode() !== 200 || count($payload["items"] ?? []) !== 1) {
        throw new RuntimeException("A bounded teaching read route failed.");
    }
}
$export = $teachingPost("/sis/ui/exports/roster/" . $teachingSection->id->value . "/", []);
if (
    $export->getStatusCode() !== 200 ||
    !str_contains($export->getHeaderLine("Content-Type"), "text/csv") ||
    !str_contains((string) $export->getBody(), "Institutional ID")
) {
    throw new RuntimeException("The explicit roster export route failed.");
}

// Room planning and booking decisions use real provider bindings and the same CSRF pipeline.
$roomCreated = $teachingPost("/sis/rooms/", ["code" => "Fictional.101", "capacity" => 30]);
if ($roomCreated->getStatusCode() !== 201) {
    throw new RuntimeException("The room create route failed.");
}
$roomId = json_decode((string) $roomCreated->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$roomPath = "/sis/rooms/" . $roomId;
$roomResponse = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $roomPath . "/")),
);
$roomRecord = json_decode((string) $roomResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$planningWindow = ["starts_at" => "2026-08-11T09:00:00+00:00", "ends_at" => "2026-08-11T10:00:00+00:00"];
if (
    $roomResponse->getStatusCode() !== 200 ||
    !isset($roomRecord["revision"]) ||
    $teachingPost($roomPath . "/edit/", [
        "code" => "Fictional.102",
        "capacity" => 25,
        "revision" => $roomRecord["revision"],
    ])->getStatusCode() !== 200 ||
    $teachingPost($roomPath . "/availability/", $planningWindow)->getStatusCode() !== 200 ||
    $teachingPost($roomPath . "/agenda/", $planningWindow)->getStatusCode() !== 200
) {
    throw new RuntimeException("A protected room planning route failed.");
}
$bookingResponse = $teachingPost("/sis/bookings/", [
    "room_id" => $roomId,
    "title" => "Fictional planning meeting",
    "slots" => [$planningWindow],
]);
if ($bookingResponse->getStatusCode() !== 201) {
    throw new RuntimeException("The booking request route failed.");
}
$bookingId = json_decode((string) $bookingResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
if (
    $teachingPost("/sis/bookings/" . $bookingId . "/approve/", [])->getStatusCode() !== 422 ||
    $teachingPost("/sis/bookings/" . $bookingId . "/approve/", ["version" => 0])->getStatusCode() !== 200 ||
    $teachingPost("/sis/bookings/" . $bookingId . "/cancel/", [])->getStatusCode() !== 422 ||
    $teachingPost("/sis/bookings/" . $bookingId . "/cancel/", ["version" => 0])->getStatusCode() !== 409 ||
    $teachingPost("/sis/bookings/" . $bookingId . "/cancel/", ["version" => 1])->getStatusCode() !== 200
) {
    throw new RuntimeException("Booking decisions failed to enforce the displayed version.");
}

// Employment editing, historical reads and decisions also boot through the real providers.
$employeeResponse = $teachingPost("/sis/employees/", [
    "person_id" => $teachingStudent->personId->value,
    "job_title" => "Fictional librarian",
]);
if ($employeeResponse->getStatusCode() !== 201) {
    throw new RuntimeException("The employment create route failed.");
}
$employeeId = json_decode((string) $employeeResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$employeePath = "/sis/employees/" . $employeeId;
$employeeGet = $get->withUri(
    new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $employeePath . "/"),
);
$employeeDetails = $app->router->match($employeeGet);
$employee = json_decode((string) $employeeDetails->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $employeeDetails->getStatusCode() !== 200 ||
    !isset($employee["revision"]) ||
    !str_contains($employeeDetails->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("The private employment profile route failed.");
}
$timeResponse = $teachingPost("/sis/timesheets/", $planningWindow + ["employee_id" => $employeeId]);
if ($timeResponse->getStatusCode() !== 201) {
    throw new RuntimeException("The time submit route failed.");
}
$timeId = json_decode((string) $timeResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
if (
    $teachingPost($employeePath . "/edit/", [
        "job_title" => "Fictional archivist",
        "revision" => $employee["revision"],
    ])->getStatusCode() !== 422 ||
    $teachingPost($employeePath . "/edit/", [
        "job_title" => "Fictional archivist",
        "active" => false,
        "revision" => $employee["revision"],
    ])->getStatusCode() !== 200 ||
    $teachingPost($employeePath . "/time-entries/", [])->getStatusCode() !== 200
) {
    throw new RuntimeException("Employment maintenance or history route failed.");
}
$timeGet = $get->withUri(
    new \Laminas\Diactoros\Uri("https://campus.example.invalid/sis/timesheets/" . $timeId . "/"),
);
if ($app->router->match($timeGet)->getStatusCode() !== 200) {
    throw new RuntimeException("Inactive employment hid historical time.");
}
$previousActor = $sis->access->actor;
$sis->access->actor = $sis->user();
if (
    $teachingPost("/sis/timesheets/" . $timeId . "/decision/", ["approve" => true])->getStatusCode() !==
        422 ||
    $teachingPost("/sis/timesheets/" . $timeId . "/decision/", ["version" => 0])->getStatusCode() !== 422 ||
    $teachingPost("/sis/timesheets/" . $timeId . "/decision/", [
        "approve" => true,
        "version" => 0,
    ])->getStatusCode() !== 200 ||
    $teachingPost("/sis/timesheets/" . $timeId . "/decision/", [
        "approve" => false,
        "version" => 0,
    ])->getStatusCode() !== 409
) {
    throw new RuntimeException("The version-checked historical time decision route failed.");
}
$sis->access->actor = $previousActor;

// Student program maintenance keeps the institutional entry term and runs through real middleware.
$studentPath = "/sis/students/" . $teachingStudent->id->value;
$recordGet = $get->withUri(
    new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $studentPath . "/record/"),
);
$recordResponse = $app->router->match($recordGet);
$studentRecord = json_decode((string) $recordResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $recordResponse->getStatusCode() !== 200 ||
    !isset($studentRecord["revision"]) ||
    !str_contains($recordResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("The private student record route failed.");
}
$originalPrograms = $teachingPost($studentPath . "/programs/", []);
$originalAssignment = json_decode((string) $originalPrograms->getBody(), true, flags: JSON_THROW_ON_ERROR)[
"items"
][0];
$additionalProgram = \Domain\Shared\ValueObject\EntityId::generate();
$sis->sql->insert("sis_programs", [
    "id" => $additionalProgram->value,
    "code" => "FIX.PROGRAM",
    "title" => "Fictional program",
]);
$assignedResponse = $teachingPost($studentPath . "/programs/assign/", [
    "program_id" => $additionalProgram->value,
    "entry_term_id" => $sis->term->value,
]);
if ($assignedResponse->getStatusCode() !== 201) {
    throw new RuntimeException("The student program assignment route failed.");
}
$assignmentId = json_decode((string) $assignedResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$primaryDecision = static function (string $assignment, string $revision) use ($teachingPost, $studentPath): int {
    $response = $teachingPost($studentPath . "/primary-program/review/", ["assignment_id" => $assignment,
        "revision" => $revision, "reason" => "Fictional primary-program decision."]);
    if ($response->getStatusCode() !== 200) {
        return $response->getStatusCode();
    }
    $preview = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    return $teachingPost($studentPath . "/primary-program/commit/", ["assignment_id" => $assignment,
        "review_token" => $preview["review_token"]])->getStatusCode();
};
if (
    $primaryDecision($assignmentId, $studentRecord["revision"]) !== 200
) {
    throw new RuntimeException("The primary program route failed.");
}
$fresh = json_decode((string) $app->router->match($recordGet)->getBody(), true, flags: JSON_THROW_ON_ERROR);
$activityDecision = static function (
    bool $active,
    string $revision
) use (
    $teachingPost,
    $studentPath,
    $sis,
): int {
    $review = $teachingPost($studentPath . "/activity/review/", [
        "active" => $active,
        "effective_on" => $sis->clock->now()->format("Y-m-d"),
        "reason" => "Fictional activity smoke decision.",
        "revision" => $revision,
    ]);
    if ($review->getStatusCode() !== 200) {
        return $review->getStatusCode();
    }
    $preview = json_decode((string) $review->getBody(), true, flags: JSON_THROW_ON_ERROR);
    return $teachingPost($studentPath . "/activity/commit/", [
        "review_token" => $preview["review_token"],
    ])->getStatusCode();
};
if (
    $fresh["entry_term"] !== $studentRecord["entry_term"] ||
    $teachingPost($studentPath . "/activity/review/", ["revision" => $fresh["revision"]])->getStatusCode() !==
        422 ||
    $activityDecision(false, $studentRecord["revision"]) !== 409 ||
    $activityDecision(false, $fresh["revision"]) !== 200
) {
    throw new RuntimeException("Student activity or entry-term protection failed.");
}
$inactive = json_decode(
    (string) $app->router->match($recordGet)->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if ($activityDecision(true, $inactive["revision"]) !== 200) {
    throw new RuntimeException("Student reactivation failed.");
}
$fresh = json_decode((string) $app->router->match($recordGet)->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $primaryDecision($originalAssignment["id"], $fresh["revision"]) !== 200
) {
    throw new RuntimeException("The original primary program could not be selected.");
}
$programPage = json_decode(
    (string) $teachingPost($studentPath . "/programs/", [])->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$selected = array_values(
    array_filter($programPage["items"], static fn($row) => $row["id"] === $assignmentId),
)[0];
$programStatusPath = $studentPath . "/programs/" . $assignmentId . "/status/";
$programStatusReview = $teachingPost($programStatusPath . "review/", ["active" => false,
    "revision" => $selected["revision"], "reason" => "Fictional program closure."]);
if ($programStatusReview->getStatusCode() !== 200) {
    throw new RuntimeException("Program status review failed.");
}
$programStatusToken = json_decode(
    (string) $programStatusReview->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
)["review_token"];
if ($teachingPost($programStatusPath . "commit/", ["review_token" => $programStatusToken])->getStatusCode() !== 200) {
    throw new RuntimeException("Program status confirmation failed.");
}

// Restrictions and encrypted notes use the real provider, explicit operation IDs and CSRF protection.
$holdId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$holdData = [
    "operation_id" => $holdId,
    "person_id" => $teachingStudent->personId->value,
    "code" => "ADVISING",
    "severity" => 99,
    "starts_on" => "2026-08-01",
    "ends_on" => "2026-08-20",
    "blocks_staff" => true,
    "note" => "Fictional internal note",
];
if (
    $teachingPost("/sis/restrictions/", $holdData)->getStatusCode() !== 201 ||
    $teachingPost("/sis/restrictions/", $holdData)->getStatusCode() !== 201 ||
    count($sis->sql->rows("sis_restrictions", ["id" => $holdId])) !== 1
) {
    throw new RuntimeException("Restriction creation was not repeatable.");
}
$holdGet = $get->withUri(
    new \Laminas\Diactoros\Uri("https://campus.example.invalid/sis/restrictions/" . $holdId . "/"),
);
$holdResponse = $app->router->match($holdGet);
$hold = json_decode((string) $holdResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $holdResponse->getStatusCode() !== 200 ||
    !isset($hold["revision"]) ||
    !str_contains($holdResponse->getHeaderLine("Cache-Control"), "no-store") ||
    $teachingPost(
        "/sis/persons/" . $teachingStudent->personId->value . "/restrictions/",
        [],
    )->getStatusCode() !== 200 ||
    $teachingPost("/sis/restrictions/" . $holdId . "/end/", ["ends_on" => "2026-08-15"])->getStatusCode() !==
        422 ||
    $teachingPost("/sis/restrictions/" . $holdId . "/end/", [
        "ends_on" => "2026-08-15",
        "revision" => $hold["revision"],
    ])->getStatusCode() !== 200 ||
    $teachingPost("/sis/restrictions/" . $holdId . "/end/", [
        "ends_on" => "2026-08-12",
        "revision" => $hold["revision"],
    ])->getStatusCode() !== 409
) {
    throw new RuntimeException("Restriction reads or stale-save protection failed.");
}
$noteData = [
    "operation_id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "note" => "Fictional follow-up",
];
if (
    $teachingPost("/sis/restrictions/" . $holdId . "/notes/append/", $noteData)->getStatusCode() !== 200 ||
    $teachingPost("/sis/restrictions/" . $holdId . "/notes/append/", $noteData)->getStatusCode() !== 200
) {
    throw new RuntimeException("A staff note could not be appended repeatably.");
}
$notesResponse = $teachingPost("/sis/restrictions/" . $holdId . "/notes/", []);
$notes = json_decode((string) $notesResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $notesResponse->getStatusCode() !== 200 ||
    count($notes["items"] ?? []) !== 2 ||
    str_contains(
        json_encode($sis->sql->rows("sis_restriction_notes"), JSON_THROW_ON_ERROR),
        "Fictional follow-up",
    )
) {
    throw new RuntimeException("Encrypted staff-note storage or authorized read failed.");
}
$sis->access->staff = false;
if (
    $app->router->match($holdGet)->getStatusCode() !== 403 ||
    $teachingPost("/sis/restrictions/" . $holdId . "/notes/", [])->getStatusCode() !== 403
) {
    throw new RuntimeException("Student ownership exposed internal restriction notes.");
}
$sis->access->staff = true;

// Transfer catalog maintenance and reviewed awards use the same provider and protected browser transport.
$institutionResponse = $teachingPost("/sis/external-institutions/", [
    "code" => "EXT.1",
    "name" => "Fictional College",
]);
$institutionId = json_decode((string) $institutionResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)[
"id"
];
$externalResponse = $teachingPost("/sis/external-courses/", [
    "institution_id" => $institutionId,
    "code" => "MATH.101",
    "title" => "External algebra",
]);
$externalId = json_decode((string) $externalResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$sis->sql->insert("sis_grades", [
    "id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "code" => "TR",
    "points_milli" => 0,
    "passes" => 1,
    "counts_gpa" => 0,
]);
$equivalencyResponse = $teachingPost("/sis/transfer-equivalencies/", [
    "institution_id" => $institutionId,
    "external_course_id" => $externalId,
    "course_id" => $teachingSection->courseId->value,
    "starts_on" => "2020-01-01",
    "minimum_grade_rank" => 2,
    "credits_milli" => 3000,
    "awarded_grade" => "tr",
]);
$equivalencyId = json_decode((string) $equivalencyResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)[
"id"
];
$transferPath = "/sis/ui/transfer/equivalencies/" . $equivalencyId . "/";
$transferGet = $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $transferPath));
$transferResponse = $app->router->match($transferGet);
$reviewed = json_decode((string) $transferResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$awardData = [
    "student_id" => $teachingStudent->id->value,
    "institution_id" => $institutionId,
    "equivalency_id" => $equivalencyId,
    "term_id" => $sis->term->value,
    "completed_on" => "2026-05-01",
    "grade_rank" => 2,
];
if (
    $institutionResponse->getStatusCode() !== 201 ||
    $externalResponse->getStatusCode() !== 201 ||
    $equivalencyResponse->getStatusCode() !== 201 ||
    $transferResponse->getStatusCode() !== 200 ||
    !isset($reviewed["revision"]) ||
    $reviewed["awarded_grade"] !== "TR" ||
    !str_contains($transferResponse->getHeaderLine("Cache-Control"), "no-store") ||
    $teachingPost("/sis/transfer-awards/", $awardData)->getStatusCode() !== 422 ||
    $teachingPost(
        "/sis/transfer-awards/",
        $awardData + ["revision" => $reviewed["revision"]],
    )->getStatusCode() !== 201 ||
    $teachingPost($transferPath . "edit/", [
        "starts_on" => "2020-01-01",
        "minimum_grade_rank" => 2,
        "credits_milli" => 3125,
        "awarded_grade" => "TR",
        "revision" => $reviewed["revision"],
    ])->getStatusCode() !== 200 ||
    $teachingPost(
        "/sis/transfer-awards/",
        $awardData + ["revision" => $reviewed["revision"]],
    )->getStatusCode() !== 409 ||
    $teachingPost($transferPath . "awards/", [])->getStatusCode() !== 200 ||
    $teachingPost("/sis/ui/transfer/institutions/" . $institutionId . "/children/", [])->getStatusCode() !==
        200
) {
    throw new RuntimeException("Transfer catalog revisions, CSRF, private reads or reviewed awards failed.");
}
$sis->access->staff = false;
if (
    $app->router->match($transferGet)->getStatusCode() !== 403 ||
    $teachingPost($transferPath . "awards/", [])->getStatusCode() !== 403
) {
    throw new RuntimeException("A student owner accessed registrar transfer administration.");
}
$sis->access->staff = true;

// Report definitions, bounded execution and page CSV use the real provider and browser CSRF envelope.
$reportResponse = $teachingPost("/sis/reports/", [
    "name" => "Fictional inactive cohort",
    "program_id" => $sis->program->value,
    "term_id" => $sis->term->value,
    "active" => false,
]);
$reportId = json_decode((string) $reportResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)["id"];
$reportPath = "/sis/reports/" . $reportId . "/";
$reportGet = $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $reportPath));
$reportMetadataResponse = $app->router->match($reportGet);
$reportMetadata = json_decode((string) $reportMetadataResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$reportPageResponse = $teachingPost($reportPath . "run/", ["after" => ""]);
$reportPage = json_decode((string) $reportPageResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
$reportCsvResponse = $teachingPost($reportPath . "export/", ["after" => ""]);
if (
    $reportResponse->getStatusCode() !== 201 ||
    $reportMetadataResponse->getStatusCode() !== 200 ||
    $reportMetadata["active"] !== false ||
    !$reportMetadata["can_run"] ||
    !$reportMetadata["can_manage"] ||
    $reportPageResponse->getStatusCode() !== 200 ||
    $reportPage["limit"] !== 50 ||
    count($reportPage["items"]) > 50 ||
    !array_key_exists("next_after", $reportPage) ||
    $reportCsvResponse->getStatusCode() !== 200 ||
    !str_starts_with($reportCsvResponse->getHeaderLine("Content-Type"), "text/csv") ||
    !str_contains($reportCsvResponse->getHeaderLine("Content-Disposition"), "student-report-page.csv") ||
    !str_contains($reportMetadataResponse->getHeaderLine("Cache-Control"), "no-store") ||
    $teachingPost($reportPath . "run/", ["after" => "not-a-ulid"])->getStatusCode() !== 422
) {
    throw new RuntimeException("Report metadata, bounded pages, CSV or cursor validation failed.");
}
$sis->access->staff = false;
if (
    $app->router->match($reportGet)->getStatusCode() !== 403 ||
    $teachingPost($reportPath . "run/", [])->getStatusCode() !== 403 ||
    $teachingPost($reportPath . "export/", [])->getStatusCode() !== 403
) {
    throw new RuntimeException("Owning a report bypassed execution or management permission.");
}
$sis->access->staff = true;

// A reviewed registration group resolves through real providers, CSRF and the typed browser transport.
$batchStudent = $sis->student();
$batchData = [
    "section_id" => $teachingSection->id->value,
    "student_ids" => [$teachingStudent->id->value, $batchStudent->id->value],
];
$batchReviewResponse = $teachingPost("/sis/registration-batches/review/", $batchData);
$batchReview = json_decode((string) $batchReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $batchReviewResponse->getStatusCode() !== 200 ||
    !isset($batchReview["revision"], $batchReview["operation_id"]) ||
    count($batchReview["students"] ?? []) !== 2 ||
    $teachingPost("/sis/registration-batches/commit/", $batchData)->getStatusCode() !== 422
) {
    throw new RuntimeException("The registration group could not be reviewed through the provider.");
}
$batchCommit = $batchData + [
    "revision" => $batchReview["revision"],
    "operation_id" => $batchReview["operation_id"],
];
$batchCommitted = $teachingPost("/sis/registration-batches/commit/", $batchCommit);
$batchResult = json_decode((string) $batchCommitted->getBody(), true, flags: JSON_THROW_ON_ERROR);
$batchRepeated = $teachingPost("/sis/registration-batches/commit/", $batchCommit);
$batchGet = $get->withUri(
    new \Laminas\Diactoros\Uri(
        "https://campus.example.invalid/sis/registration-batches/" . $batchReview["operation_id"] . "/",
    ),
);
$batchReceipt = $app->router->match($batchGet);
if (
    $batchCommitted->getStatusCode() !== 200 ||
    ($batchResult["created"] ?? null) !== 1 ||
    ($batchResult["existing"] ?? null) !== 1 ||
    $batchRepeated->getStatusCode() !== 200 ||
    json_decode((string) $batchRepeated->getBody(), true, flags: JSON_THROW_ON_ERROR) !== $batchResult ||
    $batchReceipt->getStatusCode() !== 200 ||
    !str_contains($batchReceipt->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Registration batch confirmation or repeatable receipt failed.");
}
$batchActor = $sis->access->actor;
$sis->access->actor = $sis->user();
if ($app->router->match($batchGet)->getStatusCode() !== 403) {
    throw new RuntimeException("Another staff member read the private registration receipt.");
}
$sis->access->actor = $batchActor;
$sis->access->staff = false;
if (
    $app->router->match($batchGet)->getStatusCode() !== 403 ||
    $teachingPost("/sis/registration-batches/review/", $batchData)->getStatusCode() !== 403 ||
    $teachingPost("/sis/registration-batches/commit/", $batchCommit)->getStatusCode() !== 403
) {
    throw new RuntimeException("Receipt ownership bypassed current registration permission.");
}
$sis->access->staff = true;

// Guided hiatus cases coordinate metadata, effective-dated course changes and activity through real DI/CSRF.
$caseStudent = $sis->student();
$caseSection = $sis->section(20, 0);
$sis->service->register($caseStudent->id, $caseSection->id, true);
$casePath = "/sis/students/" . $caseStudent->id->value;
$caseDraft = [
    "code" => "W",
    "starts_on" => $sis->clock->now()->format("Y-m-d"),
    "note" => "Fictional registrar case",
];
$caseReviewResponse = $teachingPost($casePath . "/hiatus-workflow/review/", $caseDraft);
$caseReview = json_decode((string) $caseReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $caseReviewResponse->getStatusCode() !== 200 ||
    !isset($caseReview["revision"], $caseReview["operation_id"]) ||
    !$caseReview["defaults"]["deactivate"] ||
    count($caseReview["defaults"]["section_ids"]) !== 1
) {
    throw new RuntimeException("The guided hiatus plan did not resolve through its provider.");
}
$caseCommit = $caseDraft + [
    "deactivate" => true,
    "section_ids" => [$caseSection->id->value],
    "revision" => $caseReview["revision"],
    "operation_id" => $caseReview["operation_id"],
];
$caseCommitted = $teachingPost($casePath . "/hiatus-workflow/commit/", $caseCommit);
$caseRepeated = $teachingPost($casePath . "/hiatus-workflow/commit/", $caseCommit);
$caseBody = json_decode((string) $caseCommitted->getBody(), true, flags: JSON_THROW_ON_ERROR);
$caseGet = $get->withUri(
    new \Laminas\Diactoros\Uri(
        "https://campus.example.invalid" . $casePath . "/hiatuses/" . $caseReview["operation_id"] . "/",
    ),
);
$caseViewResponse = $app->router->match($caseGet);
$caseView = json_decode((string) $caseViewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $caseCommitted->getStatusCode() !== 201 ||
    $caseRepeated->getStatusCode() !== 201 ||
    json_decode((string) $caseRepeated->getBody(), true, flags: JSON_THROW_ON_ERROR) !== $caseBody ||
    $caseBody["impact"]["student_active"] !== false ||
    count($caseBody["impact"]["course_changes"]) !== 1 ||
    $caseViewResponse->getStatusCode() !== 200 ||
    $caseView["comment"] !== "Fictional registrar case" ||
    !str_contains($caseViewResponse->getHeaderLine("Cache-Control"), "no-store") ||
    $teachingPost($casePath . "/hiatuses/", [])->getStatusCode() !== 200 ||
    $teachingPost($casePath . "/hiatuses/" . $caseView["id"] . "/history/", [])->getStatusCode() !== 200
) {
    throw new RuntimeException("Guided hiatus confirmation, repeatable outcomes or private history failed.");
}
$caseEnd = [
    "operation_id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "ends_on" => "2026-12-01",
    "revision" => $caseView["revision"],
    "note" => "Fictional end date",
];
if (
    $teachingPost($casePath . "/hiatuses/" . $caseView["id"] . "/end/", $caseEnd)->getStatusCode() !== 200 ||
    $teachingPost($casePath . "/hiatuses/" . $caseView["id"] . "/end/", $caseEnd)->getStatusCode() !== 200 ||
    $teachingPost($casePath . "/hiatuses/" . $caseView["id"] . "/end/", [
        "operation_id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
        "ends_on" => "2026-12-02",
        "revision" => $caseView["revision"],
        "note" => "Fictional stale amendment",
    ])->getStatusCode() !== 409
) {
    throw new RuntimeException("Hiatus amendment revisions or retry handling failed.");
}
$sis->access->staff = false;
if (
    $app->router->match($caseGet)->getStatusCode() !== 403 ||
    $teachingPost($casePath . "/hiatuses/", [])->getStatusCode() !== 403 ||
    $teachingPost($casePath . "/hiatuses/" . $caseView["id"] . "/history/", [])->getStatusCode() !== 403 ||
    $teachingPost($casePath . "/hiatus-workflow/review/", $caseDraft)->getStatusCode() !== 403
) {
    throw new RuntimeException("Ordinary student access exposed private staff hiatus cases.");
}
$sis->access->staff = true;

// Return review/confirmation uses the real registrar service and preserves the withdrawal history.
$returnHiatus = new \Domain\Student\StudentHiatus(
    \Domain\Shared\ValueObject\EntityId::generate(),
    $caseStudent->id,
    \Domain\Student\Enum\HiatusCode::Leave,
    new \Domain\Shared\ValueObject\DateRange($sis->clock->now()->modify("-2 days")->setTime(0, 0)),
);
$app->make(\Domain\Student\Service\HiatusService::class)->create($returnHiatus, "Fictional return fixture");
$returnPath = $casePath . "/hiatuses/" . $returnHiatus->id->value;
$returnReviewResponse = $teachingPost($returnPath . "/return/review/", [
    "return_on" => $sis->clock->now()->format("Y-m-d"),
]);
$returnReview = json_decode((string) $returnReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($returnReviewResponse->getStatusCode() !== 200 || !isset($returnReview["revision"])) {
    throw new RuntimeException("The return review did not resolve through the real provider.");
}
$returnData = [
    "return_on" => $returnReview["return_on"],
    "revision" => $returnReview["revision"],
    "operation_id" => $returnReview["operation_id"],
    "reactivate" => true,
    "note" => "Fictional registrar return",
];
$returnCommit = $teachingPost($returnPath . "/return/commit/", $returnData);
$returnRetry = $teachingPost($returnPath . "/return/commit/", $returnData);
$returnGet = $get->withUri(
    new \Laminas\Diactoros\Uri(
        "https://campus.example.invalid" . $returnPath . "/returns/" . $returnReview["operation_id"] . "/",
    ),
);
if (
    $returnCommit->getStatusCode() !== 201 ||
    $returnRetry->getStatusCode() !== 201 ||
    !$sis->admissions->student($caseStudent->id)->active ||
    $app->router->match($returnGet)->getStatusCode() !== 200
) {
    throw new RuntimeException("A real return confirmation or repeatable receipt failed.");
}
$sis->access->staff = false;
if ($app->router->match($returnGet)->getStatusCode() !== 403) {
    throw new RuntimeException("Student access exposed a registrar return receipt.");
}
$sis->access->staff = true;

// Catalog plans use the same CSRF-protected envelope and provider aliases.
$catalogWorkflow = $app->make(\Domain\Catalog\Service\OfferingService::class);
$catalogCourse = $sis->section();
$catalogRows = [
    [
        "id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
        "course_id" => $catalogCourse->courseId->value,
        "code" => "FICTIONAL.26FA.A",
        "capacity" => 20,
        "delivery_mode" => "online",
        "faculty_user_id" => null,
        "fee_minor" => 0,
        "currency" => "USD",
        "web_registration" => true,
    ],
];
$catalogDraft = ["term_id" => $sis->term->value, "sections" => $catalogRows];
$catalogReviewed = $teachingPost("/sis/catalog/offerings/review/", $catalogDraft);
$catalogReview = json_decode((string) $catalogReviewed->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($catalogReviewed->getStatusCode() !== 200 || !isset($catalogReview["revision"])) {
    throw new RuntimeException("The offering plan did not resolve through the provider.");
}
$catalogSaved = $teachingPost(
    "/sis/catalog/offerings/save/",
    $catalogDraft + [
        "revision" => $catalogReview["revision"],
        "operation_id" => $catalogReview["operation_id"],
    ],
);
if (
    $sis->registration->section(new \Domain\Shared\ValueObject\EntityId($catalogRows[0]["id"]))
        ->deliveryMode !== \Domain\Registration\Enum\DeliveryMode::Online
) {
    throw new RuntimeException("The offering controller lost its online delivery mode.");
}
$catalogOperationPath = "/sis/catalog/offerings/" . $catalogReview["operation_id"];
$catalogPublications = $app->make(\Domain\Catalog\Service\CoursePublicationService::class);
$catalogDefinitionReview = $catalogPublications->review(
    $catalogCourse->courseId,
    "Fictional catalog approval",
);
$catalogDefinition = $catalogPublications->publish(
    $catalogCourse->courseId,
    $catalogDefinitionReview["review_token"],
);
$catalogPublicationSetup = $teachingPost($catalogOperationPath . "/publication-setup/", []);
if (
    $catalogPublicationSetup->getStatusCode() !== 200 ||
    !str_contains($catalogPublicationSetup->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Offering publication setup did not resolve privately.");
}
$catalogChoice = ["section_id" => $catalogRows[0]["id"], "version_id" => $catalogDefinition["operation_id"]];
if (
    $teachingPost($catalogOperationPath . "/publication-review/", [
        "course_versions" => [],
        "reason" => "Fictional missing choice",
    ])->getStatusCode() !== 422 ||
    $teachingPost($catalogOperationPath . "/publication-review/", [
        "course_versions" => [$catalogChoice, $catalogChoice],
        "reason" => "Fictional duplicate choice",
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException("Offering publication accepted missing or duplicate choices.");
}
$catalogNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $catalogOperationPath . "/publication-review/",
    "POST",
)->withParsedBody(["course_versions" => [$catalogChoice], "reason" => "Fictional offering approval"]);
if ($app->router->match($catalogNoCsrf)->getStatusCode() !== 403) {
    throw new RuntimeException("Offering publication bypassed CSRF.");
}
$catalogPublication = $teachingPost($catalogOperationPath . "/publication-review/", [
    "course_versions" => [
        ["section_id" => $catalogRows[0]["id"], "version_id" => $catalogDefinition["operation_id"]],
    ],
    "reason" => "Fictional offering approval",
]);
$catalogPublishReview = json_decode(
    (string) $catalogPublication->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $catalogSaved->getStatusCode() !== 201 ||
    $catalogPublication->getStatusCode() !== 200 ||
    $teachingPost($catalogOperationPath . "/publish/", [
        "review_token" => $catalogPublishReview["review_token"],
    ])->getStatusCode() !== 200 ||
    $teachingPost("/sis/catalog/offerings/", [])->getStatusCode() !== 200
) {
    throw new RuntimeException("Catalog draft creation, publication or saved plan listing failed.");
}
$catalogPublishedResponse = $teachingPost($catalogOperationPath . "/publish/", [
    "review_token" => $catalogPublishReview["review_token"],
]);
if (
    $catalogPublishedResponse->getStatusCode() !== 200 ||
    !str_contains($catalogPublishedResponse->getHeaderLine("Cache-Control"), "no-store") ||
    $sis->sql->one("sis_section_course_versions", ["id" => $catalogRows[0]["id"]])["course_version_id"] !==
        $catalogDefinition["operation_id"]
) {
    throw new RuntimeException("Offering publication did not retain a private, repeatable version binding.");
}
$cloneReviewGet = $get->withUri(
    new \Laminas\Diactoros\Uri(
        "https://campus.example.invalid/sis/courses/" . $catalogCourse->courseId->value . "/clone/review/",
    ),
);
$cloneReviewed = $app->router->match($cloneReviewGet);
$cloneReview = json_decode((string) $cloneReviewed->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $cloneReviewed->getStatusCode() !== 200 ||
    $teachingPost("/sis/courses/" . $catalogCourse->courseId->value . "/clone/commit/", [
        "operation_id" => $cloneReview["operation_id"],
        "revision" => $cloneReview["revision"],
        "code" => "FICTIONAL.102",
        "title" => "Fictional clone",
        "credits_milli" => 3000,
        "prerequisites" => [],
    ])->getStatusCode() !== 201
) {
    throw new RuntimeException("Course cloning failed through the real provider.");
}

// Academic hand-offs share progress while finance decisions and notes stay in the finance boundary.
$handoffPath = $casePath . "/hiatuses/" . $caseView["id"] . "/finance-review/";
$handoffResponse = $teachingPost($handoffPath, []);
$handoff = json_decode((string) $handoffResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($handoffResponse->getStatusCode() !== 201 || !isset($handoff["id"])) {
    throw new RuntimeException("The registrar-to-finance hand-off did not resolve through the provider.");
}
$financeChangePath = "/sis/finance-reviews/" . $handoff["id"];
$financeChange = [
    "operation_id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "version" => 0,
    "action" => "claim",
    "note" => "Fictional private finance review",
];
if (
    $teachingPost($financeChangePath . "/change/", $financeChange)->getStatusCode() !== 200 ||
    $teachingPost("/sis/finance-reviews/", [])->getStatusCode() !== 200 ||
    $teachingPost($financeChangePath . "/history/", [])->getStatusCode() !== 200
) {
    throw new RuntimeException("The finance queue or private decision history failed.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:registration:manage"];
$handoffProgress = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $handoffPath)),
);
if (
    $handoffProgress->getStatusCode() !== 200 ||
    str_contains((string) $handoffProgress->getBody(), "private finance") ||
    $teachingPost($financeChangePath . "/history/", [])->getStatusCode() !== 403
) {
    throw new RuntimeException("Registrar visibility exposed private finance decisions.");
}
$sis->access->staff = true;
$sis->access->grants = [];

// Official documents require preparation, protected downloads and separately recorded delivery evidence.
$officialRequest = \Domain\Shared\ValueObject\EntityId::generate();
$officialStudent = $sis->student();
$originalActor = $sis->access->actor;
$sis->access->actor = $sis->admissions->person($officialStudent->personId)->ownerUserId;
$campus->service->requestTranscript($officialRequest, $officialStudent->id, "pickup", "");
$sis->access->actor = $originalActor;
$officialPath = "/sis/transcript-requests/" . $officialRequest->value;
$officialReviewResponse = $teachingPost($officialPath . "/review/", []);
$officialReview = json_decode((string) $officialReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($officialReviewResponse->getStatusCode() !== 200 || !isset($officialReview["revision"])) {
    throw new RuntimeException(
        "Official transcript preparation could not resolve through the real provider.",
    );
}
$officialPrepare = [
    "operation_id" => $officialReview["operation_id"],
    "revision" => $officialReview["revision"],
    "release_reviewed" => true,
];
$officialPrepared = $teachingPost($officialPath . "/prepare/", $officialPrepare);
$officialDocumentPath = $officialPath . "/documents/" . $officialReview["operation_id"];
$officialPdf = $teachingPost($officialDocumentPath . "/pdf/", []);
$officialHtml = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $officialDocumentPath . "/view/"),
    ),
);
if (
    $officialPrepared->getStatusCode() !== 201 ||
    $officialPdf->getStatusCode() !== 200 ||
    !str_starts_with((string) $officialPdf->getBody(), "%PDF-") ||
    !str_contains($officialPdf->getHeaderLine("Cache-Control"), "no-store") ||
    $officialHtml->getStatusCode() !== 200 ||
    !str_contains((string) $officialHtml->getBody(), "Fictional University")
) {
    throw new RuntimeException("Protected transcript PDF or accessible document view failed.");
}
$officialDelivery = [
    "operation_id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "version" => 1,
    "delivered_at" => $sis->clock
        ->now()
        ->modify("-1 minute")
        ->format(DATE_ATOM),
    "evidence" => "Fictional collection evidence",
];
if (
    $teachingPost($officialDocumentPath . "/deliver/", $officialDelivery)->getStatusCode() !== 200 ||
    $teachingPost($officialDocumentPath . "/deliver/", $officialDelivery)->getStatusCode() !== 200
) {
    throw new RuntimeException("Official transcript fulfillment or delivery retry failed.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:transcript:manage"];
if ($teachingPost($officialDocumentPath . "/pdf/", [])->getStatusCode() !== 403) {
    throw new RuntimeException("Official transcript documents bypassed academic read permission.");
}
$sis->access->staff = true;
$sis->access->grants = [];

// Catalog-year defaults and explicit corrections resolve through the provider and protected typed envelope.
$calendarTypeId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$typeSetup = [
    "version" => -1,
    "code" => "LEGACY",
    "name_pattern" => "Fall {{START_YEAR}}",
    "code_pattern" => "{{START_YEAR}}FA",
    "kind" => "non_standard",
    "active" => true,
    "starts_on_year" => "START_YEAR",
    "starts_on_month_day" => "08-01",
    "ends_on_year" => "START_YEAR",
    "ends_on_month_day" => "12-20",
    "add_drop_ends_on_year" => "START_YEAR",
    "add_drop_ends_on_month_day" => "08-15",
];
$templateExample = $teachingPost(
    "/sis/term-types/" . $calendarTypeId . "/preview/",
    $typeSetup + ["start_year" => 2028],
);
$templateExampleData = json_decode((string) $templateExample->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $templateExample->getStatusCode() !== 200 ||
    $templateExampleData["code"] !== "2028FA" ||
    $templateExampleData["starts_on"] !== "2028-08-01" ||
    $sis->sql->rows("sis_term_types") !== []
) {
    throw new RuntimeException(
        "Protected term template preview changed configuration or used different generation rules.",
    );
}
if ($teachingPost("/sis/term-types/" . $calendarTypeId . "/save/", $typeSetup)->getStatusCode() !== 200) {
    throw new RuntimeException("Term type setup failed.");
}
$yearPreview = $teachingPost("/sis/academic-years/review/", ["start_year" => 2026]);
$yearPlan = json_decode((string) $yearPreview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($yearPreview->getStatusCode() !== 200) {
    throw new RuntimeException("Academic year preview failed.");
}
$yearSaved = $teachingPost("/sis/academic-years/create/", [
    "id" => $yearPlan["id"],
    "operation_id" => $yearPlan["operation_id"],
    "start_year" => 2026,
    "terms" => array_map(
        static fn(array $t): array => ["term_type_id" => $t["term_type_id"], "id" => $t["id"]],
        $yearPlan["terms"],
    ),
    "revision" => $yearPlan["revision"],
]);
if ($yearSaved->getStatusCode() !== 201) {
    throw new RuntimeException("Academic year creation failed.");
}
// The degree-builder chooser omits blank search rather than sending an invalid optional text field.
$degreeYearChoices = $teachingPost("/sis/academic-years/", []);
$degreeYearRows = json_decode((string) $degreeYearChoices->getBody(), true, flags: JSON_THROW_ON_ERROR);
$degreeYearSearch = $teachingPost("/sis/academic-years/", ["search" => "2026"]);
$degreeYearSearchRows = json_decode((string) $degreeYearSearch->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $degreeYearChoices->getStatusCode() !== 200 ||
    $degreeYearSearch->getStatusCode() !== 200 ||
    !in_array($yearPlan["id"], array_column($degreeYearRows["items"], "id"), true) ||
    ($degreeYearSearchRows["items"][0]["id"] ?? null) !== $yearPlan["id"]
) {
    throw new RuntimeException(
        "Degree-builder academic-year choices did not resolve through the actual router.",
    );
}
// Requirement authoring uses the actual provider, shared journal and existing academic year.
$requirementProgramPath = "/sis/programs/" . $sis->program->value . "/degree-audits/";
$requirementOpenedResponse = $teachingPost($requirementProgramPath . "open/", [
    "academic_year_id" => $yearPlan["id"],
]);
$requirementOpened = json_decode(
    (string) $requirementOpenedResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $requirementOpenedResponse->getStatusCode() !== 200 ||
    !isset($requirementOpened["id"]) ||
    !str_contains($requirementOpenedResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Degree requirement builder did not resolve privately.");
}
$requirementPath = "/sis/degree-audits/" . $requirementOpened["id"] . "/";
$requirementDefinition = $requirementOpened["definition"];
$requirementDefinition["min_credits_milli"] = 3000;
$requirementDefinition["policies"] = [
    "repeat_credit" => "highest_earned",
    "repeat_gpa" => "all_eligible",
    "transfer_overall_gpa" => "exclude",
    "waiver" => "waive_required_course_everywhere",
];
$groupId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$groupDefinition = [
    "name" => "Fictional shared core",
    "min_credits_milli" => 3000,
    "min_gpa_milli" => null,
    "courses" => [
        ["course_id" => $catalogCourse->courseId->value, "version_id" => $catalogDefinition["operation_id"]],
    ],
    "rules" => [
        [
            "id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
            "mode" => "all",
            "minimum_courses" => 1,
            "course_ids" => [$catalogCourse->courseId->value],
        ],
    ],
];
$groupNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid/sis/course-groups/create/",
    "POST",
);
if (
    $app->router
        ->match($groupNoCsrf->withParsedBody(["id" => $groupId, "definition" => $groupDefinition]))
        ->getStatusCode() !== 403
) {
    throw new RuntimeException("Independent group creation bypassed CSRF.");
}
$groupCreated = $teachingPost("/sis/course-groups/create/", [
    "id" => $groupId,
    "definition" => $groupDefinition,
]);
$groupReviewResponse = $teachingPost("/sis/course-groups/" . $groupId . "/review/", [
    "reason" => "Fictional shared group approval",
]);
$groupReview = json_decode((string) $groupReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $groupCreated->getStatusCode() !== 201 ||
    $groupReviewResponse->getStatusCode() !== 200 ||
    !isset($groupReview["review_token"])
) {
    throw new RuntimeException("Independent course group provider failed.");
}
$groupPublishedResponse = $teachingPost("/sis/course-groups/" . $groupId . "/publish/", [
    "review_token" => $groupReview["review_token"],
]);
$groupPublished = json_decode((string) $groupPublishedResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $groupPublishedResponse->getStatusCode() !== 200 ||
    $teachingPost("/sis/course-groups/page/", ["published_only" => true])->getStatusCode() !== 200 ||
    !str_contains($groupPublishedResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Course group publication did not use the protected provider.");
}
$gradeScaleDefinition = [
    "grades" => [
        [
            "code" => "A+",
            "min_score_milli" => 97000,
            "max_score_milli" => 100000,
            "points_milli" => 4000,
            "passes" => true,
            "counts_gpa" => true,
        ],
    ],
];
$gradeScaleNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid/sis/grading-scales/global/review/",
    "POST",
);
if (
    $app->router
        ->match(
            $gradeScaleNoCsrf->withParsedBody([
                "definition" => $gradeScaleDefinition,
                "reason" => "Fictional no CSRF",
            ]),
        )
        ->getStatusCode() !== 403
) {
    throw new RuntimeException("Global grading setup bypassed CSRF.");
}
$gradeScaleReviewResponse = $teachingPost("/sis/grading-scales/global/review/", [
    "definition" => $gradeScaleDefinition,
    "reason" => "Fictional global grading setup",
]);
$gradeScaleReview = json_decode(
    (string) $gradeScaleReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $gradeScaleReviewResponse->getStatusCode() !== 200 ||
    !isset($gradeScaleReview["review_token"]) ||
    $teachingPost("/sis/grading-scales/global/commit/", [
        "review_token" => $gradeScaleReview["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("Global grade scale did not resolve through the provider.");
}
$gradeScaleDefinition["grades"][0]["points_milli"] = 5000;
$programGradePath = "/sis/programs/" . $sis->program->value . "/grading-scale/";
$programGradeReview = json_decode(
    (string) $teachingPost($programGradePath . "review/", [
        "definition" => $gradeScaleDefinition,
        "reason" => "Fictional program grading setup",
    ])->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    !isset($programGradeReview["review_token"]) ||
    $teachingPost($programGradePath . "commit/", [
        "review_token" => $programGradeReview["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("Program grade override did not resolve through the provider.");
}
$gradingStudent = $sis->student();
$gradingSection = $sis->section(20, 0, $sis->access->actor);
$gradingEnrollment = $sis->service->register($gradingStudent->id, $gradingSection->id);
$gradeChoicesPath =
"/sis/sections/" .
$gradingSection->id->value .
"/students/" .
$gradingStudent->id->value .
"/grade-choices/";
$gradeChoicesNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $gradeChoicesPath,
    "POST",
);
if ($app->router->match($gradeChoicesNoCsrf)->getStatusCode() !== 403) {
    throw new RuntimeException("Student-scoped grade selection bypassed CSRF.");
}
$gradeChoicesResponse = $teachingPost($gradeChoicesPath, []);
$gradeChoices = json_decode((string) $gradeChoicesResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $gradeChoicesResponse->getStatusCode() !== 200 ||
    ($gradeChoices["items"][0]["points_milli"] ?? null) !== 5000 ||
    !str_contains($gradeChoicesResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Student grading policy did not resolve privately through the provider.");
}
$finalGradeBase = "/sis/sections/" . $gradingSection->id->value . "/final-grades/";
$gradeReviewResponse = $teachingPost($finalGradeBase . "review/", [
    "student_id" => $gradingStudent->id->value,
    "grade_id" => $gradeChoices["items"][0]["id"],
    "version" => 0,
]);
$gradeReview = json_decode((string) $gradeReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $gradeReviewResponse->getStatusCode() !== 200 ||
    !isset($gradeReview["review_token"]) ||
    $teachingPost($finalGradeBase . "commit/", [
        "student_id" => $gradingStudent->id->value,
        "review_token" => $gradeReview["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "A published program grade could not be reviewed and committed through the provider.",
    );
}
$gradeHistory = json_decode(
    (string) $teachingPost(
        "/sis/sections/" .
            $gradingSection->id->value .
            "/students/" .
            $gradingStudent->id->value .
            "/grade-history/",
        [],
    )->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    count($gradeHistory["items"] ?? []) !== 2 ||
    isset($gradeHistory["items"][1]["reason"]) ||
    isset($gradeHistory["items"][1]["actor_id"])
) {
    throw new RuntimeException("Curated academic history exposed protected event context.");
}
$gradedCredit = $sis->sql->one("sis_academic_credits", ["source_id" => $gradingEnrollment->id->value]);
if ((int) $gradedCredit["grade_points_milli"] !== 15000) {
    throw new RuntimeException("Program grading points were replaced by the global scale.");
}
// Browser transport and domain authority are both rechecked for consequential grade changes.
$gradeCorrectionData = [
    "student_id" => $gradingStudent->id->value,
    "grade_id" => $gradeChoices["items"][0]["id"],
    "version" => 1,
    "reason" => "Fictional assessment correction.",
];
$correctionNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $finalGradeBase . "review/",
    "POST",
);
if ($app->router->match($correctionNoCsrf->withParsedBody($gradeCorrectionData))->getStatusCode() !== 403) {
    throw new RuntimeException("Grade correction bypassed CSRF.");
}
$sis->access->staff = false;
$sis->access->grants = [];
if ($teachingPost($finalGradeBase . "review/", $gradeCorrectionData)->getStatusCode() !== 403) {
    throw new RuntimeException("Assigned faculty amended a grade without independent authority.");
}
$sis->access->grants = ["sis:gradebook:amend", "sis:student:read"];
$correctionResponse = $teachingPost($finalGradeBase . "review/", $gradeCorrectionData);
$correction = json_decode((string) $correctionResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $correctionResponse->getStatusCode() !== 200 ||
    !isset($correction["review_token"]) ||
    !str_contains($correctionResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Registrar correction review failed.");
}
$sis->access->grants = [];
if (
    $teachingPost($finalGradeBase . "commit/", [
        "student_id" => $gradingStudent->id->value,
        "review_token" => $correction["review_token"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Revoked correction authority committed a grade.");
}
$sis->access->grants = ["sis:gradebook:amend", "sis:student:read"];
if (
    $teachingPost($finalGradeBase . "commit/", [
        "student_id" => $gradingStudent->id->value,
        "review_token" => $correction["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("Authorized registrar correction failed.");
}
$sis->access->staff = true;
$sis->access->grants = [];
$removedGradeApi = $teachingPost("/sis/grade-definitions/", [
    "code" => "UNSAFE",
    "points_milli" => 4000,
    "passes" => true,
    "counts_gpa" => true,
]);
if (
    in_array($removedGradeApi->getStatusCode(), [200, 201], true) ||
    $sis->sql->rows("sis_grades", ["code" => "UNSAFE"]) !== []
) {
    throw new RuntimeException("The earlier independent grade write API is still active.");
}
$modernGroupId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$groupView = json_decode(
    (string) $app->router
        ->match(
            $get->withUri(
                new \Laminas\Diactoros\Uri(
                    "https://campus.example.invalid/sis/course-groups/" . $groupId . "/",
                ),
            ),
        )
        ->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$modernDefinition = $groupDefinition;
unset($modernDefinition["min_gpa_milli"]);
$modernDefinition["requirement_type"] = "courses";
$modernDefinition["min_credits_milli"] = 0;
$modernDefinition["min_courses"] = 1;
$modernDefinition["min_course_grade_points_milli"] = null;
$modernDefinition["grade_scale_program_id"] = null;
$modernDefinition["max_course_grade_points_milli"] = $groupView["grade_point_scale"]["maximum_milli"];
$modernCreated = $teachingPost("/sis/course-groups/create/", [
    "id" => $modernGroupId,
    "definition" => $modernDefinition,
]);
$modernReview = json_decode(
    (string) $teachingPost("/sis/course-groups/" . $modernGroupId . "/review/", [
        "reason" => "Fictional course-count group",
    ])->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $modernCreated->getStatusCode() !== 201 ||
    !isset($modernReview["review_token"]) ||
    $teachingPost("/sis/course-groups/" . $modernGroupId . "/publish/", [
        "review_token" => $modernReview["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "Course-count group and automatic grading scale did not resolve through the provider.",
    );
}
$inlineAttempt = $requirementDefinition;
$inlineAttempt["courses"] = $groupDefinition["courses"];
$inlineAttempt["groups"] = [
    ["id" => \Domain\Shared\ValueObject\EntityId::generate()->value] +
    array_diff_key($groupDefinition, ["courses" => true]),
];
if (
    $teachingPost($requirementPath . "save/", [
        "revision" => 0,
        "definition" => $inlineAttempt,
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException("The degree API accepted new embedded group authoring.");
}
$requirementDefinition["groups"] = [
    ["group_id" => $groupId, "version_id" => $groupPublished["operation_id"]],
];
$requirementNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $requirementPath . "save/",
    "POST",
);
if (
    $app->router
        ->match($requirementNoCsrf->withParsedBody(["revision" => 0, "definition" => $requirementDefinition]))
        ->getStatusCode() !== 403
) {
    throw new RuntimeException("Degree requirement editing bypassed CSRF.");
}
if (
    $teachingPost($requirementPath . "save/", [
        "revision" => 0,
        "definition" => $requirementDefinition,
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("Degree requirement draft could not be saved.");
}
$requirementReviewResponse = $teachingPost($requirementPath . "review/", [
    "reason" => "Fictional degree requirement approval",
]);
$requirementReview = json_decode(
    (string) $requirementReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if ($requirementReviewResponse->getStatusCode() !== 200 || !isset($requirementReview["review_token"])) {
    throw new RuntimeException("Degree requirement publication did not resolve.");
}
$requirementPublished = $teachingPost($requirementPath . "publish/", [
    "review_token" => $requirementReview["review_token"],
]);
$requirementRetry = $teachingPost($requirementPath . "publish/", [
    "review_token" => $requirementReview["review_token"],
]);
if (
    $requirementPublished->getStatusCode() !== 200 ||
    (string) $requirementRetry->getBody() !== (string) $requirementPublished->getBody() ||
    $teachingPost($requirementPath . "versions/", [])->getStatusCode() !== 200
) {
    throw new RuntimeException("Degree requirement publication was not repeatable.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read", "sis:registration:manage"];
if (
    $teachingPost("/sis/course-groups/page/", ["published_only" => false])->getStatusCode() !== 403 ||
    $teachingPost("/sis/course-groups/" . $groupId . "/review/", [
        "reason" => "Forbidden",
    ])->getStatusCode() !== 403 ||
    $teachingPost($requirementProgramPath . "page/", [])->getStatusCode() !== 403 ||
    $teachingPost($requirementPath . "versions/", [])->getStatusCode() !== 403 ||
    $teachingPost($requirementPath . "publish/", [
        "review_token" => $requirementReview["review_token"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Academic read or registration access exposed degree requirement authoring.");
}
$sis->access->staff = true;
$sis->access->grants = [];

$matriculation = $app->make(\Domain\Admissions\Service\AdmissionsService::class);
$catalogPerson = $matriculation->createPerson($sis->user(), "Fictional", "Cohort");
$catalogApplication = $matriculation->apply($catalogPerson->id, $sis->program, $sis->term);
$matriculation->decide($catalogApplication->id, true);
$catalogStudent = $matriculation->matriculate($catalogApplication->id);
$catalogAssignments = json_decode(
    (string) $teachingPost("/sis/students/" . $catalogStudent->id->value . "/programs/", [])->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$catalogAssignment = $catalogAssignments["items"][0];
$studentCatalogPath =
"/sis/students/" . $catalogStudent->id->value . "/programs/" . $catalogAssignment["id"] . "/catalog/";
$catalogInfo = $teachingPost($studentCatalogPath, []);
if (
    $catalogAssignment["catalog_year"] !== "2026-2027" ||
    $catalogInfo->getStatusCode() !== 200 ||
    $teachingPost($studentCatalogPath . "history/", [])->getStatusCode() !== 200
) {
    throw new RuntimeException("Automatic matriculation catalog assignment or private history failed.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read"];
if ($teachingPost($studentCatalogPath . "history/", [])->getStatusCode() !== 403) {
    throw new RuntimeException("Academic read alone exposed private catalog-change reasons.");
}
$sis->access->staff = true;
$sis->access->grants = [];

// Student requirement decisions use the actual provider, cohort scope and private envelope.
$studentRequirementPath =
"/sis/students/" .
$catalogStudent->id->value .
"/programs/" .
$catalogAssignment["id"] .
"/degree-requirements/";
$studentChoices = $teachingPost($studentRequirementPath . "choices/", []);
if ($studentChoices->getStatusCode() !== 200) {
    throw new RuntimeException("Student requirement choices did not resolve.");
}
$requirementReceipt = json_decode(
    (string) $requirementPublished->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$studentRequirementReviewResponse = $teachingPost($studentRequirementPath . "review/", [
    "template_id" => $requirementOpened["id"],
    "requirements_version_id" => $requirementReceipt["operation_id"],
    "pursuit_started_on" => "2026-08-01",
    "reason" => "Fictional student requirement assignment",
]);
$studentRequirementReview = json_decode(
    (string) $studentRequirementReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $studentRequirementReviewResponse->getStatusCode() !== 200 ||
    !isset($studentRequirementReview["review_token"])
) {
    throw new RuntimeException("Student requirement review failed through the actual provider.");
}
$studentRequirementNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $studentRequirementPath . "assign/",
    "POST",
);
if (
    $app->router
        ->match(
            $studentRequirementNoCsrf->withParsedBody([
                "review_token" => $studentRequirementReview["review_token"],
            ]),
        )
        ->getStatusCode() !== 403
) {
    throw new RuntimeException("Student requirement assignment bypassed CSRF.");
}
$studentRequirementAssigned = $teachingPost($studentRequirementPath . "assign/", [
    "review_token" => $studentRequirementReview["review_token"],
]);
if (
    $studentRequirementAssigned->getStatusCode() !== 200 ||
    (string) $teachingPost($studentRequirementPath . "assign/", [
        "review_token" => $studentRequirementReview["review_token"],
    ])->getBody() !== (string) $studentRequirementAssigned->getBody() ||
    !str_contains($studentRequirementAssigned->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Private student requirement assignment was not retry safe.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read"];
$studentRequirementRead = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $studentRequirementPath)),
);
$studentRequirementReadBody = json_decode(
    (string) $studentRequirementRead->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $studentRequirementRead->getStatusCode() !== 200 ||
    !isset($studentRequirementReadBody["requirements"]["group_details"][$groupId]) ||
    !str_contains($studentRequirementRead->getHeaderLine("Cache-Control"), "no-store") ||
    $teachingPost($studentRequirementPath . "choices/", [])->getStatusCode() !== 403 ||
    $teachingPost($studentRequirementPath . "assign/", [
        "review_token" => $studentRequirementReview["review_token"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Student reads exposed requirement assignment authority.");
}
$sis->access->staff = true;
$sis->access->grants = [];

// Course waivers use the same protected event table and independent office permission.
$adjustmentPath = str_replace("degree-requirements/", "degree-adjustments/", $studentRequirementPath);
$adjustmentReviewResponse = $teachingPost($adjustmentPath . "review/", [
    "definition" => ["kind" => "waiver", "course_id" => $catalogCourse->courseId->value],
    "reason" => "Fictional registrar waiver",
]);
$adjustmentReview = json_decode(
    (string) $adjustmentReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if ($adjustmentReviewResponse->getStatusCode() !== 200 || !isset($adjustmentReview["review_token"])) {
    throw new RuntimeException("Student exception controller could not resolve.");
}
$adjustmentNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $adjustmentPath . "commit/",
    "POST",
);
if (
    $app->router
        ->match($adjustmentNoCsrf->withParsedBody(["review_token" => $adjustmentReview["review_token"]]))
        ->getStatusCode() !== 403
) {
    throw new RuntimeException("Student exception bypassed CSRF.");
}
$adjustmentCommit = $teachingPost($adjustmentPath . "commit/", [
    "review_token" => $adjustmentReview["review_token"],
]);
if (
    $adjustmentCommit->getStatusCode() !== 200 ||
    (string) $teachingPost($adjustmentPath . "commit/", [
        "review_token" => $adjustmentReview["review_token"],
    ])->getBody() !== (string) $adjustmentCommit->getBody()
) {
    throw new RuntimeException("Student exception commit was not retry safe.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read"];
$adjustmentView = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $adjustmentPath)),
);
$adjustmentData = json_decode((string) $adjustmentView->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $adjustmentView->getStatusCode() !== 200 ||
    count($adjustmentData["items"] ?? []) !== 1 ||
    !str_contains($adjustmentView->getHeaderLine("Cache-Control"), "no-store") ||
    $teachingPost($adjustmentPath . "commit/", [
        "review_token" => $adjustmentReview["review_token"],
    ])->getStatusCode() !== 403 ||
    $teachingPost($adjustmentPath . "history/", [])->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "Student exception reads leaked approval authority or failed private routing.",
    );
}
$sis->access->staff = true;
$sis->access->grants = [];

// Published substitutions are selected without granting catalog-editor authority.
$sis->access->staff = true;
$sis->access->grants = [];
$replacementId = \Domain\Shared\ValueObject\EntityId::generate();
$sis->registration->addCourse(
    new \Domain\Catalog\Course($replacementId, "EXC.201", "Fictional replacement", 4000),
);
$replacementPublisher = $app->make(\Domain\Catalog\Service\CoursePublicationService::class);
$replacementPlan = $replacementPublisher->review($replacementId, "Fictional replacement publication");
$replacementReceipt = $replacementPublisher->publish($replacementId, $replacementPlan["review_token"]);
$waiverRevoke = json_decode(
    (string) $teachingPost($adjustmentPath . "revoke-review/", [
        "adjustment_id" => $adjustmentReview["adjustment"]["adjustment_id"],
        "reason" => "Fictional substitution instead",
    ])->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $teachingPost($adjustmentPath . "commit/", [
        "review_token" => $waiverRevoke["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("Waiver revocation failed before substitution.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read", "sis:degree-audit:adjust"];
$replacementChoices = $teachingPost($adjustmentPath . "replacement-courses/", ["search" => "EXC.201"]);
$replacementVersions = $teachingPost(
    $adjustmentPath . "replacement-courses/" . $replacementId->value . "/versions/",
    [],
);
if ($replacementChoices->getStatusCode() !== 200 || $replacementVersions->getStatusCode() !== 200) {
    throw new RuntimeException("Protected replacement selectors failed to resolve.");
}
$substitutionReviewResponse = $teachingPost($adjustmentPath . "review/", [
    "definition" => [
        "kind" => "substitution",
        "course_id" => $catalogCourse->courseId->value,
        "replacement_course_id" => $replacementId->value,
        "replacement_version_id" => $replacementReceipt["operation_id"],
    ],
    "reason" => "Fictional substitution approval",
]);
$substitutionReview = json_decode(
    (string) $substitutionReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $substitutionReviewResponse->getStatusCode() !== 200 ||
    !isset($substitutionReview["review_token"]) ||
    $teachingPost($adjustmentPath . "commit/", [
        "review_token" => $substitutionReview["review_token"],
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException("Published student substitution failed through its provider.");
}
$sis->access->staff = true;
$sis->access->grants = [];

// Joined Report Studio plans remain typed, private and independently authorized at execution.
$studioDefinition = [
    "dataset" => "programs",
    "columns" => [
        ["field" => "institutional_id", "label" => "Student ID", "aggregate" => "none"],
        ["field" => "full_name", "label" => "Name", "aggregate" => "none"],
        ["field" => "catalog_year", "label" => "Catalog year", "aggregate" => "none"],
    ],
    "filters" => [
        "condition" => "AND",
        "rules" => [["id" => "catalog_year", "operator" => "equal", "value" => "2026-2027"]],
    ],
    "joins" => [],
    "distinct" => false,
];
$studioPreview = $teachingPost("/sis/report-studio/preview/", [
    "definition" => $studioDefinition,
    "offset" => 0,
]);
$studioReport = \Domain\Shared\ValueObject\EntityId::generate()->value;
$studioSaved = $teachingPost("/sis/report-studio/save/", [
    "report_id" => $studioReport,
    "operation_id" => $studioReport,
    "version" => -1,
    "name" => "Fictional catalog cohorts",
    "definition" => $studioDefinition,
]);
if (
    $studioPreview->getStatusCode() !== 200 ||
    $studioSaved->getStatusCode() !== 201 ||
    $teachingPost("/sis/report-studio/" . $studioReport . "/run/", ["offset" => 0])->getStatusCode() !==
        200 ||
    $teachingPost("/sis/report-studio/" . $studioReport . "/export/", ["offset" => 0])->getStatusCode() !==
        200
) {
    throw new RuntimeException(
        "The joined report builder did not resolve through its provider and protected envelope.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:reporting:run"];
if (
    $teachingPost("/sis/report-studio/" . $studioReport . "/run/", ["offset" => 0])->getStatusCode() !== 403
) {
    throw new RuntimeException("Report execution granted its own underlying student-data permission.");
}
$sis->access->staff = true;
$sis->access->grants = [];

// Staff person and access directories use the same real provider, JSON envelope and CSRF pipeline.
$personRow = $sis->sql->rows("sis_persons")[0];
$profileRequest = $get->withUri(
    new \Laminas\Diactoros\Uri(
        "https://campus.example.invalid/sis/ui/people/" . $personRow["id"] . "/profile/",
    ),
);
$profileResponse = $app->router->match($profileRequest);
$profile = json_decode((string) $profileResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($profileResponse->getStatusCode() !== 200 || !isset($profile["revision"])) {
    throw new RuntimeException("The person profile editor could not resolve through the provider.");
}
$profileWrite = $post
    ->withUri($profileRequest->getUri())
    ->withBody(new \Laminas\Diactoros\Stream("php://temp", "w+"));
$profileWrite->getBody()->write(
    http_build_query([
        "_workspace_json" => json_encode([
            "given_name" => $personRow["given_name"],
            "family_name" => $personRow["family_name"],
            "directory_restricted" => false,
            "revision" => $profile["revision"],
        ]),
    ]),
);
$profileWrite->getBody()->rewind();
if ($app->router->match($profileWrite)->getStatusCode() !== 403) {
    throw new RuntimeException("A person edit bypassed CSRF.");
}
$profileWrite->getBody()->rewind();
if (
    $app->router->match($profileWrite->withHeader("X-CSRF-Token", "fictionalcsrftoken"))->getStatusCode() !==
        200 ||
    (int) $sis->sql->one("sis_persons", ["id" => $personRow["id"]])["directory_restricted"] !== 0
) {
    throw new RuntimeException("A protected typed profile edit failed.");
}
$directoryRequest = $post
    ->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid/sis/access/directory/roles/"))
    ->withHeader("X-CSRF-Token", "fictionalcsrftoken")
    ->withBody(new \Laminas\Diactoros\Stream("php://temp", "w+"));
$directoryRequest
    ->getBody()
    ->write(http_build_query(["_workspace_json" => json_encode(["search" => "", "limit" => 2])]));
$directoryRequest->getBody()->rewind();
$directoryResponse = $app->router->match($directoryRequest);
if (
    $directoryResponse->getStatusCode() !== 200 ||
    !str_contains($directoryResponse->getHeaderLine("Cache-Control"), "no-store") ||
    count(json_decode((string) $directoryResponse->getBody(), true, flags: JSON_THROW_ON_ERROR)["items"]) !==
        2
) {
    throw new RuntimeException("The bounded access directory did not resolve through the provider.");
}

// Exercise Vihzhuo's real block renderer and theme inheritance, using only an in-memory CMS.
$config = $app->configContainer->array("vihzhuo");
ini_set("session.save_path", $fixture);
$builder = new \Application\Service\CodefyPageBuilder($config);
global $phpb_db;
$phpb_db->query(
    "CREATE TABLE settings (id INTEGER PRIMARY KEY, setting VARCHAR(50), value TEXT, is_array INTEGER)",
);
$phpb_db->query(
    "CREATE TABLE page_translations (id INTEGER PRIMARY KEY, page_id INTEGER, locale VARCHAR(8), route VARCHAR(191))",
);
\Vihzhuo\Core\HttpContext::setRequest($get);
$page = new \Vihzhuo\Page();
$page->setData([
    "id" => "fixture",
    "layout" => "portal",
    "data" => \Application\Service\CampusPageTemplate::data(),
]);
$page->setTranslations([
    "en" => ["meta_title" => "Fictional page", "meta_description" => "", "route" => "/"],
]);
foreach (["campus-harbor", "campus-quad", "campus-horizon"] as $slug) {
    $theme = new \Vihzhuo\Theme($config["theme"], $slug);
    $blocks = $theme->getThemeBlocks();
    $foreachItems = [
        "campus-hero",
        "campus-pathways",
        "campus-courses",
        "campus-news",
        "campus-programs",
        "campus-invitation",
    ];
    foreach ($foreachItems as $block) {
        if (!isset($blocks[$block]) || $blocks[$block]->get("cache") !== false) {
            throw new RuntimeException("Native campus block registration or privacy failed.");
        }
    }
    $html = new \Vihzhuo\Modules\GrapesJS\PageRenderer($theme, $page)->render();
    if (
        !str_contains($html, "/themes/" . $slug . "/public/css/theme.css") ||
        !str_contains($html, "Public fixture") ||
        str_contains($html, "Campus-only fixture") ||
        str_contains($html, "[block slug=")
    ) {
        throw new RuntimeException(
            "Vihzhuo did not render its native portal blocks or inherited layout safely.",
        );
    }
    $editor = new \Vihzhuo\Modules\GrapesJS\Block\BlockRenderer($theme, $page, true);
    $html = $editor->renderWithSlug("campus-hero", [
        "settings" => ["attributes" => ["headline" => "<script>fictional</script>"]],
    ]);
    if (
        !str_contains($html, "&lt;script&gt;fictional&lt;/script&gt;") ||
        !str_contains($html, "<phpb-block")
    ) {
        throw new RuntimeException("Editable native block settings were not safely escaped.");
    }
}
// The signature endpoint can resolve before constructing any HTTP kernel or parsing a JSON request body.
$webhook = $app->make(\Application\Http\Controller\PaymentWebhookController::class);
if ($webhook->receive('{"amount_total":12345}', "")->getStatusCode() !== 400) {
    throw new RuntimeException("An unsigned payment notification was accepted.");
}

$phpb_db->query("CREATE TABLE pages (id INTEGER PRIMARY KEY)");
$phpb_db->query("CREATE TABLE uploads (id INTEGER PRIMARY KEY)");
$empty = new \Vihzhuo\Page();
$empty->setData(["id" => "empty-fixture", "layout" => "portal"]);
$empty->setTranslations(["en" => ["meta_title" => "Fictional new portal page", "route" => "/fixture"]]);
$editor = new \Application\Service\PageEditor();
\Codefy\Framework\Http\RequestContext::set($get);
$response = $editor->renderPageBuilder($empty);
if (
    $response->getStatusCode() !== 200 ||
    $empty->getBuilderData() !== \Application\Service\CampusPageTemplate::data()
) {
    throw new RuntimeException("An empty portal page did not offer an editable starter.");
}

// Posted transfer corrections resolve through the real provider, CSRF pipeline and nested student routes.
$sis->access->staff = true;
$postedTransfer = $sis->sql->one("sis_transfer_awards", ["equivalency_id" => $equivalencyId]);
$correctionPath =
"/sis/students/" . $postedTransfer["student_id"] . "/transfer-awards/" . $postedTransfer["id"] . "/";
$correctionRequest = $get->withUri(
    new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $correctionPath),
);
if ($app->router->match($correctionRequest)->getStatusCode() !== 200) {
    throw new RuntimeException("The transfer award controller could not resolve.");
}
$correctionData = [
    "action" => "amend",
    "credits_milli" => 2500,
    "awarded_grade" => "TR",
    "reason" => "Fictional registrar correction",
    "effective_on" => "2026-08-01",
];
$correctionPreview = $teachingPost($correctionPath . "preview/", $correctionData);
$correctionReview = json_decode((string) $correctionPreview->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $correctionPreview->getStatusCode() !== 200 ||
    ($correctionReview["after"]["completed_milli"] ?? null) !== 2500
) {
    throw new RuntimeException("Transfer correction preview failed.");
}
$correctionOperation = \Domain\Shared\ValueObject\EntityId::generate()->value;
$correctionPayload = $correctionData + [
    "operation_id" => $correctionOperation,
    "revision" => $correctionReview["revision"],
];
$correctionSaved = $teachingPost($correctionPath . "confirm/", $correctionPayload);
$correctionRetry = $teachingPost($correctionPath . "confirm/", $correctionPayload);
if (
    $correctionSaved->getStatusCode() !== 200 ||
    (string) $correctionSaved->getBody() !== (string) $correctionRetry->getBody() ||
    $teachingPost($correctionPath . "history/", [])->getStatusCode() !== 200 ||
    $app->router
        ->match(
            $get->withUri(
                new \Laminas\Diactoros\Uri(
                    "https://campus.example.invalid" .
                        $correctionPath .
                        "changes/" .
                        $correctionOperation .
                        "/",
                ),
            ),
        )
        ->getStatusCode() !== 200
) {
    throw new RuntimeException("Transfer correction confirmation, retry or history failed.");
}
$transferHistoryResponse = $teachingPost($correctionPath . "history/", []);
$transferHistory = json_decode(
    (string) $transferHistoryResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    ($transferHistory["items"][0]["history_source"] ?? null) !== "journal" ||
    !str_starts_with($transferHistory["items"][0]["effective_at"] ?? "", "2026-08-01") ||
    !str_contains($transferHistoryResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Transfer history did not use its protected effective-dated journal.");
}
$correctionNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $correctionPath . "preview/",
    "POST",
);
if ($app->router->match($correctionNoCsrf->withParsedBody($correctionData))->getStatusCode() !== 403) {
    throw new RuntimeException("Transfer correction bypassed CSRF.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:transfer:manage"];
if (
    $app->router->match($correctionRequest)->getStatusCode() !== 403 ||
    $teachingPost($correctionPath . "history/", [])->getStatusCode() !== 403
) {
    throw new RuntimeException("Transfer catalog access exposed protected correction history.");
}
$sis->access->staff = true;

// Registrar enrollment history is private, curated and protected by browser CSRF.
$sis->access->staff = true;
$registrationHistoryPath = "/sis/students/" . $teachingStudent->id->value . "/registration-history/";
$registrationHistoryResponse = $teachingPost($registrationHistoryPath, []);
$registrationHistory = json_decode(
    (string) $registrationHistoryResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $registrationHistoryResponse->getStatusCode() !== 200 ||
    count($registrationHistory["items"] ?? []) !== 1 ||
    ($registrationHistory["items"][0]["items"][0]["section_sequence"] ?? null) !== 1 ||
    !str_contains($registrationHistoryResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Registrar enrollment history did not resolve privately.");
}
if (
    str_contains(json_encode($registrationHistory, JSON_THROW_ON_ERROR), '"eligibility"') ||
    str_contains(json_encode($registrationHistory, JSON_THROW_ON_ERROR), '"actor_id"')
) {
    throw new RuntimeException("Registration history exposed protected event payloads.");
}
$registrationHistoryNoCsrf = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $registrationHistoryPath,
    "POST",
);
if ($app->router->match($registrationHistoryNoCsrf)->getStatusCode() !== 403) {
    throw new RuntimeException("Registration history bypassed CSRF.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:student:read"];
if ($teachingPost($registrationHistoryPath, [])->getStatusCode() !== 403) {
    throw new RuntimeException("Student academic access exposed restricted enrollment history.");
}
$sis->access->staff = true;
$sis->access->grants = [];

// Course drops resolve through the real DI/router/CSRF pipeline and keep replay receipts after deletion.
$sis->access->staff = true;
$dropStudent = $sis->student();
$dropSection = $sis->section();
$sis->service->register($dropStudent->id, $dropSection->id, true);
$dropReviewResponse = $teachingPost(
    "/sis/students/" . $dropStudent->id->value . "/sections/" . $dropSection->id->value . "/drop-review/",
    [],
);
$dropReview = json_decode((string) $dropReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($dropReviewResponse->getStatusCode() !== 200) {
    throw new RuntimeException("Drop review did not resolve.");
}
$dropCommand = array_intersect_key(
    $dropReview,
    array_flip([
        "student_id",
        "section_id",
        "enrollment_id",
        "version",
        "operation_id",
        "revision",
        "effective_on",
    ]),
);
$dropSaved = $teachingPost("/sis/registrations/drop/", $dropCommand);
$dropRetry = $teachingPost("/sis/registrations/drop/", $dropCommand);
$dropReceiptResponse = $app->router->match(
    $get->withUri(
        new \Laminas\Diactoros\Uri(
            "https://campus.example.invalid/sis/course-drops/" . $dropReview["operation_id"] . "/",
        ),
    ),
);
$expectDropStatus = static function (
    int $expected,
    \Psr\Http\Message\ResponseInterface $response,
    string $step,
): void {
    if ($response->getStatusCode() !== $expected) {
        throw new RuntimeException(
            $step . " expected HTTP " . $expected . ", received HTTP " . $response->getStatusCode(),
        );
    }
};
$expectDropStatus(200, $dropSaved, "Drop confirmation");
$expectDropStatus(200, $dropRetry, "Drop retry");
$expectDropStatus(200, $dropReceiptResponse, "Drop receipt");
if (
    (string) $dropSaved->getBody() !== (string) $dropRetry->getBody() ||
    !str_contains($dropReceiptResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Drop retry or privacy failed.");
}
$expectDropStatus(
    422,
    $teachingPost("/sis/registrations/drop/", [
        "student_id" => $dropStudent->id->value,
        "section_id" => $dropSection->id->value,
    ]),
    "Missing reviewed drop command",
);
$dropPolicyPath = "/sis/terms/" . $sis->term->value . "/drop-policy/";
if (
    $app->router
        ->match($get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $dropPolicyPath)))
        ->getStatusCode() !== 200 ||
    $teachingPost($dropPolicyPath, [
        "version" => -1,
        "windows" => [["through" => null, "grade_code" => "MISSING"]],
    ])->getStatusCode() !== 422
) {
    throw new RuntimeException("Drop policy provider or validation failed.");
}

// Report requests use the real providers, typed browser transport, CSRF and private response pipeline.
$sis->access->staff = true;
$reportRequestId = \Domain\Shared\ValueObject\EntityId::generate()->value;
$reportRequestData = [
    "operation_id" => $reportRequestId,
    "title" => "Fictional registrar question",
    "question" => "How many students are in each fictional program?",
    "purpose" => "Plan fictional course offerings.",
];
$reportRequestCreated = $teachingPost("/sis/report-requests/", $reportRequestData);
$reportRequestRetry = $teachingPost("/sis/report-requests/", $reportRequestData);
$reportRequestPath = "/sis/report-requests/" . $reportRequestId . "/";
if (
    $reportRequestCreated->getStatusCode() !== 201 ||
    (string) $reportRequestCreated->getBody() !== (string) $reportRequestRetry->getBody() ||
    $teachingPost("/sis/report-requests/page/", ["queue" => true, "status" => "open"])->getStatusCode() !==
        200 ||
    $app->router
        ->match(
            $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $reportRequestPath)),
        )
        ->getStatusCode() !== 200
) {
    throw new RuntimeException("Report request creation, retry, queue or provider resolution failed.");
}
$reportClaim = $teachingPost($reportRequestPath . "actions/", [
    "operation_id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "version" => 0,
    "action" => "claim",
]);
$reportForRequest = $app
    ->make(\Domain\Reporting\Service\ReportService::class)
    ->create("Fictional request answer", null, null, null);
$reportDeliveryPreview = $teachingPost($reportRequestPath . "delivery-review/", [
    "report_id" => $reportForRequest->id->value,
]);
$reportDeliveryPlan = json_decode(
    (string) $reportDeliveryPreview->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if ($reportClaim->getStatusCode() !== 200 || $reportDeliveryPreview->getStatusCode() !== 200) {
    throw new RuntimeException("Report claim or handoff review failed.");
}
$reportDeliveryBody = [
    "operation_id" => $reportDeliveryPlan["operation_id"],
    "version" => $reportDeliveryPlan["version"],
    "action" => "deliver",
    "note" => "Fictional reviewed report answer",
    "report_id" => $reportForRequest->id->value,
    "delivery_revision" => $reportDeliveryPlan["delivery_revision"],
];
$reportDelivered = $teachingPost($reportRequestPath . "actions/", $reportDeliveryBody);
$reportDeliveredAgain = $teachingPost($reportRequestPath . "actions/", $reportDeliveryBody);
if (
    $reportDelivered->getStatusCode() !== 200 ||
    (string) $reportDelivered->getBody() !== (string) $reportDeliveredAgain->getBody() ||
    $teachingPost($reportRequestPath . "history/", [])->getStatusCode() !== 200 ||
    $app->router
        ->match(
            $get->withUri(
                new \Laminas\Diactoros\Uri(
                    "https://campus.example.invalid" .
                        $reportRequestPath .
                        "receipts/" .
                        $reportDeliveryPlan["operation_id"] .
                        "/",
                ),
            ),
        )
        ->getStatusCode() !== 200
) {
    throw new RuntimeException("Report handoff, immutable receipt or conversation history failed.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:reporting:run"];
if (
    $teachingPost("/sis/report-requests/page/", ["queue" => true])->getStatusCode() !== 403 ||
    $teachingPost($reportRequestPath . "delivery-review/", [
        "report_id" => $reportForRequest->id->value,
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Report requester accessed specialist actions.");
}
$sis->access->staff = true;

// Offering templates resolve through the real providers and CSRF/private typed transport.
$sis->access->staff = true;
$templateDefaults = [
    [
        "course_id" => $teachingSection->courseId->value,
        "code" => "FIX.TEMPLATE.A",
        "capacity" => 20,
        "faculty_user_id" => null,
        "fee_minor" => 1250,
        "currency" => "USD",
        "delivery_mode" => "online",
        "web_registration" => true,
    ],
];
$templateDraft = ["version" => -1, "name" => "Fictional reusable offerings", "sections" => $templateDefaults];
$templateReviewResponse = $teachingPost("/sis/catalog/offering-templates/review/", $templateDraft);
$templateReview = json_decode((string) $templateReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($templateReviewResponse->getStatusCode() !== 200) {
    throw new RuntimeException("Template review could not resolve.");
}
$templatePath = "/sis/catalog/offering-templates/" . $templateReview["id"] . "/";
$templateCommand = $templateDraft + [
    "operation_id" => $templateReview["operation_id"],
    "revision" => $templateReview["revision"],
];
$templateSaved = $teachingPost($templatePath . "save/", $templateCommand);
$templateRetry = $teachingPost($templatePath . "save/", $templateCommand);
if (
    $templateSaved->getStatusCode() !== 200 ||
    (string) $templateSaved->getBody() !== (string) $templateRetry->getBody() ||
    $teachingPost("/sis/catalog/offering-templates/page/", ["active" => true])->getStatusCode() !== 200 ||
    $teachingPost($templatePath . "prepare/", [
        "version" => 0,
        "term_id" => $sis->term->value,
    ])->getStatusCode() !== 200 ||
    $teachingPost($templatePath . "history/", [])->getStatusCode() !== 200 ||
    $app->router
        ->match($get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $templatePath)))
        ->getStatusCode() !== 200 ||
    $app->router
        ->match(
            $get->withUri(
                new \Laminas\Diactoros\Uri(
                    "https://campus.example.invalid" .
                        $templatePath .
                        "receipts/" .
                        $templateReview["operation_id"] .
                        "/",
                ),
            ),
        )
        ->getStatusCode() !== 200
) {
    throw new RuntimeException("Template save, retry, preparation, history or receipt failed.");
}
$withSourceIdentity = $templateDraft;
$withSourceIdentity["sections"][0]["term_id"] = $sis->term->value;
if ($teachingPost("/sis/catalog/offering-templates/review/", $withSourceIdentity)->getStatusCode() !== 422) {
    throw new RuntimeException("A template accepted a source term identity.");
}
$templateStatus = $teachingPost($templatePath . "status/", [
    "operation_id" => \Domain\Shared\ValueObject\EntityId::generate()->value,
    "version" => 0,
    "active" => false,
    "note" => "Fictional retirement.",
]);
if (
    $templateStatus->getStatusCode() !== 200 ||
    $teachingPost($templatePath . "prepare/", [
        "version" => 0,
        "term_id" => $sis->term->value,
    ])->getStatusCode() !== 409
) {
    throw new RuntimeException("Retired template preparation was permitted.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:registration:manage"];
if (
    $app->router
        ->match($get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $templatePath)))
        ->getStatusCode() !== 403 ||
    $teachingPost("/sis/catalog/offering-templates/page/", ["active" => true])->getStatusCode() !== 403
) {
    throw new RuntimeException("Registration permission exposed template configuration.");
}
$sis->access->staff = true;

// Course publication uses the real provider, shared journal, CSRF and private responses.
$publicationCourse = $sis->section()->courseId->value;
$publicationPath = "/sis/courses/" . $publicationCourse . "/publication/";
$sis->access->staff = true;
$publicationBefore = $sis->sql->rows("sis_history_events");
$unverifiedPublication = new \Qubus\Http\ServerRequest(
    [],
    [],
    "https://campus.example.invalid" . $publicationPath . "review/",
    "POST",
)
    ->withHeader("Content-Type", "application/x-www-form-urlencoded")
    ->withHeader("Accept", "application/json")
    ->withAttribute(
        \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
        "fictionalcsrftoken",
    )
    ->withParsedBody(["reason" => "Fictional academic catalog publication\nApproved fictional revision."]);
if (
    $app->router->match($unverifiedPublication)->getStatusCode() !== 403 ||
    $sis->sql->rows("sis_history_events") !== $publicationBefore
) {
    throw new RuntimeException("Course publication bypassed CSRF.");
}
$courseTextPath = "/sis/courses/" . $publicationCourse . "/text/";
$courseTextResponse = $app->router->match(
    $get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $courseTextPath)),
);
$courseText = json_decode((string) $courseTextResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $courseTextResponse->getStatusCode() !== 200 ||
    !str_contains($courseTextResponse->getHeaderLine("Cache-Control"), "no-store") ||
    $teachingPost($courseTextPath, [
        "revision" => $courseText["revision"],
        "transcript_title" => "Fictional short title",
        "description" => "Fictional first paragraph.\nA second paragraph.",
    ])->getStatusCode() !== 200
) {
    throw new RuntimeException(
        "Course text draft editing did not resolve through protected provider bindings.",
    );
}
$publicationReviewResponse = $teachingPost($publicationPath . "review/", [
    "reason" => "Fictional academic catalog publication\nApproved fictional revision.",
]);
$publicationReview = json_decode(
    (string) $publicationReviewResponse->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $publicationReviewResponse->getStatusCode() !== 200 ||
    !isset($publicationReview["review_token"]) ||
    !str_contains($publicationReviewResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Course publication review did not resolve privately through the provider.");
}
$publicationSaved = $teachingPost($publicationPath . "commit/", [
    "review_token" => $publicationReview["review_token"],
]);
$publicationRetry = $teachingPost($publicationPath . "commit/", [
    "review_token" => $publicationReview["review_token"],
]);
$publicationPage = $teachingPost($publicationPath . "page/", []);
$publishedDefinitions = json_decode((string) $publicationPage->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $publishedDefinitions["items"][0]["schema_version"] !== 2 ||
    $publishedDefinitions["items"][0]["definition"]["transcript_title"] !== "Fictional short title"
) {
    throw new RuntimeException("Course publication did not retain the reviewed catalog text.");
}

if (
    $publicationSaved->getStatusCode() !== 200 ||
    $publicationRetry->getStatusCode() !== 200 ||
    (string) $publicationSaved->getBody() !== (string) $publicationRetry->getBody() ||
    count(json_decode((string) $publicationPage->getBody(), true, flags: JSON_THROW_ON_ERROR)["items"]) !== 1
) {
    throw new RuntimeException("Course publication failed to preserve an idempotent, readable definition.");
}
$bindingSection = new \Domain\Registration\Section(
    \Domain\Shared\ValueObject\EntityId::generate(),
    new \Domain\Shared\ValueObject\EntityId($publicationCourse),
    $sis->term,
    null,
    "FICTIONAL.BOUND.A",
    20,
    true,
    true,
    new \Domain\Finance\ValueObject\Money(0, "USD"),
);
$sis->registration->addSection($bindingSection);
$bindingPath = "/sis/sections/" . $bindingSection->id->value . "/course-definition/";
$bindingUnverified = $unverifiedPublication->withUri(
    new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $bindingPath . "review/"),
);
if ($app->router->match($bindingUnverified)->getStatusCode() !== 403) {
    throw new RuntimeException("Section course binding bypassed CSRF.");
}
$bindingReviewResponse = $teachingPost($bindingPath . "review/", [
    "version_id" => $publishedDefinitions["items"][0]["version_id"],
    "reason" => "Fictional offering setup",
]);
$bindingReview = json_decode((string) $bindingReviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if (
    $bindingReviewResponse->getStatusCode() !== 200 ||
    !isset($bindingReview["review_token"]) ||
    !str_contains($bindingReviewResponse->getHeaderLine("Cache-Control"), "no-store")
) {
    throw new RuntimeException("Section course binding did not resolve privately through the provider.");
}
$bindingSaved = $teachingPost($bindingPath . "commit/", ["review_token" => $bindingReview["review_token"]]);
$bindingRetry = $teachingPost($bindingPath . "commit/", ["review_token" => $bindingReview["review_token"]]);
$bindingShown = json_decode(
    (string) $teachingPost($bindingPath . "page/", [])->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $bindingSaved->getStatusCode() !== 200 ||
    $bindingRetry->getStatusCode() !== 200 ||
    (string) $bindingSaved->getBody() !== (string) $bindingRetry->getBody() ||
    $bindingShown["bound"]["version_id"] !== $publishedDefinitions["items"][0]["version_id"]
) {
    throw new RuntimeException("Section course binding did not retain an idempotent definition.");
}
$sis->access->staff = false;
$sis->access->grants = ["sis:registration:manage"];
if (
    $teachingPost($bindingPath . "page/", [])->getStatusCode() !== 403 ||
    $teachingPost($bindingPath . "review/", [
        "version_id" => $publishedDefinitions["items"][0]["version_id"],
        "reason" => "Forbidden",
    ])->getStatusCode() !== 403 ||
    $teachingPost($bindingPath . "commit/", [
        "review_token" => $bindingReview["review_token"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Registration duties exposed section course binding.");
}
$sis->access->staff = true;
$sis->access->staff = false;
$sis->access->grants = ["sis:registration:manage"];
if (
    $app->router
        ->match($get->withUri(new \Laminas\Diactoros\Uri("https://campus.example.invalid" . $courseTextPath)))
        ->getStatusCode() !== 403 ||
    $teachingPost($courseTextPath, [
        "revision" => $courseText["revision"],
        "transcript_title" => "Unprivileged change",
        "description" => null,
    ])->getStatusCode() !== 403 ||
    $teachingPost($publicationPath . "page/", [])->getStatusCode() !== 403 ||
    $teachingPost($publicationPath . "commit/", [
        "review_token" => $publicationReview["review_token"],
    ])->getStatusCode() !== 403
) {
    throw new RuntimeException("Registration duties exposed catalog publication.");
}
$sis->access->staff = true;

session_write_close();
\Codefy\Framework\Http\RequestContext::clear();

// Both ports resolve through the same protected journal.
if (
    !(
        $app->make(\Domain\Shared\EventSourcing\HistoricalEventStore::class) instanceof
        \Infrastructure\EventSourcing\SqlHistoricalEventStore
    ) ||
    !(
        $app->make(\Codefy\Domain\EventSourcing\TransactionalEventStore::class) instanceof
        \Infrastructure\Persistence\PdoTransactionalEventStore
    )
) {
    throw new RuntimeException("Unified event-store provider wiring failed.");
}
