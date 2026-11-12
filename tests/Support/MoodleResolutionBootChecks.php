<?php

declare(strict_types=1);

// Included only by the isolated fixture process; no application database, network or credentials.
require_once __DIR__ . '/SisFixture.php';
$sis = new SisFixture();
$app->delegate(\Qubus\Expressive\Connection::class, static fn() => $sis->connection);
$app->delegate(\Qubus\Expressive\Database::class, static fn() => $sis->connection->queryBuilder());
$app->delegate(\Infrastructure\Persistence\SqlRecords::class, static fn() => $sis->sql);
$app->delegate(\Domain\Shared\Persistence\UnitOfWork::class, static fn() => $sis->transactions);
$app->delegate(\Domain\Shared\Security\StudentAccess::class, static fn() => $sis->access);
$app->delegate(\Domain\Shared\Service\Clock::class, static fn() => $sis->clock);
$app->delegate(\Domain\Communications\Service\MessageCipher::class, static fn() => $sis->dropCipher);
$app->booting(static function () use ($app): void {
    $app->configContainer->getConfigKey('vihzhuo');
    $app->configContainer->setConfigKey('vihzhuo', ['router' => ['use_router' => false]]);
});
$kernel->bootstrap();
$app->configContainer->setConfigKey('moodle', ['enabled' => false]);
$app->router->baseMiddleware = ['sis.privacy', 'sis.json', 'csrf.protection', 'bind.request', 'php.debugbar'];
$id = \Domain\Shared\ValueObject\EntityId::generate();
$person = new \Domain\Person\Person($id, null, 'Alex', 'Example', new \Domain\Person\ValueObject\InstitutionalId('FICTIONAL-01'));
$sis->admissions->addPerson($person);
$binding = new \Domain\Lms\ValueObject\MoodleBinding(
    \Domain\Shared\ValueObject\EntityId::generate(), str_repeat('a', 64), str_repeat('b', 64),
    $sis->access->actor, '2026-08-10T17:00:00Z',
);
new \Infrastructure\Lms\SqlMoodleBindingRepository($sis->sql)->add($binding, 'fictional-encrypted');
$operation = new \Domain\Lms\ValueObject\MoodleAccountRequest(
    \Domain\Shared\ValueObject\EntityId::generate(), $binding->id, $id, $sis->access->actor,
    '2026-08-10T17:00:00Z', str_repeat('a', 64),
);
$requests = new \Infrastructure\Lms\SqlMoodleAccountRequestRepository($sis->sql);
$requests->add($operation, 'fictional-original-approval');
$requests->enqueue($operation->id, '2026-08-10T17:00:00Z');
$sis->sql->update('sis_moodle_provisioning_outbox', [
    'state' => 'review_required', 'issue_code' => 'email_delivery',
], ['request_id' => $operation->id->value]);
new \Infrastructure\Lms\SqlMoodlePersonLinkRepository($sis->sql)->add(
    new \Domain\Lms\ValueObject\MoodlePersonLink(
        \Domain\Shared\ValueObject\EntityId::generate(), $binding->id, $id,
        new \Domain\Lms\ValueObject\MoodleExternalId('123'), $sis->access->actor, '2026-08-10T17:00:00Z',
    ), 'fictional-link-review',
);
$send = static function (string $action, array $data, bool $csrf = true) use ($app, $id): \Psr\Http\Message\ResponseInterface {
    $request = new \Qubus\Http\ServerRequest([], [], '/sis/people/' . $id->value . '/moodle/account-request/resolution/' . $action . '/', 'POST')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withHeader('Accept', 'application/json')
        ->withHeader('X-CSRF-Token', $csrf ? 'fictionalcsrftoken' : 'invalidtoken')
        ->withAttribute(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE, 'fictionalcsrftoken')
        ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
    $request->getBody()->write(http_build_query(['_workspace_json' => json_encode($data, JSON_THROW_ON_ERROR)]));
    $request->getBody()->rewind();
    $response = $app->router->match($request);
    if (!str_contains($response->getHeaderLine('Cache-Control'), 'no-store') ||
        !str_contains($response->getHeaderLine('Content-Type'), 'application/json') ||
        str_contains((string) $response->getBody(), 'phpdebugbar')) {
        throw new RuntimeException('Moodle resolution privacy boundary failed.');
    }
    return $response;
};
$response = $send('review', ['reason' => 'Verified through fictional support.']);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException('Moodle access review provider/route failed with status ' . $response->getStatusCode());
}
$review = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($send('confirm', ['review_token' => $review['review_token']], false)->getStatusCode() !== 403 ||
    $requests->processing($operation->id)['state'] !== 'review_required') {
    throw new RuntimeException('Moodle access resolution bypassed CSRF.');
}
$sis->access->staff = false;
if ($send('confirm', ['review_token' => $review['review_token']])->getStatusCode() !== 403) {
    throw new RuntimeException('Moodle access resolution bypassed current permissions.');
}
$sis->access->staff = true;
if ($send('confirm', ['review_token' => $review['review_token']])->getStatusCode() !== 200 ||
    $requests->processing($operation->id)['state'] !== 'completed') {
    throw new RuntimeException('Moodle access resolution did not complete through its real route.');
}
