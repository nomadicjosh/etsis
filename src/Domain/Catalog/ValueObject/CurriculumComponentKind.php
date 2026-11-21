<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

/** Curriculum roles share the existing program-owned component identity and requirements engine. */
enum CurriculumComponentKind: string
{
    case Major = "major";
    case Minor = "minor";
    case Concentration = "concentration";
    case Track = "track";
    case Specialization = "specialization";

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
