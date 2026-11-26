# Student-account charge breakdowns

Use the existing **Student account balances** source in Report Studio to put net tuition and fee amounts on the same account row. No new dataset or report type is required. The optional **Student balances at a cutoff** source offers the same breakdown for recorded posting activity through an inclusive UTC second.

## Build a current breakdown

1. Open Report Studio and choose **Student account balances**, or edit a report already using that source.
2. Keep **Account reference**, **Billing term** and **Currency** to identify the grain. Add **Net tuition**, **Net fees**, **Net staff-recorded charges** and **Net unclassified charges** from the account's field card. The corresponding **in minor units** fields provide integers in the account currency's smallest unit.
3. Preview the report, then save it through the ordinary report controls. A dynamic report reflects later accepted financial decisions; a static capture keeps its earlier result.

Each component is aggregated independently for one account. Two fee postings and three payments do not turn into six copies of either amount. These account fields do not require joining individual financial postings.

| Field | Retained sources included |
| --- | --- |
| Net tuition | Term tuition and per-credit tuition |
| Net fees | Section fees, term fees and per-credit fees |
| Net staff-recorded charges | Explicitly classified manual charges, including their later replacements |
| Net unclassified charges | Charges whose retained source is absent; no classification is inferred from descriptions or today's billing rules |

The four amounts reconcile to net **Charges**. A linked charge credit reduces its original source category. Reversed charges and their coupled credits are excluded from current amounts. Amount corrections retain their original source and count the active replacement. An opened account with no applicable postings has zero components; no account is created by a report.

Payments and refunds remain separate totals. This breakdown does not allocate a payment across tuition and fees, establish a due date, determine financial aid or certify bank settlement. Staff-recorded does not mean tuition or fee; use the retained source rather than guessing from a posting's text.

## Compare recorded cutoff amounts

Choose **Student balances at a cutoff** and set **Recorded activity through (UTC, inclusive second)**. Add the component fields labelled **at cutoff**. A posting contributes only if it was recorded by that second and had not been reversed by then. A later correction, linked credit or reversal preserves earlier cutoff results. An account opened after the cutoff is absent.

These are recorded posting times, not reconstructed original receipt/effective dates. Student identity, activity, program and billing-term labels remain current. Current account navigation does not turn those labels into historical evidence. Both current and cutoff definitions retain their meaning through existing encrypted saves, shares, static captures and complete background exports.

## Summaries and related records

Keep **Currency** as an ordinary output column when showing or summarizing amounts. USD, JPY and three-decimal currencies retain their own precision; minor-unit integers are not universally cents. Compatible additions/subtractions use the same currency and units. A custom label cannot replace the actual Currency dimension for a monetary summary.

For example, group by Billing term and Currency and sum Net tuition and Net fees. To select accounts with a payment, use a matching-record condition on Financial postings. This filters accounts without narrowing their charge components to the matching payment row. Ordinary joined detail can repeat account amounts; summaries reject joins that multiply account grain unless the related-record selection explicitly chooses one row. Do not sum repeated detail outside the engine as though it were an account summary.

Existing finance, student-data and reporting duties are required, including when an amount is used only in a condition or a report is shared. Cutoff reports retain their existing restriction against student-action population handoffs. Posting/balance readiness checks refuse incomplete projections; report reads never replay the journal, repair projections, change charges or send messages. Use authorized financial decisions and support procedures for corrections. Query/compiler checks and bounded PostgreSQL inline-row qualification do not certify every native engine or production load.
