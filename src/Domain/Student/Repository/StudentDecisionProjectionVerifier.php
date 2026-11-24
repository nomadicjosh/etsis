<?php

declare(strict_types=1);

namespace Domain\Student\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\ValueObject\StudentDecisionProjectionCheck;

/** Trusted selected-record observation; no authorization grant, repair or business effects. */
interface StudentDecisionProjectionVerifier
{
    public function standing(EntityId $pursuit, int $eventLimit = 500): StudentDecisionProjectionCheck;
    public function load(EntityId $determination, int $eventLimit = 500): StudentDecisionProjectionCheck;
    public function advisors(EntityId $pursuit, int $eventLimit = 500): StudentDecisionProjectionCheck;
    public function requirements(EntityId $pursuit, int $eventLimit = 500): StudentDecisionProjectionCheck;
}
