<?php

declare(strict_types=1);

namespace Domain\Communications\Enum;

/** A source record selects its canonical person account; it never creates another identity. */
enum RecordRecipientSource: string
{
    case Person = 'person';
    case Student = 'student';
    case Application = 'application';

    public function permission(): string
    {
        return match ($this) {
            self::Person => 'sis:person:manage',
            self::Student => 'sis:student:read',
            self::Application => 'sis:admissions:manage',
        };
    }
}
