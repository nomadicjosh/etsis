# Reviewed Moodle person links

This stage lets authorized staff explicitly connect one etSIS person to one Moodle user ID within the approved institutional installation. It does not create accounts, enable SSO, assign roles, enroll anyone or synchronize grades. Normal SIS work remains available without Moodle.

## Setup and permissions

Complete the [optional Moodle setup and installation approval](optional-moodle-integration.md) first. Person linking requires the independent protected permission **sis:moodle:link-person** and **sis:person:manage**. Moodle administration alone does not grant it. The migration adds no role/user grants and no broad permission implications; grant it through an institution-created role or a direct assignment to appropriate administrators.

For a person with a local sign-in account, existing account-management authorization also applies: **admin:profile** for one's own account or **admin:edit:user** for another account. Only a super administrator may link a super account. Linking a person who does not yet have a local account does not create or enable one.

The restricted Moodle service must authorize these functions:

- `core_webservice_get_site_info`
- `local_etsis_get_instance_info`
- `core_user_get_users_by_field`

The connector's predefined **etSIS instance verification** service contains only the first two functions. To authorize person lookup, create a separately reviewed restricted custom service with the three functions, explicitly authorize the integration account, and configure its token securely in etSIS. Moodle's predefined plugin service cannot be expanded through the administrator UI. See Moodle's [service creation documentation](https://moodledev.io/docs/5.3/apis/subsystems/external/advanced/custom-services). Do not grant course creation, enrollment or gradebook functions for this workflow, and do not broaden unrelated role capabilities merely to make discovery flags available.

## Staff workflow

1. Search **People** by name or institutional ID, open the person and select **Moodle account link**. No Moodle request runs while searching people or loading saved link details.
2. Enter the exact numeric Moodle user ID independently verified for this person, plus an administrative reason. Do not enter credentials, student academic details, an email address or username. etSIS does not infer identity from matching names or email addresses.
3. Select **Review Moodle account link**. The server verifies the approved installation, requests exactly one ID and verifies the installation again. The screen displays only the entered reference and known/unknown suspended/deleted flags. It does not display Moodle names, email addresses or profiles.
4. Check the independent-verification acknowledgement, then select **Approve reviewed person link** within 20 minutes. etSIS repeats the bound lookup and checks that the person, installation and reviewed flags have not changed. Approval and its audit commit atomically.

**Cancel link review** saves nothing. **Back to person** returns to the connected person workspace. **Refresh saved link** rereads local state without contacting Moodle, including after a response was interrupted. Retrying the same completed review returns its original link without a repeated external check or approval audit, even after expiry or disabling the integration; current authorization and the original approved binding still apply.

Missing records and records outside the service account's scope are both described as **no matching account returned**. A known suspended/deleted account cannot receive a new link. A missing flag remains **not supplied by Moodle**, rather than becoming false. Staff may independently approve a reference with unknown flags, but that does not establish current login eligibility. Future SSO must apply its own current account checks and institutional policy.

Saved references have no ordinary replace/unlink action. A correction needs a separate reviewed workflow that preserves the previous decision and checks any dependent mappings. Changing an institutional person ID continues to resolve through the same person ULID; it does not move the Moodle reference to a different person.

## Security and persistence

`20261111000007_CreateSisMoodlePersonLinks.php` adds `sis_moodle_person_links` and the independent protected permission. Its primary key is a ULID; Moodle's numeric identifier is an external string reference. Foreign keys retain the person and installation approval. Unique constraints on `(binding_id, person_id)` and `(binding_id, moodle_user_id)` protect both sides against competing approvals. The reason, reviewed status and local-person revision are encrypted; ordinary status responses omit that review evidence. The table is excluded from ordinary reporting sources.

These are audited integration references, using the existing permission and account boundaries. No academic record, person profile, local account, role grant or authoritative event is rewritten, and no parallel event store is introduced. Tokens, Moodle profiles, raw responses and installation identifiers are never saved in the link table or returned to the browser. The user-entered Moodle ID is visible only in this authorized person workflow and must remain out of logs and public URLs.

GET `/sis/people/{person}/moodle/` loads local saved state. POST `/review/` and `/confirm/` under that path retain framework CSRF protection, private/no-store responses and suppressed debug collectors. Reviews are encrypted, purpose-specific and bound to the administrator, person, installation, profile revision, external ID and known/unknown flags. Reviews stay in browser memory and are discarded when their view is removed. Authorization is rechecked around remote reads and within the write transaction; failures roll back both link and approval audit.

The bound reader uses one captured configuration, TLS verification, no redirects/cookies/retries and the existing response-size/depth limits. Up to four requests run outside database locks: site handshake, installation identity, one exact-ID lookup and installation identity again. They share a 30-second request budget; a final stream read can extend the operation by up to its bounded five-second read timeout. Confirmation performs a fresh operation with the same limits. Ordinary saved-link reads use the indexed local reference and do not replay history or lock streams.

A different site/installation stops the operation. A full Moodle database clone can retain the same identity, so these checks do not detect every clone or independently prove ownership. Keep clones disconnected and follow institutional recovery review. Future SSO and synchronization must use these approved references and verify the active installation before external effects; they remain subsequent stages.

## Validation

Tests use fictional Moodle responses and isolated SQLite fixtures. They cover installation changes around a lookup, configuration changes, unknown flags, stale/expired reviews, actor/permission changes, protected super accounts, uniqueness on both sides, atomic audit rollback, owned-account authorization and repeat confirmation. The schema compiles for MySQL, PostgreSQL, SQLite, SQL Server and Oracle. Browser checks cover explicit keyboard approval, narrow-screen accessibility, cancellation, private transient state and recovery after an interrupted response. No live Moodle person record was used as a test fixture or linked automatically.
