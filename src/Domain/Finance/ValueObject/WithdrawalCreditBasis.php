<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Registration\ValueObject\DropPolicyReference;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Applied institutional policy and exact original-charge calculation, retained with the credit. */
final readonly class WithdrawalCreditBasis
{
    public function __construct(#[\SensitiveParameter] public array $facts)
    {
        $fields = ['enrollment_id', 'drop_operation_id', 'policy_reference', 'basis_points',
            'original_minor', 'credited_before_minor', 'effective_on'];
        if (
            count($facts) !== count($fields) || array_diff(array_keys($facts), $fields) !== [] ||
            !is_string($facts['enrollment_id'] ?? null) || !is_string($facts['drop_operation_id'] ?? null) ||
            !is_array($facts['policy_reference'] ?? null) || !is_int($facts['basis_points'] ?? null) ||
            $facts['basis_points'] < 1 || $facts['basis_points'] > 10000 ||
            !is_int($facts['original_minor'] ?? null) || $facts['original_minor'] < 1 ||
            $facts['original_minor'] > Money::MAX_MINOR || !is_int($facts['credited_before_minor'] ?? null) ||
            $facts['credited_before_minor'] < 0 || $facts['credited_before_minor'] > $facts['original_minor'] ||
            !is_int($facts['policy_reference']['version'] ?? null) || $facts['policy_reference']['version'] < 0
        ) {
            throw new InvalidArgumentException('Use complete retained withdrawal credit evidence.');
        }
        new EntityId($facts['enrollment_id']);
        new EntityId($facts['drop_operation_id']);
        DropPolicyReference::validate($facts['policy_reference'], $facts['policy_reference']['version']);
        SectionDefinitionV1::assertDate($facts['effective_on']);
    }

    public static function target(int $original, int $basisPoints): int
    {
        if ($original < 0 || $original > Money::MAX_MINOR || $basisPoints < 0 || $basisPoints > 10000) {
            throw new InvalidArgumentException('Use bounded original charges and percentage basis points.');
        }
        return intdiv($original * $basisPoints + 5000, 10000);
    }

    public function amount(): int
    {
        return max(0, self::target($this->facts['original_minor'], $this->facts['basis_points']) -
            $this->facts['credited_before_minor']);
    }
}
