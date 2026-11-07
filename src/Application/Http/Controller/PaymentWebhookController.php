<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Domain\Portal\Service\PaymentConfirmationService;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final readonly class PaymentWebhookController
{
    public function __construct(private PaymentConfirmationService $payments)
    {
    }

    public function receive(
        #[\SensitiveParameter] string $raw,
        #[\SensitiveParameter] string $signature
    ): ResponseInterface {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
        if (strlen($raw) > 262144 || $signature === '' || strlen($signature) > 1024) {
            return new JsonResponse(['status' => 'invalid'], 400, $headers);
        }
        try {
            $this->payments->receive($raw, $signature);
        } catch (InvalidArgumentException) {
            return new JsonResponse(['status' => 'invalid'], 400, $headers);
        } catch (Throwable) {
            // Provider retries; never log payment payloads, signature values or upstream exceptions.
            return new JsonResponse(['status' => 'retry'], 503, $headers);
        }
        return new JsonResponse(['status' => 'received'], 200, $headers);
    }
}
