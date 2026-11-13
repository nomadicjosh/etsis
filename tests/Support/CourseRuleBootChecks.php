<?php

declare(strict_types=1);

require_once __DIR__ . "/CatalogWorkflowFixture.php";
$catalog = new CatalogWorkflowFixture();
$sis = $catalog->sis;
$course = \Domain\Shared\ValueObject\EntityId::generate();
$sis->registration->addCourse(
    new \Domain\Catalog\Course($course, "ENG.101", "Fictional English", 3000),
);
$publisher = $catalog->publications();
$review = $publisher->review($course, "Fictional publication approval");
$version = $publisher->publish($course, $review["review_token"])[
    "operation_id"
];
$app->delegate(
    \Qubus\Expressive\Connection::class,
    static fn() => $sis->connection,
);
$app->delegate(
    \Qubus\Expressive\Database::class,
    static fn() => $sis->connection->queryBuilder(),
);
$app->delegate(
    \Infrastructure\Persistence\SqlRecords::class,
    static fn() => $sis->sql,
);
$app->delegate(
    \Domain\Shared\Persistence\UnitOfWork::class,
    static fn() => $sis->transactions,
);
$app->delegate(
    \Domain\Shared\Security\StudentAccess::class,
    static fn() => $sis->access,
);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $sis->clock);
$app->delegate(
    \Infrastructure\EventSourcing\ProtectedEventCodec::class,
    static fn() => new \Infrastructure\EventSourcing\ProtectedEventCodec(
        EventStoreFixture::config($sis->connection->queryBuilder()),
    ),
);
$app->booting(static function () use ($app): void {
    $app->configContainer->getConfigKey("vihzhuo");
    $app->configContainer->setConfigKey("vihzhuo", [
        "router" => ["use_router" => false],
    ]);
});
$kernel->bootstrap();
$app->router->baseMiddleware = [
    "sis.privacy",
    "sis.json",
    "csrf.protection",
    "bind.request",
    "php.debugbar",
];
$send = static function (
    string $path,
    array $body = [],
    bool $csrf = true,
    string $method = "POST",
) use ($app): \Psr\Http\Message\ResponseInterface {
    $request = new \Qubus\Http\ServerRequest([], [], $path, $method)
        ->withHeader("Content-Type", "application/x-www-form-urlencoded")
        ->withHeader("Accept", "application/json")
        ->withHeader(
            "X-CSRF-Token",
            $csrf ? "fictionalcsrftoken" : "invalidtoken",
        )
        ->withAttribute(
            \Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE,
            "fictionalcsrftoken",
        )
        ->withBody(new \Laminas\Diactoros\Stream("php://temp", "w+"));
    $request
        ->getBody()
        ->write(
            http_build_query([
                "_workspace_json" => json_encode(
                    (object) $body,
                    JSON_THROW_ON_ERROR,
                ),
            ]),
        );
    $request->getBody()->rewind();
    $response = $app->router->match($request);
    if (
        !str_contains($response->getHeaderLine("Cache-Control"), "no-store") ||
        !str_contains(
            $response->getHeaderLine("Content-Type"),
            "application/json",
        ) ||
        str_contains((string) $response->getBody(), "phpdebugbar")
    ) {
        throw new RuntimeException(
            "Course rule routes lost private JSON/debug boundaries.",
        );
    }
    return $response;
};
$tree = [
    "condition" => "AND",
    "rules" => [
        [
            "id" => "course_level_number",
            "operator" => "greater",
            "value" => 200,
        ],
    ],
];
$body = [
    "code" => "DA.UPPER",
    "title" => "Upper-level course rule",
    "tree" => $tree,
    "rule_id" => null,
    "expected_revision" => null,
];
if (
    $send("/sis/course-rules/save/", $body, false)->getStatusCode() !== 403 ||
    $sis->sql->rows("sis_course_rules") !== []
) {
    throw new RuntimeException("Course rule save bypassed CSRF.");
}
$response = $send("/sis/course-rules/save/", $body);
if ($response->getStatusCode() !== 201) {
    throw new RuntimeException("Course rule provider/controller save failed.");
}
$saved = json_decode(
    (string) $response->getBody(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
if (
    $send(
        "/sis/course-rules/" . $saved["id"] . "/",
        method: "GET",
    )->getStatusCode() !== 200 ||
    $send("/sis/course-rules/page/")->getStatusCode() !== 200 ||
    $send(
        "/sis/course-rules/" . $saved["rule_id"] . "/revisions/",
    )->getStatusCode() !== 200
) {
    throw new RuntimeException("Course rule private read routes failed.");
}
$preview = [
    "tree" => $tree,
    "publications" => [
        ["course_id" => $course->value, "version_id" => $version],
    ],
];
if (
    $send("/sis/course-rules/preview/", $preview, false)->getStatusCode() !==
    403
) {
    throw new RuntimeException("Course preview bypassed CSRF.");
}
$result = $send("/sis/course-rules/preview/", $preview);
if (
    $result->getStatusCode() !== 200 ||
    json_decode((string) $result->getBody(), true)["items"][0]["outcome"] !==
        "unknown"
) {
    throw new RuntimeException(
        "Course preview failed to keep unavailable retained levels unknown.",
    );
}
$sis->access->staff = false;
$sis->access->grants = ["sis:rules:manage"];
if (
    $send(
        "/sis/course-rules/" . $saved["id"] . "/",
        method: "GET",
    )->getStatusCode() !== 403 ||
    $send("/sis/course-rules/save/", $body)->getStatusCode() !== 403 ||
    $send("/sis/course-rules/preview/", $preview)->getStatusCode() !== 403
) {
    throw new RuntimeException(
        "Course rule routes bypassed catalog authority.",
    );
}
