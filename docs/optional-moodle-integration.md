# Optional Moodle integration

Moodle integration is optional and disabled by default. An institution must explicitly enable and configure it. Normal SIS workflows do not require Moodle. The current implementation provides a private administrator status screen, an operator REST diagnostic and a single-ID mapping preflight. Opt-in direct Moodle launch is implemented in the connector; institutional deployment acceptance and background course/enrollment/gradebook synchronization remain separate stages.

## Direct Moodle launch and coordinated sessions

**Requested direction:** provide **Open Moodle** from a signed-in etSIS session without a second password prompt. Keep institutional OpenID Connect as an additional option. LDAP and `saml2` are examples of external Moodle authentication plugins, not required SSO choices. The delivered provisioning settings choose an account's authentication method. Direct launch has its own opt-in and deployment configuration.

The applications should keep their own host-scoped cookies and native session formats. etSIS currently uses a framework-encrypted authentication envelope with a server-checked expiry; Moodle uses its own server-side session manager. Giving these cookies the same name, lifetime or parent domain would not make their authentication state equivalent. Sharing an application encryption key or PHP session store is not the integration contract.

The launch uses the signed-in person's approved Moodle link and installation, never a browser-supplied target user or an email/name match. A short-lived, single-use launch credential establishes a Moodle session linked to the originating etSIS browser sign-in. The launch credential's expiry and the authenticated session's expiry are separate controls. The existing [User key authentication plugin](https://github.com/catalyst/moodle-auth_userkey) supports a one-way launch, but its key lifetime alone does not synchronize the resulting session's expiry. The connector now uses native Moodle session APIs directly, with a POST-only handoff and authenticated session validation; no user-key plugin is required.

Session coordination needs an explicit server-side authority and acceptance tests:

- Retain a revocable reference for each browser sign-in, with its actual authentication deadline. The user's reusable account token is not a browser-session identifier. Preserve concurrent browser/device sessions according to institutional policy.
- Link each launched Moodle session to that reference. Enforce its deadline and revocation before Moodle serves a protected read or mutation, including AJAX/file requests; a homepage redirect or JavaScript timer is insufficient.
- Revoke linked access when that etSIS sign-in is logged out or invalidated. Deleting a browser cookie alone is insufficient server-side revocation. A browser may retain a Moodle cookie, but that cookie must no longer authorize a request after the originating session ends.
- Coordinate idle renewal separately from the absolute maximum lifetime. Whether Moodle activity renews the shared deadline is a policy choice still being clarified. Matching independent timeout settings does not provide this behavior. Moodle's own earlier session timeout must also be accounted for.
- Define and test revocation-check latency and unavailable-authority behavior before claiming simultaneous logout. Strict checks must refuse protected access when validity cannot be established; any cached validation window must be explicit. Already displayed pages cannot be remotely erased, and expiry enforcement happens when another protected request is made.
- Keep launch credentials, session IDs and authentication envelopes out of logs, browser storage, ordinary reporting and background job payloads. Use protected server-to-server validation, bounded requests and private responses. Preserve browser CSRF protection, fixed approved destinations and login-CSRF defenses.

The reference foundation, one-use exchange and Moodle-side validation hook are implemented. See [Direct Moodle launch](moodle-direct-launch.md) for setup, exact expiry/logout behavior, request costs and outstanding native acceptance. Both launch flags default off; no institution secret or live Moodle setting is changed automatically.

### etSIS sign-in authority foundation (implemented)

`20261112000002_CreateSisLoginSessions.php` adds the private `sis_login_sessions` table. `Domain\User\Service\LoginSessionAuthority` can register the current authenticated browser sign-in and later validate a reference for its specified owner. It accepts a proof produced only by the framework-cookie adapter, verifies the current account and retains the original authentication envelope's exact expiry. Reading or registering the reference again never extends it. This first stage uses the existing etSIS authentication deadline; activity-based renewal across applications remains a later policy decision.

The adapter reads Codefy's encrypted `USERSESSID` authentication envelope, including the selected remember-me lifetime. The separate `CODEFYSESSID` PHP session/CSRF cookie is not the authentication deadline. Only private digests, a ULID reference, owning user ID, expiry, observation time and first revocation time are persisted. Tokens, password hashes, raw cookie values, browser fingerprints and IP addresses are not copied to the registry. The account revision is a digest of the current credential state, allowing tracked references to become invalid after password/token changes or account deletion. An observation time records when the reference was established; it is not an invented original login time.

The private-request middleware runs after framework cookie decryption and before request binding. It uses an indexed cookie-digest lookup, with one bounded current-account lookup for active tracked references. It performs no network call, event replay or directory scan. Ordinary valid framework cookies remain usable before a reference is registered; requests do not eagerly create registry records. Missing or corrupt authority storage blocks protected processing rather than treating it as valid. The registry is excluded from ordinary reporting sources.

POST logout retains framework CSRF protection and records a revocation even for a valid sign-in not previously registered for an integration. The old cookie cannot subsequently authenticate protected requests. Successful password re-authentication ends the prior browser reference and makes the framework issue a fresh encrypted cookie, including when the account token is unchanged. Failed password authentication does not revoke the current sign-in. Switching accounts ends the originating reference; sign-ins on other browsers/devices remain independent. Anonymous or already expired-cookie logout still clears the browser cookie without creating a reference.

References are immutable apart from their first revocation. Competing registration/logout inserts use a unique cookie digest and a bounded retry after transaction rollback; registration cannot overwrite a revocation. Revocation tombstones must remain until the original envelope expires. The [operator retention command](sign-in-session-retention.md) now provides a default preview and explicit bounded cleanup, with a grace period and checks for retained Moodle launch references. Future integration references must be included before their parent sign-ins can be cleaned up. No new event store is introduced and no academic facts are rewritten.

Tests use fictional cookies, accounts and SQLite databases. They exercise the real framework envelope and login/logout routes, Gate ownership, CSRF/no-store handling, account switching, password rehash/re-authentication, expiry, invalid storage and races through two independent SQLite connections. The migration compiles for the five framework-supported database dialects. These checks establish the etSIS foundation, not an operational Moodle sign-in or shared logout flow.

The [launch flow](moodle-direct-launch.md) now binds its one-use credential to this reference and supplies authenticated server validation plus a Moodle request hook. Source logout is enforced on the next validation; Moodle-only logout does not revoke etSIS. The reference is not exposed through a browser session-inspection API and is not sufficient by itself to authorize a Moodle user.

## Automatic account provisioning and section access

**Adopted workflow:** eligible student and faculty accounts should be created automatically in Moodle through background synchronization once the institution enables provisioning. For all other people, authorized staff must have a choice in the etSIS person workspace: **Link an existing Moodle account** by its ID, or **Create a Moodle account** and save its returned ID automatically. Staff must not have to create the account separately in Moodle and then come back to link it. The current manual-link tool's statement that it does not create accounts describes that tool's delivered scope, not a permanent restriction on Moodle integration.

| Person or change | Intended background behavior |
| --- | --- |
| Eligible student | Create one Moodle account when needed under the institution's enabled provisioning policy, retain its person link and synchronize committed section registrations as course-level student access. No individual manual-link approval is required for routine provisioning. |
| Eligible faculty member | Create one Moodle account automatically under the enabled policy. An accepted teaching assignment must request provisioning/reconciliation of that instructor and the instructor role for the assigned section's Moodle course. |
| Person with both student and faculty roles | Reuse the same Moodle account. Apply the appropriate course-level role for each authoritative registration or teaching assignment. |
| Other person, including ordinary staff, parents and administrators without a qualifying faculty/student context | Do not create an account merely because the person exists or receives one of these roles. Authorized staff can explicitly choose **Create a Moodle account** in etSIS when needed, or **Link an existing Moodle account** when it already exists. Creation automatically retains the resulting person link. |
| Existing reviewed person link | Reuse that reference after verifying the installation and account. Never create a second account merely because provisioning is automatic. |

Provisioning eligibility must use **current database-managed role assignments and authoritative student/faculty context**. The `faculty` and `student` entries in `config/rbac.php` are initial role seeds; editing that file does not change current runtime assignments or enable provisioning. A broad administrator permission does not make someone a student or instructor. An accepted teaching assignment must enter the synchronization workflow even if its faculty setup is incomplete: show an actionable eligibility/setup issue instead of silently skipping the instructor or granting unrelated SIS roles.

Role assignments, student activation, accepted teaching assignments and committed section registrations should request background reconciliation. The worker rereads current eligibility and the institution's provisioning policy before an external effect, so a queued earlier assignment cannot override a later revocation. Initial enablement should offer a bounded reconciliation of already eligible students/faculty, rather than requiring staff to reopen every person record. Institution-defined access dates control when planned section activity becomes effective.

Account creation, course creation and course-role assignment are separate operations. Resolve the approved Moodle course for the particular SIS section, provision or reuse its instructor, then assign the institution's configured **course-level instructor role**. Student registrations similarly establish course-level student access. Resolve Moodle role identifiers from reviewed configuration rather than assuming numeric IDs. Never translate SIS `super`, `admin`, registrar or finance privileges into Moodle site-administrator authority.

Automatic creation must be retry-safe and record the resulting Moodle ID against the canonical person ULID and approved installation. Use a protected integration-owned correlation/operation reference; the editable institutional ID and email address are not identity keys. When an outcome is uncertain, reconcile that reference before retrying creation. An existing but unverified Moodle account, conflicting username/email or ambiguous identity goes to an exception queue for review rather than being silently adopted, merged or duplicated. A manual exception can resolve such a case without making routine eligible provisioning manual.

Staff-requested creation must use the same retry-safe provisioning service as automatic student/faculty creation. The difference is the source of authorization: an explicit, attributed request for this person instead of automatic role/context eligibility. That request must not require granting a student or faculty role. Account creation needs its own controlled authority, distinct from approval of an existing-account link, and must preserve the existing protections around local sign-in and super accounts. Both routes must verify the approved Moodle installation and reuse an existing reviewed link when one is already present.

The person workspace should present the two choices clearly. **Link an existing Moodle account** asks for the known Moodle ID and reviews that reference. **Create a Moodle account** reuses the person's existing details, asks only for missing required account information, reviews the intended account and queues creation plus link retention as one staff workflow. Show queued, working, completed or actionable exception status in the same person workspace. Creation returning an ID must not require a second manual-link form. Repeated clicks and interrupted responses must reconcile the same operation without creating another account.

The institution must configure its supported Moodle authentication arrangements before provisioning is enabled. Offer both **institutional SSO** and **Moodle password-setup email**, subject to institution-approved setup. Do not copy SIS passwords or password hashes, send credentials in jobs or logs, or grant administrative Moodle roles to make synchronization work. Send only the minimum account attributes needed through the approved transport. Use bounded workers and a durable outbox containing protected references and operation IDs, not student profiles, credentials or upstream response bodies. Remote requests run outside academic write transactions; provisioning failures must not undo an accepted registration or teaching assignment, and staff need visible retry/reconciliation status.

Removing a teaching assignment or registration should reconcile that section's access according to institutional policy. Losing one role must not disable a person who remains eligible through another student/faculty context. Preserve existing learning and academic history; do not hard-delete accounts or historical academic facts as an automatic cleanup action. Gradebook synchronization remains separately staged for authorized academic review.

**Current implementation status:** installation approval, bounded reads and manual links to existing Moodle accounts are implemented. [Reviewed creation-request intake](moodle-person-links.md#review-a-creation-request-implemented) now provides **Create a Moodle account**, both sign-in intents, independent authorization, encrypted approval storage and an atomic reference-only SQL outbox. Intake and remote processing are separately disabled by default. A bounded [provisioning worker](moodle-person-links.md#process-a-reviewed-account-request-implemented-opt-in) now creates staff-requested accounts through the 0.3.2-alpha connector, reconciles the original operation after uncertain responses, retains the returned Moodle ID and reports password-setup delivery issues. Automatic eligibility, initial reconciliation, section-course mappings, course-role synchronization and full institutional SSO remain open. Upgrading the connector or enabling the diagnostic/intake does not enable workers; institutions must configure the restricted provisioning service, supported authentication modes, processing flag and scheduler explicitly.

## Enable the connection diagnostic

Provision a dedicated Moodle integration account and a restricted external service containing `core_webservice_get_site_info`. Enable Moodle web services and its REST protocol, authorize that account for the service, and issue a service token. Avoid using a personal administrator token or the broad mobile-app service. Moodle's official [web-service setup guide](https://supportus.moodle.com/support/solutions/articles/80001016973-using-the-web-services-application-programming-interface-api-in-moodle) describes service functions, authorized users and tokens; its [REST client documentation](https://docs.moodle.org/dev/Creating_a_web_service_client) describes request parameters.

Set deployment secrets through the institution's protected environment or secret manager. The token must never be committed, passed as a CLI argument, put in a URL or exposed to browser code. No Moodle credentials are persisted in new application tables or caches in this stage.

```dotenv
SIS_MOODLE_ENABLED=true
SIS_MOODLE_ACCOUNT_REQUESTS_ENABLED=false
SIS_MOODLE_PROVISIONING_ENABLED=false
SIS_MOODLE_URL=https://moodle.example.edu
SIS_MOODLE_TOKEN=
SIS_MOODLE_CA_FILE=
```

Supply the restricted token securely. The URL is the Moodle site base, including an installation subdirectory when applicable, without a query, fragment, embedded credentials or REST endpoint suffix. HTTPS is required. TLS verification stays enabled; an optional CA bundle path must be readable inside the PHP runtime. For local DDEV work the supplied installation is `https://moodle.ddev.site`; any required CA path must exist inside the etSIS web container rather than only on the host.

Run:

```sh
ddev codex sis:moodle:check
```

Without DDEV, invoke `codex sis:moodle:check` using the project's configured PHP interpreter. No token or endpoint options are accepted on the command line.

With the feature disabled, the command succeeds with a disabled notice and makes no network request. To disable it again, set `SIS_MOODLE_ENABLED=false`; no SIS academic facts are changed. Enabling the current diagnostic does not enable SSO, publish courses, change enrollments or import grades.

## Administrator status screen

Authorized staff can open **Moodle integration** in the backend sidebar. The screen reads the institution's enabled/disabled status without contacting Moodle. **Check Moodle connection** explicitly requests the same sanitized site-info diagnostic as the console command; **Refresh status** rereads etSIS configuration and the saved installation approval without contacting Moodle. An advertised function is not proof that SSO, identity links or synchronization are enabled. Missing functions can be expected for a restricted service.

The independent protected permission **sis:moodle:manage** is added by `20261111000005_CreateSisMoodleAdministrationPermission.php`. The migration adds no role/user grants or broad SIS implications. Super administrators already have access; authorized access administrators can assign the permission through an institution-created role or direct user grant. Existing protected system roles remain unchanged. Ordinary catalog, student-read and access-management permissions alone do not grant Moodle administration.

The status GET and explicit check POST enforce current permission, private/no-store responses, suppressed debug collectors and framework CSRF protection for the POST. A check records only requesting-actor/action metadata before the external read; it does not save credentials, responses, profiles or capability results. The call runs outside database transactions and results are discarded if the administrator or permission changes. No per-user or course record lookup is exposed through this screen.

Configuration remains deployment controlled. The screen does not display/edit the URL, token or TLS settings and cannot turn on SSO or synchronization. Use the institutional setup above to enable or disable the optional connection.

## What the check establishes

The probe calls only `core_webservice_get_site_info`, with the token in the POST body. It reports a fixed allowlist of function-availability flags for site information, the optional instance-verification plugin, targeted user/course lookups, course creation/update, manual enrollment/unenrollment and user grade items. The lookup functions (`core_user_get_users_by_field` and `core_course_get_courses_by_field`) are discovered but never invoked by this check. They are intended for future reviewed identity/section mappings and are not required for this diagnostic. Add them to a restricted service only when the corresponding mapping workflow is being enabled. See Moodle's [function reference](https://docs.moodle.org/dev/Web_service_API_functions) for their API names. A function missing from the restricted diagnostic service is expected to show **not advertised**. Do not broaden the token merely to turn every flag on.

The response must include a canonical `siteurl` matching the configured HTTPS installation. Authority case, the default HTTPS port and trailing slashes are normalized; installation path case and nondefault ports remain significant. Embedded credentials, query strings, fragments, control characters and relative dot segments are rejected. A missing or mismatched site URL fails with a fixed error, without echoing the remote value. Configure the canonical Moodle base URL rather than a proxy alias with a different reported address.

This verifies the installation address, not a permanent instance identity. Replacing a Moodle database behind the same URL cannot be detected by this handshake. Future identity/section links still need a reviewed instance binding and stable external IDs; the URL check does not establish those mappings.

Function discovery does not prove contextual permissions, mapping correctness, SSO readiness, grade compatibility or synchronization readiness. Returned account identities, profile URLs, site labels, arbitrary function names and upstream errors are discarded. The console reports fixed messages and known function names only, including in verbose mode. No student records or gradebooks are requested.

An isolated Guzzle client bypasses framework HTTP debug/observer hooks. Requests use TLS verification, no redirects, cookies or automatic retries, a 3-second connect timeout, a 15-second request/deadline budget and 5-second individual read timeouts. The final read can extend that deadline by up to its read timeout. Responses are capped at 512 KiB, JSON depth 16 and 2,000 function descriptors. Errors retain no upstream exception chain. The console command requires deployment-operator access. The separate administrator status/check routes expose only sanitized metadata under **sis:moodle:manage**.

Automated tests use fictional responses and injected clients. Disabled console commands and private administrator routes are tested through real application providers. The configured local Moodle service was checked through DDEV using the authenticated, read-only site-info request. No Moodle accounts, service tokens, plugins or settings were created or changed. Institutional OIDC, section mappings and academic synchronization remain subsequent stages. Direct launch is separately implemented and requires the acceptance checks in its setup guide; function discovery alone does not establish readiness.

## Next integration stages

- Extend reviewed person links with correction workflows, section links and source-of-truth rules. Do not silently match or merge people using email addresses alone.
- Implement optional SSO using the institution's chosen identity arrangement, with account disablement, logout and role boundaries. Assess Moodle's native capabilities before adding a plugin.
- Add transactional outbox references and bounded background workers for section/enrollment synchronization. Keep credentials and student payloads out of jobs; reauthorize work and make retries idempotent.
- Stage imported gradebook facts for authorized review. Preserve retained grading definitions and attributed academic corrections; an external grade change must not silently overwrite an authoritative SIS grade.
- Add reviewed enablement, mappings, retry/reconciliation screens and operational acceptance. Any required etSIS or Moodle plugin must preserve these boundaries.

These stages must honor institutional opt-in throughout. Moodle configuration does not authorize sending student data to an AI service; student progress guidance remains inside etSIS.

## Read-only mapping preflight

Deployment operators can inspect one explicit Moodle-owned user or course ID before designing an identity or section link:

```sh
# Fictional examples; use an explicitly authorized Moodle record ID.
ddev codex sis:moodle:inspect user 123
ddev codex sis:moodle:inspect course 456
```

The command extends Codefy's `ConsoleCommand` and has no browser route. It accepts only `user` or `course` and a canonical positive Moodle numeric ID. These are external references; etSIS people and sections retain their ULID primary keys. It never searches by email, username, course name or institution-assigned ID, and it cannot list all records.

Before a lookup, etSIS verifies the canonical site handshake and confirms that the token advertises the corresponding `core_user_get_users_by_field` or `core_course_get_courses_by_field` function. Requests fix the lookup field to `id` and supply exactly one value. The configured URL, token, TLS trust and enabled state must remain unchanged through the operation. Missing advertised functions cause refusal before any record lookup. Do not broaden the service merely to make discovery flags appear available; authorize these functions only when this preflight is required.

A successful lookup must return at most one record with the exact requested ID. etSIS discards names, email addresses, profile fields, course descriptions and other upstream data. Output is limited to whether a matching record was returned, user suspended/deleted flags, or course visibility. Missing flags remain **unknown** rather than becoming false. An empty response means **no matching record returned**: the record may be missing or hidden from the service account. A record match or visible course does not establish identity ownership, login eligibility, enrollment authority or academic standing.

This preflight creates no persistent links and makes no course, account, enrollment or grade changes. Reviewed section mappings, person-link corrections, SSO and background synchronization remain subsequent stages. Initial reviewed person links are now available separately. The separate installation approval below now saves the reviewed site/instance fingerprints. No live student or course records were used as test fixtures; automated lookup tests use fictional responses. Live checks are restricted to site-info metadata and disabled-mode command wiring.

The shared read-only gateway uses the same bounded, no-redirect, TLS-verified transport as the connection diagnostic. Preflight makes up to two requests: handshake and single-ID lookup. Each has the existing 15-second deadline/request budget and 5-second read timeout, so the complete operation can take approximately 40 seconds at those limits. It does not retry automatically or store profiles, raw responses, tokens or lookup results in application tables, caches or jobs. Protect terminal access and shell history as operator access; IDs are arguments, while tokens remain only in protected deployment settings. Verbose mode still returns fixed errors without upstream exception details.


## Installable Moodle connector and instance verification

The optional [connector ZIP](../dist/moodle/local_etsis-0.3.2-alpha.zip) is ready to distribute with its [checksum](../dist/moodle/local_etsis-0.3.2-alpha.zip.sha256). Its [installation guide](../integrations/moodle/local/etsis/README.md) covers Moodle's ZIP upload, explicit settings/service enablement, dedicated account permissions and token configuration. It adds no institutional academic tables and defaults to disabled, with a disabled restricted service and no automatic account, token or role grants. Native Moodle REST checks remain usable without it.

After an administrator installs and explicitly enables the plugin and restricted service, an etSIS deployment operator can run:

```sh
ddev codex sis:moodle:instance
```

The command extends Codefy's `ConsoleCommand`. Disabled etSIS configuration causes no network call. Otherwise it verifies the canonical site handshake, requires the advertised `local_etsis_get_instance_info` function and requests only protocol/installation identity. It accepts only protocol 1 and a lowercase 128-bit hexadecimal ID, rejects unexpected fields and discards results if configuration changes during the operation. It uses the same bounded TLS transport as single-ID preflight, with up to two requests and no automatic retry. It prints a SHA-256 fingerprint and fixed messages, never the raw identifier or upstream response. No database binding, identity matching, student lookup or synchronization occurs.

The Moodle function also requires the explicit system capability `local/etsis:viewinstance` and enabled plugin setting. Installation generates the opaque ID once in Moodle configuration. Reads refuse missing/malformed IDs rather than silently generating replacements. The ID survives ordinary plugin upgrades and same-installation restores. A cloned database inherits it: keep clones disconnected and review their identity before future mapping enablement. This stage does not detect every clone, prove ownership or implement rotation. The reviewed installation approval below now persists the fingerprints. Clone/restore reconciliation and controlled replacement remain subsequent work.

Build a new distribution from the etSIS repository root using `python3 tools/package-moodle.py`. The script uses a fixed public file manifest and reproducible ZIP metadata; it never boots etSIS or Moodle or reads their credentials. Publish the resulting ZIP and checksum together through the institution's chosen download host. Moodle's own local package validator accepts this alpha's layout and metadata; native Moodle PHPUnit tests are supplied but were not run against the application database. Cross-version installation and end-to-end service acceptance remain open. No plugin was installed or enabled as part of package validation.


## Review and approve the institutional installation

After installing and enabling the connector, open **Moodle integration → Approved Moodle installation** with **sis:moodle:manage**. The same administrative permission controls status, review, confirmation and explicit verification; unrelated catalog, student-record or access-management grants do not grant it. Initial setup requires no student or course lookup.

1. Enter an administrative approval reason without student information or credentials and select **Review installation**. etSIS reads the canonical site and installation identity, then presents their SHA-256 fingerprints. This creates no binding.
2. Compare the installation fingerprint with your deployment operator's `sis:moodle:instance` result for the intended institutional installation. Check the acknowledgement only after making that comparison. This is institutional review, not independent proof of site ownership.
3. Select **Approve reviewed installation** within 20 minutes. etSIS checks Moodle again and requires the same address and installation identity. It saves a single initial approval, including the approving user, UTC decision time and encrypted reason. Canceling, leaving the screen or refreshing clears a pending browser review without saving it.
4. Use **Verify approved installation** for a fresh comparison. Reading saved approval or refreshing status makes no Moodle request. The screen does not treat a saved approval as a current live verification.

Reviews are encrypted, purpose-specific, actor-bound and held only in browser memory. Confirmation rechecks current permission and enabled state, refuses expired/unconfirmed or changed-installation reviews and uses a unique institutional database scope to prevent two initial bindings. Network requests finish before the short write transaction. Approval and its metadata audit commit together; failed auditing or revoked permission rolls them back. A retry of the same completed review returns its original approval without another network request, audit entry or binding, even if the review later expires or the integration is disabled. It still requires the same authorized administrator.

The schema addition is `20261111000006_CreateSisMoodleBindings.php`. It adds only `sis_moodle_bindings`, with a ULID primary key, indexed unique institutional scope, site/instance fingerprints, approving actor/time and encrypted reason. It uses portable schema types and does not create or alter an event store. This administrative configuration is audited CRUD; it is not an academic or financial fact. No token, raw installation ID, URL, user profile, Moodle response or student payload is saved in this table. Reasons are excluded from ordinary status responses and audit metadata.

The private routes are GET `/sis/moodle/installation/` and POST `/sis/moodle/installation/review/`, `/confirm/`, `/verify/`. All require current Moodle-management permission and private/no-store responses; POST routes keep framework CSRF protection. Debug collectors remain suppressed. The gateway returns canonical address and instance identity from the same verified configuration snapshot; fingerprints cannot accidentally combine independently read configurations.

A site or identity mismatch blocks verification and leaves the approval untouched. There is intentionally no replace/delete button. Keep clones disconnected: a cloned Moodle database inherits the same identifier and cannot be distinguished solely by this check. Planned recovery/replacement workflows must explicitly assess existing links and preserve their provenance. Restoring the same installation should retain its original identity. Disabling integration stops new remote checks/approvals and does not erase the recorded approval.

Installation approval alone creates no person/section links and performs no SSO, enrollment or grade synchronization. The separate [reviewed person-link workflow](moodle-person-links.md) now creates explicit person references using an installation-bound single-ID reader. Existing connection/single-ID console diagnostics remain setup tools and are not bound synchronization operations. Future SSO and workers must use the saved binding and fresh installation checks before external effects; the staff-requested account worker now follows those boundaries; academic synchronization remains open. Tests use fictional gateway responses and never use real Moodle student records as fixtures.


## Troubleshooting installation review

A successful **Check Moodle connection** establishes the canonical HTTPS connection and token authentication. Installation review additionally calls the connector and checks its own enablement and capability requirements. Enabling an external service or adding authorized users does not enable the connector's separate setting.

If Moodle returns `integrationdisabled`, open **Site administration → Plugins → Local plugins → etSIS connector**, check **Enable instance verification** and save changes. Then retry **Review installation** in etSIS. A token that already authenticates and reaches this function does not need replacement to resolve that disabled-setting error. Do not disable TLS verification.

A new token is needed when moving to a different restricted service: authorize the integration account for **etSIS instance verification**, create a token for that service and update `SIS_MOODLE_TOKEN` securely. Merely installing the plugin does not move an existing token to the new service. Keep the token out of screenshots, URLs, logs and command arguments.

Known installation setup failures now display fixed local guidance for disabled verification, missing advertised function, denied permission or unavailable identity. etSIS never forwards Moodle's raw message, exception details or debugging information. Unexpected errors retain the generic connection failure. Console and administrator review paths preserve the same safe guidance; permission checks still run before any detail is returned.
