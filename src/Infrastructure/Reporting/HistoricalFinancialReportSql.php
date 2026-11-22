<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Reporting\ValueObject\ReportFinancialCutoff;
use Domain\Shared\Exception\ValidationException;
use Infrastructure\Persistence\SqlRecords;

final readonly class HistoricalFinancialReportSql
{
    public function __construct(private SqlRecords $sql)
    {
    }
    /** Only a strictly validated fixed-format date is embedded; no arbitrary SQL or user expression. */
    public function cutoff(string $value): string
    {
        $date = new ReportFinancialCutoff($value);
        return "'" . $date->sql . "'";
    }
    public function active(string $alias, string $value): string
    {
        $q = $this->sql->quote(...);
        $c = static fn(string $field): string => $q($alias) . "." . $q($field);
        $cutoff = $this->cutoff($value);
        return "(" .
        $c("recorded_at") .
        " <= " .
        $cutoff .
        " AND (" .
        $c("reversed_at") .
        " IS NULL OR " .
        $c("reversed_at") .
        " > " .
        $cutoff .
        "))";
    }
    public function opened(string $alias, string $value): string
    {
        $q = $this->sql->quote(...);
        return "EXISTS (SELECT 1 FROM " .
        $q("sis_account_entry_heads") .
        " historical_head WHERE " .
        "historical_head." .
        $q("id") .
        " = " .
        $q($alias) .
        "." .
        $q("id") .
        " AND historical_head." .
        $q("opened_at") .
        " <= " .
        $this->cutoff($value) .
        ")";
    }
    public function amount(string $alias, string $measure, string $value): string
    {
        $q = $this->sql->quote(...);
        $type = match ($measure) {
            "balance" => null,
            "charges" => " IN ('charge', 'charge_credit')",
            "payments" => " = 'payment'",
            "refunds" => " = 'refund'",
            default => throw new \LogicException("Unknown historical financial measure."),
        };
        $column = in_array($measure, ["payments", "refunds"], true)
        ? "amount_minor"
        : "effect_minor";
        return "(SELECT COALESCE(SUM(historical_entry." .
        $q($column) .
        "), 0) FROM " .
        $q("sis_account_entries") .
        " historical_entry WHERE historical_entry." .
        $q("account_id") .
        " = " .
        $q($alias) .
        "." .
        $q("id") .
        " AND " .
        $this->active("historical_entry", $value) .
        ($type === null ? "" : " AND historical_entry." . $q("type") . $type) .
        ")";
    }
    /** Indexed consistency probes only. Reports never reconstruct history or invent missing dates. */
    public function requireReady(): void
    {
        $q = $this->sql->quote(...);
        $bad = $this->sql->fetch(
            "SELECT h." .
                $q("id") .
                " FROM " .
                $q("sis_account_entry_heads") .
                " h" .
                " WHERE h." .
                $q("opened_at") .
                " IS NULL OR h." .
                $q("temporal_position") .
                " IS NULL OR h." .
                $q("temporal_position") .
                " <> h." .
                $q("position") .
                " OR h." .
                $q("entry_count") .
                " <> (SELECT COUNT(*) FROM " .
                $q("sis_account_entries") .
                " e WHERE e." .
                $q("account_id") .
                " = h." .
                $q("id") .
                ")",
            [],
            1,
        );
        if ($bad === []) {
            $bad = $this->sql->fetch(
                "SELECT " .
                    $q("id") .
                    " FROM " .
                    $q("sis_account_entries") .
                    " WHERE (" .
                    $q("reversed") .
                    " = 1 AND (" .
                    $q("reversed_at") .
                    " IS NULL OR " .
                    $q("reversed_position") .
                    " IS NULL OR " .
                    $q("reversed_position") .
                    " <= " .
                    $q("posted_position") .
                    " OR " .
                    $q("reversed_at") .
                    " < " .
                    $q("recorded_at") .
                    ")) OR (" .
                    $q("reversed") .
                    " = 0 AND (" .
                    $q("reversed_at") .
                    " IS NOT NULL OR " .
                    $q("reversed_position") .
                    " IS NOT NULL))",
                [],
                1,
            );
        }
        if ($bad !== []) {
            throw new ValidationException([
                [
                    "field" => "report",
                    "message" =>
                        "Historical financial reports need verified posting chronology. " .
                        "No partial report was returned. " .
                        "Ask a system administrator to review the reporting projection.",
                ],
            ]);
        }
    }
}
