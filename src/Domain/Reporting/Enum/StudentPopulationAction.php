<?php

declare(strict_types=1);

namespace Domain\Reporting\Enum;

/** A report selection supplies identities, never authority to perform the selected task. */
enum StudentPopulationAction: string
{
    case Registration = 'registration';
    case Billing = 'billing';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Registration => ['sis:registration:manage'],
            self::Billing => ['sis:finance:read', 'sis:finance:post'],
        };
    }
}
