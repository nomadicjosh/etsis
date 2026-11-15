# One person, one campus identity

`Person` is the parent identity. Every new person receives one linked sign-in account. Applications, student records, program pursuits, employment and faculty assignments attach to that identity; becoming a student or employee does not replace it. A staff member can study, and a graduate can later teach while keeping the original student history. Security roles grant access independently of those business records.

`sis_persons.id` is the stable ULID used by connected records. The assigned institutional ID is a separate staff-editable identifier, generated when omitted. Changing it preserves the person identity and resolves through connected records without rewriting earlier academic events or issued documents. `users` remains the credential/account child rather than a second independent person directory. A guardian relationship between two people is distinct from this model's parent/child terminology.

## Creating accounts

Prospects register before applying. Public registration creates the person and basic sign-in account together; it does not create a student record or grant staff access. An accepted applicant becomes a student through the existing reviewed matriculation workflow, retaining the same person and account.

In **People → Add a person**, staff enter a name and required email, plus an optional assigned institutional ID. One transaction creates the linked person, basic account, protected history and queued initial-password invitation. Staff do not choose a password for this flow. Duplicate email is rejected with instructions to find the existing person; the system never adopts another account by matching an email. Duplicate submissions using the same operation return the original person. An audit or projection failure rolls back the whole creation.

## Initial password setup

Configure an HTTPS `APP_BASE_URL` and the existing mail settings, including `SIS_MAIL_ENABLED`, `SIS_MAIL_FROM` and the configured SMTP transport. The application can queue invitations while delivery is disabled. When delivery is enabled, the scheduler sends at most ten queued notices per minute. Configure the normal Codefy scheduler in your environment. A manual bounded batch is:

```sh
ddev codex sis:accounts:send-setup --apply
```

Without `--apply`, the command only describes its usage. This command sends real mail when run against a configured institution; use fictional addresses and an injected transport in automated tests.

Invitations expire after 48 hours and can set the password once. The URL carries its token in a fragment; the setup page removes that fragment immediately and submits the token in the CSRF-protected form body. It uses private/no-store responses, no browser storage and no automatic sign-in. A token hash locates the record; an encrypted authenticated grant binds it to the exact person, account, credentials and expiry. A changed account email or password invalidates an older invitation.

The temporary `sis_account_setup` table stores encrypted notice content until delivery, consumption or an examined expiry. Successful and uncertain deliveries clear that content. An uncertain or interrupted delivery is not blindly resent. **People → Account setup** provides status and an explicit new link, with a one-minute cooldown. Reissuing requires person-management and existing account-edit authority, including the super-account safeguard. A new link invalidates the old one. This is initial account activation, not a general forgotten-password feature. Retention/cleanup for abandoned setup records remains operational work before production use.

## Protected profile history

New profiles retain their creation in the existing `sis_history_events` journal. Names, directory privacy, institutional ID, identity revision and the account relationship belong to the person stream. Credentials and account email retain the established User stream. Coordinated writers commit their projections, both affected streams and required audit together. There is no second authoritative event table.

Existing recorded User events are unchanged. For an older person without a profile stream, the first accepted edit records an explicit observation of the current profile, with earlier history marked unknown, followed by the actual change. The observation is recorded now; it is not an invented past creation event. Profile edits use a locked current record, expected revision, bounded replay and actor-bound retry identity. A retried older successful edit returns its original outcome without overwriting later changes. Altered projections are rejected rather than silently accepted as new historical facts.

This foundation covers current person creation, names, privacy, identifiers and account writers. Contact/address/emergency-contact history, role-specific employment/faculty decisions, controlled suspension and complete operational projection rebuild/retention tooling remain on the roadmap. Existing academic and financial histories keep their own domain boundaries and exact historical snapshots.
