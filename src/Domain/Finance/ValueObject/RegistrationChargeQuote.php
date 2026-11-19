<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Finance\Enum\BillingBasis;
use InvalidArgumentException;

/** One transient accepted pricing view. Public summaries never disclose account balances or identity. */
final readonly class RegistrationChargeQuote implements \JsonSerializable
{
    /** @var array<string,mixed> */
    private array $summary;

    public function __construct(
        public TermFeeQuote $termQuote,
        public Money $sectionFee,
        public int $creditsMilli,
    ) {
        if ($sectionFee->minor < 0 || $creditsMilli < 0 || $creditsMilli > 999999) {
            throw new InvalidArgumentException("A section fee and valid registration credits are required.");
        }
        $facts = $termQuote->facts();
        $problems = $facts["problems"];
        $states = array_column($facts["items"], "status", "rule_id");
        $currency = $facts["currency"] ?? $sectionFee->currency;
        $items = [];
        $total = 0;
        $add = static function (int $minor) use (&$total, &$problems): void {
            if ($minor > Money::MAX_MINOR - $total) {
                $problems[] = "The registration charges exceed the supported amount limit.";
                return;
            }
            $total += $minor;
        };
        if ($sectionFee->minor > 0) {
            if ($sectionFee->currency !== $currency) {
                $problems[] = "The account and applicable fees must use one currency.";
            }
            $items[] = [
                "name" => "Section fee",
                "basis" => "section",
                "minor" => $sectionFee->minor,
                "currency" => $sectionFee->currency,
                "status" => "new",
            ];
            $add($sectionFee->minor);
        }
        foreach ($termQuote->rules as $rule) {
            $credit = $rule->basis() === BillingBasis::RegisteredCredit;
            $state = $credit ? "new" : $states[(string) $rule->aggregateId()];
            try {
                $amount = $credit
                ? RegistrationPrice::amountForCredits($creditsMilli, $rule->amount())
                : $rule->amount();
            } catch (InvalidArgumentException) {
                $problems[] = "A registration price exceeds the supported amount limit.";
                continue;
            }
            $items[] = [
                "name" => $rule->name(),
                "basis" => $rule->basis()->value,
                "minor" => $amount->minor,
                "currency" => $amount->currency,
                "status" => $state === "new" ? "new" : "not_due",
                "rate_minor" => $credit ? $rule->amount()->minor : null,
                "credits_milli" => $credit ? $creditsMilli : null,
            ];
            if ($state === "new") {
                $add($amount->minor);
            }
        }
        // Include the projected new charges in overflow detection, without disclosing the existing balance.
        if ($facts["balance_before_minor"] > Money::MAX_MINOR - $total) {
            $problems[] = "The resulting account balance exceeds the supported amount limit.";
        }
        $problems = array_values(array_unique($problems));
        $this->summary = [
            "currency" => $currency,
            "total_minor" => $problems === [] ? $total : null,
            "items" => $items,
            "problems" => $problems,
        ];
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        return $this->summary;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->summary;
    }

    /** Deduplicate term-wide fees across a working schedule; never changes accepted financial facts.
     * @param array<string,true> $termFees
     * @return array<string,mixed> */
    public function planSummary(array &$termFees): array
    {
        $summary = $this->summary;
        $index = $this->sectionFee->minor > 0 ? 1 : 0;
        foreach ($this->termQuote->rules as $rule) {
            $key = $this->termQuote->term->value . ":" . (string) $rule->aggregateId();
            if ($rule->basis() === BillingBasis::Term && $summary["items"][$index]["status"] === "new") {
                if (isset($termFees[$key])) {
                    $summary["items"][$index]["status"] = "included_in_schedule";
                    if ($summary["total_minor"] !== null) {
                        $summary["total_minor"] -= $summary["items"][$index]["minor"];
                    }
                }
                $termFees[$key] = true;
            }
            ++$index;
        }
        return $summary;
    }

    /** Immutable prices and program context, excluding account changes caused by this same schedule. */
    public function definitionFingerprint(): string
    {
        $facts = $this->termQuote->facts();
        return hash(
            "sha256",
            json_encode(
                [
                    $this->termQuote->student->value,
                    $this->termQuote->term->value,
                    $facts["program_id"],
                    $facts["primary_position"],
                    $facts["currency"] ?? $this->sectionFee->currency,
                    $this->ruleFacts(),
                    $this->sectionFee->minor,
                    $this->sectionFee->currency,
                    $this->creditsMilli,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function ruleFacts(): array
    {
        $rules = [];
        foreach ($this->termQuote->rules as $rule) {
            $rules[] = [
                (string) $rule->aggregateId(),
                $rule->playhead(),
                $rule->name(),
                $rule->amount()->minor,
                $rule->amount()->currency,
                $rule->basis()->value,
            ];
        }
        return $rules;
    }

    public function fingerprint(): string
    {
        $rules = $this->ruleFacts();
        return hash(
            "sha256",
            json_encode(
                [
                    $this->termQuote->facts(),
                    $rules,
                    $this->sectionFee->minor,
                    $this->sectionFee->currency,
                    $this->creditsMilli,
                    $this->summary,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["quote" => "[protected registration pricing]"];
    }
}
