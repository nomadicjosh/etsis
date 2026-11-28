<?php

declare(strict_types=1);

$decodeGateway = static function (\Psr\Http\Message\ResponseInterface $response): array {
    if (
        $response->getStatusCode() !== 200 || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Payment setup did not return a private successful response.');
    }
    $text = (string) $response->getBody();
    if (str_contains($text, 'sk_test_') || str_contains($text, 'whsec_')) {
        throw new RuntimeException('Payment setup exposed a fictional credential.');
    }
    return json_decode($text, true, flags: JSON_THROW_ON_ERROR);
};
$gatewayGet = static function (string $path) use ($app, $get): \Psr\Http\Message\ResponseInterface {
    $uri = new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $path);
    parse_str($uri->getQuery(), $query);
    return $app->router->match($get->withUri($uri)->withQueryParams($query));
};
$directory = $decodeGateway($gatewayGet('/sis/payment-setup/directory/connections/'));
if ($directory['items'] !== []) {
    throw new RuntimeException('Payment fixture unexpectedly contains connections.');
}
$decodeGateway($gatewayGet('/sis/payment-setup/assets/?currency=USD'));
$decodeGateway($gatewayGet('/sis/payment-setup/routing/?purpose=account&currency=USD'));
$review = $decodeGateway($teachingPost('/sis/payment-setup/review/connection/', $gatewayFixture->input + [
    'reason' => 'Approve fictional HTTP payment connection.',
]));
$source = ['review_id' => $review['review_id'], 'review_token' => $review['review_token']];
$accepted = $decodeGateway($teachingPost('/sis/payment-setup/confirm/', $source));
if ($decodeGateway($teachingPost('/sis/payment-setup/confirm/', $source)) !== $accepted) {
    throw new RuntimeException('HTTP payment setup did not retain its original receipt.');
}
$decodeGateway($gatewayGet('/sis/payment-setup/connections/' . $accepted['id'] . '/'));
$missingCsrf = $app->router->match($post->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/sis/payment-setup/confirm/'
))->withParsedBody($source));
if ($missingCsrf->getStatusCode() !== 403) {
    throw new RuntimeException('Payment setup omitted CSRF protection.');
}
$abandoned = $decodeGateway($teachingPost('/sis/payment-setup/review/connection/', array_replace(
    $gatewayFixture->input,
    ['id' => $accepted['id'], 'version' => 1, 'secret' => '', 'webhook' => '',
        'reason' => 'Review fictional abandoned credential proposal.']
)));
$command = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\PrunePaymentReviewsCommand($app)
);
$proposal = new \Domain\Shared\ValueObject\EntityId($abandoned['review_id']);
if (
    $command->execute([]) !== 0 || $command->execute(['--apply' => true, '--limit' => '0']) !== 2
    || $gatewayFixture->configuration->records->review($proposal)['sealed_data'] === null
) {
    throw new RuntimeException('Payment review cleanup changed data without a valid explicit action.');
}
$sis->clock->time = $sis->clock->time->modify('+20 minutes');
if (
    $command->execute(['--apply' => true, '--limit' => '1']) !== 0
    || $gatewayFixture->configuration->records->review($proposal)['sealed_data'] !== null
    || str_contains($command->getDisplay(), 'sk_test_') || str_contains($command->getDisplay(), 'whsec_')
) {
    throw new RuntimeException('Payment review cleanup did not retain its private bounded console contract.');
}
if (
    !\Application\Console\MaintenanceCommandPolicy::permits(new \Symfony\Component\Console\Input\StringInput(
        'sis:payments:prune-reviews --apply --limit=1'
    ))
) {
    throw new RuntimeException('Disposable payment proposal cleanup was blocked during maintenance.');
}
$routeReview = $decodeGateway($teachingPost('/sis/payment-setup/review/route/', [
    'purpose' => 'account', 'currency' => 'USD', 'version' => 0, 'connection_id' => $accepted['id'],
    'reason' => 'Approve fictional account payment routing.',
]));
$decodeGateway($teachingPost('/sis/payment-setup/confirm/', [
    'review_id' => $routeReview['review_id'], 'review_token' => $routeReview['review_token'],
]));
[$paymentAccount, $paymentCheckout] = $gatewayFixture->charged();
$paymentId = \Domain\Shared\ValueObject\EntityId::generate();
$paymentCheckout->checkout($paymentId, $paymentAccount);
$activityPath = '/sis/payment-activity/account/' . $paymentAccount->value . '/';
$decodeGateway($gatewayGet($activityPath));
$decodeGateway($gatewayGet('/portal/api/payment-activity/account/' . $paymentAccount->value . '/'));
$decodeGateway($teachingPost('/portal/api/payment-activity/' . $paymentId->value . '/retry/', []));
$process = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\ProcessPaymentsCommand($app)
);
if (
    $process->execute([]) !== 0 || $process->execute(['--apply' => true, '--limit' => '4']) !== 2
    || $gatewayFixture->campus->payments->find($paymentId)['status'] !== 'pending'
) {
    throw new RuntimeException('Payment processing ran without an explicit bounded action.');
}
if (
    $process->execute(['--apply' => true, '--limit' => '1']) !== 0
    || $gatewayFixture->campus->payments->find($paymentId)['status'] !== 'paid'
) {
    throw new RuntimeException('The actual payment worker did not finish its fictional authorization.');
}
$ownerBefore = $sis->access->actor;
$sis->access->actor = $sis->user();
$sis->student();
if ($gatewayGet('/portal/api/payment-activity/account/' . $paymentAccount->value . '/')->getStatusCode() !== 403) {
    throw new RuntimeException('A staff duty bypassed portal payment ownership.');
}
$sis->access->actor = $ownerBefore;
if (
    \Application\Console\MaintenanceCommandPolicy::permits(new \Symfony\Component\Console\Input\StringInput(
        'sis:payments:process --apply --limit=1'
    ))
) {
    throw new RuntimeException('New payment work bypassed maintenance admission.');
}
$permission = $sis->sql->one('sis_access_permissions', ['name' => \Domain\Payments\Service\GatewaySetup::MANAGE]);
$gatewayFixture->roles->assign(
    \Domain\Access\Enum\GrantKind::UserPermission,
    $sis->access->actor,
    new \Domain\Shared\ValueObject\EntityId($permission['id']),
    false
);
if (
    $gatewayGet('/sis/payment-setup/directory/connections/')->getStatusCode() !== 403
    || $teachingPost('/sis/payment-setup/confirm/', $source)->getStatusCode() !== 403
) {
    throw new RuntimeException('Payment setup accepted a missing current duty.');
}
if (count(array_filter($gatewayFixture->client->calls, static fn(array $c): bool => $c[0] === 'probe')) !== 1) {
    throw new RuntimeException('Payment HTTP fixture made unexpected provider calls.');
}
