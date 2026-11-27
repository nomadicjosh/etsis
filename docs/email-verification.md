# Verify an applicant’s current sign-in email

Institutions can require proof of control of an account’s current sign-in email before its owner submits a new application or resubmits requested answer changes. This is optional and disabled by default. It does not verify identity, decide admission, create a student, match an existing person, or grant access.

## Enable the policy

Configure `SIS_APPLICATION_EMAIL_VERIFICATION_REQUIRED=true` in the deployment environment. It supplies `auth.application_email_verification_required` in `config/auth.php`; use a real boolean. An invalid value stops affected new submissions rather than silently removing the requirement. Deploy the same setting to every serving process and reload retained configuration.

Before enabling the requirement:

1. Set the canonical `app.url` / `APP_BASE_URL` to the institution’s HTTPS application address, including any application subdirectory. Link generation rejects credentials, query strings, fragments and unsafe URL characters.
2. Configure the existing transactional mail transport and `SIS_MAIL_FROM` sender, set `SIS_MAIL_ENABLED=true`, and keep mail debugging disabled. See the existing [communications server setup](communications.md#server-setup).
3. Run the server scheduler so queued links can be delivered. Verify configuration and delivery using an institution-approved nonproduction account before requiring the policy for applicants.

Existing accounts can still sign in, create drafts and review their application records. Signup availability and email-domain restrictions remain [separate policies](person-identity-and-accounts.md#institutional-public-signup-policy). Staff-assisted intake uses its existing independent authority. A staff account submitting its own application still follows the owner requirement. An already accepted submission or resubmission retry returns its original result, even if the email or policy later changes; it does not create another application or revise the earlier decision.

## Applicant workflow

When the requirement is enabled, **Your applications** shows an email-verification card beside the application work. **Personal information** also links to the verification page. The card shows a masked destination and the current verification/delivery status.

1. Select **Send verification link**. The server uses only the signed-in account’s current email; the browser cannot choose another recipient or account.
2. Keep the application tab open to preserve unsaved answers. Delivery happens in the server background process.
3. Open the private email link while signed in to that same account and select **Confirm my email**. Opening a link alone does not verify it. If sign-in is needed, sign in and then reopen the original email link.
4. Return to the original application tab and select **Refresh verification status**, then continue submission. No application draft is copied to the verification tab or browser storage.

A link expires after **30 elapsed minutes**, including across daylight-saving changes. New links have a **one-minute cooldown**; deliberately refresh status before requesting another. A new link replaces the earlier one. Verification links do not sign someone in, and a different signed-in account cannot consume them, including an office administrator.

Verification belongs to the current canonical person/account and the actual recorded email revision. Changing the sign-in email requires new verification, including changing away from and back to the old address. Changing a password, name or security role does not itself invalidate email proof. Disabled accounts and a disagreement between the recorded account email and login routing are rejected. Staff cannot mark an address verified on someone’s behalf through this workflow.

## Delivery and recovery

The normal scheduler sends batches of at most ten queued notices every minute when mail is enabled, and clears expired challenge material hourly in batches of fifty. Run the scheduler on the server using the existing deployment arrangement. The dedicated commands also support bounded explicit operation:

```sh
ddev codex sis:accounts:email-verification --send --apply --limit=10
ddev codex sis:accounts:email-verification --prune --apply --limit=50
```

Without `--apply`, the command shows usage and performs no database or mail work. Select exactly one mode. Send limits are 1–20; cleanup limits are 1–100. Output contains fixed counters or generic failures, never recipients, tokens, message bodies or transport errors. Cleanup remains available during maintenance and does not resolve the mail transport; sending remains paused by maintenance policy.

**Queued** means delivery is waiting for a worker; **Sending** means a worker claimed it. **Sent** records transport acceptance, not proof that the message reached a mailbox. An uncertain send or interrupted worker is held without automatic resend because the original message may already have been delivered. Check the mailbox and current status, then explicitly request a new link after the cooldown if needed. A late old worker cannot clear a newer request or accepted confirmation. Source changes or expiration prevent stale queued delivery. Disabled delivery prevents new requests and pauses sending; an existing unexpired link can still be confirmed. If the application requirement is enabled while delivery is unavailable, contact the institution to restore its delivery configuration or deliberately change the policy.

## Privacy, storage and qualification

Migration `20261127000004_CreateSisEmailVerifications` adds one current security-state row per account when requested. It does not infer verification for existing accounts, rewrite identity or academic history, or create a second event store. Account/profile changes retain their existing protected historical events. Requests and confirmations require transactional metadata audits; audit, current-owner, source or final-state failure rolls back the corresponding proof change.

The database keeps a token hash and encrypted, source-bound challenge/current-proof envelopes. The queued recipient and link exist only in an encrypted short-lived notice and worker memory. Delivery receipts clear the encrypted notice. Expiry cleanup removes token hashes, challenge envelopes and any remaining notice while preserving an accepted current proof. This is current security metadata, not a historical notification archive or an admissions decision. Keep the existing application encryption keys protected and apply the institution’s normal account/security retention rules.

The private link carries its random token in a URL fragment, which the browser does not send in an HTTP request. The verification page clears the fragment immediately and holds it in memory until an explicit CSRF-protected confirmation POST. It uses no browser storage, URL query token, automatic confirmation or automatic retry. Reloading loses the in-memory token; reopen the email link. Responses are private/no-store and suppress debug collection; the page uses a no-referrer policy and disallows framing. Do not share the email link.

Fictional checks cover owner binding, expiry and cooldown, encrypted storage, uncertain delivery and late receipts, current-email changes, final-check/audit rollback, original application retry receipts, portable schema compilation and actual private routes/CSRF/console providers. Bounded Chromium checks exercise all bundled themes, keyboard confirmation, narrow/enlarged text, accessible status/errors and an application draft preserved across two tabs. These checks do not establish real mail-provider delivery, production load, multi-node acceptance or independent assistive-technology certification. Institution-specific identity matching and broader public-registration abuse work remain separate roadmap items.
