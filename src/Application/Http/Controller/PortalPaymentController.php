<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Portal\Service\PortalPaymentService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class PortalPaymentController
{
    public function __construct(private PortalPaymentService $payments)
    {
    }

    public function status(): ResponseInterface
    {
        return new JsonResponse(['enabled' => $this->payments->enabled()]);
    }

    public function checkout(ServerRequest $request): ResponseInterface
    {
        $input = FormInput::from($request);
        return new JsonResponse(['url' => $this->payments->checkout(
            $input->id('payment_id'),
            $input->id('account_id')
        )]);
    }
}
