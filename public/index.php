<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Codefy\Framework\Contracts\Http\Kernel;

use function Codefy\Framework\Helpers\get_fresh_bootstrap;

$app = get_fresh_bootstrap();

// Pause admission before provider boot, session writes, body parsing or the special webhook entry point.
$maintenanceMode = \Application\Provider\MaintenanceServiceProvider::mode($app);
$maintenancePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$maintenanceMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($maintenanceMode->status()->paused()) {
    \Application\Provider\MaintenanceServiceProvider::localization($app);
    $maintenanceAuthentication = \Application\Http\Security\MaintenanceRoutes::authentication(
        $maintenanceMethod,
        $maintenancePath,
        $app->configContainer->string('auth.login_route', 'login'),
    );
    $maintenanceCandidate = \Application\Http\Security\MaintenanceRoutes::browserCandidate(
        $maintenancePath,
        $_COOKIE[$app->configContainer->string('auth.cookie_name', 'USERSESSID')] ?? null,
    );
    $maintenanceAllowed = $maintenanceAuthentication;
    if (!$maintenanceAuthentication && $maintenanceCandidate) {
        try {
            // Verify an actual live super session before matching even an unknown URL or booting page providers.
            $app->bootstrapWith([\Codefy\Framework\Bootstrap\RegisterProviders::class]);
            $maintenanceRequest = new \Qubus\Http\ServerRequest([], [], $maintenancePath, $maintenanceMethod)
                ->withCookieParams($_COOKIE);
            $maintenanceAllowed = $app->make(\Application\Http\Security\MaintenanceAccess::class)
                ->permitsRequest($maintenanceRequest);
            if ($maintenanceAllowed) {
                $app->bootstrapWith([\Codefy\Framework\Bootstrap\BootProviders::class]);
            }
        } catch (\Throwable) {
            $maintenanceAllowed = false;
        }
    }
    if (!$maintenanceAllowed) {
        $maintenance = new \Application\Http\Response\MaintenanceResponse($maintenanceMode)->respond(
            $maintenanceMethod,
            $maintenancePath,
            $_SERVER['HTTP_ACCEPT'] ?? '',
        );
        if ($maintenance !== null) {
            new \Laminas\HttpHandlerRunner\Emitter\SapiEmitter()->emit($maintenance);
            exit;
        }
    }
}

// Verify provider signatures against the untouched body before the router's legacy input parser.
// Browser mutations continue through the normal session, firewall and CSRF middleware below.
if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) === '/portal/payments/webhook/') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Cache-Control: private, no-store');
        exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    try {
        // Register persistence and gateway ports without booting routes or constructing the input parser.
        $app->bootstrapWith([\Codefy\Framework\Bootstrap\RegisterProviders::class]);
        $response = $app->make(\Application\Http\Controller\PaymentWebhookController::class)
            ->receive($raw === false ? '' : $raw, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
    } catch (\Throwable) {
        $response = new \Laminas\Diactoros\Response\JsonResponse(
            ['status' => 'retry'],
            503,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }
    new \Laminas\HttpHandlerRunner\Emitter\SapiEmitter()->emit($response);
    exit;
}

try {
    $kernel = $app->make(name: Kernel::class);
    $kernel->boot();
} catch (\Throwable $error) {
    $maintenance = new \Application\Http\Response\MaintenanceResponse($maintenanceMode)->respond(
        $maintenanceMethod,
        $maintenancePath,
        $_SERVER['HTTP_ACCEPT'] ?? '',
    );
    if ($maintenance === null) {
        throw $error;
    }
    new \Laminas\HttpHandlerRunner\Emitter\SapiEmitter()->emit($maintenance);
}
