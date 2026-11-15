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

## Reviewing profile changes

Open **People → Profile history** to review person-profile, ordinary-contact and emergency-contact changes without leaving the connected workspace. This is a read-only change summary, not an export of personal values or a general event-store browser. Each entry shows the category, changed field names, recording/effective times and the recorder's current account name. Original account attribution remains in protected history; a missing display name is shown as unavailable rather than guessed. Prior names, addresses, phone numbers, emergency-contact details, private reasons, operation identifiers, credentials and raw event payloads are excluded.

Reviewers need **both** `sis:person:manage` and `sis:audit:read`. Ownership alone grants no history-review access, and a super account still requires a super administrator. Permission and signed-in identity are checked before and after reading. The ordinary owner/staff contact editors keep their existing permissions; access to these summaries grants no editing or credential authority.

Entries appear newest first in pages of twenty. **Load older changes** retains the review's original upper boundary, even if another staff member edits the person meanwhile. **Refresh history** begins a fresh review. The page loads only when requested, preserves loaded entries if a later page fails, and ignores a delayed response after staff leave the history screen. Recorder names are current labels, not an assertion about an editor's name at the time of the original change.

An explicit observation says that existing details were first recorded when tracking began; it does not assert when those details originally became valid. Profiles with no recorded history stay explicitly unknown. Newly created people show genuine creation evidence. The review does not synthesize earlier events or use current values to reconstruct an invented past.

The review verifies the selected person's protected stream, attribution and current core identity using shared `ProfileHistory` checks. Replay is limited to 2,000 events; summaries and actor-name lookups are bounded to each twenty-entry response. Reading or paging writes no event, receipt, audit row or projection. There is no new schema, event type or parallel event table. Student academic, financial and employment decisions and User credential/sign-in decisions retain their separate domain histories.

## Contact changes

The portal personal-information form and **People → Contact information** save preferred name, phone and mailing address through the same Person-domain workflow. The encrypted `sis_portal_contacts` table remains the current read model; its original name does not make these details student-only. A signed-in prospect, student or employee edits their own person contact information in the portal. Staff privileges never override this owner-only portal boundary. The separate staff workspace requires `sis:person:manage` for reads and edits, without requiring or granting password/sign-in-email administration. Only a super administrator can use that staff workflow for a super account.

Contact creation and changes append to the same `person_profile` stream as names, privacy and institutional IDs, in `sis_history_events`. They preserve their own contact revision and the shared person stream order. An exact retry returns successfully without overwriting later values. A different edit against an old revision is rejected. Saving unchanged values writes no event or audit; clearing a field is a real change and retains its previous value in protected history.

Existing contacts are read without adding historical events. Their first actual change records an explicit current observation before the change. It does not assert when the earlier address became valid or invent prior changes. Earlier core person events retain their original payloads and bytes. There is no data conversion or additional event table.

Contact projection, attributed history and required audit commit together under the person lock. Staff mutations also serialize access-role changes before taking that lock, and recheck current person-management authority and super-account protection before returning success. Portal ownership is rechecked after waiting and after writes. Staff and owner edits share contact revisions; one cannot silently overwrite the other. Reads and subsequent writes reject a modified or missing tracked projection. Replay is bounded and performs no email delivery or academic/financial side effects. A write that would cross the replay limit is rejected before commit, so it cannot make the next normal profile read exceed that limit. Lists and searches do not replay every person's contact history.

The staff contact form loads details only when opened. It stays in the person workspace, keeps entered values on a rejected save, supports explicit clearing and preserves phone leading zeros. The current sign-in email/password and security grants use their separate existing controls. Dated address setup and mailing-source selection use the shared workflow below.

## Dated addresses

Owners open **Portal → Personal information → Manage addresses**; authorized staff open **People → Contact information → Manage addresses**. One shared editor supports up to ten entries with mailing, home, billing, campus or other purposes. Enter street/postal lines and country, plus optional locality, region and postal code. Postal codes remain text, preserving leading zeros and international formats. Country labels use the installed internationalization support; no address data is sent to a provider.

Each entry has a required **From date** and optional **Through date**. The through date is inclusive. Two entries with the same purpose cannot cover the same day; different purposes may overlap. Past, current and future intervals can coexist in the book. For a move on September 1, end the current mailing entry on August 31 and start its replacement on September 1. The current mailing display changes with the application date (`app.timezone`) without a scheduled mutation or additional event.

Existing freeform mailing text remains editable until a dated mailing entry is deliberately saved. Home/billing/campus entries alone do not replace that text. The address editor shows earlier text and offers an explicit copy into street/postal lines; staff still supply the country and dates. No migration guesses those fields or creates earlier address history.

Saving the first dated mailing entry makes the address book the mailing source immediately. Include a mailing entry covering today if the earlier address should continue until a future move. That save retires the old freeform mailing field through an attributed contact change, preserves its previous value in protected history, and updates the contact/address projections together. Later phone/name edits do not keep copying that retired address. The contact form shows the applicable mailing address read-only and directs changes to **Manage addresses**. If no mailing entry applies today, it shows an empty mailing address; clearing the book does not revive retired text.

Unsaved preferred-name and phone edits remain in the surrounding contact form when addresses are saved. The form advances its contact revision only if those saved fields still match its baseline. If another editor changed them, a later contact save is rejected rather than silently overwriting that work. Rejected address saves retain entered fields, and native labels, keyboard controls, visible errors, status and narrow-screen layouts are shared by the portal and staff workspace.

Address decisions append `PersonAddressesWereRecorded` to the existing `person_profile` stream in `sis_history_events`. The encrypted `sis_person_addresses` table is a reconstructible current projection bound to the person and revision. Expected versions, actor-bound retries, person/role locks, trusted recording time and required audit commit atomically with any retirement of earlier mailing text. An earlier successful retry leaves subsequent addresses intact. Missing, untracked, altered or swapped address projections are rejected. First-time observations of older profile/contact values say explicitly that earlier history is unknown.

The additive `20261114000007_CreateSisPersonAddresses` migration starts empty and preserves all existing rows and event bytes. Entries use ULIDs and a person-owned projection; student, staff and faculty roles share it. Replay retains the existing 2,000-event limit. Individual field/entry limits and a 55,000-byte encoded list limit keep accepted facts within the protected journal's event ceiling. Removing entries changes the current book and preserves earlier facts under institutional retention rules. Existing issued academic documents and financial postings are not rewritten, and raw address/history values are not ordinary Report Studio sources. Full operational rebuild, retention and address-as-of-recording-date tooling remain separate work.

## Emergency contacts

Owners open **Portal → Personal information → Manage emergency contacts**. Authorized staff open **People → Contact information → Emergency contacts**. Both use the same editor and person-owned list, with up to five contacts in priority order. Each contact has a required name and phone, plus an optional relationship and email. Add, remove, move earlier/later and save in place; rejected saves retain entered values. Saving ordinary contact information in the staff workspace also preserves an open emergency-contact draft.

These are minimal external contact details. Listing someone does not create a campus person/account, grant guardian access or send a message. Institution members who separately register still follow the normal canonical-person/account workflow. The contact list does not replace verified guardian relationships or legal access rules.

The ordered list and each accepted revision are retained in the existing person stream in `sis_history_events`. Adding, changing, reordering or clearing a list records a fact; an unchanged save writes nothing. Existing person, ordinary-contact and User events keep their original payloads. Old successful retries leave later choices intact, and competing stale edits fail. The new encrypted `sis_person_emergency_contacts` projection is one row per person and contains no independent academic truth. A missing, altered or untracked projection is rejected rather than invented as earlier history. The additive `20261114000005_CreateSisPersonEmergencyContacts` migration begins empty and converts no existing facts.

`PersonProfileAccess` is shared by ordinary/emergency contact workflows: portal ownership remains mandatory, staff need person-management permission, and super records require a super administrator through staff routes. Person locks, trusted actor/time, expected revisions, shared replay limits, encrypted payloads, required audit and atomic rollback remain in force. Lists and general portal overviews do not eagerly load emergency contacts. Raw event streams and emergency contact details are not ordinary Report Studio sources.

This foundation covers current person creation, names, privacy, identifiers, the existing personal contact fields and account writers. Role-specific employment/faculty decisions and complete operational projection rebuild/retention tooling remain on the roadmap. Existing academic and financial histories keep their own domain boundaries and exact historical snapshots.
