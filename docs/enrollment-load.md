# Enrollment load

Enrollment load describes the credits in a student's active registrations for a selected term. It is separate from academic standing, earned credit, GPA, attendance, student activity and financial-aid eligibility. Institutions define their own credit bands; etSIS does not assume that full time always means twelve credits.

## Set up the policy

1. Open **Students → Load policy** with the independent `sis:load:policy` permission.
2. Select **Classify enrollment load** and enter your institution's bands in increasing credit order. Each band has a unique code, a name and a minimum number of credits. Start the first band at zero. Use up to twelve bands and up to three decimal places for credits.
3. Enter a reason, choose **Review policy**, check the current/proposed ranges and choose **Publish policy**. **Back to editing** preserves your draft. The review expires after twenty minutes.
4. Use **Program policies** to find a program that needs different thresholds. Select **Use separate program bands**, review and publish its definition. Clearing that selection restores the institutional policy. Clearing institutional classification leaves verified credit totals available without assigning a load label.

For example, an institution might define Part time from zero to under twelve credits and Full time from twelve credits upward. Exactly twelve credits belongs to Full time. These are examples, not seeded institutional rules. A program can instead establish its own minimums without redefining levels such as Undergraduate or Graduate.

Policy setup is a separate administrative duty. The protected permission is not automatically assigned to existing roles, and it does not grant access to student records. A super administrator can grant it through the existing access workflow. **Publication history** additionally requires `sis:audit:read`; it shows accepted bands, publication time and the staff reason.

## Review a student's load

Staff with `sis:student:read` open the student's programs and choose **Enrollment load**. Select **Review enrollment load**, search for the term and choose it. Refresh explicitly when registrations change.

Students have the same on-demand action beside their selected program's degree progress in **My campus → Degree progress**. Current assigned academic advisors have it in **My campus → Advising** after opening an advisee's program. These endpoints enforce owner-only or dated advisor access; advisor assignment does not confer general student, policy or financial access.

The selected pursuit must be the student's current primary, active pursuit. The review uses its program override, falling back to the institutional policy. It counts **all still-active registrations in the chosen term**, including registrations accepted under the earlier pursuit before a program change. The earlier pursuit's completed coursework does not become current-term enrollment merely because the program changed.

The screen presents the registered credit total, applicable label, program, policy source and minimal course labels/credits. It distinguishes:

- No active registrations: zero credits and no load label.
- Active zero-credit registrations: a genuine active registration total of zero, classified under the configured zero-credit band.
- No configured policy: verified credits with no classification.
- Incomplete, inconsistent or excessive history: no partial total or classification; refer the review to the registrar.

Early and late drops are excluded from active load. Early-drop details are not returned through this screen; their restricted history remains retained. Excluding a late drop does not reverse its charge. A retake counts only while active in the selected term and retains its distinct attempt/billing identity.

## Which credits and term are used?

Load uses the credit quantity recorded when registration was accepted. Today's course credits/title, a later awarded-course replacement, a corrected academic posting term, or a subsequent grade/awarded-credit correction does not overwrite that registered quantity or registration term. Current award units and registered units answer different questions. This review does not create an override for registered units; an official policy requiring different treatment needs its own reviewed determination workflow.

Opening events genuinely recorded as registration establish these quantities. Earlier rows observed after the fact do not prove original registered units. Those remain unknown, and etSIS withholds classification rather than inventing them from current rows. Transfers and completed work in other terms do not contribute to current registration load.

## Historical and operational boundary

Published institutional/program policies are consequential academic definitions. Each publication appends to an `enrollment_load_policy` stream in the **existing `sis_history_events` journal** with actor, reason, effective/recorded context, expected version and an instruction-bound original-result receipt. `sis_enrollment_load_policies` is a reconstructible current policy projection; its empty global locking anchor is operational setup, not a fabricated publication. No second event journal or earlier-data conversion is introduced.

Policy writers lock the global anchor before a program, so first publication, inheritance and concurrent global changes use a consistent order. Publication replays the selected policy within a bounded budget. A program review also detects a changed institutional fallback. Required audit, authority and proof-expiry failures roll back event and projection writes together. Retrying an accepted confirmation returns its original receipt without republishing or replacing later policies.

A working load review is **runtime-only**. It appends no business event or permanent student-load projection. The selected student is locked during a bounded verification of enrollment and academic-credit history. Inventory includes both current enrollment rows and tracked registration identities, so deleting an active projection cannot silently reduce the total. Closed/early-drop identities are verified but excluded before returning course details. The review allows at most 200 inventory identities and a shared 2,000-event enrollment/credit replay allowance. Incomplete scans never produce a partial total. Student-pursuit checks have their own bounded verification.

Current policy reads verify the authenticated self-contained publication head against the projection; they do not replay every policy publication. Term/program search uses prepared, paginated SQL with twenty results per page and literal wildcard handling. Lists, dashboard and Report Studio do not run this review for each student. Private routes retain framework CSRF, no-store responses and suppressed debug collectors. Audit evidence records only actor, action and student/scope identity; protected reasons and raw streams are not exposed to students or advisors. Browser results/proofs stay in page memory, not local/session storage.

This is a working review of current registrations, including when viewing a past term. It does not reconstruct a past census, certify official full-time status, drive aid or billing, or replace an institutional standing decision. **Retained effective-dated/census determinations, load reporting, policy grandfathering and operational reconstruction qualification remain open roadmap work.**

## Verification

Focused fictional SQLite tests cover exact fractional boundaries, publication/inheritance, framework replay, stale and repeated confirmations, final-audit/authority/expiry rollback, projection tampering, registered versus corrected award units, program changes, drops, unknown earlier quantities, zero-credit registrations, strict ownership and bounded searches. The migration compiles against the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle dialects; live engine acceptance remains separate qualification. Real-provider HTTP checks cover staff, owner and advisor routes, explicit clear contracts, private transport and no event writes during reviews. Browser checks cover draft preservation, exact decimal conversion, on-demand loading, escaped content, CSRF, narrow-screen reflow and automated WCAG checks.

Migration `20261120000010_CreateSisEnrollmentLoadPolicies` was applied to the configured `nomadicjosh_sis` DDEV database on November 20, 2026. All twenty existing journal events, stream heads and operation receipts retained their before/after checksums. Existing role/user grants stayed at sixteen/zero. Only the empty policy anchor and independent system permission were added; no institutional bands, student determinations or historical events were invented. Connected verification passed 64 focused PHP tests (391 assertions), real-provider HTTP checks and two single-worker browser workflows. New PHP files and changed PHP lines pass the project coding standard.
