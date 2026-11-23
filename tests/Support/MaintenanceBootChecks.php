<?php

declare(strict_types=1);

// Included by the isolated console harness: fictional keys/accounts and SQLite memory only.
$maintenanceKernel = $app->make(\Application\Console\Kernel::class);
$mode = \Application\Provider\MaintenanceServiceProvider::mode($app);
$runMaintenance = static function (array $arguments, int $expected = 0) use ($maintenanceKernel): string {
    $output = new \Symfony\Component\Console\Output\BufferedOutput();
    $status = $maintenanceKernel->handle(
        new \Symfony\Component\Console\Input\ArrayInput(['command' => 'sis:maintenance'] + $arguments),
        $output,
    );
    if ($status !== $expected) {
        throw new RuntimeException('Maintenance controls returned an unexpected status.');
    }
    return $output->fetch();
};
$runMaintenance([]);
$runMaintenance(['action' => 'down', '--retry-after' => '90']);
$runMaintenance(['action' => 'status']);
$runMaintenance(['action' => 'down', '--retry-after' => '0'], 2);
if (!$mode->status()->paused() || $mode->status()->retryAfter !== 90 || $app->hasBeenBootstrapped()) {
    throw new RuntimeException('Operator maintenance controls booted unrelated providers or lost the accepted state.');
}
foreach (
    ['sis:communications:send --apply', 'sis:accounts:send-setup --apply',
    'sis:admissions:documents --apply', 'sis:reports:export --apply', 'sis:reports:prune --apply',
    'sis:moodle:sync', 'sis:moodle:provision', 'queue:run', 'schedule:run', 'sis:comm:send --apply'] as $command
) {
    if (
        $maintenanceKernel->handle(
            new \Symfony\Component\Console\Input\StringInput($command),
            new \Symfony\Component\Console\Output\BufferedOutput(),
        ) !== 75 || $app->hasBeenBootstrapped()
    ) {
        throw new RuntimeException('An application worker could start during maintenance.');
    }
}
if ($maintenanceKernel->call('sis:moodle:sync') !== 75 || $app->hasBeenBootstrapped()) {
    throw new RuntimeException('Programmatic console dispatch bypassed maintenance.');
}
$runMaintenance(['action' => 'up']);
if ($mode->status()->paused()) {
    throw new RuntimeException('The explicit reopen command did not restore admission.');
}

// Exercise ordinary sign-in/revocation first, then the same actual routes with maintenance enabled.
$kernel = $maintenanceKernel;
$app->booted(static function () use ($app, &$sis): void {
    $app->router->post(
        '/sis/maintenance-login-race/',
        static function () use (&$sis): \Psr\Http\Message\ResponseInterface {
            $sis->sql->execute('DELETE FROM sis_access_user_roles WHERE user_id = ?', [$sis->access->actor->value]);
            return new \Laminas\Diactoros\Response\JsonResponse(['fixture' => true]);
        },
    )->middleware(['user.authenticate', 'user.session']);
});
require __DIR__ . '/LoginSessionBootChecks.php';
$app->configContainer->setConfigKey('throttle', ['max_attempts' => 100, 'ttl' => 600]);
$app->router->baseMiddleware = ['maintenance', ...$app->router->baseMiddleware];
$mode->down(90);
$login = $app->router->match($request('/login/'));
if ($login->getStatusCode() !== 200 || !str_contains((string) $login->getBody(), 'Only super administrators')) {
    throw new RuntimeException('The maintenance sign-in page was unavailable or lacked its restriction notice.');
}
$deniedCredentials = ['email' => 'other@example.invalid', 'password' => $password];
$denied = $app->router->match($request('/auth/', 'POST', body: $deniedCredentials));
$wrong = $app->router->match($request('/auth/', 'POST', body: [
    'email' => 'other@example.invalid', 'password' => 'Fictional wrong password',
]));
if (
    $cookieFrom($denied) !== null || $cookieFrom($wrong) !== null
    || $denied->getStatusCode() !== 302 || $wrong->getHeaderLine('Location') !== $denied->getHeaderLine('Location')
) {
    throw new RuntimeException('Maintenance granted a non-super login or disclosed credential success.');
}
foreach (['/portal/session-probe/', '/sis/session-probe/', '/admin/', '/register/'] as $path) {
    $response = $app->router->match($request(
        $path,
        $path === '/sis/session-probe/' ? 'POST' : 'GET',
        $switched,
    ));
    if ($response->getStatusCode() !== 503 || !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')) {
        throw new RuntimeException('An existing non-super session bypassed maintenance.');
    }
}
$missingCsrf = $app->router->match($request('/auth/', 'POST', body: $deniedCredentials, csrf: false));
if ($missingCsrf->getStatusCode() !== 403 || $cookieFrom($missingCsrf) !== null) {
    throw new RuntimeException('Maintenance authentication bypassed browser CSRF protection.');
}

$superRole = $sis->sql->one('sis_access_roles', ['name' => 'super', 'is_system' => 1]);
$grant = static function () use ($sis, $superRole): void {
    $sis->sql->insert('sis_access_user_roles', [
        'id' => \Domain\Shared\ValueObject\EntityId::generate()->value,
        'user_id' => $sis->access->actor->value, 'role_id' => $superRole['id'],
    ]);
};
$grant();
$superLogin = $app->router->match($request('/auth/', 'POST', body: $credentials));
$superCookie = $cookieFrom($superLogin);
if ($superCookie === null || $superLogin->getHeaderLine('Location') !== '/admin/') {
    throw new RuntimeException('A current super administrator could not sign in during maintenance.');
}
foreach (['/portal/session-probe/', '/sis/session-probe/', '/admin/'] as $path) {
    $response = $app->router->match($request($path, $path === '/sis/session-probe/' ? 'POST' : 'GET', $superCookie));
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('A current super administrator could not use a protected maintenance screen.');
    }
}
$sis->sql->execute('DELETE FROM sis_access_user_roles WHERE user_id = ?', [$sis->access->actor->value]);
$sis->sql->update('users', ['role' => 'super'], ['user_id' => $sis->access->actor->value]);
if ($app->router->match($request('/admin/', cookie: $superCookie))->getStatusCode() !== 503) {
    throw new RuntimeException('A revoked protected role or stale legacy role bypassed maintenance.');
}
$revokedLogin = $app->router->match($request('/auth/', 'POST', body: $credentials));
if ($cookieFrom($revokedLogin) !== null) {
    throw new RuntimeException('A revoked super assignment could still establish a maintenance session.');
}
$grant();
$logout = $app->router->match($request('/logout/', 'POST', $superCookie));
if (
    $logout->getStatusCode() !== 302
    || $app->router->match($request('/admin/', cookie: $superCookie))->getStatusCode() !== 503
) {
    throw new RuntimeException('A revoked sign-in cookie bypassed maintenance.');
}
$raceLogin = $app->router->match($request('/auth/', 'POST', body: $credentials));
$raceCookie = $cookieFrom($raceLogin);
if ($raceCookie === null) {
    throw new RuntimeException('The maintenance race fixture could not establish its initial super session.');
}
$changedAuthority = $app->router->match($request('/sis/maintenance-login-race/', 'POST', $raceCookie, $credentials));
if ($changedAuthority->getStatusCode() !== 503 || $cookieFrom($changedAuthority) !== null) {
    throw new RuntimeException('Authority loss while creating a sign-in returned a new maintenance cookie.');
}
$grant();
$sis->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $sis->access->actor->value]);
$disabledLogin = $app->router->match($request('/auth/', 'POST', body: $credentials));
if ($cookieFrom($disabledLogin) !== null) {
    throw new RuntimeException('A disabled super account could sign in during maintenance.');
}

$responder = new \Application\Http\Response\MaintenanceResponse($mode);
$json = $responder->respond('POST', '/sis/students/private/', '*/*');
$head = $responder->respond('HEAD', '/portal/', 'text/html');
$html = $responder->respond('GET', '/portal/', 'text/html');
if (
    $json->getStatusCode() !== 503 || $json->getHeaderLine('Retry-After') !== '90'
    || $head->getStatusCode() !== 503 || (string) $head->getBody() !== ''
    || !str_contains($html->getHeaderLine('Content-Security-Policy'), "default-src 'none'")
    || !str_contains((string) $html->getBody(), 'Super administrator sign-in')
    || str_contains((string) $html->getBody(), 'private/')
) {
    throw new RuntimeException('Maintenance responses lost their private response or authentication boundary.');
}
$htmlSource = (string) $html->getBody();
preg_match('~<style>(.*?)</style>~s', $htmlSource, $style);
if (!str_contains($html->getHeaderLine('Content-Security-Policy'), base64_encode(hash('sha256', $style[1], true)))) {
    throw new RuntimeException('The maintenance response did not authorize exactly its own inline style.');
}
$runMaintenance(['action' => 'up']);
if ($responder->respond('GET', '/portal/', '') !== null) {
    throw new RuntimeException('Reopening left maintenance responses in the read path.');
}
