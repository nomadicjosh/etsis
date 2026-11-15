<?php

declare(strict_types=1);

namespace Domain\Scheduling\Repository;

use Domain\Scheduling\BookingCategory;
use Domain\Shared\ValueObject\EntityId;

interface BookingCategoryRepository
{
    public function get(EntityId $id): BookingCategory;
    public function lock(EntityId $id): void;
    public function add(BookingCategory $category): void;
    public function save(BookingCategory $category, int $expectedVersion): void;
}
