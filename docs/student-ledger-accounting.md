# Student-account accounting and period closing

Open **Student accounts → Accounting controls**. The same tool is available from **Journals** and **Chart of accounts**. It connects future student-account transactions to balanced general-ledger journals and provides reviewed accounting-period setup, reconciliation, closing and reopening.

Automatic mapping starts off for every currency. Publish a mapping deliberately before opening student accounts or posting journals in that currency. The current activation workflow supports a currency with no existing financial records; an institution with existing accounts or journals needs a separate reviewed opening-position conversion. It does not infer accounting entries from old balances or silently adopt earlier transactions.

[Tuition and invoices](tuition-invoicing.md) uses these accounting controls: assigned schedules retain classified tuition income and additional-fee counterparts, registration creates pending estimates, and invoice confirmation posts the reviewed charges and balanced journals together. Pending estimates are excluded from account balances and online payment amounts. Tuition-specific refund policies and installment plans remain separate roadmap work.

## Set up the accounts and mapping

1. In **Chart of accounts**, create the counterpart accounts with their institutional codes, titles and currencies. For a simple example, these might be a tuition counterpart and a bank account. Select the appropriate account classification when creating each account. The finance office determines its recognition policy. Earlier unclassified accounts can be reviewed through [Payment gateways → Clearing accounts](payment-gateways.md#prepare-the-chart-of-accounts).
2. Open **Accounting controls**, select the currency, and choose **Review mapping setup**.
3. Enter the accounting timezone and the proposed accounts-receivable code/title. Activation creates this currency's accounts-receivable control account. It cannot subsequently be disconnected or replaced; its code and title can be corrected through a later reviewed mapping.
4. Select the default charge counterpart, payment counterpart and refund disbursement counterpart. Every account must have the same currency, and a counterpart cannot be the receivable control account.
5. Expand **Optional source and payment-method mappings** when needed. Charges can have separate counterparts for staff entries, section fees, term tuition/fees and per-credit tuition/fees. Payment-method overrides include cash, check, card, bank/wire transfer, money order, PayPal, loan, scholarship, grant and other. Blank overrides use the selected default.
6. Enter a reason, review the complete selection, and confirm. Editing any field removes the pending confirmation and requires another review.
7. Open an accounting period covering today's date in that timezone before recording student transactions.

Mapping publication and accounting setup require `sis:finance:ledger`. Current account labels are checked again at confirmation. Changing a mapping does not move earlier postings. Accepted publications retain the original account identities, codes, titles and timezone; the current chart labels shown on a journal are explicitly labeled as current reference data.

The charge counterpart does not have to mean immediately earned tuition revenue. An institution may need deferred revenue or another account under its accounting policy. A payment method labeled loan, grant or scholarship records a receipt classification; it does not establish an aid award, approve eligibility or automatically choose an aid liability account. Those are separate financial-aid workflows.

## How student activity reaches the ledger

The SIS creates a balanced journal in the **same database transaction** as each new monetary student-account decision. Registration charges, term billing, staff postings, charge credits, corrections, refunds and reversals use the shared account writer. A failure in either side, its required audit or the period checkpoint rolls the operation back together. A retry returns its original result without a second charge or journal.

For a simple bank/revenue configuration:

| Student transaction | Debit | Credit |
| --- | --- | --- |
| Charge of 100 | Accounts receivable: 100 | Selected charge counterpart: 100 |
| Payment of 120 | Selected payment counterpart: 120 | Accounts receivable: 120 |
| Refund of 10 | Accounts receivable: 10 | Selected refund counterpart: 10 |

This example leaves a student credit balance of 10. These are recorded accounting effects; recording a refund does not itself send money through a bank or gateway. The distinction between student financial transactions and their general-ledger accounting lines is also described in [Oracle's Student Financials GL interface documentation](https://docs.oracle.com/cd/F85282_01/cs92pbr30/eng/cs/lssf/UnderstandingGLInterfaceProcessing-ab5030.html).

Corrections preserve their original lineage:

- A charge credit reduces receivables against the original charge counterpart.
- A monetary correction offsets the original recorded amount and posts its replacement against that original counterpart, even after a mapping change. Both original and correction journals remain retained.
- Reversing a charge also offsets its active linked charge credits. Reversal uses the recorded source amounts and original counterpart.
- Changing only a receipt's method/reference does not move money or remap the earlier journal.
- An automatic journal cannot be reversed directly from **Journals**. Use **Open student account** and its existing reviewed correction, credit or reversal workflow.

New automatic journals use the current acceptance date in the published accounting timezone. The original source's business effective date remains separate. For example, a withdrawal effective in August but accepted in September can have its financial adjustment recorded in the open September accounting period without changing the closed August ledger. Academic effective dates, original charges and retained financial history remain unchanged.

Manual journals retain their explicitly selected accounting dates. Once mapping is enabled, manual journals cannot post to the student receivable control account; use the student account so the two sides remain connected. Manual journals and their permitted reversals participate in period verification. A closed period blocks both new dated manual postings and reversal of its manual journals. An institution can deliberately reopen a period under the separate closing authority when required.

For a configured online student-account receipt, the [checkout's retained gateway clearing asset](payment-gateways.md) overrides the generic payment counterpart. It records the gross processor receipt, with a credit to receivables. Changing the connection or default payment mapping cannot move that earlier receipt or its linked reversal/correction. [Service-fee income/liability and actual processor-expense journals](service-fee-accounting.md) use the same period and verification controls while remaining separate from student receivables. Automated bank settlement and fee ingestion remain separate work.

[Online card capture](payment-capture.md) reserves the balance still owed before collecting funds. Reservation and provider-observation events do not create money entries or GL journals; they protect the budget and retain actual received-money evidence. Receipt posting releases the reservation and commits the payment/journal together. Missing or closed periods leave verified funds in **Funds received → Awaiting ledger posting**, rather than losing the observation or requesting another payment. Resolve the period/setup boundary and resume server verification through **Payment activity**. While a reservation is active, conflicting credits, receipts, corrections and reversals cannot reduce its available balance.

## Open an accounting period

Choose **Open an accounting period**, enter its label and inclusive first/last accounting dates, record a reason, review and confirm. Periods in the same currency cannot overlap. Their dates are independent of academic terms, so do not use term codes as accounting-period identities.

If today's accounting date has no open period, a new automatic student posting cannot complete. This also protects registration or billing when it has a financial effect. The response explains that the finance office must resolve the accounting period; no enrollment, partial charge or partial ledger journal is accepted by that failed transaction. Account readers and earlier accepted retries remain available.

## Reconcile and close

1. Choose **Reconcile period**. Both `sis:finance:ledger` and `sis:finance:read` are required.
2. Review the verified journal page and net student-receivable movement. **View journal** opens its actual lines; **Back to reconciliation** returns to the verification. Automatic journals link to their student account without exposing the protected source event; **Back to journal** preserves the reconciliation return.
3. Use **Verify next entries** until the complete period is verified. Progress and its continuation stay in page memory, with no private browser-storage copy. Verification appends no financial event and repeats no business action.
4. With `sis:finance:close`, choose **Review period closing**, enter the reason, inspect the dates/action and confirm. The ledger and student-finance read duties are also required at confirmation.

Verification compares each retained journal with its protected source and the period's authenticated movement count, ordered fingerprint and receivable total. It detects missing/reordered routing rows, altered sources, wrong retained mappings and inconsistent amounts. Manual activity is checked too, while student receivable movement remains separately identified. These are private operational checks, not a new Report Studio dataset or a source of raw event payloads.

Every new posting or manual reversal advances the period checkpoint. If activity changes during verification or before closing confirmation, start verification again; a stale confirmation cannot close an unverified period. An already accepted confirmation remains retry-safe after later changes or review expiry.

Closing retains the period and its verified checkpoint; it never deletes financial history. For an authorized correction to an earlier period, choose its **More accounting actions → Review period reopening**, record a reason, review and confirm. Reopening preserves the earlier closing decision and requires a fresh verification before another close. An ordinary student-account correction posted today can instead affect today's open period while retaining its older source.

## Operational and historical boundaries

There is still one authoritative protected domain-event table. Book publications, period decisions/checkpoints and the existing account/journal facts use separate streams in that same store. New ULID routing tables support bounded lookup and reconstruction; they are not independent monetary authorities. Commands serialize through one installation-local finance gate, with expected stream/projection versions and atomic receipts. Ordinary reads do not take that gate.

Verification processes at most twenty movements per request and can return a smaller page near its time budget. Linked-credit reversal verification uses the protected account prefix once, with at most 2,000 events, an eight-MiB payload budget and a three-second allowance. No read runs unbounded event-table analytics. Period totals use exact integer arithmetic, including totals larger than a single transaction limit. The current book catalog permits at most 1,000 nonoverlapping periods. Production throughput and native locking/recovery acceptance remain release-qualification work.

Initial activation refuses existing financial records, including a retained financial stream whose routing row is missing. An opening-position workflow must explicitly establish any previous receivable and counterpart position before later activation; that conversion remains open. No earlier actor, journal, mapping or opening balance is manufactured.

External GL exports, bank settlement, automated gateway reconciliation, financial-aid awards and revenue-recognition schedules remain separate roadmap work. [Service-fee accounting](service-fee-accounting.md) now supports prospective receipt/recognition/refund journals and reviewed actual processor costs; earlier unmapped fees need explicit opening-position handling. It adds no operational/workforce reports or student notification category.

Selected qualification covers fictional SQLite workflows, actual framework/HTTP providers, CSRF/private/no-debug responses, five-dialect schema compilation, browser reflow/accessibility and the additive installed PostgreSQL migration. It is not a claim of full native business-write, production load, recovery or independent accessibility certification.
