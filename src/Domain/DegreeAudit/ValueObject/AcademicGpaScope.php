<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** A calculation scope, not a replacement for a retained institutional policy. */
enum AcademicGpaScope: string
{
    case Overall = "overall";
    case Institutional = "institutional";
}
