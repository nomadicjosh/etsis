# Website forms and reply access

Website forms collect contact inquiries and similar public messages. They are separate from the application **Form Library**: submitting one does not create a person, sign-in account, admission application or academic record.

## Design and publish a form

In **Page builder**, add **Website form** from **Forms**, select it on the canvas and choose **Settings → Edit website form**. The designer edits this form's fields together; individual PHP-rendered labels are controlled by the designer rather than edited directly on the canvas.

Set a title, introduction, form key, submit-button label and confirmation message. Use a distinct key for each form on a page. Keys identify reply access as well as public intake, so keep them stable after publication. Two different pages can both use `contact` and still have independent access grants.

Add short or long answers, email addresses, telephone numbers, web addresses, whole numbers, dates, confirmation checkboxes, dropdowns, single choices or multiple choices. Each field has a unique key, visible label, help text and required setting. Choice values stay distinct from their visible labels. Character, number and date bounds are checked again on the server. Conditions can show a field when an earlier field has a particular answer; hiding a field clears its answer. Conditions must continue to refer to earlier fields after reordering.

Drag fields by their grip, use **Move field up/down** with a keyboard, duplicate fields or remove them. **Preview form** uses the same server-rendered controls as the public site and disables submission. **Use this form design** changes the selected block in the working page. Then **Save page**, submit the page for review and have an authorized publisher approve it through the [website publication workflow](website-publishing-and-menus.md#review-and-schedule-a-page). Saving a design alone does not make it public.

The designer supports up to forty fields, thirty options per choice field, twenty forms per translated page and a 64-KiB definition. Website forms collect text and choices and support optional [newsletter/lead-list subscriptions](website-newsletters.md). File uploads, arbitrary PHP handlers and direct staff reply emails are not part of this workflow. The earlier raw form-action/nested-control design is not silently converted: configure an explicit website form before publishing it. Stored earlier designs are preserved for deliberate review.

## Choose the department and permissions

**Replies department (optional)** routes new submissions to an existing active institutional department, such as Admissions or Academic Advising. Department selection does not grant anyone access.

An access administrator opens **Reply access** (`/sis/website/form-access/view/`) and configures either of these paths:

| Form setup | Who can see its submissions? |
| --- | --- |
| A department is selected | An enabled account listed in that department's **Department inbox members**, with `sis:website:forms:read`. |
| No department is selected | An enabled account explicitly granted **Individual form access** for that exact page and form key, with `sis:website:forms:read`. |

First grant `sis:website:forms:read` through the normal role/account permission tools. Then search for a department or website page in **Reply access**, choose the form when appropriate, search for an eligible account and select it to grant access. **Remove access** revokes that membership. Accounts can be members of several department inboxes or have grants to several individual forms. These are explicit website inbox assignments; they do not create or change HR appointments. Ordinary website editing/publishing permissions grant no reply access.

Add `sis:website:forms:manage` for staff who should also close, reopen or permanently delete submissions. Manage permission still requires read permission and the matching scope. Access setup requires the separate `admin:access:manage` duty. No ordinary staff grants or memberships are created by the migration. Even an administrator must have the matching inbox membership to read replies.

A direct form grant never bypasses its department restriction. For example, assigning `contact` access to a staff member outside Admissions does not expose submissions routed to Admissions. Removing membership, revoking read permission, disabling the sign-in account or deactivating the department blocks access. Lists, direct reads and changes enforce the same boundary; inaccessible replies do not appear in pagination or metadata.

Routing is captured when a reply is received. Changing a form from Admissions to Advising affects new submissions; earlier messages remain assigned to Admissions. A later title/key edit or page removal does not erase earlier inquiries. Removed pages with retained replies remain available in the administrator's form-access chooser. Individual form grants are keyed by page and form key, not by mutable titles or language labels.

## Review and delete submissions

Open **Website replies** from the workspace sidebar or **Form inbox** from website management (`/sis/website/form-inbox/view/`). Viewing replies does not require page-builder editing permission. The inbox shows twenty accessible inquiries at a time with open/closed filters and older/newer navigation. Open an inquiry to see its original labels and answers. Answers render as text, including text that resembles HTML. Closing an inquiry keeps it available in the closed filter; reopening returns it to open work.

**Delete inquiry → Permanently delete inquiry** physically removes the submission and its encrypted answers from the active database. **Back to inquiry** returns without deleting. Website submissions use ordinary encrypted CRUD, not event sourcing. The required staff audit records only the operation and record reference, not the message or answers. Institutional backup/disposition policies still govern previously made backups.

A separate receipt contains no answers. It allows a browser that lost its connection to check the original confirmation without creating another inquiry, including after staff deleted that inquiry. Receipts cease to authorize retries after one hour; a fresh form ticket expires after twenty minutes. Expired receipts are eligible for bounded cleanup during intake and the hourly scheduler. Operators can run `sis:website-forms:prune --apply --limit=100` (maximum 1,000); omitting `--apply` performs no deletion. Receipt cleanup never removes inbox contents.

## Optional newsletter and lead-list subscriptions

Use [Newsletter connections](website-newsletters.md) for Sendy, Mailchimp, Mautic or a signed webhook. Each published form can map an email/name and a separate optional, initially unchecked consent checkbox. Only explicit opt-in queues server-side delivery. Credentials stay encrypted and outside page designs; jobs contain references only. The existing inquiry shows delivery status and an authorized retry flow. Deleting it removes its pending job, while provider unsubscribe/contact management remains a separate action.

## Public behavior and operating limits

Intake resolves the exact currently approved, visible page and translated form. Changed, archived, expired or unavailable pages cannot accept fresh inquiries. Submitted fields and conditions are validated against that retained definition; anonymous requests cannot choose another department or recipient. The framework's CSRF cookie/header protection remains active, and the encrypted ticket is bound to that browser token, page, form and publication.

Public controls provide labelled errors, a focused error summary, keyboard links to fields, conditional-field clearing, explicit submission status and retry of the original answers after an uncertain network response. Answers and credentials stay in page memory; they are not placed in local/session storage or queued jobs. A honeypot and a twenty-message-per-page/browser-hour limit provide baseline abuse controls. They do not constitute bot-proof protection or replace deployment-specific abuse controls.

Inbox answers are encrypted at rest, and private APIs use current account/duty/scope checks, no-store responses and debug suppression. No inquiry data is included in reporting or academic event history. Existing application forms and admissions permissions remain independent.
