<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** Recorded student-account amounts at a cutoff; identity and academic labels remain current. */
final class HistoricalFinancialReportSchema
{
    public static function extend(array $datasets): array
    {
        $accounts = $datasets["accounts"];
        unset($accounts["relationships"]["entry"], $accounts["relationships"]["balance"]);
        $accounts["fields"] = array_filter(
            $accounts["fields"],
            static fn(array $field): bool => !in_array(
                $field["relation"],
                ["entry", "balance"],
                true,
            ),
        );
        foreach (
            [
                "balance" => "Balance",
                "charges" => "Net charges",
                "payments" => "Payments",
                "refunds" => "Refunds",
            ] as $key => $label
        ) {
            foreach (["" => "double", "_minor" => "integer"] as $suffix => $type) {
                $accounts["fields"][$key . $suffix] = [
                    "label" => $label . " at cutoff" . ($suffix === "" ? "" : " in minor units"),
                    "type" => $type,
                    "relation" => "account",
                    "column" => "id",
                    "permissions" => [],
                    "expression" =>
                        $suffix === "" ? "historical_account_money" : "historical_account_minor",
                    "historical_measure" => $key,
                    "currency_field" => "currency",
                    "currency_relation" => "account",
                    "money_grain" => "account",
                ];
            }
        }
        $datasets["historical_accounts"] = array_replace($accounts, [
            "id" => "historical_accounts",
            "label" => "Student balances at a cutoff",
            "description" =>
                "One row per account opened by the selected inclusive UTC second. Amounts use recorded " .
                "posting/reversal times. Student identity, activity, program and billing-term labels are current. " .
                "This is not a due-date, bank-settlement or historical academic-status report.",
            "financial_as_of" => true,
            "source_scope" => "historical_accounts",
            "population_actions" => [],
        ]);
        $postings = $datasets["postings"];
        foreach (
            [
                "posting_reversed",
                "posting_payment_method",
                "posting_corrects",
                "posting_corrected_by",
            ] as $key
        ) {
            unset($postings["fields"][$key]);
        }
        $datasets["historical_postings"] = array_replace($postings, [
            "id" => "historical_postings",
            "label" => "Student postings active at a cutoff",
            "description" =>
                "One row per posting recorded and not yet reversed at the selected inclusive UTC second. " .
                "Later corrections and reversals do not remove earlier amounts here. Receipt methods and later " .
                "correction links are omitted; identity, academic and billing-term labels remain current.",
            "financial_as_of" => true,
            "source_scope" => "historical_postings",
            "population_actions" => [],
        ]);
        foreach (["historical_accounts", "historical_postings"] as $key) {
            $datasets[$key]["fields"]["cutoff_utc"] = [
                "label" => "Recorded cutoff UTC",
                "type" => "datetime",
                "relation" => $datasets[$key]["root"],
                "column" => "id",
                "permissions" => [],
                "expression" => "historical_cutoff",
            ];
        }
        return $datasets;
    }
}
