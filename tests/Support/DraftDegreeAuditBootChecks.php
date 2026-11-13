<?php

declare(strict_types=1);

require_once __DIR__ . '/StudentRequirementsFixture.php';
$f = new StudentRequirementsFixture();
$sis = $f->f->sis;
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
            "Draft audit routes lost private JSON/debug boundaries.",
        );
    }
    return $response;
};

$digest = static function () use ($sis): string {
    $rows = [];
    foreach (['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_student_requirements',
        'sis_degree_audit_templates', 'sis_course_groups', 'sis_audit'] as $table) {
        $rows[$table] = $sis->sql->rows($table);
    }
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
};
$before = $digest();
$path = '/sis/degree-audits/' . $f->f->degree->value . '/test/';
$body = ['source' => 'draft', 'definition' => $f->f->definition, 'student_id' => $f->student->value,
    'assignment_id' => $f->assignment->value, 'use_group_drafts' => true];
if ($send($path, $body, false)->getStatusCode() !== 403) {
    throw new RuntimeException('A runtime audit bypassed browser CSRF.');
}
$response = $send($path, $body);
$result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 200 || ($result['mode'] ?? null) !== 'runtime_test' ||
    ($result['status'] ?? null) !== 'review') {
    throw new RuntimeException('Runtime draft testing did not resolve through the actual providers.');
}
$publishedBody = ['source' => 'publication', 'version_id' => $f->version->value,
    'student_id' => $f->student->value, 'assignment_id' => $f->assignment->value];
if ($send($path, $publishedBody, false)->getStatusCode() !== 403) {
    throw new RuntimeException('A published runtime test bypassed CSRF.');
}
$response = $send($path, $publishedBody);
$result = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 200 || ($result['context']['source'] ?? null) !== 'publication' ||
    ($result['context']['requirements_version_id'] ?? null) !== $f->version->value) {
    throw new RuntimeException('Published runtime testing did not retain its selected version.');
}
foreach ([['definition' => $f->f->definition], ['use_group_drafts' => true], ['use_group_drafts' => false]] as $override) {
    if ($send($path, $publishedBody + $override)->getStatusCode() !== 422) {
        throw new RuntimeException('A published runtime test accepted a draft override.');
    }
}
$sis->access->staff = false;
foreach ([['sis:catalog:manage'], ['sis:student:read']] as $grants) {
    $sis->access->grants = $grants;
    if ($send($path, $body)->getStatusCode() !== 403 || $send($path, $publishedBody)->getStatusCode() !== 403) {
        throw new RuntimeException('Runtime audit lost its dual office permission boundary.');
    }
}
if ($before !== $digest()) {
    throw new RuntimeException('Runtime audit changed a draft, student selection or event history.');
}
