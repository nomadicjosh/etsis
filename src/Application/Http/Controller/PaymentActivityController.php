<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Payments\Service\CaptureActivity;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class PaymentActivityController
{
    public function __construct(private CaptureActivity $service)
    {
    }

    public function ownerShow(ServerRequest $request, string $kind, string $id): ResponseInterface
    {
        return $this->show($request, $kind, $id, 'owner');
    }

    public function staffShow(ServerRequest $request, string $kind, string $id): ResponseInterface
    {
        return $this->show($request, $kind, $id, 'staff');
    }

    private function show(ServerRequest $request, string $kind, string $id, string $mode): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse(
            $this->service->show($kind, new EntityId($id), $mode, $f->optionalId('after')),
            200,
            ['Cache-Control' => 'private, no-store']
        );
    }

    public function ownerRetry(string $id): ResponseInterface
    {
        return $this->retry($id, 'owner');
    }

    public function staffRetry(string $id): ResponseInterface
    {
        return $this->retry($id, 'staff');
    }

    private function retry(string $id, string $mode): ResponseInterface
    {
        return new JsonResponse(
            $this->service->retry(new EntityId($id), $mode),
            200,
            ['Cache-Control' => 'private, no-store']
        );
    }
}
