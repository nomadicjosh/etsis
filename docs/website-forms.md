# Website forms and reply access

Website forms collect contact inquiries and similar public messages. They are separate from the application **Form Library**: submitting one does not create a person, sign-in account, admission application or academic record.

## Design and publish a form

In **Page builder**, add **Website form** from **Forms**, select it on the canvas and choose **Settings → Edit website form**. The designer edits this form's fields together; individual PHP-rendered labels are controlled by the designer rather than edited directly on the canvas.

Set a title, introduction, form key, submit-button label and confirmation message. Use a distinct key for each form on a page. Keys identify reply access as well as public intake, so keep them stable after publication. Two different pages can both use `contact` and still have independent access grants.

Add short or long answers, email addresses, telephone numbers, web addresses, whole numbers, dates, confirmation checkboxes, dropdowns, single choices or multiple choices. Each field has a unique key, visible label, help text and required setting. Choice values stay distinct from their visible labels. Character, number and date bounds are checked again on the server. Conditions can show a field when an earlier field has a particular answer; hiding a field clears its answer. Conditions must continue to refer to earlier fields after reordering.

Drag fields by their grip, use **Move field up/down** with a keyboard, duplicate fields or remove them. **Preview form** uses the same server-rendered controls as the public site and disables submission. **Use this form design** changes the selected block in the working page. Then **Save page**, submit the page for review and have an authorized publisher approve it through the [website publication workflow](website-publishing-and-menus.md#review-and-schedule-a-page). Saving a design alone does not make it public.

The designer supports up to forty fields, thirty options per choice field, twenty forms per translated page and a 64-KiB definition. Website forms collect text and choices and support optional [newsletter/lead-list subscriptions](website-newsletters.md). File uploads, arbitrary PHP handlers and direct staff reply emails are not part of this workflow. The earlier raw form-action/nested-control design is not silently converted: configure an explicit website form before publishing it. Stored earlier designs are preserved for deliberate review.

## Identify personal input purposes

In the designer, each eligible field has an **Input purpose** dropdown. Choose the visitor's full/given/middle/family
name, title/suffix/nickname, organization/job title, email, telephone, website or address component. The list follows
the field type: use **Email address**, **Telephone** or **Web address** fields for those purposes, a **Long answer**
for a full street address, and short answers or supported dropdown choices for locality/region/country names.
Postal codes use a short answer so leading zeros are retained. New starter contact forms explicitly identify their
name and email fields; earlier saved forms receive no inferred purpose based on their key or label.

Choose **No personal input purpose** for a general message, inquiry topic, another person's information or a field
that does not describe the visitor. This omits the explicit hint; it does not control the browser's own heuristics
or saved-information settings. etSIS continues to keep working answers in page memory, with no new browser storage.
Purposes identify fields for software without changing their visible labels, help, required state or answer rules.
This supports [identifying input purpose](https://www.w3.org/WAI/WCAG22/Understanding/identify-input-purpose.html);
it does not by itself establish complete accessibility conformance.

Purpose choices are validated again by the server and retained with the saved form. Unsupported tokens,
credentials/payment purposes and mismatched field types are rejected before publication. Changing a field's type
clears an incompatible purpose, announces the change and keeps keyboard focus on the type selector. Duplication,
keyboard ordering, preview and reopening preserve deliberate choices. Publish the saved page through its normal
review before the revised form can accept inquiries; a purpose-only publication change invalidates unaccepted
earlier tickets. Already accepted inquiries and their original-result retries retain the earlier definition.

Preview and the public form use the same renderer. Each form instance supplies a separate
[`section-*` autocomplete scope](https://html.spec.whatwg.org/multipage/form-control-infrastructure.html#autofill)
to distinguish its visitor details from another form on the page. Browsers decide whether to offer autofill.
Submission reads actual current control values even when autofill emits no input event, and still clears/excludes
answers hidden by conditions. The honeypot keeps its existing explicit `autocomplete="off"` setting. No raw
autocomplete strings, arbitrary attributes, new recipient routing or newsletter consent are accepted.

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

## Assign inquiry work

In **Website replies**, use **Inquiry work** to choose **All accessible inquiries**, **My inquiries** or **Unassigned**, together with the existing open/closed status filter. Both filters apply before twenty-row paging. **Older inquiries**, **Previous inquiries** and **Newest inquiries** navigate the same filtered queue; the browser retains at most five previous cursors in memory. **Back to inbox** returns to the current filters/page and restores focus to the inquiry when it is still listed. An inquiry assigned from the Unassigned view can disappear from that list while its detail remains available.

Open an inquiry and use **Inquiry assignment**. Choose **Claim this inquiry**, **Delegate to a colleague** or **Clear the assignment**, then explicitly select **Save assignment**. Choosing an action or a search result saves nothing by itself. Delegation searches require at least two characters and return up to twenty eligible colleagues per page, with bounded previous/more controls. Names render as text; the chooser exposes no email addresses, institutional identifiers or inquiry answers.

Assignment requires `sis:website:forms:read`, `sis:website:forms:manage` and current access to the exact inquiry. The selected colleague must have both duties, an enabled account, its canonical person link and the same existing department/form access. Department-routed inquiries require that department's membership even if a colleague has an individual grant to the form or administrative privileges. Assignment never grants permissions or inbox membership. These remain website-form replies, separate from application/admissions forms and their casework.

The assignment is pinned to both account and person. An account linked to another person does not acquire the earlier person's assigned work through **My inquiries**. When a current assignee loses a required permission, account availability or exact inbox scope, authorized colleagues see **Assigned account unavailable**. The inquiry remains assigned until an authorized colleague deliberately clears or reassigns it. Current eligible names are labels, not retained identity-history claims. Read-only inbox members can inspect routing without assignment controls.

Only open inquiries can be assigned. Closing preserves the assignment for inspection; reopening restores the assignment editor. Inquiry and assignment revisions are independent: closing/reopening or another colleague's assignment makes an earlier assignment save stale. A rejected or uncertain save retains the selected action/colleague. Select **Reload inquiry**, review its current assignment and explicitly save again. An already matching selection is a no-op; a stale request cannot rewind later work. No-op saves do not add audit entries.

Each accepted change stores only current working routing metadata and a required metadata audit. Existing encrypted intake, original labels/answers, retry receipt, newsletter work, accounts, memberships and academic history retain their meanings. Source, canonical identity, actor duty and colleague eligibility are rechecked before commit; failure rolls back the assignment and audit together. Deleting an inquiry permanently deletes its current assignment along with the message, while the separate short-lived submission receipt still handles an original browser retry without recreating either.

The existing private/no-store transport and framework CSRF protect assignment saves. Closing/returning from the drawer remains available during a pending save; late replies cannot replace another inquiry. Colleague searches and assignment saves have short browser deadlines, and loss of access clears private cards and pending selections. Filters, answers and assignment drafts are never put in browser storage. Migration **20261127000003_CreateSisWebsiteInquiryAssignments** adds the current metadata table and index without assigning earlier inquiries, changing grants or adding an event stream, report or delivery worker.

## Optional newsletter and lead-list subscriptions

Use [Newsletter connections](website-newsletters.md) for Sendy, Mailchimp, Mautic or a signed webhook. Each published form can map an email/name and a separate optional, initially unchecked consent checkbox. Only explicit opt-in queues server-side delivery. Credentials stay encrypted and outside page designs; jobs contain references only. The existing inquiry shows delivery status and an authorized retry flow. Deleting it removes its pending job, while provider unsubscribe/contact management remains a separate action.

## Public behavior and operating limits

Intake resolves the exact currently approved, visible page and translated form. Changed, archived, expired or unavailable pages cannot accept fresh inquiries. Submitted fields and conditions are validated against that retained definition; anonymous requests cannot choose another department or recipient. The framework's CSRF cookie/header protection remains active, and the encrypted ticket is bound to that browser token, page, form and publication.

Public controls provide labelled errors, a focused error summary, keyboard links to fields, conditional-field clearing, explicit submission status and retry of the original answers after an uncertain network response. Answers and credentials stay in page memory; they are not placed in local/session storage or queued jobs. A honeypot and a twenty-message-per-page/browser-hour limit provide baseline abuse controls. They do not constitute bot-proof protection or replace deployment-specific abuse controls.

Inbox answers are encrypted at rest, and private APIs use current account/duty/scope checks, no-store responses and debug suppression. No inquiry data is included in reporting or academic event history. Existing application forms and admissions permissions remain independent.
