<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Repository;

use Domain\Shared\ValueObject\EntityId;

interface NewsletterRepository
{
    public function connections(?EntityId $after = null): array;
    public function connection(EntityId $id): ?array;
    public function lockConnection(EntityId $id): void;
    public function saveConnection(array $row, ?int $expected = null): void;
    public function enqueue(EntityId $inquiry, EntityId $connection, string $target, int $now): void;
    public function job(EntityId $inquiry): ?array;
    public function next(int $now): ?array;
    public function claim(EntityId $inquiry, int $version, int $now): bool;
    public function finish(EntityId $inquiry, int $version, string $state, string $issue, int $next): void;
    public function remove(EntityId $inquiry): void;
}
