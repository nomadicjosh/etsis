# Application submission history

Implemented 2026-11-09. New form submissions and direct application entry now start the existing admissions stream with **AdmissionApplicationWasSubmitted**. [Acceptance/rejection](admissions-decisions.md) and [matriculation](student-matriculation.md) continue from that genuine creation context. Earlier applications retain their explicit unknown-history boundary.

## Applicant and staff workflow

Applicants continue using **Portal → Apply**: choose a program and entry term, complete the program's enabled form and submit it. Authorized staff can use **Start an application** for a person and complete the same program form. The existing submission identifier stays with that form in browser memory for retries; no private answers or review tokens are saved in browser storage.

The application, encrypted answers, submitted-form reference, actual submission event, stream head, operation receipt and required audits commit together. A failure leaves no partial application or submission history. This does not create a student, accept the application, assign a cohort or post a charge.

Staff can open **Admissions → an application → Application history** before a decision has been made. It shows when the application was actually submitted, program/term labels as submitted, the selected form revision and whether the person used their own account or admissions staff entered it for them. A later acceptance/rejection appears alongside that submission. These history screens exclude answers, actor identifiers, reasons, fingerprints and raw event envelopes.

The current person owner can privately read their application answers, including answers entered by admissions staff on their behalf. Admissions management authority also permits that read. Another person's account, directory access and student-read permission alone do not. The original submitting actor remains recorded privately; permitting the applicant to read staff-assisted answers does not let them claim that staff member's retry receipt.

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

All events continue using **`sis_history_events`**, its existing stream heads and atomic operation receipts. The application status/version is checked against replay, including the recorded matriculation when a tracked acceptance advances. No new event table, checkpoint, migration, conversion loop or backfill is needed. Earlier event payloads and their v1 schema contracts remain unchanged.

Creation locks the person before term/program setup and rechecks the current owner/permission and actor after waiting. Direct entry always rechecks admissions management permission, including when that staff member owns the person record. Retry reads also lock the existing application and recheck authorization. Identifier collisions are translated at the persistence boundary without returning driver details. Browser mutations retain CSRF protection; private responses use no-store and debug collectors stay suppressed. Replay and receipt reads use bounded event pages.

Fictional integration checks cover both channels, ownership and staff assistance, form/answer verification, protected payloads, replay through decisions/matriculation, stale or conflicting identifiers, earlier receipts, re-encryption and complete rollback. Real-provider/router checks exercise creation, owner reads, authorization, CSRF, private responses and retries. Browser checks cover known submission history before/after decisions and the earlier unknown-history path, including keyboard use, accessibility and narrow screens. No working database was used or migrated.

Offers, documents/checklists, institution-specific admissions correction/reopening policy, complete operational reconstruction and further admissions transitions remain later stages.
