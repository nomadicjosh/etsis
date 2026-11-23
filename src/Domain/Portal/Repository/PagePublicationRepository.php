<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Shared\ValueObject\EntityId;

interface PagePublicationRepository
{
    public function lock(string $page): void;
    public function source(string $page): array;
    public function state(string $page): ?array;
    public function save(string $page, array $state): void;
    public function receipt(EntityId $operation): ?array;
    public function saveReceipt(EntityId $operation, array $receipt): void;
    public function directory(?string $after = null): array;
    public function navigationCandidates(): array;
    public function routes(): array;
}
