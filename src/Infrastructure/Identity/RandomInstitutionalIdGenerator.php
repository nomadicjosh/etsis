<?php

declare(strict_types=1);

namespace Infrastructure\Identity;

use Domain\Person\Service\InstitutionalIdGenerator;
use Domain\Person\ValueObject\InstitutionalId;

final readonly class RandomInstitutionalIdGenerator implements InstitutionalIdGenerator
{
    public function next(): InstitutionalId
    {
        return new InstitutionalId('P-' . strtoupper(bin2hex(random_bytes(6))));
    }
}
