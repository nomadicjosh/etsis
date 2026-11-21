<?php

declare(strict_types=1);

namespace Domain\Gradebook\Repository;

use Domain\Shared\ValueObject\EntityId;

interface GradeCertificationRoster
{
    /** After student and section locks, lock current course/term headers without opening an old read snapshot. */
    public function lockDefinitions(EntityId $section): void;
    public function termCode(EntityId $term): string;
    /** Current enrolled and withdrawn attempts, including missing-source detection; at most 51 rows. */
    public function rows(EntityId $section): array;
}
