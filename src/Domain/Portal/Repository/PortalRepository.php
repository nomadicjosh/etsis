<?php

declare(strict_types=1);

namespace Domain\Portal\Repository;

use Domain\Portal\PortalPost;
use Domain\Shared\ValueObject\EntityId;

interface PortalRepository
{
    public function add(PortalPost $post): void;
    public function get(EntityId $id): PortalPost;
    public function published(string $code): PortalPost;
    public function save(PortalPost $post, int $expectedVersion): void;
    public function revise(PortalPost $post, int $expectedVersion): void;
}
