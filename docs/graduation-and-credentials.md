# Graduation and credentials

Degree progress explains academic requirements. A **graduation eligibility decision** records the reviewed evidence and institutional clearance. A separate **conferral** creates an academic credential. Neither a successful audit nor closing a program pursuit automatically awards a degree.

## Review one candidate

Open **Students → student record → Student programs → More → Graduation & credentials**.

1. Choose **Review eligibility**. Enter the credential title, actual graduation date, graduation term and optional honors. Titles are explicit; the system does not infer a degree from a program code such as `ARTS.UG`.
2. Review the exact published degree and assigned components, approved exceptions and verified coursework. Every supported academic minimum and the combined curriculum allocation must be satisfied. Unknown or failed academic checks cannot be overridden by an attestation.
3. Verify the institution's other graduation requirements and release clearances. This is an attributed staff attestation, not an automated financial or disciplinary clearance. Review and acknowledge any earlier-history gaps against supporting records when applicable.
4. Enter a reason, review the decision, and confirm it. Incomplete eligibility can be recorded for follow-up. An eligibility decision creates no credential.
5. With conferral authority, choose **Review conferral**, verify the credential details and confirm separately. Changed inputs require a fresh eligibility decision first.

The graduation date cannot be in the future. Earned coursework requires an actual, verified completion date on or before graduation. Earlier dates also require dated evidence for graded work; an undated withdrawal cannot establish a backdated GPA. Recency requirements are evaluated at the graduation date. This workflow uses the supported current academic inventory; it does not reconstruct an unrecorded historical transcript or silently backfill an old credential.

## Confer a group

Open **Group conferral queue** from the graduation drawer. The queue contains **recorded eligible candidates**, with their names, institutional IDs, credential titles and graduation dates. Recorded eligibility can become stale; queue membership alone does not authorize an award.

Select one to ten candidates, enter the ceremony/authorization evidence, and choose **Review selected conferrals**. Review every selected credential, including its graduation date and term, then choose **Confer selected group**. The server rechecks each candidate and confirms the selection atomically. If one candidate's review changed, none of that selection is conferred. Each successful candidate retains an individual credential identity, protected decision and retry receipt.

Use the next/previous controls for further candidates and confirm additional bounded selections for a larger ceremony. The ten-candidate confirmation bound keeps synchronous history verification and database locks short. Candidate checks run on the server; the browser does not calculate eligibility. Background ceremony processing and a saved-report-to-candidate import are separate future extensions.

Existing student-related saved reports can help staff identify students for eligibility review. Running a report, exporting a student list, or selecting a program does not confer credentials. The dedicated queue provides a controlled graduation workflow without creating another report type.

## What closes at conferral

Conferral **automatically closes the awarded program pursuit** in the same transaction as the credential decision. The person, sign-in account, student capability, course history, charges and other pursuits remain available. No primary-program replacement or institutional withdrawal is inferred.

The primary program can continue to identify the last selected, now-closed pursuit. Staff can select another active pursuit as primary or assign a new program through the existing reviewed workflows. A pursuit with a current credential cannot be reopened or receive new registrations/academic-work creation. Closing an unrelated pursuit through Program status remains a separate decision and does not mean graduation.

## Corrections and revocations

**Correct title or honors** records new credential wording with an attributed reason. It preserves the credential identity, graduation date, term, program and curriculum basis. The earlier wording remains in protected history.

For a material date, term or curriculum change, **Revoke credential**, independently reopen the pursuit if appropriate, record fresh eligibility, then confer a new credential. Revocation retains the original award and does not reopen the pursuit, delete coursework, alter earned course credits, deactivate the student or reverse charges. A revoked award does not make its prior coursework ordinary reusable work for another pursuit.

Original accepted retries return their original receipt, including after later correction, revocation or expiry of the original review. They do not repeat closure, confer a second credential or replace a later decision. Unaccepted reviews expire after twenty minutes and require the same authenticated reviewer, action, scope, evidence and current sources.

## Student portal and transcripts

In **Degree progress**, students can open **Graduation & credentials** for their own pursuit. They see current credential details and a bounded decision history. Original conferrals remain visible after correction/revocation. Protected reasons, actor identities, full audit inputs and raw events are withheld.

New official academic transcripts retain currently conferred credentials, their graduation dates/terms, honors and curriculum labels. Their preparation fingerprint includes the verified credential revisions. A correction or revocation invalidates an unaccepted preparation review; it does not rewrite an earlier prepared document. Earlier protected HTML/PDF documents retain their original content. CEU transcript layouts continue to represent continuing education and do not receive academic degree credentials.

## Coursework from a graduated pursuit

Once a genuine conferral is recorded, coursework belonging to that pursuit or included in its award evidence cannot satisfy another pursuit through an ordinary reuse approval. This applies to earlier reuse approvals too. New ordinary approvals and attribution changes cannot bypass the rule. The staff scope screen identifies **formal transfer evaluation required** instead of offering a new reuse approval.

The original academic work and credential remain intact. Structured same-institution transfer evaluation with a new receiving decision, source lineage and duplicate-credit safeguards remains an explicit roadmap item. Do not create a fictional external college/equivalency, move the original credits, or relabel them as transfer work to work around that boundary. A closed pursuit without a recorded award is not presumed graduated.

## Authority and retained facts

Grant `sis:student:read` with `sis:graduation:review` to eligibility reviewers. Grant `sis:student:read` with `sis:graduation:confer` to authorized conferral/correction/revocation officers. These are independent duties; installing the migration grants no roles or accounts either permission. Staff may hold both duties when institution policy allows it. Portal ownership never grants either staff duty.

Reviews show the current verified person name and institutional ID, lock the person record and retain its core identity reference. A changed canonical person link cannot reuse an earlier graduation stream. Group confirmation uses the fresh reviewed identities, rather than trusting the names previously displayed in the queue.

The protected `graduation` stream uses the pursuit ULID in the existing authoritative event journal. It retains the exact bounded eligibility basis, decision chain, actor, reason, effective time, credential and original operation receipts. `sis_graduation_cases` stores only a reconstructible routing/version/hash/queue projection. No historical awards are inferred from existing rows, graduation-looking program names, status or legacy boolean/date fields. All commands share the student's transaction lock and require expected versions, mandatory audit, source checks and framework CSRF protection. Private readers are owner/staff scoped and no-store. Drafts and review proofs stay in page memory.
