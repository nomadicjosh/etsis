# Accepted course publications for transfer awards

New transfer awards require an explicit **Accepted institutional course publication**. This records which receiving-institution definition the evaluator accepted, rather than inferring a definition from today's catalog. Course publications belong to the institutional course selected by the reviewed equivalency.

## Staff workflow

1. Catalog staff publish the receiving course through the existing course review/publication workflow.
2. Open **Transfers → Equivalencies**, review the equivalency and choose **Award transfer credit**.
3. Select the student, local posting term, sending-course completion date and received grade rank. Deliberately select the accepted publication; none is selected automatically. **Load more course publications** retrieves older choices in bounded pages.
4. Confirm the award. Its institutional code and transcript title come from that exact publication. Awarded credits come from the reviewed equivalency, which can differ from the publication's default course credits.

An evaluator needs both `sis:transfer:manage` and `sis:student:read`. Catalog maintenance alone grants no student-award authority. The posted-award screen states whether its accepted publication was retained or is unknown. Browsing curated publication choices requires those same two permissions, without requiring catalog-edit access. Browser requests retain CSRF protection and private/no-store responses.

The receiving definition is an acceptance fact recorded now. It is not a claim about which institutional course was being taught on the sending institution's earlier completion date. External completion and sending-period dates retain their independent meanings.

## Historical behavior

The genuine opening award event retains `course_version_id` in protected event metadata. A later `transfer-course-was-accepted` decision retains its predecessor, previous/new publication references and accepted code/transcript title in the same award stream. The aggregate preserves the original opening reference separately for initial-command retries. It also retains the accepted labels, awarded units, selected grading facts and source/equivalency context. Course, grade, credit and source-period corrections remain separate; credit amendments and void/reinstatement preserve the accepted course reference. Later catalog changes cannot relabel this award.

A repeat initial submission must select its original opening publication and posting term, even after a later acceptance correction. It cannot replace the accepted publication, bind an earlier unknown award or reinstate a voided award. Changing the institutional course identity still requires a future replacement workflow. A [reviewed acceptance correction](#review-an-existing-awards-accepted-course) can select another publication within the same institutional course.

Earlier awards without a recorded publication remain **unknown**. No migration, read or initial-award retry assigns today's definition to those records. An evaluator can explicitly record a new acceptance decision using the review below; the earlier unknown decision remains unknown. A known reference can participate in existing exact-publication course matching and degree-group placement. Different publications do not become equivalent merely because they share a course identity or code. Earlier equivalence review, broader grade-policy compatibility and retained final degree evaluation remain outstanding.

## Review an existing award's accepted course

Open the posted transfer award and choose **Review accepted course**. Select a publication of its existing institutional course, enter an effective date and explain the acceptance evidence. No publication is selected automatically; older choices load in bounded pages. The effective date must be between external completion and today. It expresses when this correction applies, while the journal separately retains when staff actually recorded it.

**Review accepted definition** compares the current and proposed course code, transcript title and publication status. Awarded credits and grade are shown alongside them. **Revise accepted course** preserves entered values. **Confirm course acceptance** applies the reviewed definition; an interrupted response can be retried with the same protected review token. The review expires after 20 minutes for a new confirmation. Completed retries still return their original receipt after expiry, later corrections or a void, without changing newer decisions.

Both `sis:transfer:manage` and `sis:student:read` are required for choices, review, confirmation and history. A voided award must be reinstated before a new acceptance review. Selecting the currently accepted publication is rejected as an unchanged request. A changed student/award, different actor, stale revision or malformed token requires a fresh authorized review. Institutions should grant this duty only to their authorized evaluators.

This changes the accepted publication and its current code/transcript title. Student, institutional course identity, sending course/institution, completion date, local posting term, source academic period, awarded credit units, grade and financial facts remain unchanged. Exact-publication degree matching can consequently change. This is not a reusable equivalence rule between every revision of a course or every student award.

For an untracked earlier award, confirmation first records an attributed observation of its then-current facts at confirmation time, followed by the actual new acceptance decision with its reason/effective date. Review and reads create neither event. Earlier authors, accepted definitions and decision dates are not invented. The award continues to identify its earlier-history gap; other missing grade/source evidence may still prevent a complete degree evaluation.

**Course definition history** separately shows original awards, explicit observations and later acceptance decisions, with their retained labels, known/unknown references and effective/recorded times. Pages omit raw events, actors and protected reasons. Credit/grade and source-period histories retain their separate purposes. A course-acceptance decision is shown as context in source-period history without pretending it changed the sending period.

Previously prepared or issued transcript artifacts retain their exact bytes. Changed academic labels invalidate a stale preparation for delivery; staff must prepare a fresh revision. Course acceptance alone never issues a transcript or changes charges.

## Storage, verification and recovery

This stage adds no tables or schema migration. It uses the existing authoritative `sis_history_events`, `sis_course_versions` and `sis_credit_course_versions`. Definition, subject and classification projections are shared with section bindings; a transfer award does not require a section. The credit-version index is reconstructible and must agree with the award's latest accepted reference in protected history. Missing, injected or conflicting references reject verification instead of becoming trusted academic inputs.

Confirmation verifies the chosen publication through bounded replay. A new acceptance also increments the academic credit revision and atomically changes its indexed publication reference. Initial awards retain their student/catalog locks. Acceptance corrections serialize on student and award/credit locks, with current permission and actor rechecks. Events, definition/index projections, academic rows and operational audit share one transaction. A failed acceptance rolls back its observation, decision, index and label changes together. Publication choices expose only version, course labels and catalog credit units; publication actors, reasons and protected payloads are not returned.

Projection reconstruction must use the exact retained course publication. Unknown references may remain null only when reconstructing genuinely unknown earlier records. Reconstruction creates no new award, financial event or historical decision. Complete operational rebuild/swap tooling remains a separate roadmap item.

Fictional SQLite integration tests exercise acceptance correction/replay, untracked observations, unchanged prior event bytes, stale/expired/foreign review rejection, original retries after later decisions, prepared-document preservation and stale-delivery rejection, explicit old/new selections, independent equivalency credits, unknown earlier awards, foreign/missing references, tampered projections, bounded verification, transaction rollback, reconstruction and exact-publication degree placement. Provider/router and browser tests cover private CSRF transport, office permissions, deliberate selection, paging, escaped labels, preserved form values, mobile reflow and automated accessibility checks. Live cross-database execution and production recovery are not established by these tests.
