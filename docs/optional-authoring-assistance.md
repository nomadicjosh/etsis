# Optional course authoring assistance

Catalog editors can request publication-readiness suggestions, generate a description draft, or rewrite/expand an existing description from **Titles & description → Optional AI help**. Assistance is disabled by default. This first stage supports saved course drafts; section authoring, degree authoring, and student progress remain future work.

Staff inspect the exact course facts, select consent for one request, and choose an action. Rewrite improves clarity while preserving the source meaning; expand asks for fuller explanations of supplied facts. Both require a saved, nonblank description and have their own action grants. Missing facts should be raised as questions rather than invented. Suggestions can still be inaccurate and require staff review.

Results are plain text. **Use suggested description in this editor** copies text into the editor; **Save course text draft** remains an explicit, separate action. Publication retains the existing reviewed, immutable workflow. Readiness suggestions are advisory and do not decide whether publication is permitted.

## Institutional setup

1. Review the service's data processing, retention, location and no-training terms. Use an institution-operated service if those requirements cannot be met externally. The application cannot enforce a remote provider's training or retention practices through a request parameter.
2. Configure the provider through deployment settings, keeping its key out of source control. Set `SIS_AI_DATA_POLICY_APPROVED=true` only after that review, then enable the feature. Both settings must be true.
3. Run the normal migration process. `20261111000001_CreateSisCourseAssistancePermissions.php` adds readiness/generation permissions; `20261111000002_CreateSisCourseTextAssistancePermissions.php` adds rewrite/expand permissions. Both preserve existing grants. They use portable query-builder operations and ULIDs, without schema changes. Test coverage exercises SQLite; other configured databases still need deployment verification.
4. A super administrator or authorized access administrator grants the appropriate **sis:ai:course:readiness**, **sis:ai:course:description**, **sis:ai:course:rewrite** or **sis:ai:course:expand** permission through Access administration, using an institution-created role or direct user grants. Each action also requires **sis:catalog:manage**. Existing protected system roles remain unchanged; super authority includes these actions. Ordinary catalog permission alone cannot call a provider, and description generation does not also grant rewrite or expand.
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

## Data and historical safeguards

The outbound business payload contains course code, title, transcript title, description, credits, prerequisite count and subject/classification labels. It excludes internal IDs, people/student records, prerequisite identities, publication reasons and historical events. Free text is not automatically redacted: staff must inspect it and remove confidential or personal content before consenting. The preview shows the business payload; the adapter adds its fixed task instructions, model identifier and output schema.

The service verifies the editor's identity, catalog permission, action permission, saved definition and provider fingerprint before calling the provider and again before returning suggestions. A changed draft, changed provider or revoked permission prevents results from being used. The call runs outside database transactions and write locks. A metadata-only audit entry records the actor, course and requested action; it deliberately contains no prompt, model response, endpoint or credentials.

An isolated HTTP client bypasses the framework's request/response debug hooks. Requests use TLS verification, no redirects, no automatic retries, a 3-second connection timeout, a 15-second request/deadline budget, 5-second individual body-read timeouts, a 64 KiB business-input limit, 128 KiB response limit and 2,000 output tokens. Depending on transport, the last body read can extend the deadline by up to its 5-second read timeout. Invalid, refused, incomplete or unexpected structured output produces a fixed error without an upstream exception chain. Normal editing remains available.

Suggestions and prompts are not written to application caches, files, jobs, browser storage or academic events. They appear in the current authorized response/DOM. Private routes retain authentication/authorization, framework CSRF protection, no-store responses and suppressed debug collectors. Explicitly saving a suggested description uses the ordinary revision-checked draft editor; publishing later retains the staff-approved historical facts.
