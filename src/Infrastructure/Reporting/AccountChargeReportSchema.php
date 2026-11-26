<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use function Codefy\Framework\Helpers\trans_html;

/** Independent net charge amounts at account/currency grain, never posting-row totals. */
final class AccountChargeReportSchema
{
    public static function fields(bool $historical = false): array
    {
        $labels = $historical ? [
            'tuition' => [trans_html('Net tuition at cutoff'), trans_html('Net tuition at cutoff in minor units')],
            'fees' => [trans_html('Net fees at cutoff'), trans_html('Net fees at cutoff in minor units')],
            'manual_charges' => [
                trans_html('Net staff-recorded charges at cutoff'),
                trans_html('Net staff-recorded charges at cutoff in minor units'),
            ],
            'unclassified_charges' => [
                trans_html('Net unclassified charges at cutoff'),
                trans_html('Net unclassified charges at cutoff in minor units'),
            ],
        ] : [
            'tuition' => [trans_html('Net tuition'), trans_html('Net tuition in minor units')],
            'fees' => [trans_html('Net fees'), trans_html('Net fees in minor units')],
            'manual_charges' => [
                trans_html('Net staff-recorded charges'), trans_html('Net staff-recorded charges in minor units'),
            ],
            'unclassified_charges' => [
                trans_html('Net unclassified charges'), trans_html('Net unclassified charges in minor units'),
            ],
        ];
        $fields = [];
        foreach ($labels as $key => [$major, $minor]) {
            foreach (['' => $major, '_minor' => $minor] as $suffix => $label) {
                $fields[$key . $suffix] = [
                    'label' => $label, 'type' => $suffix === '' ? 'double' : 'integer',
                    'relation' => 'account', 'column' => 'id', 'permissions' => [],
                    'currency_field' => 'currency', 'currency_relation' => 'account', 'money_grain' => 'account',
                ] + ($historical ? [
                    'historical_measure' => $key,
                    'expression' => $suffix === '' ? 'historical_account_money' : 'historical_account_minor',
                ] : [
                    'charge_component' => $key,
                    ...($suffix === '' ? ['expression' => 'account_money'] : []),
                ]);
            }
        }
        return $fields;
    }
}
