<?php

declare(strict_types=1);

namespace Domain\Access\Enum;

enum DefinitionKind: string
{
    case Role = 'role';
    case Permission = 'permission';
}
