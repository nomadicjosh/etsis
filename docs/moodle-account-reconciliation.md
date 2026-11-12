# Inspect an uncertain Moodle account-creation outcome

An interrupted response does not tell etSIS whether Moodle committed an account. Keep the original creation request and operation reference. The background worker already retries that same operation within its bounded attempt budget. The **Check original Moodle operation** action now provides a separate read-only inspection when that budget is exhausted or the request needs review.

## Setup

Upgrade the existing connector using [local_etsis 0.3.2-alpha](../dist/moodle/local_etsis-0.3.2-alpha.zip) and its [checksum](../dist/moodle/local_etsis-0.3.2-alpha.zip.sha256). Use Moodle's normal plugin upgrade; do not uninstall or reset its installation identity or account receipts. The upgrade adds a read function and does not change receipt tables or enable provisioning.

Authorize `local_etsis_get_account_receipt` in the integration token's restricted service. The connector's predefined **etSIS account provisioning** service includes it; an institution-managed custom service must add it explicitly. Receipt reads require `local/etsis:provisionaccounts` and `local/etsis:viewinstance`, an enabled connector, and the original approved installation. The etSIS Moodle connection must also be enabled. Account-request intake, provisioning, password email and the current authentication policy may remain disabled or changed: this action does not approve new effects under those settings.

## Staff workflow

1. Open **People**, select the person and open **Create a Moodle account**. A saved request with an uncertain response, exhausted attempts or a changed-request warning offers **Check original Moodle operation**. Loading this screen makes no Moodle call.
2. Select that action to inspect the exact original request. The server derives the operation and request digest from verified encrypted approval evidence; the browser supplies neither a Moodle ID nor account attributes.
3. Review the observation:

| Observation | Meaning and next step |
| --- | --- |
| Account recorded | Moodle retained an eligible account for the exact original request. The screen shows its Moodle ID and recorded sign-in setup state. Keep that account and the original operation. This observation does not attach a link or restart work. |
| No receipt observed | The outcome remains unknown. An uncommitted operation could still be in progress. This is not permission to create a replacement. |
| Receipt restricted | The receipt cannot provide an eligible account, for example because that account is suspended, deleted or a site administrator. Ask a Moodle administrator to review it. No account ID is returned. |
| Check unavailable | Connection, installation, service authorization or receipt verification failed. Keep the original operation; restore access and refresh its status before another check. |

The screen flags a person/profile/defaults change since the original approval. Inspection still uses the original request digest. It does not approve those changes or move an account to another person. A changed person, link, request version, installation, signed-in administrator or permission during the read invalidates the observation; refresh before checking again.

Use Moodle's verified support process for an existing account's access problem. A recorded email submission does not prove delivery. Pending or uncertain email markers are displayed as requiring review without sending anything or updating the remote receipt. For already-linked accounts, use the separate [setup-email resolution](moodle-person-links.md#resolve-a-setup-email-review-implemented).

This stage provides inspection only. Reviewed attachment of a recovered account, changes to the original approved attributes, and renewed processing after exhausted attempts remain subsequent work. Do not clear requests, reset attempt counters or repair identity links directly in SQL to bypass those boundaries.

## Security and persistence

The action uses the existing `sis:moodle:create-person` and `sis:person:manage` permissions, current local-account management authority and super-account protections. Currently authorized staff can inspect an earlier staff member's request without gaining authority to retry it. An attributed `moodle.account_receipt_check_requested` audit records the attempt before contacting Moodle; it does not claim that a receipt exists or that an account was reconciled.

POST `/sis/people/{person}/moodle/account-request/receipt/` preserves framework CSRF, private/no-store responses and suppressed debug collectors. The private client makes bounded TLS-verified POST requests without redirects, retries, browser cookies or debug hooks. It sends the installation fingerprint, original operation and original request digest; it sends no names, email address, password, review reason or academic data. Responses must match all original references and the fixed protocol/status schema. Raw exceptions and upstream responses are not returned.

The connector reads a single uniquely indexed operation receipt and, when needed, one account's ID under active-account filters. It uses no write locks and performs no account, receipt, password-reset, role or email writes. The SIS action does not claim or requeue work, change approvals, create links, change academic history, or persist the observation as a completed business decision. Only the inspection-attempt audit is added. The ordinary worker's lease, attempt budget and same-operation idempotency remain unchanged.

## Validation

Fictional connector, gateway and SQLite integration checks cover recorded/missing/restricted outcomes, changed policies, pending email markers, erased receipts, authorization, exact digests, profile/version changes and absence of creation or mail effects. Real-route checks cover CSRF, current permissions, dependency wiring and private responses. Browser checks cover keyboard access, narrow-screen accessibility, unavailable connections, unchanged request status and transient results. Native Moodle tests are supplied for a dedicated PHPUnit database; deployment, native REST and institutional mail/SSO acceptance remain separate requirements.
