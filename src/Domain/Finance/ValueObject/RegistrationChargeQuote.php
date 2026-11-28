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
        private ?array $pendingTuition = null,
    ) {
        if (
            $sectionFee->minor < 0 ||
            $creditsMilli < 0 ||
            $creditsMilli > 999999
        ) {
            throw new InvalidArgumentException(
                "A section fee and valid registration credits are required.",
            );
        }
        $facts = $termQuote->facts();
        $problems = $facts["problems"];
        $states = array_column($facts["items"], "status", "rule_id");
        $currency = $facts["currency"] ?? $sectionFee->currency;
        $items = [];
        $total = 0;
        $add = static function (int $minor) use (&$total, &$problems): void {
            if ($minor > Money::MAX_MINOR - $total) {
                $problems[] =
                "The registration charges exceed the supported amount limit.";
                return;
            }
            $total += $minor;
        };
        if ($sectionFee->minor > 0) {
            if ($sectionFee->currency !== $currency) {
                $problems[] =
                "The account and applicable fees must use one currency.";
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
                ? RegistrationPrice::amountForCredits(
                    $creditsMilli,
                    $rule->amount(),
                )
                : $rule->amount();
            } catch (InvalidArgumentException) {
                $problems[] =
                "A registration price exceeds the supported amount limit.";
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
            $problems[] =
            "The resulting account balance exceeds the supported amount limit.";
        }
        $problems = array_values(array_unique($problems));
        $summary = [
            "currency" => $currency,
            "total_minor" => $problems === [] ? $total : null,
            "items" => $items,
            "problems" => $problems,
        ];
        if ($pendingTuition !== null) {
            $pending = $pendingTuition["after"];
            $summary = [
                "currency" =>
                    $pendingTuition["snapshot"]["definition"]["currency"],
                "total_minor" =>
                    $pending["total_minor"] - $pendingTuition["before_minor"],
                "mode" => "pending",
                "pending_total_minor" => $pending["total_minor"],
                "problems" => [],
                "items" => array_map(
                    static fn(array $line): array => [
                        "name" => $line["name"],
                        "minor" => $line["minor"],
                        "currency" =>
                            $pendingTuition["snapshot"]["definition"][
                                "currency"
                            ],
                        "basis" => "pending_invoice",
                        "status" => "new",
                    ],
                    $pending["lines"],
                ),
            ];
        }
        $this->summary = $summary;
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

    /** Proposed early-drop course reversal only; no balances, account IDs or unrelated entries leave this value.
     * @return array<string,mixed> */
    public function sectionChangeSummary(
        \Domain\Shared\ValueObject\EntityId $enrollment,
    ): array {
        $account = $this->termQuote->account;
        $credit = 0;
        $references = [];
        foreach ($account?->courseCharges($enrollment) ?? [] as $id) {
            $entry =
            $account->entry($id) ??
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            if (
                $entry["type"] !==
                    \Domain\Finance\Enum\EntryType::Charge->value ||
                $entry["currency"] !== $account->balance()->currency
            ) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            $remaining = $entry["remaining_charge_minor"];
            if ($remaining > Money::MAX_MINOR - $credit) {
                throw new \InvalidArgumentException(
                    "The course reversal exceeds its supported amount limit.",
                );
            }
            $credit += $remaining;
            $references[] = [
                $id->value,
                $entry["minor"],
                $entry["reversed"],
                $remaining,
            ];
        }
        $problems = array_values(
            array_filter(
                $this->summary["problems"],
                static fn(string $problem): bool => $problem !==
                    "The resulting account balance exceeds the supported amount limit.",
            ),
        );
        $newCharges = 0;
        foreach ($this->summary["items"] as $item) {
            if ($item["status"] === "new") {
                if ($item["minor"] > Money::MAX_MINOR - $newCharges) {
                    $problems[] =
                    "The registration charges exceed the supported amount limit.";
                    break;
                }
                $newCharges += $item["minor"];
            }
        }
        $pending = null;
        if ($this->pendingTuition !== null) {
            $state = $this->pendingTuition["state"];
            unset($state["courses"][$enrollment->value]);
            $root = \Domain\Tuition\TuitionBill::estimate(
                $this->pendingTuition["snapshot"],
                $state,
                [
                    "enrollment_id" => "00000000000000000000000000",
                    "minor" => 0,
                    "credits_milli" => 0,
                ],
            );
            $next = \Domain\Tuition\TuitionBill::estimate(
                $this->pendingTuition["snapshot"],
                $state,
                [
                    "enrollment_id" => "00000000000000000000000000",
                    "minor" => $this->sectionFee->minor,
                    "credits_milli" => $this->creditsMilli,
                ],
            );
            $newCharges =
            $next["pending"]["total_minor"] -
            $root["pending"]["total_minor"];
            $pending = $next["pending"]["total_minor"];
        }
        $currency = $this->summary["currency"];
        $balance = $account?->balance()->minor ?? 0;
        if ($balance < -Money::MAX_MINOR + $credit) {
            $problems[] =
            "The course reversal exceeds the supported account amount limit.";
        }
        if ($balance - $credit > Money::MAX_MINOR - $newCharges) {
            $problems[] =
            "The resulting account balance exceeds the supported amount limit.";
        }
        $newCharges = $problems === [] ? $newCharges : null;
        return [
            "currency" => $currency,
            "credit_minor" => $credit,
            "new_charge_minor" => $newCharges,
            "net_change_minor" =>
                $newCharges === null
                    ? null
                    : ($pending === null ? $newCharges : 0) - $credit,
            ...$pending === null
                ? []
                : ["mode" => "pending", "pending_total_minor" => $pending],
            "problems" => $problems,
            "reversal_revision" => hash(
                "sha256",
                json_encode($references, JSON_THROW_ON_ERROR),
            ),
        ];
    }

    /** Deduplicate term-wide fees across a working schedule; never changes accepted financial facts.
     * @param array<string,mixed> $termFees
     * @return array<string,mixed> */
    public function planSummary(array &$termFees): array
    {
        if ($this->pendingTuition !== null) {
            $key = "tuition:" . $this->pendingTuition["bill_id"];
            $working = $termFees[$key] ?? [
                "state" => $this->pendingTuition["state"],
                "total" => $this->pendingTuition["before_minor"],
            ];
            $next = \Domain\Tuition\TuitionBill::estimate(
                $this->pendingTuition["snapshot"],
                $working["state"],
                [
                    "enrollment_id" => \Domain\Shared\ValueObject\EntityId::generate()
                        ->value,
                    "minor" => $this->sectionFee->minor,
                    "credits_milli" => $this->creditsMilli,
                ],
            );
            $termFees[$key] = [
                "state" => $next["state"],
                "total" => $next["pending"]["total_minor"],
            ];
            return array_replace($this->summary, [
                "total_minor" =>
                    $next["pending"]["total_minor"] - $working["total"],
                "pending_total_minor" => $next["pending"]["total_minor"],
            ]);
        }
        $summary = $this->summary;
        $index = $this->sectionFee->minor > 0 ? 1 : 0;
        foreach ($this->termQuote->rules as $rule) {
            $key =
            $this->termQuote->term->value .
            ":" .
            (string) $rule->aggregateId();
            if (
                $rule->basis() === BillingBasis::Term &&
                $summary["items"][$index]["status"] === "new"
            ) {
                if (isset($termFees[$key])) {
                    $summary["items"][$index]["status"] =
                    "included_in_schedule";
                    if ($summary["total_minor"] !== null) {
                        $summary["total_minor"] -=
                        $summary["items"][$index]["minor"];
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
                    $this->pendingTuition === null
                        ? null
                        : \Domain\Finance\ValueObject\LedgerSchema::hash(
                            $this->pendingTuition["snapshot"],
                        ),
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
                    $this->pendingTuition["version"] ?? null,
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

    public function pendingTuition(): ?array
    {
        return $this->pendingTuition;
    }
}
