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

## Disabling and restoring sign-in

In **People → Manage sign-in account**, an authorized administrator can disable or enable another person's sign-in, with a required reason. Staff need `sis:person:manage` and `admin:edit:user`; only a super administrator may change a super account. Administrators cannot disable themselves, and a change cannot remove the final active access administrator. Assign another administrator first. A disabled super account still receives super-account editing protection, but does not count as an active administrator.

Disabling blocks new password sign-ins and rejects existing etSIS sign-in cookies on subsequent requests. Both disabling and enabling rotate the account token, so old cookies and initial-password invitations cannot become valid again. Restoring access retains the current password. For an account still awaiting initial password setup, enable sign-in and issue a new setup link through the existing Account setup screen. Queued invalid notices are cleared without delivery when examined by the bounded worker.

The person, linked student/employment records, roles, academic standing and historical facts remain intact. This is sign-in access control, not academic suspension, withdrawal or an employment decision. Bound direct-Moodle-launch sessions become invalid at their next session check; independent Moodle password or institutional SSO access is not automatically disabled by this control.

Each actual decision appends `UserSignInWasChanged` to the existing User stream in `sis_history_events`, with trusted actor/time, encrypted reason, expected version and actor-bound operation identity. The read model, event, operation receipt and required audit commit together. A stale competing decision fails. Retrying an earlier successful disable after a later enable returns successfully without disabling the account again. Account maintenance loads fresh, bounded history; ordinary password/cookie authorization uses indexed read models without replaying every person's stream. List pages show whether an account is linked; the account editor shows its actual enabled/disabled state.

The additive `20261114000006_CreateSisUserSignInStatus` migration adds `users.sign_in_enabled`, initially enabled for existing accounts to preserve their prior behavior. It creates no invented access decisions, deletes no records and rewrites no recorded events. The projection remains reconstructible from the original User history plus subsequent access decisions.

## Protected profile history

New profiles retain their creation in the existing `sis_history_events` journal. Names, directory privacy, institutional ID, identity revision and the account relationship belong to the person stream. Credentials and account email retain the established User stream. Coordinated writers commit their projections, both affected streams and required audit together. There is no second authoritative event table.

Existing recorded User events are unchanged. For an older person without a profile stream, the first accepted edit records an explicit observation of the current profile, with earlier history marked unknown, followed by the actual change. The observation is recorded now; it is not an invented past creation event. Profile edits use a locked current record, expected revision, bounded replay and actor-bound retry identity. A retried older successful edit returns its original outcome without overwriting later changes. Altered projections are rejected rather than silently accepted as new historical facts.

## Contact changes

The portal personal-information form and **People → Contact information** save preferred name, phone and mailing address through the same Person-domain workflow. The encrypted `sis_portal_contacts` table remains the current read model; its original name does not make these details student-only. A signed-in prospect, student or employee edits their own person contact information in the portal. Staff privileges never override this owner-only portal boundary. The separate staff workspace requires `sis:person:manage` for reads and edits, without requiring or granting password/sign-in-email administration. Only a super administrator can use that staff workflow for a super account.

Contact creation and changes append to the same `person_profile` stream as names, privacy and institutional IDs, in `sis_history_events`. They preserve their own contact revision and the shared person stream order. An exact retry returns successfully without overwriting later values. A different edit against an old revision is rejected. Saving unchanged values writes no event or audit; clearing a field is a real change and retains its previous value in protected history.

Existing contacts are read without adding historical events. Their first actual change records an explicit current observation before the change. It does not assert when the earlier address became valid or invent prior changes. Earlier core person events retain their original payloads and bytes. There is no data conversion or additional event table.

Contact projection, attributed history and required audit commit together under the person lock. Staff mutations also serialize access-role changes before taking that lock, and recheck current person-management authority and super-account protection before returning success. Portal ownership is rechecked after waiting and after writes. Staff and owner edits share contact revisions; one cannot silently overwrite the other. Reads and subsequent writes reject a modified or missing tracked projection. Replay is bounded and performs no email delivery or academic/financial side effects. A write that would cross the replay limit is rejected before commit, so it cannot make the next normal profile read exceed that limit. Lists and searches do not replay every person's contact history.

The staff contact form loads details only when opened. It stays in the person workspace, keeps entered values on a rejected save, supports explicit clearing and preserves phone leading zeros. The current sign-in email/password and security grants use their separate existing controls. No new schema or historical-event format is needed for staff maintenance.

## Emergency contacts

Owners open **Portal → Personal information → Manage emergency contacts**. Authorized staff open **People → Contact information → Emergency contacts**. Both use the same editor and person-owned list, with up to five contacts in priority order. Each contact has a required name and phone, plus an optional relationship and email. Add, remove, move earlier/later and save in place; rejected saves retain entered values. Saving ordinary contact information in the staff workspace also preserves an open emergency-contact draft.

These are minimal external contact details. Listing someone does not create a campus person/account, grant guardian access or send a message. Institution members who separately register still follow the normal canonical-person/account workflow. The contact list does not replace verified guardian relationships or legal access rules.

The ordered list and each accepted revision are retained in the existing person stream in `sis_history_events`. Adding, changing, reordering or clearing a list records a fact; an unchanged save writes nothing. Existing person, ordinary-contact and User events keep their original payloads. Old successful retries leave later choices intact, and competing stale edits fail. The new encrypted `sis_person_emergency_contacts` projection is one row per person and contains no independent academic truth. A missing, altered or untracked projection is rejected rather than invented as earlier history. The additive `20261114000005_CreateSisPersonEmergencyContacts` migration begins empty and converts no existing facts.

`PersonProfileAccess` is shared by ordinary/emergency contact workflows: portal ownership remains mandatory, staff need person-management permission, and super records require a super administrator through staff routes. Person locks, trusted actor/time, expected revisions, shared replay limits, encrypted payloads, required audit and atomic rollback remain in force. Lists and general portal overviews do not eagerly load emergency contacts. Raw event streams and emergency contact details are not ordinary Report Studio sources.

This foundation covers current person creation, names, privacy, identifiers, the existing personal contact fields and account writers. Structured dated/multiple-address history, role-specific employment/faculty decisions and complete operational projection rebuild/retention tooling remain on the roadmap. Existing academic and financial histories keep their own domain boundaries and exact historical snapshots.
