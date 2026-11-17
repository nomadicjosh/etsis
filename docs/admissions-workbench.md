# Admissions workbench

Admissions staff keep application responses, educational background, the working checklist, office routing and follow-up together in the existing application review. Formal decisions and matriculation continue through their own reviewed, retained workflows. Working casework does not accept/reject an application, create a student, award credit or change registration eligibility.

## Start or continue a case

Open **Admissions**, find an applicant and open the application. Choose **Checklist & follow-up**. This loads only that application's current working case; opening an ordinary listing does not load its notes or replay history.

Add checklist items or use a reusable checklist. Each item has a label, instructions, **Needed to finish casework**, **Show item and instructions to applicant**, progress and an optional due date. A staff-only item can serve as an internal follow-up task. Both types use the same editor and thirty-item bound, with collapsible named cards, keyboard move controls and explicit save.

Progress can be **Pending**, **Received**, **Complete** or **Not needed**. Use **Received** when an item has arrived through the institution's existing delivery process and still needs review. A case cannot be marked **Done** while a needed item remains pending or received. These are working checklist statuses; **Not needed** is not an academic waiver or a formal exception to an admissions policy.

Under **Routing & next steps**, enter the office, a next step for the applicant, private staff notes, the work status and an optional follow-up date. Office and next-step fields are explicitly applicant-visible. Keep confidential working commentary in **Private staff notes**, or in a staff-only checklist item.

Choose **Assign to me** to claim responsibility, **Keep current assignment** to retain it, **Assign a colleague** to hand it over, or **Leave unassigned** to release it. For a colleague, search with at least two characters of their name or institutional ID and select a result. Results include only linked, enabled accounts with current `sis:admissions:manage` authority, including direct grants and role permissions. Search returns twenty eligible records at a time, without email addresses or full permission sets. A colleague needs admissions access before they can be assigned; assignment never grants it.

Save casework to commit the checklist, routing, next step and assignment together. Selected assignments recheck both the actor and recipient under the access-definition lock before changing the application. If access was revoked, choose another colleague; the screen preserves the draft. Any authorized admissions manager can continue the working case; assignment does not establish an exclusive lock.

Work status is **Open**, **Waiting** or **Done**. It is separate from the application's submitted/accepted/rejected/matriculated status. Completing, waiting or reopening casework does not change an admissions outcome. An unchanged save appends no audit or revision. A current exact retry is a no-op; an older retry after another edit receives a conflict and cannot overwrite the newer case.

## Reuse and maintain checklists

Expand **Reusable checklists** and use **Find presets**. The library shows enabled presets for this program or all programs, with twenty metadata records per page. Search uses literal text; application notes and answers are not searched or returned.

**Use checklist** adds its definitions to the current draft. New copied items begin pending with no due date. Existing manual work is preserved. Applying the same preset again does not duplicate or reset its already copied items; later preset edits do not replace application-specific wording or progress. Different presets have separate item references. If a copy would exceed thirty items, the whole copy is rejected and the draft stays intact. Save casework when the combined list is ready.

Staff with `sis:forms:manage` can also **Create preset from draft** or **Edit preset**. Review the generic labels and instructions before saving. The preset includes labels, instructions, visibility and whether items are needed; it excludes application progress, dates, assignment and private case notes. Uncheck **Available to all programs** to scope it to this application's program. Disable a preset to stop new use without removing existing application copies. Preset edits use their own version checks and save action; they do not discard the unsaved casework draft or update other cases.

The workbench itself requires `sis:admissions:manage`; authoring presets additionally requires the form-management permission in this screen. The preset configuration API permits a form manager to maintain a known preset without granting access to private applications.

## Work the follow-up queue

From **Admissions**, choose **Follow-up queue**. Filter **All open**, **Assigned to me** or **Unassigned**, optionally check **Due today or earlier**, then refresh. Open/waiting cases appear in pages of twenty, ordered by case creation identity with exclusive cursors. Private notes, instructions and encrypted case payloads are not loaded into this queue.

The follow-up date is the earliest of the case's explicit follow-up date and its unfinished checklist item dates. Completed or not-needed items contribute no deadline. Done cases leave the queue but remain accessible through their applications. Dates use the institution's configured clock; the queue displays the institution date used for the comparison. This queue tracks work; it does not send automatic messages or make admissions decisions.

## Applicant next steps

Applicants open **Start your application → Your applications → Next steps** for the relevant program/term. The read happens on demand and preserves a new application being edited on the same page. It shows the office, applicant next step and only the checklist items staff marked visible, including instructions, progress and due dates. It excludes private notes, internal tasks, assignee identities, work status and revision metadata. Staff privileges do not override ownership on this portal endpoint.

If staff have not added guidance, the portal directs the applicant to contact admissions. Checklist progress alone is not an admission approval or registration confirmation. Reading next steps neither changes the application nor writes an event/audit. Closing it or refreshing the application list prevents detached viewers from restoring late results.

## In-portal admissions updates

Applicants can choose **Admissions updates** from their application toolbar or campus-services navigation. This page is available to a signed-in person before they have a student record. It loads only update metadata, without requesting the full student overview. Updates show the application program and entry term, the update time and whether they are unread. Use **Read update** to open the current next steps. **Include read updates** and explicit refresh let applicants revisit earlier guidance; lists contain twenty records per page.

Saving a change to applicant-visible office, next step or checklist guidance automatically creates an unread update for the application's current owner. Staff-only notes, internal tasks, assignments and working status do not create an update. The update and case save commit together. Existing cases receive no fabricated backfill; their next visible change creates the first update.

Each application/owner pair has one reference with a public-update counter. Repeated changes coalesce into that reference, so it is an inbox of current guidance, not a timeline of earlier wording or an extra event journal. It contains no copied checklist, note or message body. Private case revisions do not advance its public counter. Changing the person account owner makes earlier-owner references inaccessible; staff authority cannot override current ownership.

Loading the list does not mark updates read. After successfully opening guidance, the page submits an explicit, CSRF-protected read confirmation for the update it displayed. A delayed confirmation cannot hide a newer update, and repeating an older confirmation cannot undo a later read. Closing the viewer before guidance arrives prevents that detached request from confirming the update. Read-state changes require audit and commit atomically.

These are in-portal updates. This workflow sends no email or push messages and does not start a background delivery job. Institutions should continue using their established delivery channels for time-sensitive communication.

## Message an applicant

Choose **Message applicant** in application review when you have Communications and person-directory duties as well as admissions access. This opens the [shared Communications workflow](communications.md#message-from-a-person-student-or-application) with the applicant's enabled linked account selected. Save or clear any unsaved casework or educational-background/review feedback first; the shortcut preserves unfinished work rather than discarding it.

Write and save the message, review the recipient, then queue it. Sending still requires the existing separate background-delivery confirmation and configured server worker. Use **Back to application** to continue admissions work. Private notes and application responses are never prefilled into the message. These are deliberate staff messages; saving visible checklist guidance continues to create only the in-portal update described above.

## Storage, privacy and setup

Migration **20261117000002** adds `sis_admission_cases` and `sis_admission_checklists` with ULID primary keys, parent references and indexes for assignment, work status, due work and preset scope. Case contents, preset definitions and request fingerprints are encrypted. Only the routing fields needed by the staff queue remain separate indexed columns. Detailed reads verify protected content against its parent and routing metadata; unreadable data is rejected. Applying this additive migration converts or deletes no existing application, profile or event facts.

This is audited CRUD for working case data and mutable setup, consistent with the selective event-sourcing boundary. It does not introduce another event table or manufacture historical events. Case saves lock the canonical person and application, use expected revisions, and recheck the actor and admissions permission after waiting and before completion. Preset saves serialize the author/configuration rows and recheck form-management authority. Audits and changes commit atomically. The existing authoritative admission decision/reason and matriculation histories remain separate; this checklist is not a retained decision-evidence snapshot.

Migration **20261117000003** adds `sis_admission_updates`, with a ULID primary key, an application reference, a unique application/owner pair and an index for owner/date pagination. It stores only identifiers, public-update/read counters and a UTC timestamp. This operational CRUD table is separate from authoritative admissions history. It neither copies private text nor adds an event store. Owner lookups check both the reference owner and the person account currently owning the application. Retention and account-disposition rules still need institutional configuration; there is no automatic purge.

All endpoints retain framework CSRF for browser mutations, private/no-store responses, and suppression of debug collectors. No notes, answers or definitions enter browser storage, logs or queued jobs. Current actor labels are resolved only for authorized staff views. Both case and preset values are redacted from debugging. There is no delete API or destructive rollback; decide institutional retention, legal holds and disposition before production rather than enabling a speculative automatic purge.

Endpoints are:

- Staff `GET`/`POST /sis/applications/{application}/casework/`.
- Staff `GET /sis/admissions/assignees/` with literal `search` and exclusive `after` cursor.
- Staff `GET /sis/admissions/follow-ups/` with `scope`, `due` and exclusive `after` cursor.
- Staff `GET /sis/admissions/checklists/` with `application_id`, `search` and `after`.
- Authorized preset `GET`/`POST /sis/admissions/checklists/{preset}/`.
- Owner-only `GET /portal/api/applications/{application}/next-steps/`.
- Signed-in page `GET /portal/admissions-updates/`.
- Owner-only `GET /portal/api/admissions-updates/` with `include_read`, `after_at` and `after`.
- Owner-only `POST /portal/api/admissions-updates/{id}/read/` with the displayed `revision`.

The additive schema compiles for the framework's five database dialects. Runtime checks cover fictional SQLite data and the configured MariaDB installation. Broader driver deployment acceptance remains separate.

Protected uploads/references, scanning/quarantine, institutional retention settings, optional automatic email/push delivery and any policy that gates a formal admissions decision remain later work. No upload control is exposed before those safeguards are implemented. Continue using the institution's established document-delivery process and give applicants the appropriate instructions.
