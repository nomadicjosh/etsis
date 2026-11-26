<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Finance\Enum\ChargeSource;
use Infrastructure\Persistence\SqlRecords;

/** Indexed account-local aggregation, independent of every joined posting/payment branch. */
final readonly class AccountChargeReportSql
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function amount(string $accountAlias, string $measure, ?string $cutoff = null): string
    {
        $sources = match ($measure) {
            'tuition' => [ChargeSource::TermTuition, ChargeSource::CreditTuition],
            'fees' => [ChargeSource::Section, ChargeSource::TermFee, ChargeSource::CreditFee],
            'manual_charges' => [ChargeSource::Manual],
            'unclassified_charges' => [],
            default => throw new \LogicException('Unknown account charge component.'),
        };
        $q = $this->sql->quote(...);
        $alias = 'charge_component_entry';
        $column = static fn(string $name): string => $q($alias) . '.' . $q($name);
        $active = $cutoff === null ? $column('reversed') . ' = 0'
        : new HistoricalFinancialReportSql($this->sql)->active($alias, $cutoff);
        // These literals come only from the closed source enum, never an editor-supplied expression.
        $source = $column('charge_source') . ($sources === [] ? ' IS NULL' : ' IN (' . implode(', ', array_map(
            static fn(ChargeSource $kind): string => "'" . $kind->value . "'",
            $sources,
        )) . ')');
        return '(SELECT COALESCE(SUM(' . $column('effect_minor') . '), 0) FROM ' . $q('sis_account_entries')
        . ' ' . $q($alias) . ' WHERE ' . $column('account_id') . ' = ' . $q($accountAlias) . '.' . $q('id')
        . ' AND ' . $column('type') . " IN ('charge', 'charge_credit') AND " . $active . ' AND ' . $source . ')';
    }
}
