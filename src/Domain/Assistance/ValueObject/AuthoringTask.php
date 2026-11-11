<?php

declare(strict_types=1);

namespace Domain\Assistance\ValueObject;

enum AuthoringTask: string
{
    case Readiness = "readiness";
    case Description = "description";
}
