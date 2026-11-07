<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Codefy\Framework\Contracts\Http\Kernel;

use function Codefy\Framework\Helpers\get_fresh_bootstrap;

$app = get_fresh_bootstrap();

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

$kernel = $app->make(name: Kernel::class);
$kernel->boot();
