<?php

declare(strict_types=1);

namespace Domain\Transfer\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\TransferEquivalency;

interface TransferRepository
{
    public function addInstitution(EntityId $id, string $code, string $name): void;
    public function addExternalCourse(EntityId $id, EntityId $institution, string $code, string $title): void;
    public function addEquivalency(TransferEquivalency $equivalency): void;
    /** @return list<array<string, mixed>> */
    public function transcript(EntityId $student): array;
}
