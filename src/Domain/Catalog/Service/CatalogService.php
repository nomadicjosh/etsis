<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Catalog\Program;
use Domain\Catalog\Repository\CatalogRepository;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class CatalogService
{
    public function __construct(private CatalogRepository $repository, private StudentAccess $access)
    {
    }

    public function createProgram(string $code, string $title): Program
    {
        $this->access->requirePermission("sis:catalog:manage");
        $program = new Program(EntityId::generate(), $code, $title);
        $this->repository->addProgram($program);

        return $program;
    }
}
