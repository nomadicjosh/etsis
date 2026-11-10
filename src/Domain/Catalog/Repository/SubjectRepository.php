<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Shared\ValueObject\EntityId;

interface SubjectRepository
{
    /** @return array<string, mixed> */
    public function get(EntityId $id): array;
    public function lock(EntityId $id): void;
    /** @return list<array<string, mixed>> */
    public function page(string $search, ?EntityId $after): array;
    public function save(SubjectSnapshot $subject, bool $active, bool $create): void;
    /** Caller holds the course lock before locking its subject. */
    public function lockForCourse(EntityId $course): void;
    public function course(EntityId $course): ?SubjectSnapshot;
    public function assign(EntityId $course, ?EntityId $subject): void;
}
