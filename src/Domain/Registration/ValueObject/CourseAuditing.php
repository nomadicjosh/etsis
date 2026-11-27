<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Finance\ValueObject\Money;
use Domain\Registration\Enum\EnrollmentBasis;
use Domain\Registration\Section;
use Domain\Shared\Exception\ValidationException;
use InvalidArgumentException;

/** Optional published institutional rules for auditing this offering. Absence prohibits auditing. */
final readonly class CourseAuditing
{
    public function __construct(public int $feeMinor, public bool $selfService = false)
    {
        if ($feeMinor < 0 || $feeMinor > Money::MAX_MINOR) {
            throw new InvalidArgumentException('Use a nonnegative supported audit fee.');
        }
    }

    public static function from(mixed $value): self
    {
        if (
            !is_array($value) || count($value) !== 2 ||
            !isset($value['fee_minor'], $value['self_service']) ||
            !is_int($value['fee_minor']) || !is_bool($value['self_service'])
        ) {
            throw new InvalidArgumentException('Invalid course auditing policy.');
        }
        return new self($value['fee_minor'], $value['self_service']);
    }

    public static function stored(?string $value = null): ?self
    {
        return $value === null ? null : self::from(json_decode($value, true, 4, JSON_THROW_ON_ERROR));
    }

    public function facts(): array
    {
        return ['fee_minor' => $this->feeMinor, 'self_service' => $this->selfService];
    }

    public static function requireAllowed(Section $section, bool $byStaff): void
    {
        if (
            $section->auditing === null || (!$byStaff && !$section->auditing->selfService) ||
            $section->publicationVersionId === null
        ) {
            throw new ValidationException([['field' => 'basis', 'message' =>
                \Codefy\Framework\Helpers\trans(
                    'Auditing requires a published section policy that allows this registration method.'
                )]]);
        }
    }

    public static function fee(Section $section, EnrollmentBasis $basis): Money
    {
        return $basis === EnrollmentBasis::Standard ? $section->fee :
        new Money(
            $section->auditing?->feeMinor ?? throw new InvalidArgumentException('An explicit audit price is required.'),
            $section->fee->currency
        );
    }
}
