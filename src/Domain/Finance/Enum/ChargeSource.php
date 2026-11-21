<?php

declare(strict_types=1);

namespace Domain\Finance\Enum;

use DomainException;

/** Accepted posting classification; absent earlier metadata stays unknown. */
enum ChargeSource: string
{
    case Manual = 'manual_charge';
    case Section = 'section_fee';
    case TermTuition = 'term_tuition';
    case TermFee = 'term_fee';
    case CreditTuition = 'credit_tuition';
    case CreditFee = 'credit_fee';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Staff-recorded charge',
            self::Section => 'Section fee',
            self::TermTuition => 'Term tuition',
            self::TermFee => 'Term fee',
            self::CreditTuition => 'Per-credit tuition',
            self::CreditFee => 'Per-credit fee',
        };
    }

    public function validate(string $type, bool $staff, bool $creditPrice): void
    {
        if (
            $type !== 'charge' || ($staff !== ($this === self::Manual)) ||
            ($creditPrice !== in_array($this, [self::CreditTuition, self::CreditFee], true))
        ) {
            throw new DomainException('Charge source must match its actual posting path.');
        }
    }
}
