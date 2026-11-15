<?php

declare(strict_types=1);

namespace Domain\HumanResources\Enum;

enum HrLibraryKind: string
{
    case Jobs = 'jobs';
    case PayGrades = 'pay-grades';
}
