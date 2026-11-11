# Retained repeat-attempt ordering

In the **Degree audit builder → Degree settings → Institutional audit policies**, choose **Latest eligible attempt per course** to reveal **How latest attempts are ordered**.

- **Academic term / instructional period** is the default for a newly created blank degree draft. It orders institutional attempts by the instructional **start date** from the exact section publication retained at registration. Short/non-standard offerings can therefore have distinct instructional periods within a term.
- **Actual completion date** uses an explicitly retained source completion date. It does not substitute an instructional end date, grade-posting time or correction time. Authorized registrars can now [record or correct institutional completion dates](academic-completion-dates.md); missing required dates still leave competing attempts undetermined.

Changing to all-attempt or highest-grade GPA hides this extra choice while retaining the draft's explicit value. Review and publication show the chosen policy. Cloning retains the source definition's choice; opening or cloning an earlier definition with no choice leaves it unknown. Only a new blank draft receives the default.

New latest-attempt publications must select an ordering policy. A missing choice blocks review and a new confirmation without writing an event or receipt. Earlier publications still replay with their original missing value. Completed publication confirmations retain their original retry result; no current policy default is added to an accepted earlier event.

Students continue using the exact degree publication selected for their program pursuit. Publishing a later degree with different ordering does not change that selection.

## Dates used by Credits & GPA

The on-demand [Credits & GPA review](student-credit-gpa-review.md) resolves dates only when eligible GPA attempts for the same actual course need academic-period comparison. It uses the current verified academic/registration inputs to locate their retained section publications, then verifies the course identity, course publication and term against the academic record.

For example, a course taken in an August instructional period remains earlier than its October retake even if the August grade is posted or corrected in November. A later edit to today's term dates or a later section publication does not change either retained period. The contribution cards show **Retained instructional start** or **Retained completion date** when a date was supplied to the calculation.

Only eligible GPA attempts compete: GPA-excluded transfers, voids, zero-credit attempts and grades from unknown/incompatible publications do not acquire a new ordering rule through this read. A sole eligible attempt needs no chronological comparison. Equal dates retain the calculation core's existing tie behavior: numerically equivalent contributions can remain alternatives for one contribution; different weights remain undetermined.

Older unbound institutional work has no retained offering dates. Transfer awards retain actual source completion dates, and authorized registrars can now [record a separate sending-institution period start](transfer-academic-periods.md). Older missing period evidence stays unknown. The review leaves these gaps explicit. It never uses today's posting-term dates or treats transfer completion as academic-period chronology. A required missing date leaves GPA undetermined, while independent known earned credits remain available.

## Bounded historical reads

Each distinct required section stream is replayed once per calculation invocation, retaining only requested versions and minimal academic context. Multiple requested versions of the same section share that replay. A new invocation rechecks history; no cross-request date cache or live section fallback is introduced.

These reads share the existing 400-page/2,000-event request budget with the selected-policy and academic-input reads. Reaching a limit or finding contradictory/corrupt historical context returns no partial calculation. Reads stay inside the existing student lock/transaction and owner or `sis:student:read` authorization boundary. Raw publication events, faculty identities, protected reasons and removed-record details are not returned to the review.

## Alpha storage change

The composition's existing JSON policy object now accepts `repeat_attempt_order` as `academic_period`, `completion_date` or unknown/missing. New blank drafts record `academic_period`. Existing payloads are not normalized or rewritten on read, so their hashes, accepted receipts and missing facts remain unchanged. Historical validation and validation for a new publication are deliberately separate.

A bounded, read-only inventory of the configured application database found one composition draft without this field and no degree-publication streams/events before this extension. No stored row or event was changed. The existing JSON columns and registered event format support the additive policy field; no table migration, numbered model wrapper or parallel event store was introduced.

Full degree constraints, retained evaluation results and accepted-course publication/equivalence capture remain later stages. Sending-institution period starts now have a separate reviewed workflow. Institutional completion dates now have a separate reviewed workflow. This ordering integration changes current-work review, not standing, issued transcripts or graduation decisions.
