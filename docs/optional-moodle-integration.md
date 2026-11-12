# Optional Moodle integration

Moodle integration is optional and disabled by default. An institution must explicitly enable and configure it. Normal SIS workflows do not require Moodle. The current implementation provides a private administrator status screen, an operator REST diagnostic and a single-ID mapping preflight. SSO and background course/enrollment/gradebook synchronization remain separate development stages.

## Enable the connection diagnostic

Provision a dedicated Moodle integration account and a restricted external service containing `core_webservice_get_site_info`. Enable Moodle web services and its REST protocol, authorize that account for the service, and issue a service token. Avoid using a personal administrator token or the broad mobile-app service. Moodle's official [web-service setup guide](https://supportus.moodle.com/support/solutions/articles/80001016973-using-the-web-services-application-programming-interface-api-in-moodle) describes service functions, authorized users and tokens; its [REST client documentation](https://docs.moodle.org/dev/Creating_a_web_service_client) describes request parameters.

Set deployment secrets through the institution's protected environment or secret manager. The token must never be committed, passed as a CLI argument, put in a URL or exposed to browser code. No Moodle credentials are persisted in new application tables or caches in this stage.

```dotenv
SIS_MOODLE_ENABLED=true
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

Authorized staff can open **Moodle integration** in the backend sidebar. The screen reads the institution's enabled/disabled status without contacting Moodle. **Check Moodle connection** explicitly requests the same sanitized site-info diagnostic as the console command; **Refresh status** only rereads etSIS configuration. An advertised function is not proof that SSO, identity links or synchronization are enabled. Missing functions can be expected for a restricted service.

The independent protected permission **sis:moodle:manage** is added by `20261111000005_CreateSisMoodleAdministrationPermission.php`. The migration adds no role/user grants or broad SIS implications. Super administrators already have access; authorized access administrators can assign the permission through an institution-created role or direct user grant. Existing protected system roles remain unchanged. Ordinary catalog, student-read and access-management permissions alone do not grant Moodle administration.

The status GET and explicit check POST enforce current permission, private/no-store responses, suppressed debug collectors and framework CSRF protection for the POST. A check records only requesting-actor/action metadata before the external read; it does not save credentials, responses, profiles or capability results. The call runs outside database transactions and results are discarded if the administrator or permission changes. No per-user or course record lookup is exposed through this screen.

Configuration remains deployment controlled. The screen does not display/edit the URL, token or TLS settings and cannot turn on SSO or synchronization. Use the institutional setup above to enable or disable the optional connection.

## What the check establishes

The probe calls only `core_webservice_get_site_info`, with the token in the POST body. It reports a fixed allowlist of function-availability flags for site information, the optional instance-verification plugin, targeted user/course lookups, course creation/update, manual enrollment/unenrollment and user grade items. The lookup functions (`core_user_get_users_by_field` and `core_course_get_courses_by_field`) are discovered but never invoked by this check. They are intended for future reviewed identity/section mappings and are not required for this diagnostic. Add them to a restricted service only when the corresponding mapping workflow is being enabled. See Moodle's [function reference](https://docs.moodle.org/dev/Web_service_API_functions) for their API names. A function missing from the restricted diagnostic service is expected to show **not advertised**. Do not broaden the token merely to turn every flag on.

The response must include a canonical `siteurl` matching the configured HTTPS installation. Authority case, the default HTTPS port and trailing slashes are normalized; installation path case and nondefault ports remain significant. Embedded credentials, query strings, fragments, control characters and relative dot segments are rejected. A missing or mismatched site URL fails with a fixed error, without echoing the remote value. Configure the canonical Moodle base URL rather than a proxy alias with a different reported address.

This verifies the installation address, not a permanent instance identity. Replacing a Moodle database behind the same URL cannot be detected by this handshake. Future identity/section links still need a reviewed instance binding and stable external IDs; the URL check does not establish those mappings.

Function discovery does not prove contextual permissions, mapping correctness, SSO readiness, grade compatibility or synchronization readiness. Returned account identities, profile URLs, site labels, arbitrary function names and upstream errors are discarded. The console reports fixed messages and known function names only, including in verbose mode. No student records or gradebooks are requested.

An isolated Guzzle client bypasses framework HTTP debug/observer hooks. Requests use TLS verification, no redirects, cookies or automatic retries, a 3-second connect timeout, a 15-second request/deadline budget and 5-second individual read timeouts. The final read can extend that deadline by up to its read timeout. Responses are capped at 512 KiB, JSON depth 16 and 2,000 function descriptors. Errors retain no upstream exception chain. The console command requires deployment-operator access. The separate administrator status/check routes expose only sanitized metadata under **sis:moodle:manage**.

Automated tests use fictional responses and injected clients. Disabled console commands and private administrator routes are tested through real application providers. The configured local Moodle service was checked through DDEV using the authenticated, read-only site-info request. No Moodle accounts, service tokens, plugins or settings were created or changed. SSO, mappings and synchronization remain unimplemented; function discovery is not operational acceptance of those workflows.

## Next integration stages

- Establish reviewed, stable identity links and source-of-truth rules. Do not silently match or merge people using email addresses alone.
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

This preflight creates no persistent links and makes no course, account, enrollment or grade changes. Actual reviewed mappings, instance bindings, SSO and background synchronization remain subsequent stages. No live student or course records were used as test fixtures; automated lookup tests use fictional responses. Live checks are restricted to site-info metadata and disabled-mode command wiring.

The shared read-only gateway uses the same bounded, no-redirect, TLS-verified transport as the connection diagnostic. Preflight makes up to two requests: handshake and single-ID lookup. Each has the existing 15-second deadline/request budget and 5-second read timeout, so the complete operation can take approximately 40 seconds at those limits. It does not retry automatically or store profiles, raw responses, tokens or lookup results in application tables, caches or jobs. Protect terminal access and shell history as operator access; IDs are arguments, while tokens remain only in protected deployment settings. Verbose mode still returns fixed errors without upstream exception details.


## Installable Moodle connector and instance verification

The optional [connector ZIP](../dist/moodle/local_etsis-0.1.0-alpha.zip) is ready to distribute with its [checksum](../dist/moodle/local_etsis-0.1.0-alpha.zip.sha256). Its [installation guide](../integrations/moodle/local/etsis/README.md) covers Moodle's ZIP upload, explicit settings/service enablement, dedicated account permissions and token configuration. It adds no institutional academic tables and defaults to disabled, with a disabled restricted service and no automatic account, token or role grants. Native Moodle REST checks remain usable without it.

After an administrator installs and explicitly enables the plugin and restricted service, an etSIS deployment operator can run:

```sh
ddev codex sis:moodle:instance
```

The command extends Codefy's `ConsoleCommand`. Disabled etSIS configuration causes no network call. Otherwise it verifies the canonical site handshake, requires the advertised `local_etsis_get_instance_info` function and requests only protocol/installation identity. It accepts only protocol 1 and a lowercase 128-bit hexadecimal ID, rejects unexpected fields and discards results if configuration changes during the operation. It uses the same bounded TLS transport as single-ID preflight, with up to two requests and no automatic retry. It prints a SHA-256 fingerprint and fixed messages, never the raw identifier or upstream response. No database binding, identity matching, student lookup or synchronization occurs.

The Moodle function also requires the explicit system capability `local/etsis:viewinstance` and enabled plugin setting. Installation generates the opaque ID once in Moodle configuration. Reads refuse missing/malformed IDs rather than silently generating replacements. The ID survives ordinary plugin upgrades and same-installation restores. A cloned database inherits it: keep clones disconnected and review their identity before future mapping enablement. This stage does not detect every clone, prove ownership or implement rotation. Persisted instance bindings and clone/restore reconciliation remain subsequent work.

Build a new distribution from the etSIS repository root using `python3 tools/package-moodle.py`. The script uses a fixed public file manifest and reproducible ZIP metadata; it never boots etSIS or Moodle or reads their credentials. Publish the resulting ZIP and checksum together through the institution's chosen download host. Moodle's own local package validator accepts this alpha's layout and metadata; native Moodle PHPUnit tests are supplied but were not run against the application database. Cross-version installation and end-to-end service acceptance remain open. No plugin was installed or enabled as part of package validation.
