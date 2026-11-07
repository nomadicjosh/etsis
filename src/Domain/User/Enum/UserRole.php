<?php

declare(strict_types=1);

namespace Domain\User\Enum;

/** Built-in display labels only; custom roles are defined in the access database. */
enum UserRole: string
{
    case USER = 'user';
    case MANAGER = 'manager';
    case ADMIN = 'admin';
    case SUPER = 'super';
    case FACULTY = 'faculty';
    case PARENT = 'parent';
    case STUDENT = 'student';
    case STAFF = 'staff';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(array: self::cases(), column_key: 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::USER => 'User',
            self::MANAGER => 'Manager',
            self::ADMIN => 'Admin',
            self::SUPER => 'Super administrator',
            self::FACULTY => 'Faculty',
            self::PARENT => 'Parent',
            self::STUDENT => 'Student',
            self::STAFF => 'Staff',
        };
    }
}
