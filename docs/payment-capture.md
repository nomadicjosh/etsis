# Balance-limited online payments

Hosted Stripe card payments for student accounts, application fees and official transcript fees use authorization followed by a server-controlled capture. Card details stay in Stripe. An authorization can appear as a temporary hold at the card issuer; it is not a recorded SIS payment.

For example, a student opens checkout for USD 100.00. Staff then credit USD 25.00 before authorization completes. The server reserves and captures USD 75.00, records that payment and releases the remainder of the authorization. If nothing remains payable, it cancels the authorization and records no payment. Application and transcript fees use their own outstanding assessments, separately from a student's account.

## Read payment activity

- **Workspace:** open the student account record and choose **Payment activity**. Staff need `sis:finance:read`; requesting verification additionally requires `sis:finance:post`.
- **Portal:** open **Student accounts → Payment activity**. Only the signed-in account's own student accounts are available, including when that person also has staff duties.
- **Application and transcript fees:** use **Payment activity** beside the outstanding fee or inside its detail drawer. Staff use the existing person and service-fee management duties; owners can see only their own assessments.

The activity drawer has a return control, twenty-item pages, **Refresh payment activity**, and **Request verification** for unresolved attempts. Requesting verification queues work on the server. It does not contact the provider in the browser or authorize another capture amount.

| Status | Meaning and next step |
| --- | --- |
| Checkout open | No SIS payment is recorded. The owner can finish the hosted checkout. |
| Capture reserved | The server retained a maximum capture against the amount still owed. Verification or completion is in progress. |
| Funds received | The provider confirms money was received; the financial receipt still needs ledger posting. Student accounts should check the accounting period and setup. |
| Payment recorded | The protected receipt and applicable account journal are committed. |
| Authorization cancelled | The provider confirms cancellation with no money collected. Card-issuer release timing is outside the SIS. |
| Checkout expired | The working checkout expired. Expiry alone does not prove that an earlier authorization or capture was cancelled. Request verification for an unresolved attempt. |
| Provider reconciliation required | A verified provider outcome differs from the retained command. Student accounts must reconcile it; retrying cannot increase the retained maximum or initiate a new capture. |

Ordinary receipt corrections, charge credits, reversals, other payments and fee waivers cannot reduce the outstanding balance below an active capture reservation. A conflicting change receives an explanation to wait for verification or ask student accounts to review the activity. Once the payment is posted or zero-receipt cancellation is verified, normal finance workflows can proceed. There is no timer or staff shortcut that silently releases a reservation with an uncertain provider outcome.

## Enable server processing

Complete [named payment-connection setup](payment-gateways.md), purpose routing and the installation's existing scheduler setup before enabling new checkouts. The scheduler runs a single-instance `sis:payments:process --apply --limit=1` each minute. Operators can run that command through the normal application console; omitting `--apply` only displays usage. An explicit batch can contain one to three attempts. The worker starts no further attempt after its forty-second processing budget; individual provider calls have twelve-second request and four-second connection timeouts. Use the default batch for a small installation.

Signed Checkout notifications wake this same worker. Subscribe `/portal/payments/webhook/` to `checkout.session.completed`, `checkout.session.async_payment_succeeded` and `checkout.session.expired`. The server also schedules every attached checkout, so it can verify an attempt even when its notification is delayed. A return URL, notification alone or locally expired checkout never establishes that money was received.

The worker retrieves the session and payment authorization through an authenticated matching connection. It verifies the original session, merchant mode, payment reference, connection revision, amount and currency before making a decision. Under the shared finance lock, it reserves the lesser of the authorization and the unreserved amount still owed. Multiple attempts share that budget. It then captures that exact amount once, outside the database transaction. Stripe's [manual-capture documentation](https://docs.stripe.com/payments/place-a-hold-on-a-payment-method) describes the authorization boundary; the [capture API](https://docs.stripe.com/api/payment_intents/capture) supports the explicit partial amount and final capture that releases the remainder.

The new-payment deployment switch, route removal and connection disabling stop new checkout admission; they do not abandon earlier receipts. A reviewed current API key for the same immutable merchant/mode/currency can reconcile an earlier attempt after rotation. Keep the connection and a usable credential available until its outstanding attempts are resolved. Maintenance mode pauses processing; check outstanding authorizations promptly when normal operation resumes.

## Recover uncertain or delayed outcomes

A lost response leaves the reservation in protected account or service-fee history. A later run retrieves the provider state before retrying the same retained intent, amount and idempotency key. A leased operational queue coordinates workers; an expired lease or deleted/recreated job does not erase the protected reservation. Restart the scheduler or use **Request verification** to resume eligible work. Do not create another capture manually in the provider dashboard to resolve a timeout.

After authenticated observation, received money is retained in protected history before the separate posting transaction. A missing or closed accounting period can therefore leave **Funds received** visible without losing that fact or taking another payment. Finance resolves the applicable period/setup boundary, then requests verification or lets the queued worker retry. Posting commits the account payment, balanced gross clearing/receivable journal, paid checkpoint and required audit together. Service fees commit their own protected receipt and clearance, plus [source-linked journals](service-fee-accounting.md) when their quote retains an accounting map. A delayed fee posting uses this same received-money recovery boundary. Automated settlement remains separate roadmap work.

An unexpected capture performed outside this workflow, a provider result above its reserved maximum, or another inconsistent outcome is retained as **Provider reconciliation required**, including the verified received amount. The SIS does not discard that evidence, increase its command or automatically convert it into an account overpayment. Staff must reconcile the external activity and any actual refund through the institution's approved process. Automated refund/dispute and exception-disposition tools remain separate work. This implementation cannot prevent someone with independent provider access from collecting money outside the SIS.

## Historical and privacy boundaries

Capture plans and observations use the existing protected account/service-fee streams in the one authoritative event journal. The separate ULID work table contains only payment identity, scheduling state, lease and version. Financial history retains credential-free connection/clearing references, not provider responses or secrets. The checkout's copied API key stays encrypted until paid or cancelled; its signing and financial evidence remain available under the institution's reconciliation and retention policy.

Private owner/staff activity exposes only amounts, currency, curated state and available actions. It withholds credentials, upstream payloads and provider session/intent identifiers. Browser mutations retain CSRF protection; responses are private/no-store and activity is not saved in browser storage. There is no new report, notification category, journal table or inferred historical payment.

Migration `20261128000001_CreateSisPaymentWork` refuses unfinished earlier automatic-capture attempts before introducing this workflow. Reconcile them explicitly with the provider before upgrading. Already paid rows and protected events remain unchanged. Do not relabel earlier automatic sessions as manual authorizations or delete their evidence to bypass the gate.

The balance ceiling is implemented for every currently enabled hosted payment purpose: Stripe credit/debit cards. Future ACH or other provider adapters must implement and qualify an equivalent coordinated collection boundary before being enabled; manual card capture is not an ACH implementation. Automated tests use fictional providers and an injected official SDK transport. Live Stripe test-mode end-to-end acceptance, institutional reconciliation procedures, refunds/disputes, bank settlement, production load and independent release qualification remain required before live use.
