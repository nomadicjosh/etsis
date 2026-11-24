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

## Record a current-term determination

Staff additionally need the independent `sis:load:determine` duty, which grants no policy or general student access. No roles or accounts receive it automatically. Open **Enrollment load**, review a currently running term under the student's current primary active pursuit, and enter **Reason for recording load**. Choose **Review load determination**, inspect the date, total, classification, exact published bands and registration quantities, then **Confirm load determination**. Back to editing preserves the reason. A rejected or stale confirmation leaves the review available for deliberate refresh; obtain a new review before confirming changed evidence.

The server accepts only fully verified recorded inputs, a published institutional/program policy and at most fifty active registrations. No registrations yields zero with no label; an active zero-credit registration receives the zero-credit band. Unknown/limited inputs and past/future terms cannot become retained determinations. The date comes from the application clock; staff cannot supply a backdate or alternate credit quantity. A review lasts twenty minutes and binds its staff member, student, pursuit, term, reason, policy choice and evidence.

Confirmation records a separate immutable fact. A later determination does not replace an earlier one or designate a single official result. Changes to grades, registrations, program/catalog labels and published bands do not alter the original total or classification. An original accepted retry returns its receipt even after later changes or review expiry, subject to current staff authority and integrity checks. No registration, charge, standing, aid or eligibility decision follows automatically.

### Choose a retained policy publication

The default **Use the current effective policy** follows the program's current override or institutional fallback. Staff who also have `sis:load:policy` can choose a retained publication when the institution authorizes a different basis for this particular determination:

1. Choose **Choose a published policy** in the determination form. The list loads only on request.
2. Select **Institution** or **This program** in **Publication scope**. Inspect the publication number, date and exact credit bands; use **More publications** and **Previous publications** as needed. Publications that cleared classification or restored inheritance have no bands and cannot be selected.
3. Choose **Use publication …**. Explain the institution-authorized basis in **Reason for recording load**. The choice and reason survive paging, a failed request and **Back to determination editing**. **Use the current effective policy** clears the explicit choice.
4. Review the selection basis, publication date, bands and today's verified registration quantities before confirmation. The server rejects unknown publications, publications from another program, altered choices, stale registration evidence and lost authority. A new publication does not change an explicitly selected retained publication.

This choice applies to one new determination. It does not configure pursuit-level grandfathering, reconstruct an earlier census, backdate registration quantities, or change the current working load review. If current classification is unconfigured, a retained publication with bands can still support an authorized new current-term determination. Without either a current definition or an explicitly selected definition, recording is unavailable. Institution policies governing when a historical publication is appropriate remain the institution's responsibility; the private reason records that justification.

## Recorded history

Choose **Recorded determinations** to find a term, or **Recorded determinations for this term** from a working review. Ten-record pages show retained date, total, classification, active registration count and policy bands/program context. Earlier pursuits remain readable through staff and owner scope; advisors still need the existing current dated advising assignment. Working reviews and new determinations continue to require the current primary active pursuit.

Students and assigned advisors use the same read-only history panel beside degree progress. Readers receive no staff reason, actor, per-course source detail or enrollment/credit source identity. Retaining a total does not reopen coursework restricted after an early drop. Source evidence remains in protected academic history. Detached screen responses cannot update another record, and private drafts/proofs stay in page memory.

New records show whether the policy was current at determination or explicitly selected, with the publication number and date. Earlier records retain their original bands and policy reference; when selection basis/date were not separately recorded, the panel says so rather than inferring them. Students and advisors can read this explanation but cannot choose publications or record determinations. The chooser exposes no publication reasons or actors and does not require access to the separate audit timeline.

## Check a retained determination

In **Recorded determinations**, choose a term and use **Check retained load history** on the particular determination. This staff-only support action requires `sis:history:verify`, `sis:audit:read`, `sis:student:read` and `sis:load:determine`. It opens the shared history-check drawer; **Back to record** restores the original term, ten-record page and newer/older navigation. The selected reference is available only in staff history responses. Student/advisor history continues to withhold internal determination/source/policy-version identities and private reasons.

Run the check deliberately to compare the original retained decision and its minimized projection. Results contain safe status/counts and a restricted support reference; they do not repeat credit amounts, classifications, policy details or private narratives. Later registrations, corrected quantities, current policy changes or closed pursuits do not rewrite the earlier observation. This is not a fresh load calculation or a reconstruction of census/financial-aid eligibility. The check does not independently replay every referenced registration or policy. A missing/damaged decision is unverified; changed/limited results are incomplete. Support receipts do not approve a later business action.

Trusted operators can use [the existing selected verification command](event-history-foundation.md#selected-standing-and-enrollment-load-verification) with a larger bounded allowance. No repair, new determination, enrollment change, finance posting or automatic side effect occurs. Existing domain decisions retain their own fresh source/concurrency checks.

## Reporting

Report Studio offers **Recorded enrollment load** and **Recorded load by date**. The source has **one row per retained determination**, including multiple decisions for the same student/pursuit/term/date. It includes retained term/program labels, date, classification, credits and registration count, with explicitly current person names/institutional ID. Filter dates explicitly. Count distinct students within each selected group for headcounts; a student may still appear in several date/classification groups. Neither summing decisions nor summing group headcounts produces a current census.

The source requires both `sis:student:read` and `sis:load:determine`, including for shared and static reports. Existing save, sharing, encrypted static capture, CSV, expiry and authorized related-record features apply. Credit/count sums or averages reject joins that repeat the determination unless a supported single-related-record selection protects the source grain. Protected reasons, actor identities, policy-version references, registration-source identifiers and raw events are absent. Reports query the minimized projection and do not replay journal events per row; selected history reads additionally verify their protected fact and projection.

## Which credits and term are used?

Load uses the credit quantity recorded when registration was accepted. Today's course credits/title, a later awarded-course replacement, a corrected academic posting term, or a subsequent grade/awarded-credit correction does not overwrite that registered quantity or registration term. Current award units and registered units answer different questions. This review does not create an override for registered units; institutional treatment of corrected registered units remains separate policy work.

Opening events genuinely recorded as registration establish these quantities. Earlier rows observed after the fact do not prove original registered units. Those remain unknown, and etSIS withholds classification rather than inventing them from current rows. Transfers and completed work in other terms do not contribute to current registration load.

## Historical and operational boundary

Published institutional/program policies are consequential academic definitions. Each publication appends to an `enrollment_load_policy` stream in the **existing `sis_history_events` journal** with actor, reason, effective/recorded context, expected version and an instruction-bound original-result receipt. `sis_enrollment_load_policies` is a reconstructible current policy projection; its empty global locking anchor is operational setup, not a fabricated publication. No second event journal or earlier-data conversion is introduced.

Policy writers lock the global anchor before a program, so first publication, inheritance and concurrent global changes use a consistent order. Publication replays the selected policy within a bounded budget. A program review also detects a changed institutional fallback. Required audit, authority and proof-expiry failures roll back event and projection writes together. Retrying an accepted confirmation returns its original receipt without republishing or replacing later policies.

A working load review is **runtime-only**. It appends no business event or permanent student-load projection. The selected student is locked during a bounded verification of enrollment and academic-credit history. Inventory includes both current enrollment rows and tracked registration identities, so deleting an active projection cannot silently reduce the total. Closed/early-drop identities are verified but excluded before returning course details. The review allows at most 200 inventory identities and a shared 2,000-event enrollment/credit replay allowance. Incomplete scans never produce a partial total. Student-pursuit checks have their own bounded verification.

Current working policy reads verify the authenticated self-contained publication head against the projection; they do not replay every policy publication. Term/program search uses prepared, paginated SQL with twenty results per page and literal wildcard handling. Lists, dashboard and Report Studio do not run this review for each student. Private routes retain framework CSRF, no-store responses and suppressed debug collectors. Audit evidence records only actor, action and student/scope identity; protected reasons and raw streams are not exposed to students or advisors. Browser results/proofs stay in page memory, not local/session storage.

Reading a working review never records a determination. Explicit confirmation appends one `EnrollmentLoadWasDetermined` event to its own immutable `enrollment_load_determination` stream in the same journal, using the operation ULID as its identity and expected version zero. Actor, reason, local determination date, retained program/term labels, exact bands/publication reference and actual enrollment/academic source references and versions are protected event facts. No events are synthesized for earlier data. A later decision gets a different identity.

`sis_load_determinations` is a minimized, reconstructible report/list projection, not another event store or mutable current-load cache. It contains retained totals/labels and routing fields, never reasons, actors or source identities. Selected history pages and accepted retries verify the event and compare the derived row; missing or altered selected evidence fails closed without repair. A paged list is not a complete archive reconciliation. Operational rebuild/restore qualification remains open.

Recording locks the selected student, term and policy scopes in a consistent order. A shared 2,000-event budget covers source and pursuit verification across confirmation checks. Snapshots contain at most fifty active sources and 55,000 JSON bytes. Final source/date/proof/authority/audit checks and expected-version append keep the event, receipt and projection atomic. Fingerprints normalize object-key order so replay through the protected store preserves the reviewed intent.

Current-term determinations preserve the fact recorded today. **Backdated/census policy selection, grandfathering, further institutional quantity policies and a reviewed determination replacement/correction lifecycle remain open.** [Corrections to genuinely recorded registered credits](registered-credit-corrections.md) now use an independent registrar review, preserve the original quantity and leave earlier determinations unchanged. No determination reconstructs an earlier census, certifies financial-aid eligibility, drives billing or replaces an institutional standing decision.

## Verification

Focused fictional SQLite tests cover exact fractional boundaries, publication/inheritance, framework replay, stale and repeated confirmations, final-audit/authority/expiry rollback, projection tampering, registered versus corrected award units, program changes, drops, unknown earlier quantities, zero-credit registrations, strict ownership and bounded searches. The migration compiles against the framework's MySQL, PostgreSQL, SQLite, SQL Server and Oracle dialects; live engine acceptance remains separate qualification. Real-provider HTTP checks cover staff, owner and advisor routes, explicit clear contracts, private transport and no event writes during reviews. Browser checks cover draft preservation, exact decimal conversion, on-demand loading, escaped content, CSRF, narrow-screen reflow and automated WCAG checks.

Migration `20261120000010_CreateSisEnrollmentLoadPolicies` was applied to the configured `nomadicjosh_sis` DDEV database on November 20, 2026. All twenty existing journal events, stream heads and operation receipts retained their before/after checksums. Existing role/user grants stayed at sixteen/zero. Only the empty policy anchor and independent system permission were added; no institutional bands, student determinations or historical events were invented. Connected verification passed 64 focused PHP tests (391 assertions), real-provider HTTP checks and two single-worker browser workflows. New PHP files and changed PHP lines pass the project coding standard.


### Current-term determination verification

The connected determination/history/reporting bundle passed **46 focused PHP tests / 302 assertions**, the real-provider staff/owner/advisor HTTP and CSRF/private-response check, and four single-worker browser workflows. Coverage includes immutable facts after drops/policy changes, no registrations versus active zero credits, local date/UTC storage, stale/expired/source-changed confirmation, final audit/authority rollback, accepted retries after pursuit closure, protected-event/projection damage, ten-record history paging, exact fractional report totals, shared/static source permissions, static result preservation and five-dialect schema/query compilation. Browser checks cover confirmation drafts, stale responses, read-only advising, report templates, escaping, 320-pixel reflow and automated accessibility.

Migration `20261120000020_CreateSisLoadDeterminations` was applied to the inspected configured development database on November 21, 2026 in 0.38 seconds. The new table remained empty; all nine inspected history/support/student/standing/load-policy/audit/grant checksums matched. The existing journal retained twenty events and role/user grants remained sixteen/zero. This is additive schema acceptance on the available development database, not populated native/load or institutional census qualification. Checks used focused low-priority, time/memory-bounded commands and one browser worker.


## Explicit registered-credit corrections

Working load uses the quantity recorded at registration unless a registrar explicitly accepts a [registered-credit correction](registered-credit-corrections.md). This is independent of an awarded-credit/grade correction and never recalculates original charges. Staff with the new correction duty can open **Registered credits · course code** beside a load item, review evidence and deliberately confirm; students and advisors receive only the resulting current load quantities. Current bands apply when refreshed, unaccepted determination reviews become stale, and existing retained determinations keep their original amounts/policies. Unknown earlier registration quantities remain unknown.


## Load after a reviewed reinstatement

A [tracked withdrawal reinstated after registered-credit corrections](registration-history.md#reinstate-with-a-corrected-registered-quantity) resumes its latest accepted registered quantity for current load, including an explicitly corrected zero. Its original registered quantity and academic attempted/earned amounts remain separate. Earlier retained load determinations are observations and do not change. A fresh current review uses the restored active attempt and its latest verified source version; recording another current-term determination remains an explicit authorized decision under a published load policy. Reinstatement adds no tuition assessment, census reconstruction or financial-aid eligibility decision.

New determination reviews and publication choices verify the selected policy stream and its current projection within the request’s shared 2,000-event budget. The chooser returns twenty minimized publications per page with protected scope-bound cursors; a new publication requires reopening a continued list. Confirmation pins the exact publication and selection basis, rechecks current registration quantities and authority after the required audit, and preserves the original accepted receipt. Historical selection metadata lives inside the existing protected snapshot; no schema change, parallel journal or new report is introduced.
