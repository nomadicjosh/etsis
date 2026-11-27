# Course auditing

An audit enrollment lets a student attend a section without earning academic credit, GPA points or
CEUs. It uses a normal seat and stays visible on the student and teaching rosters. Auditing is an
explicit enrollment basis, independent of registration status: a staff or student registration can
be an audit, and a later withdrawal keeps that basis.

## Configure the institution's rules for a section

An authorized catalog officer opens **Course sections → section → Course auditing**:

1. Select **Allow auditing for this section**. The default is off.
2. Decide whether students may request an audit seat themselves. Leaving this off requires staff
   registration. Ordinary student self-registration must also be allowed for the section.
3. Enter the **Audit section fee** in the section's currency. An explicit zero waives this fee.
4. Save the draft, then use **Review offering publication** to publish and select the offering through
   the existing reasoned review. Saving the draft alone does not authorize auditing.

These are institutional rules for the selected offering; students cannot change them. Audit registration
keeps ordinary student activity, holds, program and campus eligibility, prerequisites, co-requisites,
graduate-course approval/GPA rules, timetable, course-count limits, registration windows and waitlist
priority. Auditing does not grant an eligibility waiver. Institutions that want a different eligibility
rule must review their existing published course/section policies rather than granting an implicit
exception through the audit choice.

Changing saved offering rules requires a new reviewed publication/selection before new registration
can use them. Existing enrollments retain their accepted basis, publication, academic facts and charges.
An earlier accepted confirmation remains an idempotent retry after later policy changes.

## Register and review charges

Staff use **Student → Register for a section → Enrollment basis → Audit — no credit or GPA**.
Students see the same enrollment choice under **Registration → Review & register** when that section's
published policy permits student auditing. The review explicitly shows zero academic credits and the
proposed charges before confirmation. The basis is bound to the review; a standard review cannot be
reused to confirm an audit enrollment.

The audit section fee replaces the ordinary section fee. Registered-credit tuition is calculated with
zero credits; applicable once-per-term fees still apply under the existing financial rules. Audit does
not mean free attendance. Registration, its seat, the zero-credit academic record, actual charges,
notification and required audit commit together. An unsuccessful confirmation leaves no partial seat,
credit or charge. Accepted retries do not charge again.

Register audit sections individually in the staff/student screens. The staff batch API also supports
one explicit basis for the reviewed group. The schedule planner continues to create standard
enrollments. An audit request does not join or consume a credit waitlist request; resolve that request
separately and review an available audit seat. Existing waitlist priority still protects earlier requests.

## Teaching and academic records

Auditors remain available for attendance and ordinary working coursework. Academic final-grade
choices, earned CEUs and registered-credit corrections are unavailable for an audit. The final-grade
queue excludes auditors, and class grade certification verifies their source before leaving them
outside the academic grading roster. An all-audit class has no academic grades to certify.

Audits have zero attempted/earned academic credit and zero GPA points. They cannot meet a prerequisite,
co-requisite or degree requirement, including a zero-credit course requirement. They contribute zero
registered credits to current load calculations. A normal zero-credit passing course remains distinct
and can qualify under its existing academic rules.

Academic transcripts show **AU** for auditing and **AU/W** for a withdrawn audit. These are designations
derived from the retained enrollment basis, not fabricated final grades or evidence of completion.
Audit rows do not enter GPA totals or CEU transcripts. The same designation appears in the ordinary
staff/student transcript reader and the verified official transcript preparation/preview.

## Drop, withdrawal and corrections

The approved drop policy still applies. An early drop removes the current enrollment/academic
projections and reverses the actual course charge; protected original decisions remain in history.
A late withdrawal retains the audit basis and charge, with zero academic credit and an AU/W transcript
designation. Separately governed withdrawal credits still follow the existing reviewed policy.
Reviewed restoration of an eligible tracked ungraded withdrawal preserves the audit basis and original
financial facts; it requires the current published audit policy and ordinary reinstatement checks.

Changing from audit to standard enrollment, or moving an audit to another section, uses a reviewed
drop and a new registration during add/drop. There is no silent conversion of an existing attempt into
earned credit. Later closed-record changes require the records office's review; they must not overwrite
the accepted audit facts.

## Historical and implementation boundaries

The current enrollment and institutional academic snapshots carry an optional explicit audit basis.
Earlier standard events keep their original shape and bytes. The additive migration gives existing
current projections their existing standard behavior, with no inferred audits or rewritten grades.
Published audit policy is retained with the existing offering publication and genuine registration
evidence in the single protected event journal. Working policy remains audited catalog CRUD.
Current reads compare the basis and zero quantities with protected history; changing a projection
cannot turn an audit into qualifying credit. Normal owner/office permissions, CSRF and private/no-store
responses apply. No separate audit event store, permission grant or report is introduced.
