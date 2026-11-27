# Campus portal

[Full-page publishing and website menus](website-publishing-and-menus.md) now connect the Vihzhuo designer to reviewed approval/scheduling, public revision checks and a professional nested navigation builder. News retains its separate existing content workflow. Website authors use `vihzhuo:manage`; full-page/menu publishing additionally uses `sis:portal:publish`.

The portal uses Bootstrap 5, local assets, and Vihzhuo native PHP blocks. It reads the existing academic and event-sourced financial services. No demonstration students, balances, or news are installed in the institution database.

Signed-in pages now share the [notification bell, recent action feedback and optional sound choices](workspace-notifications.md#student-and-applicant-portal) with the staff workspace. Choose Message, Warm success, Bird, Piano or Short tone and test it; sound defaults off. Only display/sound preferences persist in this browser. Recent messages clear when leaving the page and do not replace Admissions updates, Booking updates or request status.

The same bell now includes [Campus announcements](campus-announcements.md): explicitly approved campus news, its publication window and per-account read state. Opening an announcement does not mark it read. **Mark as read** records only the displayed revision; revised/reapproved wording becomes unread again. This is separate from temporary action feedback and formal policy acknowledgement.

## Public campus events

See the consolidated [public campus event workflow](public-campus-events.md) for publishing, the calendar, Vihzhuo blocks, permissions and cancellation behavior.

## Routes and setup

- `/portal/` — public welcome page; `/` uses this as its fallback when Vihzhuo has no matching homepage.
- `/portal/courses/` — public course sections, term filters, keyword search, seats, credits, and section fees. Human codes such as `26/FA` and `MATH.101` remain display/search values; record links use ULIDs.
- `/portal/news/` — explicitly public, published news, with individual article pages and older-news navigation.
- `/portal/events/` — explicitly published public campus events, with institution-timezone week/month views, day selection, search and daily pages. Forms and links work without JavaScript; AJAX enhances the same server-rendered calendar.
- `/portal/apply/` — program selection, paged program search, entry term and review before submission. Signed-in applicants can reopen their own submitted answers on demand, including staff-assisted applications, without losing a new application being edited. Existing application forms and conditional rules are reused.
- `/portal/student/` — signed-in overview, applications, personal information, grades/status, degree progress, registration, transcripts, accounts, and campus updates.
- `/login/` and `/register/` — matching campus account screens, retaining configured authentication and CSRF protection. An allowlisted application continuation returns applicants to the application page. Staff otherwise land in the staff workspace; students and prospects land in My campus.

Apply the new `20261107000000_CreateCampusPortalTables.php` migration through the existing migration workflow before opening the portal. It adds audiences, encrypted contact information, transcript requests, and checkout checkpoints. It also adds the protected `sis:transcript:manage` permission. No live migration is run by the tests or by rendering a page.

Set `PORTAL_NAME` and `PORTAL_THEME` in the environment. Campus Harbor is the default. Keep the existing application encryption key: contacts, transcript recipients, application answers, and payment checkpoints depend on it. Personal information belongs to the canonical person record; sign-in credentials remain attached through its owner account. Assigned institutional IDs continue to resolve from the person identity table.

## Degree progress

**Degree progress** is an on-demand, owner-only view of retained program requirements and verified work. Pursuit choices are paged; selecting one reuses the staff calculation engine, with a separate minimized student response and the same local guide. Unknown/limited results remain explicit. The Grades screen links to this policy-based review instead of calculating GPA from transcript rows in the browser. See [scope, privacy and limits](student-degree-progress.md#student-portal).

## Vihzhuo themes and native blocks

The themes in `public/themes` are:

| Theme | Character |
| --- | --- |
| `campus-harbor` | Warm paper, blue accents, academic serif typography, illustrated courtyard |
| `campus-quad` | Heritage green and gold, arched illustration, formal program cards |
| `campus-horizon` | Plum accents, contemporary headings, softer shapes |

Quad and Horizon inherit Harbor's page structure while supplying their own CSS. Harbor inherits the existing Bootstrap Business blocks, so the existing theme and registered `resources/blocks` remain available. Theme assets use clean `/themes/{theme}/...` URLs. `public/.htaccess` maps these internally to each theme's physical `public/` directory; `public/` is not part of the generated URL. The application `WebsiteTheme` resolves the actual owning theme before emitting that URL, so child-theme overrides and inherited parent assets both work. DDEV uses Apache; another web server needs the equivalent static-file mapping.

Use **Page builder** in the staff workspace or open `/admin/manager`. Creating a page with the **Campus portal — complete page** layout offers a starter in the editor when the page has no content. It contains six native blocks: welcome, pathways, live course offerings, public news, programs, and application invitation. The welcome copy and live-section headings have block settings. Existing pages are never overwritten. Save through the builder to persist the starter; choose `/` as the page route to use it as the institution homepage. `/portal/` remains the application-provided welcome route.

The native blocks are registered in `CampusBlocksServiceProvider` and live in `resources/blocks/campus-*`. They render through Vihzhuo's real block renderer in both public and editor modes. Live blocks read public academic/news data only. They never embed a student's record in editable CMS content. Page caching is disabled because the layout can include a signed-in state and CSRF token. Configure trusted staff with `vihzhuo:manage` for the page builder.

## News and transcript operations

In **Campus content**, create a news draft and choose **Signed-in campus** or **Public website**. The [news editor and publication workflow](#news-editor-and-publication) below cover author handoff, publisher review, scheduling and archiving. Editing an approved or archived item creates a new draft before it can appear again. Content without an explicit audience record remains campus-only.

Students can request an official transcript for pickup or electronic registrar review. Requests are idempotent; recipients are encrypted. **Transcript requests** is a permission-controlled registrar queue. Its lifecycle is pending → ready → fulfilled. Only the requesting owner can cancel a pending request. Marking a request fulfilled records that staff completed delivery through the institution's approved process; this portal does not automatically email an academic record. The unofficial transcript can be printed separately.

## Optional bill payment

Checkout is disabled by default. The Pay bill action explains that the institution must configure a payment provider. The included adapter uses the official `stripe/stripe-php` package with hosted card checkout; card data never reaches this application. Another provider can implement the domain `PaymentGateway` port.

To configure Stripe, set an HTTPS `APP_BASE_URL`, `PORTAL_PAYMENTS_ENABLED=true`, `STRIPE_SECRET_KEY`, and `STRIPE_WEBHOOK_SECRET`. Start with test-mode credentials and `PORTAL_PAYMENTS_LIVE=false`. Live operation requires both live credentials and `PORTAL_PAYMENTS_LIVE=true`; a mismatched key mode leaves checkout disabled.

Register the HTTPS endpoint `/portal/payments/webhook/` for `checkout.session.completed` and `checkout.session.async_payment_succeeded` in the same Stripe account/mode as the API key. Follow Stripe's [fulfillment guidance](https://docs.stripe.com/checkout/fulfillment) and [webhook signature guidance](https://docs.stripe.com/webhooks/signature). Keep the server clock accurate for timestamp validation.

The server determines the account balance and currency, verifies ownership, and reuses an active checkout for retries. Intent IDs are provider idempotency keys. The URL checkpoint is encrypted. Provider calls run outside database transactions, with bounded connection/request timeouts. A verified paid notification must match the persisted amount, currency, and provider session. Confirmation and the event-sourced account payment commit together; duplicate notifications cannot post a second payment. Returning from checkout only displays a status message and never credits an account. Disabling new checkouts still allows valid notifications for existing payments, as long as the webhook secret and expected mode remain configured. If the balance changes during checkout, the account retains the actual payment as an immutable entry; normal finance procedures handle any resulting credit.

The webhook reads its raw signed body before constructing the router's legacy scalar JSON parser. It uses signature authentication rather than a browser CSRF token. All other browser mutations retain the normal framework CSRF pipeline and the typed `_workspace_json` form transport. Unexpected/transient webhook failures return a generic retry response without recording payloads or upstream error details in logs. Configure provider retry monitoring and institutional reconciliation before accepting live payments. Automated Stripe refunds/disputes and transcript-email delivery are outside this adapter's scope; financial corrections use the existing staff finance workflows.

## Optional application and transcript fees

[Service fee policies and workflows](service-fees.md) connect hosted payment and reasoned staff waivers to application submission and official transcript requests. Both fees default to zero. Prospects can pay through their sign-in/person account before matriculation; service fees stay separate from student-account bill payments. Server-confirmed receipts govern clearance. Unofficial academic/CEU records remain free, and fee payment does not automatically release or deliver an official transcript.

## Privacy and validation

Portal reads/writes require the signed-in owner, including when that person also holds staff permissions. Registrar processing uses its dedicated permission. Private records are never placed in browser storage. Responses are private/no-store, with CSRF, escaped rendering, focus indicators, semantic labels, accessible errors, reduced motion, and keyboard-scrollable tables. Debug collectors are suppressed on portal, root, admin, and account-entry routes.

Tests use fictional records, an in-memory database, an injected payment gateway, and locally signed fictional Stripe events. They do not access a live student database or charge a card. Schema compilation covers the framework's MySQL, PostgreSQL, SQLite, SQL Server, and Oracle dialects; execution tests use SQLite. Playwright uses one browser worker against an isolated PHP fixture and runs axe checks for WCAG 2.2 AA on public themes and student flows. Configure `ETSIS_TEST_PHP` to the project's PHP interpreter before running the browser tests. Tests and static analysis should run sequentially with memory limits; no DDEV startup is needed.

Staff operations, catalog editing, registration, grading, billing, transfer credit and campaign delivery are described in [backend-workspace.md](backend-workspace.md). They remain in the staff workspace at `/admin/`; the student portal continues to enforce owner-only access.


## Course drops

Registration changes show a fresh review before confirmation. During the inclusive drop/add grace period, the course’s enrollment/academic credit is removed and its original course charge reversed. Payments and other charges determine any remaining credit or balance. Cash refunds require a separate institutional review. After grace, the course remains with the term’s configured grade (for example D or W), zero earned credits and its original charge; GPA follows the catalog grade configuration. The default late marker is W outside GPA.

Confirmations are bound to the reviewed enrollment/version, institutional date and policy. **Check saved drop** recovers an interrupted response without another mutation. An old retry cannot drop a newly added enrollment. Portal review, confirmation and receipt recovery all require the signed-in student owner; staff privileges do not bypass portal ownership. The new term policy/receipt migration must be applied before using this flow; it has not been applied to an institutional database during development.


## News editor and publication

News uses self-hosted **Tiptap / ProseMirror**, with the editor packages under the [MIT license](https://github.com/ueberdosis/tiptap/blob/main/LICENSE.md). Its toolbar provides headings, bold, italic, underline, lists, quotes, links, undo/redo and clear formatting. Controls have accessible names, keyboard access and visible focus. The editor loads only when needed and keeps unfinished content in page memory. Saving a draft does not publish it. Text pages and website links retain their appropriate existing fields; Vihzhuo remains the full-page designer.

The editor uses pinned `@tiptap/core`, `@tiptap/pm` and `@tiptap/starter-kit` **3.35.0**. Only headings 2/3, ordinary text marks, lists, quotes, links and bounded undo/redo are enabled. Code blocks, horizontal rules, media and collaboration are not enabled. Link actions reject executable schemes and credentials; editor paste is sanitized before parsing, and server sanitization remains authoritative for every save/read. Rich drafts survive a content-type round trip when the intervening plain text is unchanged. Empty or oversized drafts stay editable with accessible errors; a failed editor load blocks saving, preserves the original content/format and offers **Retry news editor**. Closing/replacing the form destroys the editor and cancels late mounting.

On 2026-11-20 this replaced Quill 2.0.3 following review of [its unpatched HTML-export advisory](https://github.com/advisories/GHSA-v3m3-f69x-jf25). Existing news remains stored as sanitized HTML or explicitly literal plain text, so no database conversion or automatic republication is performed. Original publication decisions and rendered-content protections stay intact. Tiptap is an editing dependency, not a security boundary: keep package advisories and the sanitizers under review.

`npm run build:assets` uses the pinned local esbuild tool to produce `public/assets/vendor/tiptap.js`, plus `tiptap.LICENSES.txt` containing complete notices for the packages in that bundle. It also removes the four obsolete served Quill files. All editor code is local and loaded only for news authoring; no CDN, cloud editor, subscription, analytics or external student-data service is configured. Changes to the editor entry or lockfile require rebuilding and committing the generated assets. The browser build does not require a Node service in production.

`DOMPurify` is distributed under its [Apache-2.0 option](https://github.com/cure53/DOMPurify/blob/main/LICENSE), and the server uses the MIT-licensed [Symfony HTML Sanitizer](https://symfony.com/packages/html-sanitizer). License notices ship with the built local assets. No editor subscription, CDN or remote editing service is required. The server allows only editorial text formatting and links; scripts, event handlers, arbitrary styling, forms, embedded media and tracking images are removed. Rich text is checked again when read and rendered safely in the workspace, article pages and private campus updates. News cards use plain-text excerpts; full formatted updates open on request. New saves are bounded to 60,000 bytes of text and formatting to fit portable text storage without truncation. Oversize drafts return an editable field error. Earlier plain-text records keep literal text, including any angle brackets, until deliberately edited into rich text.

### Assign author and publisher duties

- `sis:portal:manage` lets authors create and maintain their own drafts and request review. `sis:portal:admin` additionally permits editing other authors' content.
- `sis:portal:publish`, together with content-management access, permits reviewing submitted drafts, approving publication, returning a note to the author and archiving approved content. Publishers can find other authors' content in the existing Campus content directory; this does not itself permit rewriting someone else's wording.
- Assign both author and publisher duties to one person when direct publication of their own drafts is appropriate. Assign them separately when a review handoff is wanted. The new protected publishing permission is not automatically granted to roles or users.

Authors save a draft and choose **Request review → Confirm review request**. It stays hidden while awaiting review. A publisher opens the same record and chooses **Review publication** or **Return to author**. Returning requires a note visible in the author's editor. Saving revised wording resets it to draft, so the changed content needs a fresh review. A publisher may review their own saved draft directly. Archived content must first be saved as a new draft.

### Choose when news is visible

Publication review offers **Start publishing** and **Stop publishing**. Leave the start blank to publish immediately and the end blank for ongoing visibility. The picker labels the browser's time zone, sends an explicit UTC instant and displays the reviewed local times. A selected start or end must still be in the future at confirmation; the end must follow the start. Changing either field disables confirmation until **Review schedule** succeeds. A delayed response cannot approve subsequently changed fields.

After **Confirm publication**, an item becomes visible at its start and disappears at its end. These checks apply to public and signed-in news lists, direct article URLs, authenticated content lookup and the native news blocks in all three campus themes. They use the same server clock and query rule. No open browser tab, scheduled publishing worker or academic replay is needed. Before or after the window, direct public URLs return the ordinary unavailable-article page. **Review archive → Confirm archive** removes approved content immediately while retaining its wording.

### Storage and verification

This is audited operational CRUD. It does not add academic events or another event store. Post revisions protect wording, audience and visibility; actor/instruction-bound reviews expire after twenty minutes. Publication, minimal operation receipts and required audit commit atomically, with final source, authority and expiry checks. Repeating an accepted confirmation returns its original result without replacing later edits. Private routes retain framework CSRF, no-store responses and debug suppression. Browser drafts and proofs use no persistent browser storage.

Migration `20261120000014_AddSisContentPublicationWorkflow` adds nullable window/feedback fields, an explicit plain/rich content format, a visibility-query index, minimal operation receipts and the independent publishing permission. Earlier wording, publication state and audience remain intact, and no approval is inferred. The configured MariaDB database was upgraded to migration 14 with all 20 existing events and event/stream/operation checksums unchanged; content remains empty and role/user grants remain 16/0. Fictional tests cover author/publisher separation, return notes, stale reviews, timed lists/direct URLs, original retries, sanitized markup, unchanged event history and atomic failure. Native provider checks render Vihzhuo blocks in all three themes. Schema/compiler checks cover the framework's five database dialects; live multi-engine transaction/load qualification remains separate work. [Vihzhuo page approval and website menus](website-publishing-and-menus.md) are now implemented separately; broader theme/block qualification remains open.
