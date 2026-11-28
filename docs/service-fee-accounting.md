# Service-fee income and processing expenses

Open **Student accounts → Accounting controls** and select the currency. **Service-fee accounting** connects application and official transcript fees to the existing general ledger. Fee quotes remain separate from student accounts, so a prospect can pay and submit an application before becoming a student.

## Prepare and review the mapping

1. Prepare the classified accounts in **Chart of accounts**, then publish the currency's [accounting mapping](student-ledger-accounting.md) and open an accounting period. The student receivable control account cannot be selected for fee accounting.
2. Choose **Review service-fee accounting**. This requires both `sis:finance:ledger` and `sis:fees:configure`. Existing permissions are reused; no role receives another grant automatically.
3. Select the accounts below. Every selection must have the indicated classification and the same currency.

| Selection | Classification | Use |
| --- | --- | --- |
| Staff receipt and refund asset | Asset | Clearing/cash counterpart for staff-recorded money and its completed refunds. Choose an institutional clearing asset if external payment methods settle through different accounts. |
| Unearned fees liability | Liability | Holds collected amounts awaiting recognition, and any amount received above the quoted fee. |
| Processing expense account | Expense | Default debit for reviewed actual processor costs. |
| Application fee income | Income | Recognized application fee receipts. |
| Transcript fee income | Income | Recognized official transcript fee receipts. |

The two income selections may share an account. Assets, liability, expenses and income must otherwise be distinct. Earlier unclassified accounts must be explicitly classified through the existing chart/gateway setup workflow before selection.

4. Choose **Recognize fee income**. The default is **When the application or transcript request is accepted**. Alternatively, choose **When money is received**. The finance office must approve the setting under the institution's accounting policy. Acceptance means the SIS accepts the application or official transcript request; it does not mean admission, transcript delivery or completion of an educational service. This setting does not provide recognition schedules or a fulfillment-based revenue engine. The [IFRS Foundation's revenue guidance](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-15-revenue-from-contracts-with-customers/) illustrates why receipt and recognition are separate decisions; selecting a software option does not establish compliance with an accounting standard.
5. Enter a reason, review the account codes/titles and recognition policy, then confirm. Editing a field invalidates the review. A changed publication or chart label requires fresh review; the ordinary draft stays available. Reviews expire after twenty minutes. Original accepted retries retain their result, with current duties checked again.

The mapping applies prospectively to new nonzero fee quotes. Each quote retains the exact published policy and accounts. Changing the map, fee policy, gateway route or current account labels does not move an earlier receipt. Updating the separate student-account mapping preserves service-fee setup.

Earlier quotes without accounting evidence remain unmapped, even if paid after activation. The staff fee drawer says so. Their original assessments, receipts and events remain unchanged. An institution with earlier activity needs an explicit reviewed opening-position/conversion decision; this workflow does not guess prior GL entries or retroactively assign today's map. Reconcile that boundary before treating the GL as a complete institutional fee ledger.

## Understand the automatic entries

A quote creates no GL charge or receivable. A waiver alone creates no revenue. Actual receipts, completed refunds and applicable recognition create balanced journals in the same transaction as their protected fee decision.

For a USD 25.00 fee with the default acceptance policy:

| Decision | Debit | Credit |
| --- | --- | --- |
| Receive 25.00 | Original receipt asset: 25.00 | Unearned fees: 25.00 |
| Accept the application/request | Unearned fees: 25.00 | Selected fee income: 25.00 |
| Complete a 10.00 refund after recognition | Selected fee income: 10.00 | Original receipt asset: 10.00 |

Under receipt-time recognition, the initial receipt credits income directly, up to the quoted amount. Excess receipts stay in the liability account. A refund first reduces excess liability as applicable, then recognized income. Before acceptance under the default policy, refunds reduce the liability. Recognition is always limited to actual net collected money and the original quoted fee. For example, a USD 10.00 receipt plus an authorized waiver of the remaining USD 15.00 recognizes only USD 10.00 when accepted; it does not invent income for the waived balance.

Online receipts debit the **clearing asset retained by their checkout**, overriding the staff receipt asset. Staff receipts use the asset retained by their quote. Refund entries use the original receipt's asset. These are gross accounting entries; recording a completed refund does not ask Stripe or a bank to send money. Subsequent bank transfers, payouts, processor charges and fee credits require their own reconciled accounting effects.

In **Journals**, an automatic fee journal offers **Open service fee** when the staff member also has the existing person/fee management duties. The fee opens with a **Back to journal** control; the journal retains its return to period verification. Automatic journals cannot be reversed independently of their fee source. Existing completed-refund workflows remain available; further fee correction/exception-disposition tools are separate work.

## Record an actual processor cost

In **Accounting controls → Service-fee accounting**, choose **Record processing expense**. This requires `sis:finance:ledger`.

1. Enter the positive amount actually charged in the displayed currency's ordinary units, such as `1.50 USD`. The server uses integer minor units.
2. Select the classified asset actually charged, such as the payment's original processor clearing asset. It must match the currency and cannot be student receivables.
3. Supply a unique uppercase accounting reference and the exact accounting date. Put the original statement transaction reference and an explanation in the reason. The existing journal reference uniqueness rule rejects duplicate accounting references; review the statement to avoid recording the same charge under a different reference.
4. Review both accounts, amount, date, reference and reason, then confirm. The journal debits the configured expense account and credits the selected asset. A stale or expired review needs renewal. A confirmed retry returns the same journal, including after review expiry or subsequent period activity.

Use actual statement amounts rather than estimated card percentages. Stripe exposes separate gross `amount`, charged `fee` and resulting `net` fields in its [balance-transaction documentation](https://docs.stripe.com/api/balance_transactions/object). This screen records an institution-reviewed expense; it does not retrieve those records or prove that a payout arrived. For example, a USD 100.00 student payment remains a USD 100.00 receipt. A separately recorded USD 3.00 processing cost debits expense and credits clearing by USD 3.00, without reducing the student's payment or adding a student charge.

The result opens its journal with return navigation to Accounting controls. Use the existing reviewed journal reversal when an expense entry needs to be reversed, preserving the original. A fee refund does not automatically reverse a processor expense; use the provider's actual statement and approved institutional process for any fee credit.

## Periods, history and recovery

The existing shared finance lock, expected versions, source checks, required audits and transactions govern these effects. A missing/closed accounting period blocks a new receipt/refund/recognition/expense posting atomically. A failed recognition does not partially accept an application or official request. Resolve the period boundary and retry the same action; preserve the application draft.

For an already captured online payment, [Payment activity](payment-capture.md) retains **Funds received → Awaiting ledger posting** and its protected reservation. Opening the applicable period and resuming the server worker posts the fee and journal without collecting again. Ordinary fee quotes and nonmonetary authorization progress do not require a money journal.

Period verification includes each fee journal, its exact protected source decision, original mapping, amounts and accounting date. It does not count fees as student receivables. Verification replays only the selected bounded fee prefix; it does not run event-store analytics or rewrite previous periods. Staff and owners keep their existing independent access boundaries, and owners receive no internal chart assignments or private reasons.

No new event table, financial backfill, report, notification, database migration or role grant is introduced. Book publications, fee decisions and journal/period facts use the existing protected storage. Live provider settlement/reconciliation, automated processor-fee ingestion/refunds/disputes, multi-currency conversion, earlier opening positions and independent native/load/recovery acceptance remain separate roadmap work.
