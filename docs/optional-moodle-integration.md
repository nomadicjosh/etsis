# Optional Moodle integration

Moodle integration is optional and disabled by default. An institution must explicitly enable and configure it. Normal SIS workflows do not require Moodle. The first implementation is an operator-only REST connection diagnostic; SSO and background course/enrollment/gradebook synchronization remain separate development stages.

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

## What the check establishes

The probe calls only `core_webservice_get_site_info`, with the token in the POST body. It reports a fixed allowlist of function-availability flags for site information, targeted user/course lookups, course creation/update, manual enrollment/unenrollment and user grade items. The lookup functions (`core_user_get_users_by_field` and `core_course_get_courses_by_field`) are discovered but never invoked by this check. They are intended for future reviewed identity/section mappings and are not required for this diagnostic. Add them to a restricted service only when the corresponding mapping workflow is being enabled. See Moodle's [function reference](https://docs.moodle.org/dev/Web_service_API_functions) for their API names. A function missing from the restricted diagnostic service is expected to show **not advertised**. Do not broaden the token merely to turn every flag on.

The response must include a canonical `siteurl` matching the configured HTTPS installation. Authority case, the default HTTPS port and trailing slashes are normalized; installation path case and nondefault ports remain significant. Embedded credentials, query strings, fragments, control characters and relative dot segments are rejected. A missing or mismatched site URL fails with a fixed error, without echoing the remote value. Configure the canonical Moodle base URL rather than a proxy alias with a different reported address.

This verifies the installation address, not a permanent instance identity. Replacing a Moodle database behind the same URL cannot be detected by this handshake. Future identity/section links still need a reviewed instance binding and stable external IDs; the URL check does not establish those mappings.

Function discovery does not prove contextual permissions, mapping correctness, SSO readiness, grade compatibility or synchronization readiness. Returned account identities, profile URLs, site labels, arbitrary function names and upstream errors are discarded. The console reports fixed messages and known function names only, including in verbose mode. No student records or gradebooks are requested.

An isolated Guzzle client bypasses framework HTTP debug/observer hooks. Requests use TLS verification, no redirects, cookies or automatic retries, a 3-second connect timeout, a 15-second request/deadline budget and 5-second individual read timeouts. The final read can extend that deadline by up to its read timeout. Responses are capped at 512 KiB, JSON depth 16 and 2,000 function descriptors. Errors retain no upstream exception chain. The command requires deployment-operator access; no browser route is exposed.

Automated tests use fictional responses and injected clients. The disabled command is tested through real application providers. The configured local Moodle service was checked through DDEV using the authenticated, read-only site-info request. No Moodle accounts, service tokens, plugins or settings were created or changed. SSO, mappings and synchronization remain unimplemented; function discovery is not operational acceptance of those workflows.

## Next integration stages

- Establish reviewed, stable identity links and source-of-truth rules. Do not silently match or merge people using email addresses alone.
- Implement optional SSO using the institution's chosen identity arrangement, with account disablement, logout and role boundaries. Assess Moodle's native capabilities before adding a plugin.
- Add transactional outbox references and bounded background workers for section/enrollment synchronization. Keep credentials and student payloads out of jobs; reauthorize work and make retries idempotent.
- Stage imported gradebook facts for authorized review. Preserve retained grading definitions and attributed academic corrections; an external grade change must not silently overwrite an authoritative SIS grade.
- Add reviewed enablement, mappings, retry/reconciliation screens and operational acceptance. Any required etSIS or Moodle plugin must preserve these boundaries.

These stages must honor institutional opt-in throughout. Moodle configuration does not authorize sending student data to an AI service; student progress guidance remains inside etSIS.
