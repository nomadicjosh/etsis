# Same-institution transfer evaluation

Coursework used in a completed degree does not automatically count toward a later program pursuit. Authorized staff can now evaluate **institutionally earned academic work from a genuinely conferred pursuit** and create a separate receiving transfer award. The original attempt, grade, completion date and credential remain intact.

For example, a student completes `ARTS.UG` and later enters `NEXT.UG`. The registrar can review an original history course and accept an explicit amount toward a published receiving course in `NEXT.UG`. The new credit belongs only to `NEXT.UG`; it does not move the original course or grant ordinary reuse of completed-degree work.

## Evaluate completed-degree work

1. Open **Students → the student → Student programs**.
2. Open the receiving pursuit's **More** menu and choose **Same-institution transfer evaluation**.
3. Choose **Evaluate completed-degree work**, then select an available original attempt. Review its course, passed grade, earned credits, verified completion date, source program and conferred credential.
4. Select the receiving course and a specific **Published receiving definition**. Use **Find receiving course** to choose a different course when appropriate.
5. Select the receiving posting term, academic credits to accept and a published passing receiving grade. Enter the evaluation reason.
6. Choose **Review evaluation**, verify the student's identity and both pursuits, then **Confirm evaluation**.

New awards require an active receiving pursuit. The original work must belong to the same student and canonical person, have an eligible institutional source and known protected history, and have a genuine current source credential. Its completion must be known and on or before conferral. A closed program alone does not establish graduation. Audited, unpassed, zero-earned, CEU-only and already-transferred work are not eligible institutional sources.

Earlier-history gaps require records review; the evaluator does not invent old awards or silently accept observations to make a source eligible. An empty candidate list means there is no currently eligible, unevaluated source within the supported verified inventory. It is not an automatic denial of an institution's separately documented petition.

## Receiving credits, grades and dates

The accepted amount cannot exceed either the earned source credits or the published receiving-course maximum. A smaller amount may be accepted. Staff must select the receiving grade explicitly; the original passing grade does not automatically select it. The published receiving grade determines its points and GPA eligibility. The selected degree's transfer-GPA rules can still exclude that grade from the degree GPA.

Receiving credit is transfer credit and supplies no institutional residency credit. CEUs remain separate and are not converted to academic credits. The original completion date supplies completion and recency evidence; selecting a later posting term does not make old coursework recent. Existing degree-audit, prerequisite, transcript and approved student-report readers use the verified receiving award under their normal pursuit and transfer rules. A requirement restricted to a particular external sending institution does not treat a known same-institution source as an unknown external college.

A source attempt can have only one receiving evaluation per pursuit, including when that evaluation has been voided. Correct or reinstate the existing case rather than creating another award. For another later pursuit, evaluate the original institutional attempt separately; do not repackage a receiving transfer award as newly earned institutional work.

## Correct an evaluation

Open **View evaluation** to review the retained source evidence and current receiving credit. The staff transcript's **Review transfer award** action also opens this receiving case.

- **Correct receiving units or grade** records a reasoned amendment. The current eligible source and published receiving limits are rechecked.
- **Void receiving award** removes its current receiving credits and GPA contribution while retaining the original source and all earlier decisions. It remains available if the source later becomes ineligible.
- **Reinstate receiving award** reviews the source again and accepts an explicit amount and grade. The last accepted quantity is offered as a starting value after a void.
- **Correct receiving course** uses the shared published-course review. A replacement cannot have a smaller maximum than the currently accepted amount; first correct the receiving units when needed.
- **Correct posting term** changes the receiving attribution through the shared reviewed writer. The original completion date and source period cannot be rewritten here.

Corrections remain available to authorized staff after receiving-pursuit closure; closure prevents new awards. Course and posting-term corrections preserve the accepted source lineage. Corrections that change units or reinstate credit require fresh eligible source evidence and remain capped by the originally retained earned source amount. A source change or revoked credential invalidates an unaccepted evaluation review. It does not automatically rewrite an already accepted receiving award; staff must review that case and record an appropriate independent correction or void.

Every mutation requires a reason, a current review and confirmation. Drafts and review proofs stay in page memory. A stale or expired review must be revised against current evidence. An exact retry of an already accepted operation returns its original result rather than applying it again. **Back to record** returns to the student; the secondary return action returns to the evaluation list or case.

## Authority and retained evidence

Staff need both `sis:student:read` and `sis:transfer:manage`. Student ownership does not grant evaluation authority. Private browser routes retain framework CSRF protection, current authorization checks, no-store responses and debug suppression. This migration grants no roles or accounts additional permissions.

The existing `TransferCredit` aggregate records genuine receiving decisions in the single protected event journal. Its internal source capsule retains the original credit, source and receiving pursuits, source credential, canonical person reference, verified academic/registration/pursuit/graduation versions, original course/grade/date/quantity and evaluated intent. Later labels or policy drafts cannot reinterpret that accepted evidence. Expected versions, durable operation receipts, person/student locks, required audit and final source checks protect the atomic award, academic projection and receiving-pursuit attribution.

`sis_internal_transfer_awards` is a reconstructible source-routing projection. The read-only `sis_transfer_sources` view unifies real external and same-institution sources for existing readers; it creates no fictional external college or equivalency. There is no additional event journal, historical award backfill or report type. Source history and accepted receiving decisions remain separate facts. Ordinary attribution and reuse cannot move this receiving award to another pursuit.

Candidate and evaluation lists use ten-entry pages, curated decision history uses bounded pages, and reviews expire after twenty minutes. Shared replay budgets limit verification. Larger background evaluations, automated articulation or institution-specific approval policy, and production recovery/native business-write certification remain separate roadmap work. This feature does not imply that external coursework used in an earlier degree has become institutionally earned; retain its real external origin and evaluate it through the appropriate external workflow.
