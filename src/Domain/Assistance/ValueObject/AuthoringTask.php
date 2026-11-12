<?php

declare(strict_types=1);

namespace Domain\Assistance\ValueObject;

enum AuthoringTask: string
{
    case Readiness = "readiness";
    case Description = "description";
    case Rewrite = "rewrite";
    case Expand = "expand";
    case SectionReadiness = "section_readiness";

    public function requiresExistingDescription(): bool
    {
        return $this === self::Rewrite || $this === self::Expand;
    }
}
