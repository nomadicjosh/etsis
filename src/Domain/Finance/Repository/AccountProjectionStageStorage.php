<?php

declare(strict_types=1);

namespace Domain\Finance\Repository;

use Domain\Finance\ValueObject\AccountProjectionStage;
use Domain\Shared\ValueObject\EntityId;

interface AccountProjectionStageStorage
{
    public function write(AccountProjectionStage $stage): void;
    public function read(EntityId $id): AccountProjectionStage;
    public function discard(EntityId $id): void;
}
