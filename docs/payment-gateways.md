# Payment connections and clearing accounts

Open **Student accounts → Payment gateways**. The tool is also available from **Journals**, **Chart of accounts**, and their record drawers. A staff member needs both `sis:payments:configure` and `sis:finance:ledger`; creating the permission definition does not grant it to a role or account.

This workflow supports named Stripe connections for hosted credit/debit card payments. Assign a connection independently to **Student account · Tuition and fees**, **Application fee**, or **Official transcript fee**, for each currency. Multiple connections can use different merchants or clearing accounts. ACH, Authorize.net, PayPal, donations, payment plans and third-party payer access remain separate roadmap work.

## Prepare the chart of accounts

1. Open **Clearing accounts**, choose the three-letter currency, and select **Create clearing asset**. Enter the institutional account code, title and a reason; review and confirm.
2. Alternatively, create an account in **Chart of accounts** and select **Asset** as its classification. The available classifications are asset, liability, equity, income and expense.
3. Earlier accounts keep an unknown classification. In the clearing-account directory, use **Classify existing account** to review its classification. This changes reference classification, without creating or changing earlier journals. A saved classification is immutable; use a separate account for another financial purpose.
4. For student-account payments, publish the currency's [accounting mapping](student-ledger-accounting.md) and open an accounting period before accepting payments. The receivables control account cannot be a gateway clearing asset. An installation with earlier financial records needs the separately planned, reviewed opening-position conversion before mapping activation.

A processor clearing asset represents the gross money held or received through that processor. The institution's finance office chooses its actual chart and recognition policy. Calling an account a bank account does not initiate a payout or prove that a payout arrived.

For example, a verified USD 100.00 student-account payment debits the **clearing asset retained by its checkout** and credits that currency's accounts-receivable control account. This assignment takes precedence over the generic payment/method counterpart for that receipt. Later corrections/reversals retain the original source counterpart. A subsequent bank deposit, processor expense or settlement adjustment is a separate accounting decision; this workflow does not infer a net deposit or a processing fee.

Application and transcript fees retain their own protected service-fee assessments and receipts. Their checkout retains the selected connection/clearing assignment, but this milestone does not automatically journal those service fees into the student-account GL. Service-fee income, processing-expense mappings and settlement reconciliation remain open.

## Verify a named Stripe connection

1. Keep the installation switch `PORTAL_PAYMENTS_ENABLED=false` while preparing the setup. Configure an HTTPS `APP_BASE_URL`. Existing environment API-key, webhook-secret and live-mode entries are no longer read; credentials now belong to named connections.
2. In Stripe, register the installation's HTTPS `/portal/payments/webhook/` endpoint. Select snapshot notifications for `checkout.session.completed` and `checkout.session.async_payment_succeeded` in the intended merchant and mode. Use the corresponding signing secret. Connections sharing the same merchant/mode and endpoint use that endpoint's signing secret; rotate it on every connection that uses the endpoint. See Stripe's [webhook setup and signature guidance](https://docs.stripe.com/webhooks).
3. Choose **Connections → Add Stripe connection**. Enter a clear name, currency, live/test mode and classified clearing asset. Provide the secret API key and webhook signing secret. The API key must permit reading its own merchant and creating Checkout sessions. Card details stay in Stripe's hosted checkout.
4. Enter a reason and request review. The server checks the API key against Stripe's authenticated own-account endpoint, retains only the merchant identifier, and displays that identifier, mode, currency, asset and enabled state for review. This verifies the merchant association; it does not exercise a payment, validate the signing secret against Stripe, or establish that payouts are ready.
5. Confirm the reviewed connection. Merchant, currency and mode cannot be changed on that connection; create another connection for a different identity. Editing permits a new name, enabled state, asset assignment or key rotation. Blank credential fields retain the saved credentials.

Test connections are permitted only when `APP_ENV` is `local`, `development` or `testing`. Use an isolated development database for provider qualification. Production rejects test connections and test-payment confirmations; changing an environment label is not a conversion of test receipts into institutional money.

The installation switch and HTTPS requirement control new checkouts. An enabled connection alone does not expose a payment route. No connection is automatically created or assigned by migration, and no real credentials or provider payments are exercised by the automated tests.

## Assign payment purposes

1. Choose **Payment routing → Assign payment route** and select the purpose and currency.
2. Select an enabled connection in that currency, or deliberately choose **No connection · Disable this payment route**.
3. Enter a reason, review the selected merchant/mode/clearing account, and confirm. If the route or selected connection changed since review, refresh and review the current values.
4. After the finance office has completed provider qualification and reconciliation procedures, the operator can deliberately enable `PORTAL_PAYMENTS_ENABLED`.

Removing a route or disabling a connection prevents new checkouts through that selection. Authentic notifications for earlier live sessions still complete against their retained configuration. A browser's success/cancel return never records a payment.

Connections, routes and account choices are paged in batches of twenty. Child tasks provide return controls; the create-asset detour preserves ordinary connection draft fields while clearing credential inputs. Editing an ordinary field invalidates its pending confirmation. A review lasts twenty minutes and is bound to its staff account, exact proposal and source revisions. A successful confirmation retry returns its original result, with current authority checked again.

## Retained checkout evidence and key rotation

Before any provider call, the server seals a snapshot containing the exact payment identity, owner, purpose, target, server-calculated amount/currency, expiry, return base URL and connection revision/merchant/mode/asset/credentials. A retry reuses its payment identifier and original provider parameters. The provider call holds no database transaction; connection/request timeouts are bounded. Stripe's [idempotency contract](https://docs.stripe.com/api/idempotent_requests) requires repeated parameters to agree.

A notification's untrusted reference only selects a candidate snapshot. Signature, event mode, payment reference, connection/revision metadata, session, amount and currency must verify before any financial effect. A rotated signing secret from the authenticated current version of that same merchant/mode/currency connection can verify an older session; its financial assignment stays frozen. An unrelated connection's key cannot redirect it. Keep retired merchant connections available for outstanding notifications; do not revoke old API keys while uncertain session creation still needs retrying.

After a hosted session is attached or a paid notification is accepted, its snapshot discards the API key; signing and financial evidence remain. Uncertain, not-yet-attached creation retains its original encrypted API key so a retry can resolve the same session.

Connection settings, pending proposals and checkout snapshots are encrypted with the application's private message cipher. API keys, signing secrets, provider bodies and checkout snapshots are never included in financial events, audit messages, browser review tokens, browser storage or job payloads. Student-account payment events retain a typed, credential-free receipt reference in the existing protected journal. Financial entry, automatic journal, paid state and required audit commit together. Duplicate notifications do not post again.

Successful setup confirmation clears its credential-bearing proposal and retains a minimized encrypted decision receipt with the reason and public configuration summary. Abandoned proposals expire after twenty minutes. The existing server scheduler clears up to fifty expired proposals hourly, independently of mail. Operators can run `sis:payments:prune-reviews --apply --limit=50` with a batch of 1–100; without `--apply` it performs no cleanup. Accepted receipts, current connections, checkout bindings and financial history are preserved. Their institutional retention and eventual credential/checkpoint disposition require a separate reconciliation-aware policy; the proposal cleanup must not be used to erase them.

## Current limits and upgrade boundary

Checkout takes a positive server-calculated balance, and normal owner/CSRF checks still apply. **A strict no-overpayment guarantee remains unfinished.** The balance can change while a hosted automatic-capture session is open. This milestone does not provide the authorization/capture or reservation workflow needed to guarantee the amount at capture. If money really arrives after the balance decreases, preserve the authentic receipt and resulting credit for reconciliation; do not discard received money to make a balance appear correct. Do not use this adapter where that stronger guarantee is required until the remaining roadmap gate is completed.

For an earlier alpha installation, reconcile unfinished/unbound provider sessions before applying this migration. The migration refuses those earlier pending/expired sessions when introducing checkout binding. It cannot infer their merchant, signing key or clearing account. Already recorded payments and all earlier protected events remain unchanged; they are not assigned today's configuration. The inspected development installation had no earlier payment rows or chart accounts.

If a signed receipt arrives while the applicable accounting period is closed or missing, its transaction rolls back and the webhook returns a generic retry response. Finance must resolve the period/reconciliation boundary; monitor provider retries and outstanding checkouts. Closing periods does not make already captured money disappear.

Provider test-environment acceptance, refunds/disputes, uncertain outcomes, bank settlement, processing-fee accounting, recurring donations, emailed receipts, the strict balance ceiling and independent operational/load qualification remain explicit gates before live use. The isolated checks use fictional providers and locally signed fictional events; they do not establish production readiness.
