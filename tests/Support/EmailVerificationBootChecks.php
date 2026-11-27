<?php

declare(strict_types=1);

use Domain\Shared\ValueObject\EntityId;

// Actual providers, private routes, CSRF and console workers over fictional SQLite only.
$app->delegate(\Domain\User\Repository\UserAggregateRepository::class, static fn () => $emailFixture->users);
$app->delegate(\Domain\Communications\Service\MailTransport::class, static fn () => $emailFixture);
$authConfig = $app->configContainer->getConfigKey('auth', []);
$app->configContainer->setConfigKey('auth', array_replace($authConfig, [
    'application_email_verification_required' => true,
]));
$sisConfig = $app->configContainer->getConfigKey('sis', []);
$app->configContainer->setConfigKey('sis', array_replace($sisConfig, ['mail_enabled' => true]));
$appConfig = $app->configContainer->getConfigKey('app', []);
$app->configContainer->setConfigKey('app', array_replace($appConfig, ['url' => 'https://campus.example.invalid']));
$base = '/portal/api/email-verification/';
// The focused API harness omits the normal csrf.token generator; rendering its field still needs that service.
$app->make(\Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware::class);
$page = $app->router->match($get->withUri(new \Laminas\Diactoros\Uri(
    'https://campus.example.invalid/portal/verify-email/'
)));
if (
    $page->getStatusCode() !== 200 || $page->getHeaderLine('Referrer-Policy') !== 'no-referrer'
    || !str_contains($page->getHeaderLine('Content-Security-Policy'), "frame-ancestors 'none'")
    || !str_contains($page->getHeaderLine('Cache-Control'), 'no-store') || $page->hasHeader('phpdebugbar-id')
) {
    throw new RuntimeException('The private verification page lost its browser boundaries.');
}
$readEmail = static function () use ($app, $get, $base): array {
    $uri = new \Laminas\Diactoros\Uri('https://campus.example.invalid' . $base);
    $response = $app->router->match($get->withUri($uri));
    if (
        $response->getStatusCode() !== 200 || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
        || $response->hasHeader('phpdebugbar-id')
    ) {
        throw new RuntimeException('Email verification lost private transport.');
    }
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};
$status = $readEmail();
if ($status['verified'] || !$status['required'] || str_contains(json_encode($status), 'proof@example.invalid')) {
    throw new RuntimeException('Email status lost its optional policy or minimized owner scope.');
}
$response = $teachingPost($base . 'request/', ['version' => 0, 'user_id' => EntityId::generate()->value]);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException('The private email request did not resolve through real providers.');
}
$token = $emailFixture->token();
$history = EventStoreFixture::historyDigest($sis->sql);
$command = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\EmailVerificationCommand($app)
);
foreach (
    [
    ['--send' => true], ['--prune' => true], ['--send' => true, '--apply' => true, '--limit' => '0'],
    ] as $options
) {
    $expected = isset($options['--apply']) ? 2 : 0;
    if ($command->execute($options) !== $expected || $emailFixture->sent !== []) {
        throw new RuntimeException('Email verification usage/limits caused delivery.');
    }
}
if (
    $command->execute(['--send' => true, '--apply' => true, '--limit' => '1']) !== 0
    || count($emailFixture->sent) !== 1
) {
    throw new RuntimeException('The bounded verification worker did not use its injected fictional transport.');
}
$response = $teachingPost($base . 'confirm/', ['token' => $token]);
if (
    $response->getStatusCode() !== 200 || !$readEmail()['verified']
    || $history !== EventStoreFixture::historyDigest($sis->sql)
) {
    throw new RuntimeException('Email confirmation changed authoritative facts or lost owner proof.');
}
$sis->clock->time = $sis->clock->time->modify('+31 minutes');
$app->delegate(\Domain\Communications\Service\MailTransport::class, static function (): never {
    throw new RuntimeException('Cleanup must not resolve mail.');
});
if (
    $command->execute(['--prune' => true, '--apply' => true, '--limit' => '1']) !== 0
    || $emailFixture->records->get($emailFixture->owner)['token_hash'] !== null
) {
    throw new RuntimeException('Verification cleanup required mail or left expired token material.');
}
if (str_contains($command->getDisplay(), $token) || str_contains($command->getDisplay(), 'proof@example.invalid')) {
    throw new RuntimeException('Verification console output exposed private values.');
}
