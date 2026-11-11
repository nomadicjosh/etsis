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

The genuine opening award event retains `course_version_id` in protected event metadata. It also retains the accepted labels, awarded units, selected grading facts and source/equivalency context. Course, grade, credit and source-period corrections remain separate; credit amendments and void/reinstatement preserve the accepted course reference. Later catalog changes cannot relabel this award.

A repeat initial submission must select the same retained publication and posting term. It cannot replace the accepted publication, bind an earlier unknown award or reinstate a voided award. Changes to course identity and historical publication selection require a future explicit replacement/review workflow.

Earlier awards without a recorded publication remain **unknown**. No migration, read or retry assigns today's definition to those records. A known reference can participate in existing exact-publication course matching and degree-group placement. Different publications do not become equivalent merely because they share a course identity or code. Earlier equivalence review, broader grade-policy compatibility and retained final degree evaluation remain outstanding.

## Storage, verification and recovery

This stage adds no tables or schema migration. It uses the existing authoritative `sis_history_events`, `sis_course_versions` and `sis_credit_course_versions`. Definition, subject and classification projections are shared with section bindings; a transfer award does not require a section. The credit-version index is reconstructible and must agree with the award's protected history. Missing, injected or conflicting references reject verification instead of becoming trusted academic inputs.

Confirmation verifies the chosen publication through bounded replay. Student/catalog locks, permission and actor rechecks, the initial event, definition/index projections, award/credit rows and operational audit retain their transaction boundary. Audit failure rolls back the whole new award. Publication choices expose only version, course labels and catalog credit units; publication actors, reasons and protected payloads are not returned.

Projection reconstruction must use the exact retained course publication. Unknown references may remain null only when reconstructing genuinely unknown earlier records. Reconstruction creates no new award, financial event or historical decision. Complete operational rebuild/swap tooling remains a separate roadmap item.

Fictional SQLite integration tests exercise explicit old/new selections, independent equivalency credits, unknown earlier awards, foreign/missing references, tampered projections, bounded verification, transaction rollback, reconstruction and exact-publication degree placement. Provider/router and browser tests cover private CSRF transport, office permissions, deliberate selection, paging, escaped labels, preserved form values, mobile reflow and automated accessibility checks. Live cross-database execution and production recovery are not established by these tests.
