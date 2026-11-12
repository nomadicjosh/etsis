# Reviewed Moodle person links

The person workspace offers **Moodle account link** for an existing account and **Create a Moodle account** for reviewed request intake. Existing-account linking is operational. Creation requests can now be reviewed and saved, but the remote provisioning worker is not implemented: saving a request creates no Moodle account and sends no email. Request intake is separately disabled by default. The completed creation workflow must retain the returned Moodle ID automatically, without a second linking task.

The intended integration automatically provisions eligible **students** and **faculty** in the background when the institution enables that capability. An accepted teaching assignment must request provisioning of the instructor and the appropriate role in that specific Moodle course; committed registrations drive student course access. For all other people, staff choose creation or existing-account linking on an as-needed basis. Such people do not need a student or faculty role just to receive a Moodle account. See the [provisioning policy](optional-moodle-integration.md#automatic-account-provisioning-and-section-access) for the intended workflow and current implementation status. Normal SIS work remains available without Moodle.

## Setup and permissions

Complete the [optional Moodle setup and installation approval](optional-moodle-integration.md) first. Person linking requires the independent protected permission **sis:moodle:link-person** and **sis:person:manage**. Moodle administration alone does not grant it. The migration adds no role/user grants and no broad permission implications; grant it through an institution-created role or a direct assignment to appropriate administrators.

For a manual link involving a person with a local sign-in account, existing account-management authorization also applies: **admin:profile** for one's own account or **admin:edit:user** for another account. Only a super administrator may manually link a super account. This tool does not create or enable a local sign-in account. Planned automatic provisioning will use its own institution-approved service authority and current eligibility checks; it must not require a staff member to approve every normal student/faculty account individually.

The restricted Moodle service must authorize these functions:

- `core_webservice_get_site_info`
- `local_etsis_get_instance_info`
- `core_user_get_users_by_field`

The connector's predefined **etSIS instance verification** service contains only the first two functions. To authorize person lookup, create a separately reviewed restricted custom service with the three functions, explicitly authorize the integration account, and configure its token securely in etSIS. Moodle's predefined plugin service cannot be expanded through the administrator UI. See Moodle's [service creation documentation](https://moodledev.io/docs/5.3/apis/subsystems/external/advanced/custom-services). Do not grant course creation, enrollment or gradebook functions for this workflow, and do not broaden unrelated role capabilities merely to make discovery flags available.

## Link an existing account (implemented)

1. Search **People** by name or institutional ID, open the person and select **Moodle account link**. No Moodle request runs while searching people or loading saved link details.
2. Enter the exact numeric Moodle user ID independently verified for this person, plus an administrative reason. Do not enter credentials, student academic details, an email address or username. etSIS does not infer identity from matching names or email addresses.
3. Select **Review Moodle account link**. The server verifies the approved installation, requests exactly one ID and verifies the installation again. The screen displays only the entered reference and known/unknown suspended/deleted flags. It does not display Moodle names, email addresses or profiles.
4. Check the independent-verification acknowledgement, then select **Approve reviewed person link** within 20 minutes. etSIS repeats the bound lookup and checks that the person, installation and reviewed flags have not changed. Approval and its audit commit atomically.

**Cancel link review** saves nothing. **Back to person** returns to the connected person workspace. **Refresh saved link** rereads local state without contacting Moodle, including after a response was interrupted. Retrying the same completed review returns its original link without a repeated external check or approval audit, even after expiry or disabling the integration; current authorization and the original approved binding still apply.

Missing records and records outside the service account's scope are both described as **no matching account returned**. A known suspended/deleted account cannot receive a new link. A missing flag remains **not supplied by Moodle**, rather than becoming false. Staff may independently approve a reference with unknown flags, but that does not establish current login eligibility. Future SSO must apply its own current account checks and institutional policy.

Saved references have no ordinary replace/unlink action. A correction needs a separate reviewed workflow that preserves the previous decision and checks any dependent mappings. Changing an institutional person ID continues to resolve through the same person ULID; it does not move the Moodle reference to a different person.

## Review a creation request (implemented)

Creation intake requires the independent protected permission **sis:moodle:create-person** together with **sis:person:manage**. Existing-account link permission and Moodle administration do not imply creation authority. The same local-account and super-account protections described above apply. The migration adds no role/user grants. This workflow supports people without student or faculty roles.

For development of request intake, explicitly set `SIS_MOODLE_ACCOUNT_REQUESTS_ENABLED=true` alongside the enabled integration and approved installation. Leave it false for ordinary operation until remote processing is delivered. This switch enables saving requests only; it does not enable a worker, SSO or email delivery. Status and confirmation responses explicitly return `processing_available: false`.

1. Search **People**, open the person and choose **Create a Moodle account**. Status reads stay local. An existing link or saved creation request prevents another request.
2. Review the person's canonical name. Username and email are prefilled from their local sign-in account when available; enter or correct only those account attributes. No password is requested or copied.
3. Select **Institutional SSO** or **Moodle password-setup email**, and give a short administrative reason. Both choices are supported request intents. They do not establish that the institution's authentication arrangement is configured.
4. Select **Review account request**. etSIS verifies the approved installation and displays the exact name, username, email and sign-in choice. Acknowledgement is required before **Save reviewed creation request**. Reviews expire after 20 minutes and cannot be used by a different administrator or for another person.
5. Confirmation verifies the installation again, checks the current permissions, profile, account defaults and reviewed binding, then saves the encrypted approval, reference-only outbox entry and audit atomically. No remote write occurs. The workspace clearly reports **queued** and that account processing is not yet available.

**Cancel review** saves nothing. **Refresh request status** rereads local state after an interrupted response. Repeating the same successful confirmation returns the saved request without another approval audit or remote check, even after review expiry or intake disablement; current authorization and the original binding still apply. A saved request blocks a competing manual link, including a link reviewed before the request was saved. There is no saved-request cancellation or replacement workflow yet.

## Complete account provisioning (next stage)

The shared worker must verify the installation, current request authority, profile and institution-approved authentication policy, create or reconcile the account using a retry-safe operation reference, and retain its Moodle ID against the same person. Automatic student/faculty provisioning will use that service without requiring individual staff approval. Older intake requests must not execute automatically merely because a worker is later installed; their intent must still match current policy and authorization.

The institution must be able to offer both approved sign-in arrangements. SSO requires its configured identity provider; password-email mode requires a password-setup link workflow. Moodle's native `createpassword` option sends a generated password and must not be treated as the requested reset-link workflow. Neither authentication execution nor email sending is implemented by this intake stage.

An existing account or conflicting identity needs review rather than silent adoption or duplicate creation. An uncertain result must reconcile the original operation before another attempt. The completed workspace should show progress and actionable exceptions; staff should never need a separate Moodle creation step or a second manual-link form.

## Security and persistence

`20261111000007_CreateSisMoodlePersonLinks.php` adds `sis_moodle_person_links` and the independent protected permission. Its primary key is a ULID; Moodle's numeric identifier is an external string reference. Foreign keys retain the person and installation approval. Unique constraints on `(binding_id, person_id)` and `(binding_id, moodle_user_id)` protect both sides against competing approvals. The reason, reviewed status and local-person revision are encrypted; ordinary status responses omit that review evidence. The table is excluded from ordinary reporting sources.

`20261112000000_CreateSisMoodleAccountRequests.php` adds ULID-keyed `sis_moodle_account_requests` and `sis_moodle_provisioning_outbox`, plus the independent creation permission. Request approvals contain encrypted reviewed attributes and reason, with actor/time, binding/person references and a review hash. Unique `(binding_id, person_id)` prevents duplicate requests. The outbox contains only its ID, request reference, state, version and creation time; it contains no profile, credential or upstream response. The SQL outbox shares the approval/audit transaction; filesystem queue dispatch alone would not provide that atomicity. These private tables are excluded from ordinary reporting sources. Application encryption keys must be retained securely for approved evidence to remain readable.

Creation routes are GET `/sis/people/{person}/moodle/account-request/` and POST `/review/`, `/confirm/` beneath it. They preserve private/no-store responses, framework CSRF protection and suppressed debug collectors. Attribute previews are transient browser memory only. Network checks happen before the short person/account-lock transaction, never while holding those locks. Request saving does not change an academic record or add events to the authoritative event store.

These are audited integration references, using the existing permission and account boundaries. No academic record, person profile, local account, role grant or authoritative event is rewritten, and no parallel event store is introduced. Tokens, Moodle profiles, raw responses and installation identifiers are never saved in the link table or returned to the browser. The user-entered Moodle ID is visible only in this authorized person workflow and must remain out of logs and public URLs.

GET `/sis/people/{person}/moodle/` loads local saved state. POST `/review/` and `/confirm/` under that path retain framework CSRF protection, private/no-store responses and suppressed debug collectors. Reviews are encrypted, purpose-specific and bound to the administrator, person, installation, profile revision, external ID and known/unknown flags. Reviews stay in browser memory and are discarded when their view is removed. Authorization is rechecked around remote reads and within the write transaction; failures roll back both link and approval audit.

The bound reader uses one captured configuration, TLS verification, no redirects/cookies/retries and the existing response-size/depth limits. Up to four requests run outside database locks: site handshake, installation identity, one exact-ID lookup and installation identity again. They share a 30-second request budget; a final stream read can extend the operation by up to its bounded five-second read timeout. Confirmation performs a fresh operation with the same limits. Ordinary saved-link reads use the indexed local reference and do not replay history or lock streams.

A different site/installation stops the operation. A full Moodle database clone can retain the same identity, so these checks do not detect every clone or independently prove ownership. Keep clones disconnected and follow institutional recovery review. Future SSO and synchronization must use these approved references and verify the active installation before external effects; they remain subsequent stages.

## Validation

Tests use fictional Moodle responses and isolated SQLite fixtures. They cover installation changes around a lookup, configuration changes, unknown flags, stale/expired reviews, actor/permission changes, protected super accounts, uniqueness on both sides, atomic audit rollback, owned-account authorization and repeat confirmation. The schema compiles for MySQL, PostgreSQL, SQLite, SQL Server and Oracle. Browser checks cover explicit keyboard approval, narrow-screen accessibility, cancellation, private transient state and recovery after an interrupted response. No live Moodle person record was used as a test fixture or linked automatically.

Creation-intake checks additionally cover both sign-in intents, encrypted approval storage, reference-only outbox fields, competing requests/manual links, strict opt-in flags and rollback on audit failure. The current full PHP suite passes 1,515 tests; the Moodle administration, person-link, creation-intake and person-workspace browser checks pass 23 tests. These checks do not establish remote creation or email acceptance.
