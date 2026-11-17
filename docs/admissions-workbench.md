# Admissions workbench

Admissions staff keep application responses, educational background, the working checklist, office routing and follow-up together in the existing application review. Formal decisions and matriculation continue through their own reviewed, retained workflows. Working casework does not accept/reject an application, create a student, award credit or change registration eligibility.

## Start or continue a case

Open **Admissions**, find an applicant and open the application. Choose **Checklist & follow-up**. This loads only that application's current working case; opening an ordinary listing does not load its notes or replay history.

Add checklist items or use a reusable checklist. Each item has a label, instructions, **Needed to finish casework**, **Show item and instructions to applicant**, progress and an optional due date. A staff-only item can serve as an internal follow-up task. Both types use the same editor and thirty-item bound, with collapsible named cards, keyboard move controls and explicit save.

Progress can be **Pending**, **Received**, **Complete** or **Not needed**. Use **Received** when an item has arrived through the institution's existing delivery process and still needs review. A case cannot be marked **Done** while a needed item remains pending or received. These are working checklist statuses; **Not needed** is not an academic waiver or a formal exception to an admissions policy.

Under **Routing & next steps**, enter the office, a next step for the applicant, private staff notes, the work status and an optional follow-up date. Office and next-step fields are explicitly applicant-visible. Keep confidential working commentary in **Private staff notes**, or in a staff-only checklist item.

Choose **Assign to me** to claim responsibility, **Keep current assignment** to retain it, or **Leave unassigned** to release it. Any authorized admissions manager can continue the working case; assignment neither grants account permissions nor establishes an exclusive lock. Naming another assignee directly is not part of this initial claim-based workflow. Save casework to commit the checklist, routing, next step and assignment together.

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

## Storage, privacy and setup

Migration **20261117000002** adds `sis_admission_cases` and `sis_admission_checklists` with ULID primary keys, parent references and indexes for assignment, work status, due work and preset scope. Case contents, preset definitions and request fingerprints are encrypted. Only the routing fields needed by the staff queue remain separate indexed columns. Detailed reads verify protected content against its parent and routing metadata; unreadable data is rejected. Applying this additive migration converts or deletes no existing application, profile or event facts.

This is audited CRUD for working case data and mutable setup, consistent with the selective event-sourcing boundary. It does not introduce another event table or manufacture historical events. Case saves lock the canonical person and application, use expected revisions, and recheck the actor and admissions permission after waiting and before completion. Preset saves serialize the author/configuration rows and recheck form-management authority. Audits and changes commit atomically. The existing authoritative admission decision/reason and matriculation histories remain separate; this checklist is not a retained decision-evidence snapshot.

All endpoints retain framework CSRF for browser mutations, private/no-store responses, and suppression of debug collectors. No notes, answers or definitions enter browser storage, logs or queued jobs. Current actor labels are resolved only for authorized staff views. Both case and preset values are redacted from debugging. There is no delete API or destructive rollback; decide institutional retention, legal holds and disposition before production rather than enabling a speculative automatic purge.

Endpoints are:

- Staff `GET`/`POST /sis/applications/{application}/casework/`.
- Staff `GET /sis/admissions/follow-ups/` with `scope`, `due` and exclusive `after` cursor.
- Staff `GET /sis/admissions/checklists/` with `application_id`, `search` and `after`.
- Authorized preset `GET`/`POST /sis/admissions/checklists/{preset}/`.
- Owner-only `GET /portal/api/applications/{application}/next-steps/`.

The additive schema compiles for the framework's five database dialects. Runtime checks cover fictional SQLite data and the configured MariaDB installation. Broader driver deployment acceptance remains separate.

Protected uploads/references, scanning/quarantine, institutional retention settings, assigned-to-other workflows, automated notifications and any policy that gates a formal admissions decision remain later work. No upload control is exposed before those safeguards are implemented. Continue using the institution's established document-delivery process and give applicants the appropriate instructions.
