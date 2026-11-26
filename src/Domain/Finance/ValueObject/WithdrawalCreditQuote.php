<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Shared\ValueObject\EntityId;

/** Internal protected source snapshot; only summary() is suitable for an authorized drop review. */
final readonly class WithdrawalCreditQuote implements \JsonSerializable
{
    /** @param list<array{source_id:string,original_minor:int,credited_minor:int,minor:int}> $charges */
    public function __construct(
        public EntityId $student,
        public EntityId $term,
        public EntityId $enrollment,
        public int $basisPoints,
        public ?AccountId $account,
        public int $position,
        public ?string $currency,
        #[\SensitiveParameter] public array $charges,
    ) {
        WithdrawalCreditBasis::target(0, $basisPoints);
        if (count($charges) > 101 || $position < 0 || ($account === null && ($charges !== [] || $position !== 0))) {
            throw new \InvalidArgumentException('Use a bounded registration-charge snapshot.');
        }
        if (
            ($account === null && $currency !== null) ||
            ($account !== null && ($currency === null || preg_match('/^[A-Z]{3}$/D', $currency) !== 1))
        ) {
            throw new \InvalidArgumentException('Use the verified account currency.');
        }
        $sources = [];
        foreach ($charges as $row) {
            if (
                !is_array($row) || count($row) !== 4 || !is_string($row['source_id'] ?? null) ||
                isset($sources[$row['source_id']]) || !is_int($row['original_minor'] ?? null) ||
                $row['original_minor'] < 1 || !is_int($row['credited_minor'] ?? null) ||
                $row['credited_minor'] < 0 || $row['credited_minor'] > $row['original_minor'] ||
                ($row['minor'] ?? null) !== max(
                    0,
                    WithdrawalCreditBasis::target($row['original_minor'], $basisPoints) - $row['credited_minor']
                )
            ) {
                throw new \InvalidArgumentException('Use exact original-charge credit calculations.');
            }
            new EntityId($row['source_id']);
            $sources[$row['source_id']] = true;
        }
    }

    public function summary(): array
    {
        return ['basis_points' => $this->basisPoints, 'minor' => array_sum(array_column($this->charges, 'minor')),
            'currency' => $this->currency, 'charge_count' => count($this->charges)];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->student->value, $this->term->value, $this->enrollment->value,
            $this->basisPoints, $this->account === null ? null : (string) $this->account,
            $this->currency, $this->charges], JSON_THROW_ON_ERROR));
    }

    public function after(): self
    {
        $position = $this->position;
        $charges = $this->charges;
        foreach ($charges as &$row) {
            $position += (int) ($row['minor'] > 0);
            $row['credited_minor'] += $row['minor'];
            $row['minor'] = 0;
        }
        unset($row);
        return new self(
            $this->student,
            $this->term,
            $this->enrollment,
            $this->basisPoints,
            $this->account,
            $position,
            $this->currency,
            $charges
        );
    }

    public function __debugInfo(): array
    {
        return ['withdrawal_credit' => '[protected registration-charge snapshot]'];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
