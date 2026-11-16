<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Finance\BillingRule;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Enum\BillingBasis;
use Domain\Finance\StudentAccount;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Transient, verified inputs. No events are recorded while calculating the proposed charges. */
final readonly class TermFeeQuote
{
    /** @var array<string,mixed> */
    private array $facts;

    /** @param list<BillingRule> $rules */
    public function __construct(
        public EntityId $student,
        public EntityId $term,
        EntityId $program,
        int $primaryPosition,
        public ?StudentAccount $account,
        public array $rules,
    ) {
        if (
            $account !== null &&
            ($account->studentId()->value !== $student->value ||
                $account->termId()->value !== $term->value)
        ) {
            throw new CorruptHistoryException();
        }
        $flatRules = array_values(
            array_filter(
                $rules,
                static fn(BillingRule $rule): bool => $rule->basis() === BillingBasis::Term,
            ),
        );
        $first = $flatRules[0] ?? ($rules[0] ?? null);
        $currency = $account?->balance()->currency ?? $first?->amount()->currency;
        $before = $account?->balance()->minor ?? 0;
        $balance = $before;
        $total = $posted = $reversed = $new = 0;
        $problems = $items = [];
        foreach ($rules as $rule) {
            if (
                $rule->isRetired() ||
                $rule->termId()->value !== $term->value ||
                ($rule->programId() !== null && $rule->programId()->value !== $program->value)
            ) {
                throw new CorruptHistoryException();
            }
            $id = new EntityId((string) $rule->aggregateId());
            if ($rule->basis() === BillingBasis::RegisteredCredit) {
                if ($rule->amount()->currency !== $currency) {
                    $problems[] = "The account and applicable fees must use one currency.";
                }
                $items[] = ["rule_id" => $id->value, "status" => "registration"];
                continue;
            }
            $entry = $account?->entry($id);
            if ($entry !== null) {
                if (
                    $entry["type"] !== EntryType::Charge->value ||
                    $entry["minor"] !== $rule->amount()->minor ||
                    $entry["currency"] !== $rule->amount()->currency
                ) {
                    throw new CorruptHistoryException();
                }
                $status = $entry["reversed"] ? "reversed" : "posted";
                $reversed += (int) $entry["reversed"];
                $posted += (int) !$entry["reversed"];
            } else {
                $status = "new";
                $new++;
                if ($rule->amount()->minor > PHP_INT_MAX - $total) {
                    throw new InvalidArgumentException(
                        "This proposed total exceeds the calculation limit.",
                    );
                }
                $total += $rule->amount()->minor;
                if ($rule->amount()->currency !== $currency) {
                    $problems[] = "The account and applicable fees must use one currency.";
                } else {
                    try {
                        $balance = new Money($balance, $currency)->add($rule->amount())->minor;
                    } catch (InvalidArgumentException) {
                        $problems[] =
                        "The resulting account balance exceeds the supported amount limit.";
                    }
                }
            }
            $items[] = ["rule_id" => $id->value, "status" => $status];
        }
        $this->facts = [
            "student_id" => $student->value,
            "program_id" => $program->value,
            "primary_position" => $primaryPosition,
            "account_id" => $account === null ? null : (string) $account->aggregateId(),
            "account_position" => $account?->playhead(),
            "currency" => $currency,
            "balance_before_minor" => $before,
            "balance_after_minor" => $problems === [] ? $balance : null,
            "charge_minor" => $problems === [] ? $total : null,
            "new_charges" => $new,
            "already_posted" => $posted,
            "previously_reversed" => $reversed,
            "items" => $items,
            "problems" => array_values(array_unique($problems)),
        ];
    }

    /** @return array<string,mixed> */
    public function facts(): array
    {
        return $this->facts;
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["quote" => "[protected financial review]"];
    }
}
