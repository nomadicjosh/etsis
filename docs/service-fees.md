# Application and official transcript fees

Institutions can require an application fee or an official transcript request fee. Both are optional and start at zero. Unofficial academic and continuing-education records remain free. A prospect can pay an application fee using their person/sign-in account before they have a student record or student account.

## Configure fees

In **Admissions**, **Students** or **Transcript requests**, open **Service fee policies**. The `sis:fees:configure` permission controls these settings.

For each service, choose the amount, currency and whether staff may grant an individual waiver with a recorded reason. Enter amounts in the displayed currency’s ordinary units: `25.00 USD`, `2500 JPY`, or `2.500 KWD`. Zero disables the fee for new assessments. Save each service’s policy separately. A concurrent policy change requires reloading instead of overwriting it.

The policy applies institution-wide. An assessment retains its quoted amount, currency and waiver policy. A later policy change applies to new assessments; it does not rewrite existing quotes, receipts, waivers or accepted requests. An outstanding existing quote remains payable under its retained terms even if new fees are subsequently disabled.

Finance can separately enable [service-fee accounting](service-fee-accounting.md) through **Accounting controls**. New quotes retain the reviewed income/liability/asset assignments and recognition policy; earlier unmapped quotes stay unchanged. Receipts, completed refunds and recognition then post source-linked journals atomically. Actual processor costs use a separate reviewed expense journal and never reduce the person’s payment.

## Applicant workflow

1. Sign in, select the academic program and entry term, and continue to the application. Complete any required email verification first.
2. If a fee applies, review the quote and choose **Pay securely**. The institution’s configured hosted Stripe checkout collects payment details.
3. Return to Applications and select **Resume application**, or choose the same program and term again. The quote and payment status come from the server. Use **Refresh payment status** if confirmation is still pending.
4. Once payment or an authorized waiver clears the fee, complete and review the application answers, then submit.

Payment precedes the application questions so leaving for checkout does not discard a long application draft. Neither answers nor checkout credentials are saved in browser storage or continuation URLs. Returning from checkout does not establish payment: the server requires a provider-authenticated confirmation matching the original payment, session, amount and currency.

The fee covers one accepted application for the selected program and entry term. An unsubmitted assessment is reused across tabs/reloads for the same person, linked payer and target. An accepted submission retains its original retry identity. A second, distinct application requires its own clearance when a fee applies. Staff-assisted form submissions and direct staff application creation use the same server gate.

## Official transcript workflow

Select the student record, delivery method and recipient when needed, then choose **Request official transcript**. A required fee appears before the request is accepted. After checkout, return to Transcripts, select the same student record and request the transcript again. The server reuses the pending assessment; it does not require another payment for that quote. Refreshing confirmed payment in the still-open request form resumes that request.

A cleared fee is consumed by one official request. The registrar still reviews the request, prepares a protected document, and records delivery through the existing [transcript workflow](transcript-worklists.md). Payment does not automatically send a transcript or override release policy. Canceling a request does not automatically refund money. Previously accepted requests are not charged retrospectively.

Unofficial record controls remain available while an official-request fee is outstanding. Academic and CEU transcript purposes remain separate. Fee support does not add an institution-designed template editor, external signatures or automatic delivery integrations.

## Staff receipts, waivers and completed refunds

Open the **Person record → More → Application and transcript fees**. Staff need both `sis:fees:manage` and the existing `sis:person:manage` permission. Configuring fees does not grant access to individual financial records, and owning an account does not grant staff authority. No roles receive these new permissions automatically.

The person drawer lists ten assessments at a time. Select **View fee**, or prepare an application/transcript assessment for that person. A transcript assessment must identify that person’s own student record. The staff-assisted application setup can open an outstanding assessment before displaying the questions.

- **Record received payment:** enter money actually received, its method, an optional receipt reference, and a reason. Partial receipts reduce the remaining balance but do not clear it prematurely. References are for receipts; never enter card numbers, bank account details or credentials.
- **Review fee waiver:** where the assessment’s retained policy permits waivers, enter the authorization/reason. A waiver can clear the remaining balance after a partial payment. The original charge and receipts remain recorded.
- **Record completed refund:** select the original receipt, amount, completed refund reference and reason. Complete the actual refund through the institution’s payment process first. This action records that completed refund; it does not call Stripe to send money. Cumulative refunds cannot exceed the original receipt.

Review the decision and confirm it within twenty minutes. A changed balance or lost staff authority requires fresh review. Returning from review preserves the draft fields. Retrying an already accepted decision returns its original receipt without adding another payment, waiver or refund.

A refund before submission can make an unwaived fee payable again. A refund after acceptance preserves the accepted application/request and its original fee clearance; it does not unsubmit the application, cancel the request, alter academic records or rewrite an issued transcript. Hosted card authorizations capture only the remaining fee balance. If staff already waived or applied the fee before reservation, the server cancels the authorization instead. Once capture is reserved, conflicting staff payments or waivers wait for verification. Earlier actual receipts remain recorded; unexpected external-provider captures retain their received-money evidence for institutional reconciliation.

Staff can review bounded decision history and recorded reasons. Owners receive their own assessments, payment/refund amounts and curated status/history; private staff reasons, receipt references and provider session identifiers are withheld. Relinking a person’s sign-in account does not expose the earlier payer’s assessments to the new account.

## Payment setup and recovery

Hosted payment uses the existing [portal payment configuration](campus-portal.md#optional-bill-payment): an explicitly assigned named connection for each fee purpose/currency, matching live/test mode, HTTPS application URL and authenticated webhook. [Connection setup](payment-gateways.md) retains the merchant and clearing assignment; mapped fee receipts debit that original clearing asset and use their quote’s recognition policy. Automated settlement and processor-fee ingestion remain separate work. Application checkout returns to Applications; transcript checkout returns to Transcripts. No live settlement was exercised by the isolated tests.

If online payment is unavailable, the quote remains pending and directs the owner to the institution. Authorized staff can record an actual external receipt or an allowed waiver, subject to any protected capture reservation. A provider timeout leaves recoverable work with the same idempotency identity. Expired attempts can be replaced, but local expiry cannot release an uncertain reservation. New and earlier attempts share the same protected outstanding-balance budget; duplicate notifications cannot post money twice. **Payment activity** beside the fee and in its detail drawer shows progress and lets an authorized owner or staff member request server verification. See [Balance-limited online payments](payment-capture.md) for worker setup, holds, cancellation and reconciliation.

The authoritative financial decisions are `service_fee` streams in the existing protected event journal: assessment, capture reservation/progress, actual receipt, waiver, application to an accepted service, and completed refund. Routing/hash/version rows are support projections. Policies remain audited CRUD. Source verification, person locks, the shared finance lock, expected versions, operation receipts, required audits and shared transactions protect the fee and its consuming application/request together. No earlier fees or payments are inferred from existing records. There is no new report source or second event table.

This milestone covers optional per-request fees and retained financial clearance. Automated provider refunds/disputes, institution-wide settlement reconciliation and further charge-adjustment operations remain later finance operations work.
