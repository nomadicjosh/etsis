<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\ValueObject\CourseClassifications;
use Domain\Shared\ValueObject\EntityId;

interface ClassificationRepository
{
    /** @return array<string, mixed> */
    public function get(EntityId $id): array;
    public function lock(EntityId $id): void;
    /** @return list<array<string, mixed>> */
    public function page(ClassificationKind $kind, string $search, ?EntityId $after): array;
    public function save(
        ClassificationKind $kind,
        ClassificationSnapshot $value,
        bool $active,
        bool $create,
    ): void;
    /** Caller holds the course lock. References are locked in identity order.
     * @param list<EntityId> $additional
     */
    public function lockForCourse(EntityId $course, array $additional = []): void;
    public function course(EntityId $course): CourseClassifications;
    public function assign(EntityId $course, CourseClassifications $values): void;
}
