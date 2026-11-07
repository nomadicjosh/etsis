<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Person\ValueObject\InstitutionalId;

interface InstitutionalIdGenerator
{
    public function next(): InstitutionalId;
}
