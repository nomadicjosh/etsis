<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Portal\PortalPost;
use Domain\Shared\ValueObject\EntityId;

interface PortalRepository
{
    public function lock(EntityId $id): void;
    public function receipt(EntityId $operation): ?array;
    public function saveReceipt(
        EntityId $operation,
        EntityId $post,
        EntityId $actor,
        string $intent,
        array $result,
    ): void;
    public function add(#[\SensitiveParameter] PortalPost $post): void;
    public function get(EntityId $id): PortalPost;
    public function published(string $code): PortalPost;
    public function save(#[\SensitiveParameter] PortalPost $post, int $expectedVersion): void;
    public function revise(#[\SensitiveParameter] PortalPost $post, int $expectedVersion): void;
}
