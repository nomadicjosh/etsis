# Reviewed Moodle grade intake

Moodle supplies a captured course total for review. **The SIS remains authoritative for the academic grade.** A background intake never assigns, replaces or approves a final SIS grade automatically. It does not change Moodle grades, assignment scores or calculations.

## Enable the institutional workflow

Install or upgrade the [Moodle connector](../dist/moodle/local_etsis-0.7.0-alpha.zip) using its [installation guide](../integrations/moodle/local/etsis/README.md). Complete Moodle's upgrade and retain the existing installation identity, account receipts, course mappings and learning records. Do not uninstall to upgrade.

In Moodle, enable the connector and **Allow reviewed course-total export**. The restricted service must advertise `local_etsis_get_course_grade` and `core_webservice_get_site_info`. Use the updated section-synchronization service or the separate disabled-by-default **etSIS reviewed grade intake** service. Custom services need the new function. The Moodle service account needs `local/etsis:exportgrades` at system context and `moodle/grade:viewall` in each intended course; setup does not grant either capability automatically.

In the SIS deployment settings, enable the integration and select the explicit source policy:

```dotenv
SIS_MOODLE_ENABLED=true
SIS_MOODLE_GRADE_IMPORT_MODE=reviewed_final
SIS_MOODLE_SYNC_ACTOR=<existing-authorized-SIS-service-account-ULID>
```

The default mode is `disabled`. The service account and requesting/reviewing staff require all three SIS duties: `sis:moodle:manage`, `sis:gradebook:amend` and `sis:student:read`. Existing catalog-management authority controls the Moodle section-search/mapping screen. Normal grade-change approval and closed-record duties remain independent; Moodle-management permission alone cannot publish a grade.

Approve the institutional installation, publish/select the section offering, retain its explicit Moodle course link and approve each student's canonical person-to-Moodle-user link. There is no matching by email, name or course title.

Schedule the dedicated server worker through the existing scheduler:

```sh
ddev codex sis:moodle:grades --limit=10
```

Use the deployment's usual console entry point outside DDEV. Each invocation takes at most twenty steps and stops starting new steps after thirty seconds. One step processes one enrollment or finishes a batch; the network read has a shared ten-second deadline. A browser does not run the worker. Roster synchronization and grade intake have separate enablement and commands.

## Collect and review course totals

1. Open **Moodle integration → Course sections & access**, search for the section and choose **Review imported grades** on its saved course link.
2. Enter an intake reason and choose **Start background intake**. The request returns immediately. You may leave or close the browser.
3. Refresh intake status when useful. The server sends a private completion, failure or cancellation notification to the requester. A notification's review action opens this section's intake; deleting it permanently deletes only the notification row.
4. Open **View imported result**. Compare the student identity, captured course total, original minimum/maximum and observation time with the current SIS grade.
5. Select the published SIS grade explicitly. Where required, choose the grading pursuit. Enter the effective date and evaluation reason, then choose **Review SIS grade**.
6. Verify the proposed grade and choose **Confirm grade decision**. If institutional policy requires independent approval, this requests the existing grade-correction approval instead of replacing the current grade. Use **Review grade-correction request** to follow it.

The displayed total uses Moodle's original numeric scale; it is not assumed to be a percentage and does not automatically select an SIS grade. The existing grade writer retains the chosen published grading context, academic quantity, effective date, actor and reason. Earlier grades and imported source evidence remain in protected history. Corrections continue to honor independent approval, applicable pursuit/policy rules and existing academic notifications.

**Revise grade decision** preserves the selected grade, pursuit, date and reason. A stale review stays editable and cannot claim success. **Back to record** returns to the section; **Back to imported results** returns to the queue. A grade-change-request status records what this intake requested; its linked correction record shows any later approval, rejection or cancellation.

## Unavailable or changed evidence

Both staff and self-service active registrations are eligible. Audits, CEU-only work, withdrawals, canceled/removed enrollments and unverified academic history are excluded from grade publication. Missing approved person links or other unavailable SIS sources produce a local issue without requesting a Moodle grade. Corrupt protected history stops the batch for review.

The connector reads only one explicitly linked user's course total. Hidden totals, missing grades, recalculation-pending items and nonnumeric/custom-scale totals supply no numeric candidate. Suspended/deleted Moodle users and missing course membership require review. Retained inactive course membership can still support an authorized grade read after the student's access window closes; it does not restore Moodle login or course access.

Use **Review dismissal** with a reason when a result should not become a new SIS grade, including when the existing SIS grade is already correct. Dismissal retains its encrypted disposition and creates no academic event. Final cases show their retained reason and decision time. Recorded-grade and approval-request statuses verify the protected academic operation receipt; changing a queue status cannot create an academic decision. An unchanged accepted or dismissed source is not offered as a new grade decision. A changed Moodle observation creates another candidate and supersedes earlier pending evidence. If the SIS source changes while a candidate is pending, a fresh intake can refresh that working review against current verified records. It never rewrites an accepted academic fact.

Intake scans use a retained upper enrollment-reference boundary and recheck current eligibility for each step. Run a fresh intake after roster changes; the queue is not a historical roster reconstruction. Lists have ten-entry pages. Per-step leases and atomic source/checkpoint writes let another server invocation resume interrupted work. **Cancel remaining intake** stops future acceptance of in-flight results while preserving completed observations and any independently accepted grades. Failure or cancellation never withdraws an existing SIS grade.

## Protected source records and limits

Course-total observations are encrypted working records. On grade acceptance, the existing academic event retains minimized source evidence: approved installation and section/person links, original enrollment/student references, exact released numeric total and scale, source revision/hash and observation time. The academic grade remains its own decision. Pending reviews require current source checks and an actor-bound encrypted twenty-minute confirmation. Original accepted retries return their original result after expiry or later integration disablement.

The existing protected journal remains the only event store. `sis_moodle_grade_imports` holds server checkpoints, `sis_moodle_grade_candidates` holds encrypted working observations/dispositions and durable accepted-decision receipts, and `sis_moodle_grade_notices` holds deletable owner-only completion notices. Browser drafts stay in page memory. Private routes retain authorization, CSRF protection for mutations, no-store responses and debug suppression. Tokens, feedback, email, names from Moodle and complete gradebook responses never enter jobs or logs. Curated academic history omits upstream identifiers and fingerprints.

This milestone covers reviewed numeric course totals. Assessment-detail copying, automatic conversion policies, nonnumeric scale mapping and scheduled automatic intake requests are separate extensions. Native Moodle upgrade/capability testing must run in a dedicated Moodle PHPUnit database; institutional OIDC, outage/load and full native recovery acceptance remain separate roadmap gates. See the [Moodle Gradebook API](https://docs.moodle.org/dev/Gradebook_API) for developer context; this connector deliberately reads retained totals without invoking regrading helpers.
