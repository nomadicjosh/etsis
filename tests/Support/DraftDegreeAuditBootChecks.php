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
    \Domain\Communications\Service\MessageCipher::class,
    static fn() => $f->f->catalog->fixture->cipher,
);
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

// Specialization requirements use the same authoring, publication and trial services under a distinct scope.
$sis->access->staff = true;
$sis->access->grants = [];
$specialization = $app->make(\Domain\Catalog\Service\ProgramSpecializationService::class)->save(
    $sis->program, null, null, 'DATA', 'Fictional retained data path', null, true,
);
$specializationBase = '/sis/programs/' . $sis->program->value . '/specializations/' .
    $specialization['id'] . '/degree-audits/';
$openData = ['academic_year_id' => $f->f->year->value];
if ($send($specializationBase . 'open/', $openData, false)->getStatusCode() !== 403) {
    throw new RuntimeException('Specialization authoring bypassed browser CSRF.');
}
$openedResponse = $send($specializationBase . 'open/', $openData);
$opened = json_decode((string) $openedResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($openedResponse->getStatusCode() !== 200 || ($opened['specialization_id'] ?? null) !== $specialization['id']) {
    throw new RuntimeException('Specialization scope did not resolve through the actual repository/provider.');
}
$specializationPath = '/sis/degree-audits/' . $opened['id'] . '/';
if ($send($specializationPath . 'save/', ['revision' => 0, 'definition' => $f->f->definition])->getStatusCode() !== 200) {
    throw new RuntimeException('Specialization requirements did not use the shared draft save.');
}
$programs = $app->make(\Domain\Catalog\Service\ProgramPublicationService::class);
$programReview = $programs->review($sis->program, $f->f->year, 'Fictional offered program approval.');
$programs->publish($sis->program, $programReview['review_token']);
$reviewResponse = $send($specializationPath . 'review/', ['reason' => 'Fictional protected specialization publication.']);
$specializationReview = json_decode((string) $reviewResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($reviewResponse->getStatusCode() !== 200 || ($specializationReview['specialization']['code'] ?? null) !== 'DATA') {
    throw new RuntimeException('Specialization review lost its retained program context.');
}
$confirmedResponse = $send($specializationPath . 'publish/', ['review_token' => $specializationReview['review_token']]);
$confirmed = json_decode((string) $confirmedResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($confirmedResponse->getStatusCode() !== 200 ||
    $send($specializationPath . 'publish/', ['review_token' => $specializationReview['review_token']])->getStatusCode() !== 200) {
    throw new RuntimeException('Specialization publication or exact retry failed.');
}
$historyResponse = $send($specializationPath . 'versions/');
if ($historyResponse->getStatusCode() !== 200 ||
    str_contains((string) $historyResponse->getBody(), 'Fictional protected specialization publication.')) {
    throw new RuntimeException('Specialization history exposed protected publication context.');
}
$specializationTest = $send($specializationPath . 'test/', [
    'source' => 'publication', 'version_id' => $confirmed['operation_id'],
    'student_id' => $f->student->value, 'assignment_id' => $f->assignment->value,
]);
$specializationResult = json_decode((string) $specializationTest->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($specializationTest->getStatusCode() !== 200 ||
    ($specializationResult['context']['specialization']['id'] ?? null) !== $specialization['id']) {
    throw new RuntimeException('Specialization runtime testing lost its component scope.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:student:read'];
if ($send($specializationBase . 'page/')->getStatusCode() !== 403 ||
    $send($specializationBase . 'open/', $openData)->getStatusCode() !== 403 ||
    $send($specializationPath, [], method: 'GET')->getStatusCode() !== 403) {
    throw new RuntimeException('Student-read authority exposed specialization authoring.');
}
$sis->access->staff = true;
$sis->access->grants = [];
