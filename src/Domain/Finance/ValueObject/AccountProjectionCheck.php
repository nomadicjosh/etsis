<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Minimized operator result; no financial amounts, reasons, profile values or event contents. */
final readonly class AccountProjectionCheck
{
    /** @param list<string> $issues */
    public function __construct(
        public string $status,
        public EntityId $account,
        public int $events,
        public ?int $postings = null,
        public array $issues = [],
        public ?int $position = null,
    ) {
        if (
            !in_array($status, ['verified', 'changed', 'limited', 'unverified'], true) ||
            $events < 0 || $events > 2000 || ($postings !== null && ($postings < 0 || $postings > 2000)) ||
            ($position !== null && $position < 1) || !array_is_list($issues) ||
            array_diff($issues, ['journal', 'account_identity', 'balance', 'postings', 'unavailable']) !== [] ||
            ($status === 'verified' && ($issues !== [] || $position === null || $postings === null)) ||
            ($status === 'unverified' && $issues === [])
        ) {
            throw new InvalidArgumentException('Use a bounded, consistent account-verification result.');
        }
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return ['status' => $this->status, 'scope' => 'financial_account',
            'account_reference' => $this->account->value, 'position' => $this->position,
            'events_checked' => $this->events, 'expected_postings' => $this->postings,
            'issues' => $this->issues, 'records_changed' => false];
    }
}
