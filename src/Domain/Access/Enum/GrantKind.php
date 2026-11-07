<?php

declare(strict_types=1);

namespace Domain\Access\Enum;

enum GrantKind: string
{
    case RolePermission = 'role_permission';
    case UserRole = 'user_role';
    case UserPermission = 'user_permission';
}
