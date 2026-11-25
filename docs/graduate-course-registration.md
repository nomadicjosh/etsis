# Undergraduate registration in graduate courses

An undergraduate registers for graduate credit only when the verified GPA for the current undergraduate program pursuit meets the institution's minimum **at registration**, the assigned advisor approves the specific course section, and the registrar records a separate override. The default GPA minimum is **2.000**. Neither approval can waive an academic minimum, section program eligibility, prerequisites, holds, seats or other registration rules.

The GPA is checked when registration is accepted. A later GPA decrease does not cancel that accepted enrollment, prevent its final grade from being posted or remove earned credit. A new registration, section replacement or reviewed reinstatement uses the rules applicable to that new decision.

## Configure the institution's policy

Open **Registration → Undergraduate access to graduate courses** with both registration-management and graduate-policy publication permission.

1. Choose the institution's undergraduate and graduate **academic levels**. These are explicit catalog classifications, independent of names such as `ARTS.UG`, `BA.ENG` or `SCI.501`.
2. Enter the minimum current undergraduate pursuit GPA. The initial value is 2.000.
3. Optionally enter the minimum grade points required in **every completed graduate course** for further graduate enrollment. Leave this blank to omit the additional rule.
4. Enable the policy, provide a reason, review the complete proposed rules and publish them.

Initial installations leave this policy disabled until the institution supplies the level mapping. Publish academic levels on course definitions and the student's assigned program cohort before enabling it. A graduate-course decision with missing or unverifiable classifications, cohort, academic work or calculation policy needs registrar attention; it cannot pass by guessing a level from a name or treating an unknown GPA as zero.

The GPA calculation uses verified institutional cumulative work applicable to the selected current undergraduate pursuit, with its retained grading and repeat policies. Transfer grades do not enter this institutional GPA. Another pursuit's work is excluded unless an authorized academic-work decision makes it applicable. Previously graduated-program coursework still requires the separate formal transfer evaluation into the receiving pursuit; this policy does not create that approval.

## Set a minimum grade for continued enrollment

The optional course-grade rule applies to **every completed institutional graduate-course attempt in the current pursuit**, including a failing attempt with zero earned credits. It does not look only at the most recent course or only at courses earning credit. An earlier failure still matters after a later passing course. The GPA repeat policy remains a separate calculation policy; selecting a higher repeat for GPA does not erase an earlier attempt for this rule.

For example, with a 3.000 course-grade minimum, an undergraduate who completes a graduate course with 2.000 grade points cannot register for another graduate course while that rule applies. This affects further registration, not the earlier course's recorded grade or earned credit. The institution may change its published policy through a separate reasoned decision.

No earlier graduate grade is required for the **first** graduate course. In-progress work, removed or voided work, and withdrawals are not completed courses for the additional grade rule. Non-GPA grades and incompatible or unknown grading publications cannot prove a numeric grade-point minimum; the registrar must resolve their interpretation rather than an approval silently waiving it. Numeric grade points come from retained grading definitions; letter codes are never compared alphabetically.

## Advisor and registrar workflow

An assigned advisor opens **Portal → Advising → an advisee's program and degree progress → Review graduate-course approvals**. Search for the course section, inspect the academic eligibility status, choose **Approve as assigned advisor**, enter a reason, review and confirm. The advisor needs the independent graduate-advisor approval permission and an actual current dated advisor assignment. Having administrative permissions alone does not make someone the student's assigned advisor.

The registrar opens the student's workspace record or an individual registration review and selects **Review graduate-course approvals**. After current advisor approval exists, the registrar chooses **Authorize registrar override**, supplies a reason, reviews and confirms. This requires registration management, student-read access and the separate graduate override permission. These are two separately recorded decisions; holding both duties does not combine them into one click.

The student sees the academic eligibility, advisor approval and registrar override checks in ordinary registration readiness. Personal status responses omit protected reasons, GPA source details and raw event history. The approval status screen itself does not enroll the student: return to registration, refresh its review and confirm registration normally.

A changed published graduate policy, assigned advisor, selected course definition, section offering or program cohort requires fresh approvals for the new context. Academic work and GPA are checked again at registration even after both approvals. Advisors and registrars can separately revoke their decisions; revoking advisor approval also invalidates its dependent registrar override. Revocation remains possible when a later GPA no longer meets the minimum.

## Retained facts and operational bounds

Policy publications and advisor/registrar decisions use the existing protected event journal. A new accepted registration retains the actual GPA totals, calculation-publication reference, bounded academic/enrollment/pursuit source positions, optional completed graduate-grade evidence, policy reference and both approval operation references. Verification follows the original accepted decisions rather than substituting today's GPA or approval state. Ordinary owner reads never expose this restricted envelope.

Individual registration, reviewed student/staff entry, grouped registration, multi-section schedule plans, section changes and reviewed reinstatements share the same academic gate. Existing accepted receipts remain original results after later changes. Failed, stale, expired, unauthorized or interrupted confirmations preserve atomic enrollment, academic-credit, seat and billing behavior; no partial approval or technical counter survives a failed required audit.

Selectors are paged, sections are explicitly selected, and confirmations use bounded history readers and expiring actor-bound proofs. Drafts and reviews remain only in page memory. No new permission grants, report, notification retention or parallel event table is introduced. Institutions assign the three new duties through the existing role/permission workflow.

## Check retained policy and approval history

Authorized support staff can select **Check policy history** in the institutional policy drawer or **Check pursuit approval history** in the section's approval drawer. The latter checks **all recorded graduate-course approval decisions for that pursuit**, including revocations and decisions for other sections. It remains available when the selected section offers no new approval action.

Opening the support drawer lists earlier observation receipts. Select **Run history check** to perform a fresh check; opening the approval screen itself does not create an observation. **Back to record** rechecks access, preserves the unfinished policy fields or same-pursuit approval action/reason, and restores keyboard focus. A lost source permission prevents restoration of the private draft. Drafts remain in page memory, with no browser-storage persistence. A support result never submits or approves the draft; normal fresh review is still required.

Both checks require `sis:history:verify`, `sis:audit:read` and `sis:registration:manage`. Policy checks additionally require `sis:registration:graduate-policy:publish`. Pursuit checks additionally require `sis:student:read` and `sis:registration:graduate-override`, with canonical person routing and super-account protection. An advisor assignment, student ownership or advisor-approval duty alone grants no support access. Institutions assign these existing duties explicitly; no grants are added automatically.

The checker compares protected policy/approval history with its technical version counter. Pursuit checks also authenticate the original policy, course, program cohort, calculation publication and section definition retained by each decision, including revoked decisions. A later policy publication, changed GPA or current advisor assignment does not replace those original definitions. The result contains fixed statuses, safe component labels and counts; it omits GPA values, grade details, reasons and raw payloads. Only a minimized support receipt and required access audit are saved. Policy receipts have no person/student routing.

The browser shares one **200-event** allowance across the selected history and its referenced histories, plus bounded payload, reference-inventory and cooperative time limits. Missing or damaged evidence is unverified; concurrent changes and exhausted limits are incomplete observations without a partial successful decision count. Empty history leaves earlier unrecorded activity unknown. See [operator scope and bounds](event-history-foundation.md#selected-graduate-policy-and-approval-checks) for larger bounded checks.

These checks do not recalculate an earlier GPA, authenticate every underlying academic input or retry receipt, certify archive completeness, approve enrollment or repair records. Registration continues to use its separate fresh eligibility and confirmation checks.

## Recovery qualification

[Selected PostgreSQL and SQLite recovery checks](native-workflow-qualification.md#graduate-policy-approval-and-registration-recovery) now cover policy publication, all four approval/revocation actions, duplicate confirmations, competing revocation/registration, interruption before commit and lost committed replies. They verify that later GPA/policy changes or revoked future consent preserve an accepted enrollment's earned-credit path, while unaccepted reviews must meet current rules. The optional minimum still considers every completed graduate attempt, including an earlier failure after a later pass.

These are isolated fictional service/transaction checks. They create no application records, grant no permissions and do not replace institution-led policy, authentication, load or restore acceptance. Use the linked bounded operator procedure when rerunning them.
