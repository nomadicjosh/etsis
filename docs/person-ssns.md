# Social Security numbers on person records

Open **People**, open the person, then choose **More person actions → Social Security number**. The same canonical person can be a student, employee or faculty member; an SSN never creates another identity or replaces the institutional ID.

Saved numbers display only the last four digits, for example `***-**-1234`. There is no reveal button or endpoint returning the full saved number. Ordinary profile screens, student portal responses, profile history, reports and notifications do not include it.

## Enter or correct a number

1. Enter the full replacement in **Replacement SSN** and **Enter replacement SSN again**. Both fields hide typed digits. Nine digits or the `XXX-XX-XXXX` format are accepted; leading zeroes are preserved. This checks format only, not government issuance or ownership.
2. Enter a reason without including the number. Select **Review SSN correction**. Review displays masked before/after values and the reason; it does not save.
3. Select **Confirm SSN correction** to record the change. The saved view shows only its last four digits, and replacement fields return blank.

A correction can change earlier digits while leaving the same last four. Both masks will then look identical; confirmation still records the reviewed replacement. **Keep editing** returns to your private in-memory draft. Closing or leaving the record discards that draft; it is not saved in browser storage.

To remove an incorrect current number, enter a reason and select **Clear saved SSN**, then confirm the review. Clearing removes the current mask; earlier protected decisions remain retained. It is not historical erasure.

If another staff member changes the record, reload the saved mask and review your replacement again. Reload preserves your current draft. Reviews expire after twenty minutes. Retrying an already accepted confirmation returns its original result and cannot overwrite later corrections. Submitting another review and confirmation is a new decision, even for the same number.

## Staff access

Viewing requires both `sis:person:manage` and `sis:person:ssn:read`. Corrections additionally require `sis:person:ssn:manage`. **SSN correction history** additionally requires `sis:audit:read` and shows recording times, decision sequence, recorded/cleared action and reasons, without numbers. Viewing never implies correction authority. These duties are checked against current enabled-account access for every request and again before its transaction finishes. A person linked to a super administrator remains protected by the existing super-administrator boundary.

The installation defines the two SSN permissions without granting them to ordinary roles or accounts. Administrators assign them deliberately through existing access management. The existing super role retains its established access. Profile ownership alone does not authorize this staff task, and there is no student-portal SSN editor.

## Protected storage and limits

Real entry, replacement and clearing decisions use the existing protected `sis_history_events` journal in a private `person_ssn` stream keyed by the canonical person. There is no second event table or invented earlier history. The complete number and correction intent remain encrypted inside each authoritative payload. A private random nonce prevents the journal's visible operation fingerprint from becoming a simple SSN lookup hash. The current `sis_person_ssns` projection contains only an encrypted last-four mask, bound to the person and accepted version; it contains no full number or standalone SSN hash.

Expected-version checks, source-bound review proofs, actor-bound operation receipts, canonical locks and required metadata-only audits keep the event and projection atomic. Reads authenticate bounded history and compare the projection; they never silently repair it. The ordinary task supports up to 500 recorded decisions per person with additional byte/time limits; reaching the limit requires operator review rather than an unbounded request. Curated history pages contain twenty entries.

The feature does not add SSN imports, matching/search indexes, exports, reports, jobs, email delivery or general profile-event disclosure. The existing selected-person profile integrity mode does not inspect this separate private stream. Global journal verification authenticates its protected records. Institution-approved retention/disposition, encryption-key custody and operational rebuild procedures remain separate responsibilities; clearing a current value does not dispose of historical facts.
