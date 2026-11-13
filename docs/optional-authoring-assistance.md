# Optional catalog authoring assistance

Catalog editors can request publication-readiness suggestions, generate a description draft, or rewrite/expand an existing description from **Titles & description → Optional AI help**. Assistance is disabled by default. Course assistance supports saved drafts. Sections and reusable course groups also have saved-setup readiness reviews; degrees have a saved-setup readiness review; student progress guidance stays inside etsis and does not use an AI provider.

Staff inspect the exact course facts, select consent for one request, and choose an action. Rewrite improves clarity while preserving the source meaning; expand asks for fuller explanations of supplied facts. Both require a saved, nonblank description and have their own action grants. Missing facts should be raised as questions rather than invented. Suggestions can still be inaccurate and require staff review.

Results are plain text. **Use suggested description in this editor** copies text into the editor; **Save course text draft** remains an explicit, separate action. Publication retains the existing reviewed, immutable workflow. Readiness suggestions are advisory and do not decide whether publication is permitted.

## Institutional setup

1. Review the service's data processing, retention, location and no-training terms. Use an institution-operated service if those requirements cannot be met externally. The application cannot enforce a remote provider's training or retention practices through a request parameter.
2. Configure the provider through deployment settings, keeping its key out of source control. Set `SIS_AI_DATA_POLICY_APPROVED=true` only after that review, then enable the feature. Both settings must be true.
3. Run the normal migration process. `20261111000001_CreateSisCourseAssistancePermissions.php` adds readiness/generation permissions; `20261111000002_CreateSisCourseTextAssistancePermissions.php` adds rewrite/expand permissions. `20261111000003_CreateSisSectionAssistancePermission.php` adds section readiness and `20261111000004_CreateSisDegreeAssistancePermission.php` adds degree setup readiness. All preserve existing grants. They use portable query-builder operations and ULIDs, without schema changes. Automated migration tests exercise SQLite; the degree-assistance permission migration was also applied and verified on MySQL through DDEV. Other configured databases still need deployment verification.
4. A super administrator or authorized access administrator grants the appropriate **sis:ai:course:readiness**, **sis:ai:course:description**, **sis:ai:course:rewrite**, **sis:ai:course:expand**, **sis:ai:section:readiness** or **sis:ai:degree:readiness** permission through Access administration, using an institution-created role or direct user grants. Each action also requires **sis:catalog:manage**. Existing protected system roles remain unchanged; super authority includes these actions. Ordinary catalog permission alone cannot call a provider, and description generation does not also grant rewrite or expand.
5. Exercise the configured service in the institution's test environment. Automated tests use fictional data and injected providers; they do not verify a live account, deployment or model.

OpenAI Responses configuration:

```dotenv
SIS_AI_ENABLED=true
SIS_AI_DATA_POLICY_APPROVED=true
SIS_AI_LABEL="Institutional authoring service"
SIS_AI_ENDPOINT=https://api.openai.com/v1
SIS_AI_PROTOCOL=responses
SIS_AI_AUTH=bearer
SIS_AI_KEY=
SIS_AI_MODEL=
SIS_AI_ALLOW_HTTP=false
```

Supply an approved key and a model supporting strict JSON Schema output. The model has no default. The adapter sends `store: false`, which disables storage for later API retrieval; it does **not** promise zero provider retention. See the official [structured output guide](https://developers.openai.com/api/docs/guides/structured-outputs) and [Responses API reference](https://developers.openai.com/api/reference/resources/responses/methods/create).

For Azure's v1 API, set `SIS_AI_ENDPOINT=https://YOUR-RESOURCE.openai.azure.com/openai/v1`, `SIS_AI_AUTH=api-key`, and use the deployment name as `SIS_AI_MODEL`. The same `responses` protocol applies. See Microsoft's [Azure OpenAI v1 API documentation](https://learn.microsoft.com/en-us/azure/foundry/openai/api-version-lifecycle). This adapter supports configured API keys, not managed identity token renewal.

For an institution-operated OpenAI-compatible server, configure its base URL and model. `SIS_AI_PROTOCOL=chat` uses `/chat/completions` and strict `response_format`; `responses` uses `/responses` and strict `text.format`. The server must support that protocol, structured output, output token limits and `store: false`; unsupported services fail safely. Use `SIS_AI_AUTH=none` only where institutional network controls make unauthenticated service access appropriate. HTTP requires an explicit `SIS_AI_ALLOW_HTTP=true`; HTTPS remains the default.

Additional providers implement `Domain\Assistance\Service\AuthoringAssistant` and replace its provider binding. Their availability fingerprint must change whenever the destination, model, credentials or data policy changes. Browser clients never supply those settings. Changing configuration invalidates existing consent previews without changing ordinary course editing.

To rewrite or expand, save the current description, reopen **Optional AI help**, inspect the preview and consent to the selected action. The original editor text remains until staff select **Use suggested description in this editor**. If staff have edited the description since the preview, copying is blocked so the later edits are preserved. Each further request requires a fresh consent check.

## Saved section readiness

In **Sections → Published section details → Optional section AI help**, staff inspect saved setup and consent to one request. The preview sends section code, capacity, delivery mode, a teaching-assignment-present flag, term code/start/end dates and a minimized retained course definition when one is bound. That definition includes course code/title, credits, transcript title, description and prerequisite count. An unbound course is shown as unknown rather than replaced with today's draft. Do not consent if the preview contains confidential content; correct its source through the normal governed workflow before requesting assistance.

This action excludes student records, faculty identities, fees, reasons, internal IDs and unsaved instructional dates in the publication form. Check proposed dates and reasons separately. The form stays open with its existing values; suggestions cannot edit, publish, activate or select an offering. Online delivery does not imply a room requirement. Staff must verify suggestions against institutional policy.

Section assistance requires both **sis:catalog:manage** and **sis:ai:section:readiness**; course AI grants do not provide section authority. The snapshot uses short section/course/term locks and indexed retained-definition reads, without replaying publication history. Network work starts after those locks are released. Saved setup, provider configuration, actor and permissions are rechecked before returning results; a changed source requires a new preview. Consent is reset after each attempt, including failures.

## Saved course-group readiness

Open **Academic catalog → Course group library → Build a group → Optional group AI help**. Save the group's settings first, inspect the saved facts, consent to one request, then choose **Suggest saved group checks**. This action requires both `sis:catalog:manage` and the independent protected permission `sis:ai:group:readiness`. Migration `20261112000010_CreateSisCourseGroupAssistancePermission.php` adds it without changing existing roles or grants. Course, degree and description assistance permissions do not imply it. Earlier groups using the average-GPA format must first be updated explicitly through the existing builder.

The preview includes the saved group name, credit/course quantity, optional per-course grade-point floor, saved grading maximum, a program-grading-context selection flag and all/choose/pool rules. Course code, catalog title and credits come from the exact selected immutable publications. One-based positions connect rules to those courses; they are request-local references, not database keys. Internal IDs, student records, course descriptions, publication reasons, raw history and credential details are excluded. Inspect all supplied text before consenting.

Verification uses one shared history budget across all selected course streams: at most 200 pages and 2,000 events per snapshot, plus the 64 KiB outbound business-input limit. Oversized or unverifiable groups are not partially sent. One short transaction locks the saved group for a consistent preview; network work runs after that transaction ends. The saved group, selected facts, provider and current authorizations are rechecked before results are returned. A later edit in the builder discards the pending suggestions.

The grade floor applies to **each course**, not an average GPA. The maximum comes from the saved group; the provider must not assume a 4-point scale or invent institutional minimums. Suggestions may highlight quantities or overlapping rules that need review, but they cannot certify curriculum validity, accreditation, course availability, degree coverage or student eligibility. Results are advisory plain text; there is no automatic edit, save or publication. Groups remain independent reusable blocks. The group and degree editors share one consent and stale-draft interface.

## Saved degree setup readiness

In the **Degree audit builder → Optional degree AI help**, save the current draft, inspect the exact setup summary and consent to one request. The independent **sis:ai:degree:readiness** permission and **sis:catalog:manage** are both required. Course and section assistance grants do not grant degree assistance.

The summary includes the requirement title, program code/title, academic year, duration, credit/GPA minimums, institution-selected policy values and a publication-selection flag for each group. Internal IDs, group contents, courses, students, academic work and publication reasons are excluded. This is a setup review, not a coverage check, degree evaluation, accreditation review or eligibility decision. Verify group contents and institutional requirements separately.

Only saved composition drafts using reusable course groups are supported. Earlier embedded-group drafts must be converted through the existing builder first. Short program/year/template locks protect the snapshot; assistance does not replay group or degree history. Provider calls run after the transaction ends. Changed saved context, provider settings or revoked permissions invalidate the result. The browser also discards suggestions if the editor changes during the request. Suggestions cannot modify a draft, publish requirements or assign requirements to a student.

## Academic program descriptions

Open **Academic catalog → Programs → Program details & publications → Optional AI help**. Save working program changes first, inspect exactly what will be sent, then consent to one request. **Suggest a program description**, **Rewrite description** and **Expand description** have independent permissions: `sis:ai:program:description`, `sis:ai:program:rewrite` and `sis:ai:program:expand`, each also requiring `sis:catalog:manage`. Rewrite and expansion appear only after a nonblank description has been saved. Apply migration `20261112000009_CreateSisProgramAssistancePermissions.php`; it creates protected system permissions without adding broad role grants. Super administrators already have access; grant other authors the selected actions through access administration.

Only program code, title, saved description and department/academic-level/award labels are sent, identified as an academic program. Internal identifiers, students, curriculum requirements, publication reasons and historical publications are excluded. The same configured provider and structured-output contract serve courses and programs. Suggestions cannot invent accreditation or academic requirements.

**Use suggested description in this editor** copies text only if the program editor is unchanged. **Save program details** remains explicit, and publishing for an academic year remains a separate reviewed decision. Changes to the saved definition, provider or current permissions invalidate pending assistance. The request records only actor/program/action metadata; prompts and suggestions are not added to historical events.

## Troubleshooting unavailable assistance

For OpenAI, use `SIS_AI_AUTH=bearer` with a server-side `SIS_AI_KEY`; `none` omits the credential even when a key is present. Use `api-key` only for a service expecting that header, such as the Azure configuration above. `none` is reserved for explicitly configured services that require no authentication. The official OpenAI endpoint rejects non-bearer configuration locally before a network request. See [OpenAI authentication](https://developers.openai.com/api/reference/overview#authentication).

After changing `.env`, reload the editor and open a fresh consent preview. Earlier previews are invalidated when provider settings change. Authentication failures now ask the administrator to check the credential and authentication mode; usage/rate-limit failures ask them to check provider usage and billing; rejected requests ask them to check model access, API protocol and structured-output support. These are fixed messages derived from HTTP status, without upstream error bodies or exception chains. A successful connection does not establish that an institution's provider terms satisfy its data policy.

## Student progress stays local

Student progress guidance runs inside etsis using the already authorized, captured [degree-progress review](student-degree-progress.md#next-steps-for-this-review). Do not send student progress, grades, earned-credit totals or group-placement results to an AI provider. This includes institution-operated model endpoints: no student-progress model calls are implemented or enabled by the catalog assistance configuration. Any future change to this boundary requires a new explicit project decision.

Catalog readiness and description actions remain separate. Their action grants and institutional provider approval do not authorize student-record processing.

## Data and historical safeguards

For course assistance, the outbound business payload contains course code, title, transcript title, description, credits, prerequisite count and subject/classification labels. It excludes internal IDs, people/student records, prerequisite identities, publication reasons and historical events. Free text is not automatically redacted: staff must inspect it and remove confidential or personal content before consenting. The preview shows the business payload; the adapter adds its fixed task instructions, model identifier and output schema.

The service verifies the editor's identity, catalog permission, action permission, saved definition and provider fingerprint before calling the provider and again before returning suggestions. A changed draft, changed provider or revoked permission prevents results from being used. The call runs outside database transactions and write locks. A metadata-only audit entry records the actor, course and requested action; it deliberately contains no prompt, model response, endpoint or credentials.

An isolated HTTP client bypasses the framework's request/response debug hooks. Requests use TLS verification, no redirects, no automatic retries, a 3-second connection timeout, a 15-second request/deadline budget, 5-second individual body-read timeouts, a 64 KiB business-input limit, 128 KiB response limit and 2,000 output tokens. Depending on transport, the last body read can extend the deadline by up to its 5-second read timeout. Invalid, refused, incomplete or unexpected structured output produces a fixed error without an upstream exception chain. Normal editing remains available.

Suggestions and prompts are not written to application caches, files, jobs, browser storage or academic events. They appear in the current authorized response/DOM. Private routes retain authentication/authorization, framework CSRF protection, no-store responses and suppressed debug collectors. Explicitly saving a suggested description uses the ordinary revision-checked draft editor; publishing later retains the staff-approved historical facts.
