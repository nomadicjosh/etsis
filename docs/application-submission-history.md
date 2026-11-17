# Application submission history

Implemented 2026-11-09. New form submissions and direct application entry now start the existing admissions stream with **AdmissionApplicationWasSubmitted**. [Acceptance/rejection](admissions-decisions.md) and [matriculation](student-matriculation.md) continue from that genuine creation context. Earlier applications retain their explicit unknown-history boundary.

## Applicant and staff workflow

Applicants continue using **Portal → Apply**: choose a program and entry term, complete the program's enabled form and submit it. Authorized staff can use **Start an application** for a person and complete the same program form. The existing submission identifier stays with that form in browser memory for retries; no private answers or review tokens are saved in browser storage.

The application, encrypted answers, submitted-form reference, actual submission event, stream head, submission-date read model, operation receipt and required audits commit together. A failure leaves no partial application or submission history. This does not create a student, accept the application, assign a cohort or post a charge.

Staff can open **Admissions → an application → Application history** before a decision has been made. It shows when the application was actually submitted, program/term labels as submitted, the selected form revision and whether the person used their own account or admissions staff entered it for them. A later acceptance/rejection appears alongside that submission. These history screens exclude answers, actor identifiers, reasons, fingerprints and raw event envelopes.

The current person owner can privately read their application answers, including answers entered by admissions staff on their behalf. Admissions management authority also permits that read. Another person's account, directory access and student-read permission alone do not. The original submitting actor remains recorded privately; permitting the applicant to read staff-assisted answers does not let them claim that staff member's retry receipt.

## Application form studio

Open **Application forms** to build a draft, preview it, save it and then **Review publication → Confirm publication**. Publication reviews the exact saved revision. Unsaved edits must be saved first; opening a published form creates a new draft revision and preserves the original.

Use **Find a form** to search form names, program codes/titles or the institution's academic-level codes/titles. **Show** filters All forms, Drafts, Published forms or Enabled forms. Published includes earlier revisions; Enabled shows the current application defaults. **Previous** and **Next** browse twenty records at a time, including forms beyond the former hundred-record ceiling. Searches and filters run before that page limit. Results contain current metadata, not questions, layouts, submitted answers or event history. They are a live library, so a later publication can change which form is enabled.

Browsing and retrying a failed search preserve the open draft, its entered values and its collapsed fields. Earlier results remain visible if a request fails; **Find** retries it. **Clear** resets library filters. Opening a different form still asks before discarding unsaved work. **Hide library** gives the editor the full available width; **Show library** restores it. On narrow screens the library starts closed, and choosing a form returns focus to its name in the editor. These view choices stay in memory.

Choose **An academic level** for a reusable default, or **One academic program** for a program override. Academic levels come from **Academic catalog → Curriculum → Departments & levels → Academic levels**. They are institution-defined: Undergraduate and Graduate are examples, not built-in codes or seeded requirements. An installation with no levels can still publish program forms. An authorized catalog administrator can open **Academic levels** directly from the form editor.

Applicants still choose their intended program and entry term. A program-specific form takes precedence; otherwise the program uses the form enabled for its current academic level. Programs without either form do not accept form-based applications. Removing a program override exposes the level default if one is enabled. Removing a level default leaves program overrides enabled. Both actions explain this before confirmation. A saved draft's target stays fixed; create a new draft to change its target.

Field cards display their current question label and type. Expand or collapse individual cards or all cards, and reorder them using drag handles or the keyboard-accessible arrow buttons. **More** groups **Duplicate field**, **Move question** and **Remove field**; moving between columns appears when containers are in use. Menus support keyboard navigation and restore focus after edits. Each question has a label, optional hint and required-answer setting. Dropdown and **Single choice** radio questions provide one choice per line. **Field settings** holds the unique stable key used by conditions and saved answers. Forms support up to 40 questions, with up to 30 choices per choice question.

### Containers and columns

Choose **Add container** to group the questions already in your draft. Give it a **Container title**, an optional **Container introduction** and choose **1 column**, **2 columns** or **3 columns**. Add more containers for subsequent parts of the application. Up to twelve containers are supported; forms without containers keep their original single-column layout.

Each column has its own **Add a field** menu. Drag a question into another column, or use **More → Move question** to choose a destination with a keyboard. Arrow buttons move a question within its column; container arrows and drag handles reorder whole containers. Duplicating a question places the copy beside the original in that same column. Remove a container after moving or removing its questions. Reducing the number of columns moves questions from removed columns into the last remaining column; it never deletes them.

Reading and condition order runs down the first column, then down the next column, then through subsequent containers. Reordering updates that order and checks earlier-question references before save. All columns stack in the same order on narrow screens. Preview uses the same container titles, introductions, columns and field controls as applicant and staff submissions.

### Question guidance and validation

Under **Field settings**, short/long text and email questions can have a placeholder and minimum/maximum character counts. Whole-number questions support inclusive minimum/maximum values. Date questions support inclusive earliest/latest dates. The editor rejects reversed ranges and unsupported settings. The supported limits remain 191 characters for short text/email, 4,000 for long text and whole numbers from -999,999,999 through 999,999,999. Optional unanswered questions stay optional even when they have limits.

Use **Preview form → Test answers** to exercise required fields, radio choices, character limits and conditional questions without submitting anything. Applicant and staff forms use this same renderer, show field guidance, and provide an error summary linking to the affected controls. Rejected submissions preserve entered answers. Unicode character limits use character counts, including emoji, consistently with server validation. Server validation remains authoritative for direct or modified requests.

**Edit conditions** opens the existing visual QueryBuilder for that question. Combine earlier answers using **All** or **Any**, nested groups, lists and numeric comparisons. **Apply conditions** commits the rule to the draft; **Cancel** leaves it unchanged; **Always show** removes it. Rules can only reference earlier questions. Moving or deleting a source question requires repairing its dependent conditions before saving. Preview evaluates the same bounded rule language as submission validation. Hidden questions do not require answers and their values are omitted from the submitted answers.

Sorting uses self-hosted [SortableJS](https://github.com/SortableJS/Sortable), with reduced-motion support and arrow-button alternatives. The builder and preview keep working values in memory; no applicant answers or drafts are stored in browser storage. Failed saves retain edits, and edits made while a save is pending remain unsaved rather than being overwritten by the response.

Migration **20261116000007** makes the existing form's program reference nullable, adds an optional academic-level reference and introduces the unique level/default binding table. It preserves existing program forms, bindings, protected answers and receipt fingerprints. A shared-form submission additionally binds its actual chosen program into its receipt, preventing the same receipt from being reused for another program. Historical reads and exact retries use the originally retained program/form facts even after current classifications or defaults change. Drafts and bindings remain audited configuration; consequential submissions continue using the existing authoritative event store.

Migration **20261116000008** adds nullable layout storage and widens MySQL's question-definition and encrypted-answer columns to accommodate valid forms exceeding 64 KB. Other supported engines already use large text/LOB types. Existing definitions, encrypted answers, bindings and receipts are preserved. Layout and explicit question settings become part of the retained publication digest; older forms without them keep their original digest and reading order. Changing either later requires a new publication. No additional event table, fabricated history or conversion of earlier answers is introduced. Compiler checks cover the five framework dialects; runtime checks cover SQLite fixtures and the configured MariaDB migration, with broader deployment acceptance still pending.

The paged library needs no schema change. **GET `/sis/forms/`** accepts optional `search` up to 80 characters, `state` as `all`, `draft`, `published` or `enabled`, and an exclusive ULID `after` cursor. Its current response is `{items, next_after}` with at most twenty metadata records. Search terms are bound and SQL wildcards are escaped. Current `sis:forms:manage` authority is required independently of publication or catalog permissions. Private/no-store responses and debug suppression still apply; library reads append no events or audits.

## Preserved facts

The new event retains application/person/program/entry-term ULIDs, initial submitted status/version, program and term labels, form/direct channel and the authorization relationship used at submission. Trusted context records the actor and actual effective/recording times. Names, institutional IDs and answers are not copied into the event.

For forms, the event pins the submitted record/form identities, form revision/name, existing answer fingerprint and a definition digest. The answer and form projections are verified against those protected references before owner answer reads, staff application review, admissions decisions and matriculation use them. Changing both a stored answers value and its current fingerprint does not change the original reference in the journal. Re-encrypting unchanged answers preserves verification. Replacing the active form leaves earlier submitted revisions intact.

The frozen fingerprint reader compares recorded values without executing today's conditional-answer rules again. New commands still validate against their selected form. A later program/term rename can be displayed in today's catalog while the submission retains its original labels; decision-time labels remain a separate retained snapshot.

Answers and published form definitions remain protected retained database artifacts. The event deliberately stores references/digests rather than another copy of their content. Deleted answer/form artifacts cannot be reconstructed from a digest; they require the institution's existing backup, encryption-key and retention controls alongside the journal. Operational rebuild and retention tooling remain staged work.

## Retries and earlier applications

Submitting the same form identifier with the same canonical answers returns the existing application. It remains safe after acceptance/rejection, matriculation or replacement/disablement of the active form. Changing the person, term, form, canonical answers or submitting actor cannot reuse that identifier. A retry never changes the current application/student status or appends another birth event.

The direct application API is restricted to staff with `sis:admissions:manage`; applicants must use their program form and cannot bypass its required answers. **POST `/sis/applications/`** now requires an explicit canonical ULID `application_id` alongside `person_id`, `program_id` and `entry_term_id`. Reuse that application ID only for an exact retry of the same request by the same actor. The original creation result remains submitted even if today's application has since advanced; reload its current review to see the latest status. An earlier untracked application identity cannot be claimed as a new creation receipt. Staff's existing form-based GUI keeps its current submission-ID contract.

An older form receipt can still be retried through its existing actor/fingerprint checks without inventing a creation event. Reads and previews never manufacture submission history. The first reviewed decision on an earlier submitted application still records **AdmissionApplicationWasObserved**, followed by the actual decision. New applications instead append their decision after their genuine submission; they do not receive a fabricated observation or original timestamp.

## Storage and validation

All events continue using **`sis_history_events`**, its existing stream heads and atomic operation receipts. The application status/version is checked against replay, including the recorded matriculation when a tracked acceptance advances. There is no parallel event table. The submission-date read model below has its own additive migration; earlier event payloads and their v1 schema contracts remain unchanged.

Creation locks the person before term/program setup and rechecks the current owner/permission and actor after waiting. Direct entry always rechecks admissions management permission, including when that staff member owns the person record. Retry reads also lock the existing application and recheck authorization. Identifier collisions are translated at the persistence boundary without returning driver details. Browser mutations retain CSRF protection; private responses use no-store and debug collectors stay suppressed. Replay and receipt reads use bounded event pages.

Fictional integration checks cover both channels, ownership and staff assistance, form/answer verification, protected payloads, replay through decisions/matriculation, stale or conflicting identifiers, earlier receipts, re-encryption and complete rollback. Real-provider/router checks exercise creation, owner reads, authorization, CSRF, private responses and retries. Browser checks cover known submission history before/after decisions and the earlier unknown-history path, including keyboard use, accessibility and narrow screens. Those original submission-workflow checks used fictional fixtures. The subsequent reporting migration is described below.

Offers, documents/checklists, further institution-specific approval/reopening policy, complete operational reconstruction and further admissions transitions remain later stages.

[Outcome corrections](admissions-corrections.md) now retain reasoned pre-matriculation changes and original decisions in this same stream.


## Submission-date reporting

Implemented 2026-11-14. Open **Report Studio → Applications & admissions**. **Submitted on** is included in the initial columns. Under **Conditions**, choose that field and **between** to enter a start/end date. Add status, current program, intended entry term or independently authorized student/registration conditions as needed. These use the existing general builder; submission reports are not a separate reporting screen.

The launch screen also offers **Applications with recorded submission dates** and **Applications with unknown submission dates**. An unknown date means the original submission was not recorded in the current journal. It does not mean the application was never submitted. The default optional relationship retains these applications with blank date/channel fields; an inner join deliberately selects only known submissions.

Available fields are:

- **Submitted on**: the actual submission's effective calendar date in its retained timezone. A submission at 11:30 p.m. in Los Angeles belongs to that local day, even when its UTC date is the next day.
- **Submission timezone**: the original timezone or offset, making that calendar-date interpretation visible.
- **Submission recorded on**: the journal's separate UTC recording date.
- **Submission channel**: staff direct entry or an application form. This does not identify the submitting actor or expose form answers.

The intended entry term remains separate. Later acceptance/rejection, correction, matriculation, retries or catalog changes do not alter the original submission date. Date MIN/MAX and grouped application counts use the same typed query engine. All fields require current admissions data authority plus the relevant report duty; adding student-linked fields still requires student-read authority. Sharing, static captures, expiry and private CSV pages retain their existing checks. A report grants no permission to accept or matriculate anyone.

### Storage and alpha migration

**`sis_application_intake`** is a reconstructible read model keyed by the application's existing ULID, not another event store. It contains one authenticated opening-event reference, original effective/UTC recording timestamps, queryable calendar dates, timezone and channel. It contains no names, answers, actor identifiers or decision narratives. The private event reference and full timestamps are not Report Studio fields. Submission and recording date indexes support range filters without decrypting or replaying streams on report loads.

New form and direct-entry commands maintain this row in the same transaction as the submission event, application, answers, receipts and audits. A required audit or projection failure rolls everything back. Repeating projection with identical facts is harmless; conflicting facts are rejected rather than silently overwritten. There is no editable submission-date form.

Migration **`20261114000003_CreateSisApplicationIntake`** creates the portable schema and reconstructs existing rows only from authenticated **AdmissionApplicationWasSubmitted** opening events. It validates that first event through the existing store and aggregate contract; this is not a full-stream health scan. An older observed application remains unknown, including when it has a later acceptance or matriculation event. No date is guessed from a ULID, current status, entry term, decision date or form artifact. Events, receipts and application/academic rows are unchanged.

This alpha conversion is explicitly bounded to **1,000 admissions streams and a 30-second work budget**, with each opening-event read limited to one event. Missing keys, invalid evidence, orphan/conflicting projections or exceeded bounds stop the migration. Data reconstruction is one transaction; a failure rolls those writes back. Engines with nontransactional DDL may retain the newly created empty table; rerunning the migration verifies/reuses it. Larger installations need a controlled offline conversion before this migration can be completed. General operational projection rebuild/retention tooling remains roadmap work, rather than adding another recovery screen for this one index.

Fictional checks cover local/UTC midnight boundaries, retries after decisions/matriculation, form/direct atomic rollback, unknown older history, authenticated reconstruction and rejected corruption, date-range index use, typed grouped dates, source permissions, metadata minimization, saved browser date controls and five supported schema/SELECT dialects. SQLite runs the integration tests; the configured MariaDB database receives a bounded additive migration with journal/application/receipt hashes checked before and after. Other engines still require runtime deployment acceptance.
