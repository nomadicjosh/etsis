# Course credit policies and offering credits

A course defines a default number of academic credits and either a fixed value or a permitted range. A course section chooses **one fixed offering value** within its selected course policy. For example, a course can allow 1–6 credits in 0.5-credit increments, defaulting to 3; one section can offer 2.5 credits and another 4.

Every new student registering in that section receives its selected offering quantity. Student-selected quantities within a section and separate continuing education units (CEUs) remain open in the roadmap. Academic credits are never converted to or labelled as CEUs. Contact hours remain a separate institutional calculation.

## Configure and publish a course

1. Open **Academic catalog → Courses → a course → Course credit policy**.
2. Enter **Default academic credits**. Choose **Fixed credits**, or **Credit range** with a minimum, maximum and increment.
3. Choose **Save credit draft**. The default and rule save together. A range must have aligned endpoints, and the default must be an allowed value. Values use exact thousandths, from 0 through 999.999; a fixed rule has no increment.
4. Choose **Review publication**, enter the approval reason and complete the existing course-publication confirmation. Saving a draft leaves earlier publications unchanged.
5. Select that publication for the section through **Choose course definition**. A variable-credit course requires this retained policy before registration.

The ordinary editor cannot save a default outside its saved range. Cloning copies a range and validates the proposed default; a fixed-credit clone uses its proposed fixed default. Today's course policy does not change a section already bound to an earlier publication.

## Choose and publish an offering value

1. Open **Academic catalog → Sections → a section → Offering credits**. The drawer shows the selected course policy.
2. Keep **Use the retained course default** checked, or clear it and enter **Fixed credits for this offering**. In the example above, 2.5 is allowed; 2.25 is not.
3. Choose **Save credit draft**, then **Review publication**. Review the saved quantity with the offering's dates, classifications, capacity, teaching assignment and fee.
4. Publish and select the offering through the existing reviewed workflow. Offering-plan activation uses the same retained quantity and policy checks.
5. Changing a selected quantity requires a new publication and a reviewed **future offering amendment**. New registrations stop while the draft and selected offering disagree. Earlier students keep their accepted references.

Both editors provide **Back to record**. An unbound section explicitly labels its draft policy and default; choose a publication before publishing the offering. Failed saves retain entries; lost access clears the form. Stale revisions require reloading. Drafts and publication proofs stay in page memory.

## Registration, billing and academic history

Individual/grouped registration, schedules, section changes and waitlist entry share the offering quantity. Reviews show that value; per-credit pricing multiplies it by the retained rate with exact currency rounding. Flat charges retain their existing meaning. New academic attempts record the offering quantity as attempted credits, and verified registration load uses the accepted registration quantity. Completed credits still follow academic award/grade decisions. The course default remains separate catalog metadata.

Course discovery shows the offering value. A student's enrollment shows the quantity retained with that student's offering, even after a draft edit or future-only amendment. Changing a policy or offering does not recalculate earlier charges, awards, corrected registered quantities or retained load determinations. Use separately authorized [registered-credit corrections](registered-credit-corrections.md), academic corrections and financial reviews when existing facts need correction. A correction is not the student-selected-credit workflow.

## Retention and validation

New course publications retain `credit_policy`; new section publications retain `credits_milli`. Both use the existing protected streams in `sis_history_events`, expected versions, idempotent receipts, required audit and final source/authority checks. Earlier publications without those fields remain unrecorded. No policy, quantity or event is backfilled.

Migration **20261123000003_AddSisOfferingCreditPolicies** adds four nullable columns to existing draft/publication tables. It is retry-safe and refuses destructive rollback. Five SQL compiler dialects and populated SQLite preservation are tested. On the configured development MariaDB database, all original values across these tables and all journal/support bytes matched before and after. Earlier new fields stayed null, and independent journal/support checksums matched.

Focused tests cover precision, immutable binding, unpublished choices, stale reviews, offering-plan activation, future amendments, pricing/attempt/load quantities, clone rollback, tampered projections, audit/source/authority rollback and real private provider/CSRF routes. Five browser workflows cover exact editing, publication/record returns, failure/access handling, detached responses, draft/retained labels, narrow screens, enlarged text and WCAG scans. Existing publication, registration, billing, waitlist, correction and portal regressions pass in bounded sequential batches. Student-selected quantities, CEUs, populated multi-engine qualification and broader historical reconciliation remain open.
