<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Domain\Audit\Service\AuditService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

final readonly class SisAuditController
{
    public function __construct(private AuditService $service)
    {
    }

    public function subject(string $id): ResponseInterface
    {
        return new JsonResponse(['events' => $this->service->forSubject(new EntityId($id))]);
    }
}
