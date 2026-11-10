# Degree requirements and published program details

Degree requirements now retain the applicable program publication when one exists for their program and academic year. Program details and course/group requirements remain separate definitions; this link records which program revision staff reviewed with the requirements.

## Staff workflow

1. Publish the [program details](program-publication.md) for the applicable academic year.
2. Build and save that program/year's degree requirements in the [degree-audit builder](degree-audit-builder.md).
3. Prepare the publication review. **Linked program** shows the exact published code and title that will be retained, even if today's working program title has changed.
4. Confirm the reviewed requirements within 20 minutes. **Refresh publication review** prepares a fresh review with the same reason and shows the current saved definition again. Publication takes effect at confirmation time. If a newer program publication becomes applicable before confirmation, review again. The system does not switch an unconfirmed review to a different program revision silently.

If there is no recorded program publication for that year, the review explicitly says so. The requirement version remains unlinked; later program setup does not invent a relationship for that earlier version. Older unconfirmed review formats require refreshing, while already completed confirmations remain retryable.

## Student assignments

New assignment reviews and confirmations require a linked requirement version to match the student's saved program catalog revision. Publishing a newer catalog revision does not move an existing student's cohort or replace their requirement selection. Staff can still select an older requirement publication whose program link matches that student's cohort. A cohort change requires its own authorized workflow.

Earlier unlinked requirements keep their existing program/year checks. They are explicitly unknown with respect to this link, rather than retroactively assigned today's program definition. Existing student selections remain readable and unchanged.

## Historical and security behavior

The requirement publication records the program catalog ULID in the existing protected event metadata field `curriculum_version_id`. Curated requirement history and internal student readers expose it as `program_version_id`. The existing requirement payload/schema and prior recorded events remain unchanged. No new table, migration, numbered model or event store is introduced.

Publication review resolves the current year's program reference through verified program history and its retained projection under program/year locks. The encrypted review binds that exact reference and definition. Confirmation checks it again, with current permission and actor checks after waiting. A changed program publication prevents confirmation. Expired reviews and stale definitions write no event, receipt, projection or audit entry. Permission is checked again after locks and replay and immediately before a new publication is recorded.

An exact retry of an accepted requirement confirmation returns the original receipt without following today's program publication or changing a student's selection. Requirement labels retain the reviewed program's code/title. Student and catalog permission boundaries remain separate, with existing CSRF protection, private responses and suppressed debug collectors.

Ordinary builder views do not replay program streams just to render a list. Verification occurs during consequential publication review/confirmation. Explicit historical reads use bounded pages. Evaluation, graduation and other remaining degree-audit features remain on the roadmap.

## Inspect the retained program and choose compatible requirements

Inspecting a published degree version, a student's retained baseline, or an assignment review now includes **Retained program**. Expand it to see the program's original academic year, catalog revision, description, department, academic level and award. The reader verifies the referenced program's protected history and program/year/label scope; it does not substitute the latest catalog definition or today's reference-library labels. Earlier unlinked versions remain unlinked.

The student chooser labels each version's compatibility before selection. Requirements linked to another program catalog revision remain visible with an explanation and cannot be selected. Earlier unlinked versions explicitly identify the unknown link. Both supported reusable-group formats (3 and 4) can be selected when otherwise eligible; server-side review and confirmation still enforce the same boundaries. Existing student selections are not changed by these display improvements.

No new tables, migrations, historical conversion or raw-event endpoint is introduced. Program history is replayed in bounded pages only when an authorized user explicitly inspects a linked definition; ordinary version-choice lists use the existing paged requirement metadata. Missing/foreign program references are rejected, while historical inspection remains independent of today's editable program row.

## Review lifetime and earlier history

New reviews expire after 20 minutes; the server checks the deadline after waiting/replay and again immediately before writing. The review has no effective-time field. A confirmed publication records its actual confirmation time, rather than the time staff first prepared the review.

An already accepted confirmation remains retryable after expiry, later draft edits or later publications. Bounded replay verifies its actor, reason, exact definition, program link, schema and stream position before returning the original result; it changes no history or current selection. Earlier accepted review formats retain their original recorded effective times and remain retryable. Earlier unconfirmed review formats require a fresh review.

This changes the current encrypted review format only. Stored requirement-event schemas, events and receipts remain intact; no database migration or historical conversion is needed.
