# Review and recover uncertain Moodle account creation

An interrupted response does not tell etSIS whether Moodle committed an account. Keep the original creation request and operation reference. The background worker already retries that same operation within its bounded attempt budget. The **Check original Moodle operation** action now provides a separate read-only inspection when that budget is exhausted or the request needs review.

## Setup

Upgrade the existing connector using [local_etsis 0.4.0-alpha](../dist/moodle/local_etsis-0.4.0-alpha.zip) and its [checksum](../dist/moodle/local_etsis-0.4.0-alpha.zip.sha256). Use Moodle's normal plugin upgrade; do not uninstall or reset its installation identity or account receipts. Receipt inspection reads the original account receipt without enabling provisioning. Connector 0.4.0 also adds a separate opt-in section membership table; original account receipts remain intact.

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

Reviewed recovery of a missing link is available below. Changes to the original approved attributes and renewed processing after exhausted attempts remain subsequent work. Do not clear requests, reset attempt counters or repair identity links directly in SQL to bypass those boundaries.

## Recover a stopped request's account link

Recovery requires all three permissions: `sis:moodle:create-person`, `sis:moodle:link-person` and `sis:person:manage`, plus current local-account management authority. Super-account protection also applies. Inspection authority alone cannot approve a link. Recovery is available only for an unlinked request stopped in **review required** because attempts were exhausted or the approved setup changed. A worker still processing an uncertain response must finish or stop for review first.

1. Select **Check original Moodle operation**. When Moodle confirms an eligible account and the request has stopped for review, the screen offers **Recover the missing account link**.
2. Independently verify that the original account belongs to this person. Enter an administrative reason, without credentials or academic details, then select **Review recovery link**.
3. Check the current person's name, originally approved name, original username/email, retained Moodle ID and sign-in setup result. A profile/defaults change is explicitly flagged. Recovery retains the original account; it does not copy today's profile changes to Moodle or replace the original approval.
4. Acknowledge the identity verification and select **Save recovered account link** within 20 minutes. Confirmation reads the exact original receipt again. A different account, sign-in result, profile, request version, installation, administrator or permission invalidates the outstanding review.
5. The link, immutable recovery evidence, stopped-job transition and attributed `moodle.account_link_recovered` audit commit together. The link's approval time is the actual recovery time; etSIS does not invent an earlier approval. Existing source approvals, operation IDs, attempt counters, person attributes and academic events remain intact.

**Cancel recovery review** saves nothing. An interrupted confirmation can be checked with **Refresh request status**, which reads local state without contacting Moodle. A competing review cannot replace the saved decision, and the Moodle account cannot already belong to another person's link.

For recorded SSO setup or accepted setup email, the stopped request becomes **completed**. If the retained receipt indicates uncertain/pending setup email, the link is saved and **setup-email review** remains open. Resolve that separate task using [verified access recovery](moodle-person-links.md#resolve-a-setup-email-review-implemented); no email is resent by linking the account. An observed receipt does not independently prove current sign-in success or email delivery.

Repeating the same completed confirmation returns its original result without another remote read, link or audit, including after expiry or connection disablement. Current recovery permissions, account protections, original installation and retained link still apply. If staff later resolve the email warning, repeating the earlier link confirmation cannot reopen it: its original reply is returned without changing today's state. Refresh request status to see the current outcome.

Intake and automatic processing may remain disabled while staff recover a verified original account. Moodle connectivity and connector 0.3.2-alpha are required for a new review and confirmation; this workflow uses its existing read-only receipt function and makes no Moodle-side writes. It cannot recover an unknown/restricted receipt, select another Moodle ID, create a replacement, restart processing or change an original request's approved attributes.

## Security and persistence

The action uses the existing `sis:moodle:create-person` and `sis:person:manage` permissions, current local-account management authority and super-account protections. Currently authorized staff can inspect an earlier staff member's request without gaining authority to retry it. An attributed `moodle.account_receipt_check_requested` audit records the attempt before contacting Moodle; it does not claim that a receipt exists or that an account was reconciled.

POST `/sis/people/{person}/moodle/account-request/receipt/` preserves framework CSRF, private/no-store responses and suppressed debug collectors. The private client makes bounded TLS-verified POST requests without redirects, retries, browser cookies or debug hooks. It sends the installation fingerprint, original operation and original request digest; it sends no names, email address, password, review reason or academic data. Responses must match all original references and the fixed protocol/status schema. Raw exceptions and upstream responses are not returned.

The connector reads a single uniquely indexed operation receipt and, when needed, one account's ID under active-account filters. It uses no write locks and performs no account, receipt, password-reset, role or email writes. The SIS action does not claim or requeue work, change approvals, create links, change academic history, or persist the observation as a completed business decision. Only the inspection-attempt audit is added. The ordinary worker's lease, attempt budget and same-operation idempotency remain unchanged.

Recovery uses separate POST `/sis/people/{person}/moodle/account-request/recovery/review/` and `/recovery/confirm/` endpoints with the same private/no-store, CSRF and debug suppression boundaries. Encrypted, purpose-specific reviews bind the actor, person, original request/installation, exact local snapshot/version, retained Moodle account, sign-in status, reason and expiry. Review and confirmation perform remote reads before acquiring local write locks. The final short transaction locks the access/person boundaries, rechecks authority and the reviewed snapshot, refuses competing links, and uses a version-checked transition on stopped work. Any failure rolls back the new link, recovery receipt and job transition.

Migration `20261112000005_CreateSisMoodleAccountRecoveries.php` adds the private ULID-keyed `sis_moodle_account_recoveries` support table. Unique request/link references and foreign keys preserve the original approval and retained person link. The review/reason and actual UTC recovery time are encrypted; ordinary status and reporting omit this evidence. Setup-email resolution keeps its independent evidence on the original request. The outbox remains reference-only and its attempt count is never reset. This integration-decision receipt is not another authoritative event table, and no academic or financial history is changed or synthesized. Retain application encryption keys and follow institutional retention controls.

## Validation

Fictional connector, gateway and SQLite integration checks cover recorded/missing/restricted outcomes, changed policies, pending email markers, erased receipts, authorization, exact digests, profile/version changes and absence of creation or mail effects. Real-route checks cover CSRF, current permissions, dependency wiring and private responses. Browser checks cover keyboard access, narrow-screen accessibility, unavailable connections, unchanged request status and transient results. Native Moodle tests are supplied for a dedicated PHPUnit database; deployment, native REST and institutional mail/SSO acceptance remain separate requirements.

Recovery checks cover the independent link grant, stopped-worker requirement, fresh exact receipts, actor/person isolation, protected super accounts, expiry, profile changes, conflicting/competing links, atomic audit rollback and unchanged original approvals/history/counters. Repeated confirmation is tested after a later email resolution and connection disablement. Real routes and fictional browser fixtures exercise CSRF, current permissions, keyboard acknowledgement, cancellation, narrow-screen accessibility and interrupted-response recovery. The additive recovery schema compiles for all five framework database dialects and has been applied to the configured MariaDB test installation, with before/after verification that existing history, approvals, jobs and links were unchanged.
