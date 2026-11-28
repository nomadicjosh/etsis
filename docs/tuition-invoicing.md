# Tuition schedules, pending charges and student invoices

Open **Student accounts → Tuition and invoices**. The same tool is available from **Term billing**, **Journals** and **Chart of accounts**. This workflow connects an institutional tuition schedule to accepted registrations, staff review, an issued invoice and balanced accounting entries.

A pending estimate does not change the student's account balance or the amount available for online payment. Confirming an invoice creates its actual charges. The student can review pending estimates and issued invoices in **My campus → Student accounts**, including before an account has been opened.

## Prepare and assign a schedule

1. Create a classified **income** account in **Chart of accounts**. Publish the currency's [accounting mapping and an open accounting period](student-ledger-accounting.md). The tuition income account must use the same currency and cannot be the receivable control account.
2. Open **Tuition and invoices → Tuition schedules → Create tuition schedule**. Enter its name, currency, income account and credit brackets. Supply an institutional reason, review the exact definition and confirm it.
3. Choose **Assign a tuition schedule**. Select a student, academic program or term, choose the published schedule, give a reason, review and confirm. Assignment is also available from the student record's **More** menu and program/term record actions.
4. Register courses through the ordinary staff, portal, grouped schedule or waitlist workflow. An accepted registration creates pending tuition and applicable fees in that student's term bill. Registration, its pending billing decision and required audit are atomic.

Assignment precedence is **student → current academic program → term**. The student assignment applies to that student across terms; the program assignment applies when that program is the student's primary program at the start of a new term bill. Clearing an assignment uses the next available fallback. A term assignment establishes the default for students without a higher-priority assignment.

An existing term bill retains the selected schedule, assignment, accounting publication and additional-fee definitions. Editing today's schedule or changing the student's primary program does not rewrite that bill. Before its first invoice, authorized staff can use **Review current schedule repricing** to deliberately adopt the current assignment and additional fees. The review becomes stale if those sources change before confirmation. Once any invoice is issued, the term keeps its retained pricing; use a reasoned account adjustment to correct issued charges.

## Credit brackets

Each bracket uses:

**Flat amount + amount per additional credit × academic credits above the specified threshold.**

Ranges include both endpoints. Cover **0 through 999.999 academic credits** exactly once, without gaps or overlaps, using up to twelve brackets. Amounts use the selected currency's decimal precision. Credit quantities support thousandths; the final calculated amount rounds once to the nearest minor currency unit, with halves rounded up.

An illustrative schedule, in a currency with two decimal places:

| From credits | Through credits | Flat amount | Rate per additional credit | Rate applies above |
| --- | --- | --- | --- | --- |
| 0 | 11.999 | 0.00 | 100.00 | 0 |
| 12.000 | 18.000 | 1,000.00 | 0.00 | 12.000 |
| 18.001 | 999.999 | 1,000.00 | 100.00 | 18.000 |

This example charges 300.00 for three academic credits, 1,000.00 for twelve through eighteen credits and 1,200.00 for twenty credits. It is an example of configuration, not a recommended institutional rate. A bracket can deliberately change the total at a boundary; inspect those boundaries before publishing.

Zero academic credits produce zero tuition. Auditing does not contribute academic credits; its accepted audit/section fee can still be pending. CEUs are a separate measurement and do not increase the tuition credit quantity. Accepted offering and student-selected academic credits supply the quantity; later academic corrections do not silently reprice an issued invoice.

## Additional fees and existing records

**Term billing** continues to define additional fees, either once per term or per registered academic credit. New tuition authoring uses **Tuition schedules**. Earlier tuition-rule definitions and their charges remain readable and can be retired; they are not converted into invented schedule publications. Resolve active earlier tuition rules before using a schedule for the same term/program.

For an assigned schedule, the first accepted registration retains applicable additional-fee definitions and their original accounting counterparts. A flat fee already posted to the term account is not charged again. Section fees retain the accepted section code, quantity and amount. Additional fees can be explicitly refreshed by repricing before the first invoice; they are frozen afterward. Publish the intended fees before accepting registrations.

A term without an assigned schedule or an existing term bill continues its existing immediate section/additional-fee workflow. Assigning a tuition schedule deliberately enables pending invoice pricing for that student's new term bill. It does not manufacture pending charges or historical registration facts from old balances.

Activation refuses to start a term bill alongside other already-recorded registrations in that term. Existing term registrations need a separate explicit opening-billing review; automatic historical conversion is outside this feature. Start with a new term or use the institution's existing reviewed manual account workflow for older work. Ordinary batch billing cannot bypass invoice review for a student term covered by a schedule or retained bill.

## Review and issue an invoice

1. Open a pending term bill. Inspect the student, term, registered academic credits, schedule and each pending line. These estimates are **not yet payable**.
2. Choose **Review and issue invoice**. Check the due date, which initially proposes thirty days from today. Select an explicit current or future date under institutional policy.
3. Adjust each line's amount as necessary. **Remove from this invoice** sets its amount to zero; **Restore quoted amount** restores the estimate. Give the reason for the complete billing decision.
4. Choose **Review billing decision** and inspect the quoted and proposed amounts. Editing any field discards the review. Confirm only after checking the complete invoice.

A zero line remains on the issued invoice as an explicit exclusion. Its quoted quantity is settled by that review, so the excluded or discounted amount does not silently reappear on the next invoice. A later course produces only new positive pending increments against the previously reviewed quote. An invoice containing only excluded lines creates no student account, money posting or GL journal.

For positive lines, confirmation commits the retained invoice, student-account charges, original income counterparts, balanced journals, invoice index and audit together. A missing/closed accounting period, stale registration/account/profile, expired review or failed required write rolls the entire new issue back. Pending charges remain available for a fresh review. An already accepted original confirmation can be retried after its twenty-minute review expiry without issuing or charging again; current authority still applies.

Each invoice retains its original student name and institutional ID, reference, issue time, due date, currency, quoted amounts, actual amounts and source pricing. Opening it later shows these issued facts. Account payments, credits and refunds affect the current statement separately. Staff can open the linked account with a return to the invoice. Staff and the verified student owner can print the private invoice or save it through the browser's Print action. Issued invoices are paged and retained; there is no public invoice URL or email attachment in this workflow.

## Registration changes and refunds

An early drop removes that registration from pending tuition/fees. If its course-linked section or per-credit fee was already invoiced, the existing exact reversal returns the actual issued amount, including a reviewed discount. It retains the original price basis and income counterpart. A section replacement reviews the pending replacement and makes the actual early-drop/account effects explicit.

Term tuition is one aggregate term charge. It is not apportioned into invented per-course charges. If invoiced term tuition exceeds the new estimate after a drop, the bill displays an adjustment warning for finance. Review the original account charge and record any authorized credit with a reason. This feature does not infer a tuition refund. A manual adjustment also does not reset the invoice's retained pricing baseline or silently create replacement charges.

Later withdrawals retain their accepted registration pricing. Existing course-linked [withdrawal credit schedules](withdrawal-credit-schedules.md) continue to apply within their documented scope. Tuition-specific default/student refund policies, pending refund-credit review, payment plans, automatic cash refunds, bulk invoice issuance and provider settlement remain separate roadmap work.

## Permissions, privacy and storage

Staff reads require both `sis:finance:read` and `sis:student:read`. Invoice review/issue additionally requires `sis:finance:post`. Publishing schedules, assigning them and repricing additionally require `sis:finance:ledger`. These duties are rechecked at confirmation and accepted retries; the feature does not grant roles automatically. Setup target lookups also respect their existing directory permissions.

Portal invoice reads always verify the actual person/account owner, including when the reader also has a staff role. Student responses omit private staff reasons, source publications, account assignments and profile-history references. Browser mutations retain framework CSRF; responses and printable invoices are private/no-store with debug collectors suppressed. Private bills, review tokens and invoices are not stored in browser storage.

Schedule publications, assignments, pending-registration changes and invoices use the existing protected event table. Four support tables provide verified current headers and invoice routing. They do not form a second authoritative event store. Per-term replay is bounded to 500 decisions, fifty retained courses, one hundred pending lines and a two-MiB retained state. Directory pages contain twenty records with a shared replay budget. These are interactive safeguards; larger historical conversion and bulk/background invoice runs require their own workflow.
