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
$receiptReader = new class ($sis) implements \Domain\Lms\Service\MoodleAccountReceiptReader {
    public int $calls = 0;
    public bool $recorded = false;
    public function __construct(private SisFixture $sis) {}
    public function receipt(\Domain\Lms\ValueObject\MoodleBinding $binding, \Domain\Shared\ValueObject\EntityId $operation, string $requestHash): \Domain\Lms\ValueObject\MoodleAccountReceipt
    {
        if ($this->sis->connection->pdo->inTransaction()) throw new RuntimeException('Receipt read held a write lock.');
        $this->calls++;
        return new \Domain\Lms\ValueObject\MoodleAccountReceipt($this->recorded ? 'recorded' : 'not_observed',
            $this->recorded ? new \Domain\Lms\ValueObject\MoodleProvisionedAccount(new \Domain\Lms\ValueObject\MoodleExternalId('500'), 'sso_ready') : null);
    }
};
$app->define(\Domain\Lms\Service\MoodleAccountReconciliationService::class, [':remote' => $receiptReader]);
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
$send = static function (string $action, array $data, bool $csrf = true) use ($app, &$id): \Psr\Http\Message\ResponseInterface {
    $suffix = $action === 'receipt' || str_starts_with($action, 'recovery/') ? $action . '/' : 'resolution/' . $action . '/';
    $request = new \Qubus\Http\ServerRequest([], [], '/sis/people/' . $id->value . '/moodle/account-request/' . $suffix, 'POST')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withHeader('Accept', 'application/json')
        ->withHeader('X-CSRF-Token', $csrf ? 'fictionalcsrftoken' : 'invalidtoken')
        ->withAttribute(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE, 'fictionalcsrftoken')
        ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
    $request->getBody()->write(http_build_query(['_workspace_json' => json_encode((object) $data, JSON_THROW_ON_ERROR)]));
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

$app->configContainer->setConfigKey('moodle', ['enabled' => true]);
$id = \Domain\Shared\ValueObject\EntityId::generate();
$person = new \Domain\Person\Person($id, null, 'Alex', 'Example', new \Domain\Person\ValueObject\InstitutionalId('FICTIONAL-02'));
$sis->admissions->addPerson($person);
$operationId = \Domain\Shared\ValueObject\EntityId::generate();
$approval = [
    'kind' => 'moodle-account-request', 'id' => $operationId->value, 'actor_id' => $sis->access->actor->value,
    'person_id' => $id->value, 'binding_id' => $binding->id->value,
    'binding_hash' => hash('sha256', json_encode($binding->describe(), JSON_THROW_ON_ERROR)),
    'person_revision' => $app->make(\Domain\Lms\Service\MoodleAccountProfile::class)->revision($person),
    'firstname' => 'Alex', 'lastname' => 'Example', 'username' => 'alex_example', 'email' => 'fictional@example.invalid',
    'sign_in' => 'sso', 'reason' => 'Fictional review.', 'policy_hash' => str_repeat('a', 64), 'expires_at' => 1790000000,
];
$encoded = json_encode($approval, JSON_THROW_ON_ERROR);
$requests->add(new \Domain\Lms\ValueObject\MoodleAccountRequest(
    $operationId, $binding->id, $id, $sis->access->actor, '2026-08-10T17:00:00Z', hash('sha256', $encoded),
), $sis->dropCipher->seal($encoded));
$requests->enqueue($operationId, '2026-08-10T17:00:00Z');
$sis->sql->update('sis_moodle_provisioning_outbox', ['state' => 'processing', 'issue_code' => 'connection_uncertain'], ['request_id' => $operationId->value]);
if ($send('receipt', [], false)->getStatusCode() !== 403 || $receiptReader->calls !== 0) {
    throw new RuntimeException('Original receipt check bypassed CSRF.');
}
$sis->access->staff = false;
if ($send('receipt', [])->getStatusCode() !== 403 || $receiptReader->calls !== 0) {
    throw new RuntimeException('Original receipt check bypassed current permissions.');
}
$sis->access->staff = true;
$response = $send('receipt', []);
$data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 200 || ($data['status'] ?? null) !== 'not_observed' ||
    $receiptReader->calls !== 1 || $requests->processing($operationId)['state'] !== 'processing') {
    throw new RuntimeException('Original receipt route did not return a private read-only observation.');
}

$sis->sql->update('sis_moodle_provisioning_outbox', ['state' => 'review_required', 'issue_code' => 'attempt_limit', 'attempts' => 6, 'lease_until' => 0], ['request_id' => $operationId->value]);
$receiptReader->recorded = true;
if ($send('recovery/review', ['reason' => 'Fictional verification.'], false)->getStatusCode() !== 403 || $receiptReader->calls !== 1) {
    throw new RuntimeException('Recovery review bypassed CSRF.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:person:manage', 'sis:moodle:create-person'];
if ($send('recovery/review', ['reason' => 'Fictional verification.'])->getStatusCode() !== 403 || $receiptReader->calls !== 1) {
    throw new RuntimeException('Recovery review bypassed separate link authority.');
}
$sis->access->staff = true;
$response = $send('recovery/review', ['reason' => 'Fictional independent identity verification.']);
$review = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
if ($response->getStatusCode() !== 200 || !is_string($review['review_token'] ?? null) || $receiptReader->calls !== 2) {
    throw new RuntimeException('Recovery review failed through actual provider bindings.');
}
if ($send('recovery/confirm', ['review_token' => $review['review_token']], false)->getStatusCode() !== 403 || $receiptReader->calls !== 2) {
    throw new RuntimeException('Recovery confirmation bypassed CSRF.');
}
$sis->access->staff = false;
if ($send('recovery/confirm', ['review_token' => $review['review_token']])->getStatusCode() !== 403 || $receiptReader->calls !== 2) {
    throw new RuntimeException('Recovery confirmation bypassed current link authority.');
}
$sis->access->staff = true;
$response = $send('recovery/confirm', ['review_token' => $review['review_token']]);
if ($response->getStatusCode() !== 200 || $receiptReader->calls !== 3 || $requests->processing($operationId)['state'] !== 'completed' || count($sis->sql->rows('sis_moodle_account_recoveries')) !== 1) {
    throw new RuntimeException('Recovery confirmation did not retain an atomic link and receipt.');
}
$app->configContainer->setConfigKey('moodle', ['enabled' => false]);
$sis->clock->time = $sis->clock->time->modify('+21 minutes');
if ($send('recovery/confirm', ['review_token' => $review['review_token']])->getStatusCode() !== 200 || $receiptReader->calls !== 3) {
    throw new RuntimeException('Repeated recovery confirmation was not local and idempotent.');
}

// Section mapping shares the existing private Moodle boundary and current catalog authority.
$sis->access->staff = true;
$sis->access->grants = [];
$sectionSend = static function (bool $post, bool $csrf = true) use ($app): \Psr\Http\Message\ResponseInterface {
    $path = $post ? '/sis/moodle/sections/' . \Domain\Shared\ValueObject\EntityId::generate()->value . '/link/' : '/sis/moodle/sections/';
    $request = new \Qubus\Http\ServerRequest([], [], $path, $post ? 'POST' : 'GET')
        ->withQueryParams(['search' => 'FICTIONAL'])
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withHeader('Accept', 'application/json')
        ->withHeader('X-CSRF-Token', $csrf ? 'fictionalcsrftoken' : 'invalidtoken')
        ->withAttribute(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE, 'fictionalcsrftoken')
        ->withBody(new \Laminas\Diactoros\Stream('php://temp', 'w+'));
    if ($post) {
        $request->getBody()->write(http_build_query(['_workspace_json' => json_encode(['course' => '81', 'reason' => 'Fictional setup.', 'acknowledged' => false])]));
        $request->getBody()->rewind();
    }
    $response = $app->router->match($request);
    if (!str_contains($response->getHeaderLine('Cache-Control'), 'no-store') ||
        !str_contains($response->getHeaderLine('Content-Type'), 'application/json') ||
        str_contains((string) $response->getBody(), 'phpdebugbar')) {
        throw new RuntimeException('Section mapping lost private JSON boundaries.');
    }
    return $response;
};
if ($sectionSend(false)->getStatusCode() !== 200 || $sectionSend(true, false)->getStatusCode() !== 403 ||
    $sectionSend(true)->getStatusCode() !== 422) {
    throw new RuntimeException('Section mapping failed its directory, CSRF or acknowledgement boundary.');
}
$sis->access->staff = false;
$sis->access->grants = ['sis:moodle:manage'];
if ($sectionSend(false)->getStatusCode() !== 403 || $sectionSend(true)->getStatusCode() !== 403) {
    throw new RuntimeException('Section mapping bypassed current catalog authority.');
}
