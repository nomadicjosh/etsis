<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Finance\BillingRule;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

/** Current rule lookup in its existing metadata row. Financial events remain authoritative. */
final readonly class SqlBillingRuleProjection
{
    public const array FIELDS = [
        "name",
        "kind",
        "amount_minor",
        "currency",
        "retired",
        "position",
        "pricing_basis",
    ];

    public function __construct(private SqlRecords $sql)
    {
    }

    public function advance(BillingRule $rule, int $expected): void
    {
        $row = $this->metadata($rule);
        $values = $this->values($rule);
        if ($expected === 0) {
            foreach (self::FIELDS as $field) {
                if (!array_key_exists($field, $row) || $row[$field] !== null) {
                    throw new CorruptHistoryException();
                }
            }
        } else {
            $previous = $values;
            $previous["position"] = $expected;
            $previous["retired"] = 0;
            if (!$rule->isRetired() || !$this->matches($row, $previous)) {
                throw new CorruptHistoryException();
            }
        }
        $criteria = ["id" => (string) $rule->aggregateId()];
        if ($expected > 0) {
            $criteria["position"] = $expected;
        }
        if ($this->sql->update("sis_billing_rules", $values, $criteria) !== 1) {
            throw new ConcurrentModificationException(
                "This billing rule changed. Review it again.",
            );
        }
    }

    /** Explicit offline conversion only; known conflicting values are never replaced. */
    public function initialize(BillingRule $rule): void
    {
        $row = $this->metadata($rule);
        $values = $this->values($rule);
        $missing = false;
        foreach ($values as $field => $value) {
            if (!array_key_exists($field, $row)) {
                throw new CorruptHistoryException();
            }
            if ($row[$field] === null) {
                $missing = true;
            } elseif ((string) $row[$field] !== (string) $value) {
                throw new CorruptHistoryException();
            }
        }
        if (
            $missing &&
            $this->sql->update("sis_billing_rules", $values, [
                "id" => (string) $rule->aggregateId(),
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The billing rule changed during projection conversion.",
            );
        }
    }

    public function verify(BillingRule $rule): void
    {
        $row = $this->metadata($rule);
        if (isset($row["position"]) && (int) $row["position"] > $rule->playhead()) {
            throw new ConcurrentModificationException(
                "This billing rule changed while it was being read. Reload it.",
            );
        }
        if (!$this->matches($row, $this->values($rule))) {
            throw new CorruptHistoryException();
        }
    }

    /** Bounded-result metadata checks; no payload decryption, event replay or write locks. */
    public function assertCurrent(): void
    {
        $q = $this->sql->quote(...);
        $c = static fn(string $alias, string $field): string => $q($alias) . "." . $q($field);
        $invalid = [];
        foreach (self::FIELDS as $field) {
            $invalid[] = $c("r", $field) . " IS NULL";
        }
        $invalid[] = $c("r", "position") . " <> " . $c("h", "version");
        $invalid[] = $c("r", "retired") . " NOT IN (0, 1)";
        $invalid[] = $c("r", "amount_minor") . " < 1";
        $invalid[] = $c("r", "amount_minor") . " > " . Money::MAX_MINOR;
        $invalid[] = $c("r", "pricing_basis") . " NOT IN ('term', 'registered_credit')";
        $invalid[] = $c("r", "kind") . " NOT IN ('fee', 'tuition')";
        $invalid[] = $c("r", "name") . " = ''";
        $invalid[] = $c("h", "id") . " IS NULL";
        $invalid[] = $c("t", "id") . " IS NULL";
        $invalid[] =
        "(" . $c("r", "program_id") . " IS NOT NULL AND " . $c("p", "id") . " IS NULL)";
        $bad = $this->sql->fetch(
            "SELECT " .
                $c("r", "id") .
                " FROM " .
                $q("sis_billing_rules") .
                " r" .
                " LEFT JOIN " .
                $q("sis_history_streams") .
                " h ON " .
                $c("h", "aggregate_id") .
                " = " .
                $c("r", "id") .
                " AND " .
                $c("h", "stream_type") .
                " = ?" .
                " LEFT JOIN " .
                $q("sis_terms") .
                " t ON " .
                $c("t", "id") .
                " = " .
                $c("r", "term_id") .
                " LEFT JOIN " .
                $q("sis_programs") .
                " p ON " .
                $c("p", "id") .
                " = " .
                $c("r", "program_id") .
                " WHERE " .
                implode(" OR ", $invalid),
            ["billing_rule"],
            1,
        );
        $orphan = $this->sql->fetch(
            "SELECT " .
                $c("h", "id") .
                " FROM " .
                $q("sis_history_streams") .
                " h" .
                " LEFT JOIN " .
                $q("sis_billing_rules") .
                " r ON " .
                $c("r", "id") .
                " = " .
                $c("h", "aggregate_id") .
                " WHERE " .
                $c("h", "stream_type") .
                " = ? AND " .
                $c("r", "id") .
                " IS NULL",
            ["billing_rule"],
            1,
        );
        if ($bad !== [] || $orphan !== []) {
            throw new CorruptHistoryException();
        }
    }

    /** @return array<string, mixed> */
    private function metadata(BillingRule $rule): array
    {
        $row = $this->sql->current("sis_billing_rules", (string) $rule->aggregateId());
        if (
            $row === null ||
            $row["term_id"] !== $rule->termId()->value ||
            $row["program_id"] !== $rule->programId()?->value
        ) {
            throw new CorruptHistoryException();
        }
        return $row;
    }

    /** @param array<string, mixed> $row
     * @param array<string, int|string> $values */
    private function matches(array $row, array $values): bool
    {
        foreach ($values as $field => $value) {
            if (!isset($row[$field]) || (string) $row[$field] !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    /** @return array{name:string,kind:string,amount_minor:int,currency:string,retired:int,position:int,pricing_basis:string} */
    private function values(BillingRule $rule): array
    {
        if (
            $rule->playhead() < 1 ||
            $rule->amount()->minor < 1 ||
            !in_array($rule->kind(), ["fee", "tuition"], true) ||
            trim($rule->name()) === ""
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "name" => $rule->name(),
            "kind" => $rule->kind(),
            "amount_minor" => $rule->amount()->minor,
            "currency" => $rule->amount()->currency,
            "retired" => (int) $rule->isRetired(),
            "position" => $rule->playhead(),
            "pricing_basis" => $rule->basis()->value,
        ];
    }
}
