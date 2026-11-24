# Earned CEUs and continuing education transcripts

Offered CEUs and earned CEUs are separate facts. A course or section can declare an offered value; a registrar must explicitly record the units actually earned after verifying completion. A passing grade never awards CEUs automatically. CEUs do not convert into academic credits or contact hours and do not change academic-credit load, GPA, degree progress or charges.

**CEUs do not appear on undergraduate or graduate academic transcripts.** They appear only in a specifically selected **CEU transcript** format. This separation uses the selected transcript kind, not a program-name convention such as BA, MA or ARTS-UG.

## Record an earned CEU decision

1. Publish the course/offering declaration through [Course CEUs and Offered CEUs](course-credit-policies.md#separate-continuing-education-units). The student's registration must retain that offering publication. An older unknown or explicitly no-CEU offering cannot be converted into an earned award by assuming today's declaration.
2. Record a passing final grade and the verified **Actual completion date** through the existing academic-record workflow. The attempt must be tracked, active and free of a pending grade correction.
3. Open **Students → student → More actions → Earned CEU records**. Browse the paged CEU-bearing attempts, then open the course. Staff who already use registration or final-grade screens can also use their **Earned CEUs** action.
4. Enter **Earned continuing education units (CEUs)**, the decision's **Effective date**, and **Reason and institutional completion evidence**. Values use exact thousandths from 0 to 999.999 and cannot exceed the amount in that student's accepted offering. The effective date runs from verified completion through today.
5. Confirm the evidence acknowledgement, choose **Review earned CEUs**, check the previous/proposed quantity, date and reason, then **Confirm CEU decision**.

For example, an accepted offering of 1.250 CEUs can support an explicit earned award of 1.000 CEUs. That decision changes no attempted/completed academic credits or financial postings. Changing today's offering to 2.000 CEUs leaves the earlier student's accepted 1.250 ceiling intact.

**Student read** permits reviewing minimized current CEU information. The independent **`sis:academic:ceus:amend`** duty permits awards, corrections and restricted CEU history. Both are required for decisions; the migration grants the new duty to no role or user automatically. CEU staff can use the student-record chooser without registration or gradebook grants. Existing grade and completion-date operations keep their own permissions.

The drawer uses the shared white inset panels, Bootstrap controls/icons, keyboard access and record returns. Entered values survive history paging, changing a review and ordinary failures. **Reload current academic evidence** preserves the proposed inputs while loading current versions. Authority loss clears private editor content. Late responses cannot replace a returned record. Drafts and review grants stay only in page memory.

## Correct or revoke an award

Open the same attempt and record a new reasoned decision. A corrected value retains the original award. **Revoke the recorded CEU award** explicitly records no earned units; it does not erase history. Zero is a recorded numeric quantity and remains distinct from both revocation and no earlier CEU decision.

Review grants expire after twenty minutes and belong to the reviewing actor, attempt, operation and exact command. Stale academic versions, changed sources, wrong actors, modified commands, missing authority, required audit failure and expiry during completion refuse the decision atomically. Accepted original retries return their original receipt after later corrections and expiry; they do not restore an earlier value or repeat an award.

**Earned CEU history** shows curated before/after quantities, effective and recorded times, actor references and protected institutional reasons under the CEU duty. It reads bounded pages; a page can contain no CEU decisions when intervening academic events fill it. Continue with **Older history** where available. Owner-facing records and transcripts contain no staff reason, actor or raw event envelope.

Closed/removed attempts, unresolved imported history, transfer CEU awards and retrospective repair of an accepted no-CEU or understated offering require further institutional resolution. They are not silently inferred or converted by this workflow.

## Separate academic and CEU transcripts

The existing **Classic** and **Modern** Letter/A4 formats remain academic transcripts. Their snapshots, staff preview tables, HTML/PDF and ordinary owner unofficial records omit CEU fields. The original academic document template is unchanged. CEU awards remain separate from the attempted/earned academic-credit and GPA totals.

An institutional attempt with zero attempted and completed academic credits and an explicitly retained CEU offering is excluded from those academic transcripts. A course carrying both academic credits and CEUs retains its academic-credit row, with its CEUs shown only in the separate CEU record. Earlier unknown declarations are not reclassified from today's course settings, and program names do not determine transcript kind.

For a continuing education transcript, the records office explicitly selects **CEU transcript · Letter** or **CEU transcript · A4** in the existing preparation workflow. Review the dedicated preview, complete the institution's release checks, prepare the protected document and record actual delivery through the existing process. Selecting a CEU format changes the reviewed transcript kind; changing the format requires a fresh review. A transcript result never creates an award.

A CEU transcript includes only explicit current earned institutional CEU awards, retained course/term labels, the completion date retained with the award and a separate earned-CEU total. It excludes revoked awards, degree-program/standing presentation, academic-credit totals and GPA. Unknown earlier CEUs are not represented as zero. A recorded zero remains an explicit quantity.

The student portal shows accepted offered and earned CEUs separately in registered-section records. Under **Transcripts**, **View unofficial record** remains academic, while **View CEU record** opens a separate personal continuing education record. The latter is explicitly unofficial and directs official CEU requests to the records office. Both owner APIs recheck the signed-in owner and keep responses private/no-store.

Prepared documents retain their actual snapshot and artifact bytes. A later CEU correction or revocation leaves earlier prepared/issued documents intact; a fresh CEU preparation uses the current recorded award. The existing source/release verification determines when another review is required. Custom academic document hooks remain separate from the dedicated `ceu_transcript` hook; custom CEU formats must preserve the same scope and escaping rules.

## History and persistence

`EarnedCeusWereDecided` joins the existing academic-credit stream in the single protected journal. It retains the original/next award, the accepted offering reference, actual completion evidence and a command fingerprint, with trusted actor, reason, effective/recorded times, correlation, causation and expected-version/idempotency checks. No new event store or parallel academic authority is introduced.

The current CEU projection is a nullable bounded JSON field on the existing academic-credit stream routing row. Ordinary credit snapshots and academic quantity columns retain their contracts. Replay compares the projection to authenticated history, and award/projection/version/receipt/required audit writes commit together. Missing earlier CEUs remain unknown; the migration backfills no award or event. Existing grade, chronology, credit-unit, pursuit and transcript workflows continue to verify the same academic history.

Migrations **20261124000001_AddSisEarnedCeuProjection** and **20261124000002_AddSisEarnedCeuPermission** are additive and idempotent and refuse destructive downgrade. The former compiles through the supported schema abstraction; the latter registers a protected ULID duty and its reflexive implication without implicit grants. Tests cover five SQL dialect compilers, populated prior rows and the configured MariaDB development database; this does not certify all engines in production.
