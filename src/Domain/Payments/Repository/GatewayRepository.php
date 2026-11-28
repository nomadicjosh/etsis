<?php

declare(strict_types=1);

namespace Domain\Payments\Repository;

use Domain\Shared\ValueObject\EntityId;

interface GatewayRepository
{
    public function connection(EntityId $id, bool $lock = false): ?array;
    public function connections(?EntityId $after = null): array;
    public function saveConnection(EntityId $id, int $version, string $sealed): void;
    public function route(EntityId $id, bool $lock = false): ?array;
    public function routes(?EntityId $after = null): array;
    public function saveRoute(EntityId $id, string $purpose, string $currency, int $version, string $sealed): void;
    public function asset(EntityId $id, bool $lock = false): array;
    public function assets(string $currency, ?EntityId $after = null): array;
    public function codeExists(string $code): bool;
    public function classify(EntityId $id, string $kind): void;
    public function review(EntityId $id, bool $lock = false): array;
    public function stage(array $row): void;
    public function accept(EntityId $id, string $sealed): void;
    public function clearExpired(int $now, int $limit): int;
    public function prune(EntityId $actor, int $now): void;
}
