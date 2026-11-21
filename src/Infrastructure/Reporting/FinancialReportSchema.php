<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Finance\Enum\PaymentMethod;
use Domain\Finance\Enum\ChargeSource;

final class FinancialReportSchema
{
    /** @param array<string,array<string,mixed>> $datasets
     * @return array<string,array<string,mixed>> */
    public static function extend(array $datasets): array
    {
        $accounts = $datasets['accounts'];
        $field = static fn(string $label, string $type, string $column): array => [
            'label' => $label, 'type' => $type, 'relation' => 'entry', 'column' => $column, 'permissions' => [],
        ];
        $fields = [
            'posting_id' => $field('Posting reference', 'string', 'id'),
            'posting_operation' => $field('Account operation reference', 'string', 'operation_id'),
            'posting_type' => $field('Posting type', 'string', 'type') + ['choices' => [
                'charge' => 'Charge', 'payment' => 'Payment', 'refund' => 'Refund', 'charge_credit' => 'Charge credit',
            ]],
            'posting_reversed' => $field('Posting reversed', 'boolean', 'reversed'),
            'posting_charge_source' => $field('Retained charge source', 'string', 'charge_source') + [
                'choices' => array_combine(
                    array_column(ChargeSource::cases(), 'value'),
                    array_map(static fn(ChargeSource $source): string => $source->label(), ChargeSource::cases())
                ),
            ],
            'posting_payment_method' => $field('Current receipt method', 'string', 'payment_method') + [
                'choices' => array_combine(
                    array_column(PaymentMethod::cases(), 'value'),
                    array_map(
                        static fn(PaymentMethod $method): string => $method === PaymentMethod::PayPal ?
                            'PayPal' : ucwords(str_replace('_', ' ', $method->value)),
                        PaymentMethod::cases()
                    ),
                ),
            ],
            'posting_recorded_at' => $field('Posting event recorded UTC', 'datetime', 'recorded_at'),
            'posting_position' => $field('Posting account sequence', 'integer', 'posted_position'),
            'posting_origin' => $field('Recorded posting origin', 'string', 'origin') + ['choices' => [
                'staff' => 'Staff recorded', 'staff_correction' => 'Staff amount correction',
                'registration_rate' => 'Recorded registration rate', 'charge_credit' => 'Linked charge credit',
                'unclassified' => 'Earlier or unclassified source',
            ]],
            'posting_corrects' => $field('Corrects account operation', 'string', 'corrects_operation_id'),
            'posting_corrected_by' => $field('Corrected by account operation', 'string', 'corrected_by_operation_id'),
            'posting_source' => $field('Credited charge operation', 'string', 'source_operation_id'),
        ];
        foreach (['amount' => 'Posting amount', 'effect' => 'Posting balance effect'] as $name => $label) {
            $fields['posting_' . $name] = $field($label, 'double', $name . '_minor') + [
                'currency_field' => 'currency', 'currency_relation' => 'account',
                'expression' => 'account_money', 'money_grain' => 'entry',
            ];
            $fields['posting_' . $name . '_minor'] = $field($label . ' in minor units', 'integer', $name . '_minor') + [
                'currency_field' => 'currency', 'money_grain' => 'entry',
            ];
        }
        $related = $accounts['relationships'];
        unset($related['balance']);
        $accountFields = array_filter($accounts['fields'], static fn(array $f): bool =>
            !isset($f['currency_field']) && $f['relation'] !== 'balance');
        $datasets['postings'] = [
            'id' => 'postings', 'root' => 'entry', 'table' => 'sis_account_entries',
            'label' => 'Financial postings',
            'description' => 'One row per original or replacement posting, including reversed entries. '
                . 'Filter Posting reversed to No for current totals. Times record the posting event, not bank '
                . 'settlement. Account operation references are unique only within their account.',
            'permissions' => $accounts['permissions'], 'student_population' => true,
            'financial_balances' => true, 'financial_entries' => true,
            'relationships' => ['account' => [
                'table' => 'sis_student_accounts', 'parent' => 'entry', 'left' => 'account_id', 'right' => 'id',
                'label' => 'Student accounts', 'permissions' => [],
            ]] + $related,
            'fields' => $fields + $accountFields,
        ];
        $datasets['accounts']['financial_entries'] = true;
        $datasets['accounts']['relationships']['entry'] = [
            'table' => 'sis_account_entries', 'parent' => 'account', 'left' => 'id', 'right' => 'account_id',
            'many' => true, 'label' => 'Financial postings', 'permissions' => [],
        ];
        $datasets['accounts']['fields'] += $fields;
        return $datasets;
    }
}
