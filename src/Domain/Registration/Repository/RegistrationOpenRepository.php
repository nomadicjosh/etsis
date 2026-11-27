<?php

declare(strict_types=1);

namespace Domain\Registration\Repository;

use Domain\Shared\ValueObject\EntityId;

interface RegistrationOpenRepository
{
    public function person(EntityId $owner): ?EntityId;
    public function page(EntityId $owner, EntityId $person, string $now, bool $unreadOnly, ?array $after): array;
    public function candidate(EntityId $owner, EntityId $person, EntityId $term, string $now): ?array;
    public function readState(EntityId $owner, EntityId $person, EntityId $term): ?array;
    public function dismissal(EntityId $owner, EntityId $person, EntityId $term): ?array;
    public function markRead(EntityId $owner, EntityId $person, EntityId $term, array $publication, string $at): void;
    public function dismiss(EntityId $owner, EntityId $person, EntityId $term, array $publication): void;
}
