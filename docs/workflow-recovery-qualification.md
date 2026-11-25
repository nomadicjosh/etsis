# Workflow recovery qualification

Implemented November 24, 2026. The focused recovery suite exercises real registration, publication, academic, waitlist, billing, drop and recorded-refund services across separate PHP processes. It terminates a worker with a POSIX kill signal before commit or after commit but before its reply. The default tests use disposable fictional SQLite databases. The original ten recovery/contention cases passed on isolated MariaDB; the [native PostgreSQL runner](native-workflow-qualification.md) now qualifies thirty selected cases, including admissions, matriculation, academic approvals, transfer recovery and coordinated FIFO waitlists. It copies installed schema definitions and seeds only fictional rows. Neither mode uses configured application records as fixtures.

The separate [independent journal checkpoint workflow](history-checkpoint-recovery.md) now detects an older valid restored journal inventory and qualifies mixed event-key recovery. It supplements these process-loss checks; full database, projection and encrypted-artifact backup/restore acceptance remains open.

## Qualified scenarios

| Scenario | Required result |
| --- | --- |
| A final-seat registration is terminated after its batch receipt and financial/academic writes are staged. | Another connection sees none of those uncommitted changes. A competing student can take the seat after termination; retrying the original pending review cannot displace that student. |
| A full 25-student batch is terminated at its final audit boundary. | The entire batch rolls back, including receipts, retained course/offering references, academic work, accounts and charges. Retrying its original confirmation accepts the whole batch once. |
| A registration commits but its reply is lost. | Its original confirmation returns the accepted receipt. After an early drop or a new registration, that old confirmation cannot restore or replace the later enrollment. |
| A waitlisted registration is terminated after completion is staged. | Priority, the seat, academic work, charges and the personal notice all roll back together. A successful retry creates one notice. Owner deletion physically removes it, and another accepted retry does not recreate it. |
| A five-student FIFO waitlist group is terminated after all completion writes are staged, or loses its committed reply. | The pending group rolls back completely. Original confirmation accepts once in queue order, with retained course/offering references, credits, charges and notices. After a later drop, owner notice deletion, replacement registration and expiry, the original receipt changes none of those later facts. |
| Duplicate three-student confirmations or overlapping two/three-student prefixes contend. | Duplicate confirmations return one identical accepted receipt. An accepted smaller group makes the old larger proof stale; an interrupted smaller group lets the larger group proceed. The PostgreSQL competitor reaches a real SQL lock wait before the first transaction ends. |
| An individual later request or reviewed priority exception competes with a two-student prefix. | Ordinary registration cannot skip the earlier requests after group rollback. A reviewed bypass retains its own accepted queue boundary and evidence; group completion makes that earlier bypass proof stale. Real PostgreSQL lock waits establish the overlap. |
| An early drop is terminated while another registration is attempted. | The original seat and charge remain. After a successful reviewed drop, the charge reverses and the seat becomes available. An old drop retry cannot remove the next student's enrollment. |
| A linked refund is terminated before commit or its committed reply is lost. | The original payment's refundable allowance remains correct. Repeating the accepted confirmation creates no second refund and preserves later postings. |
| Competing full-refund confirmations overlap and the first process is terminated. | One accepted refund consumes the source payment once; the interrupted competing proof cannot spend it again. |

Published-course and selected-offering cases use genuine protected publications and their normal selection services. The comparisons include protected journal, stream/operation receipts, enrollment/credit/pursuit references, student/activity projections, account-entry/balance projections, waitlist notices and audit metadata. Private fixture comparisons use counts and fingerprints. These checks supplement the existing ordinary final-seat, authorization, stale-review, pricing and transaction-rollback tests.

## Admissions, academic approvals and transfer recovery

The connected [native workflow guide](native-workflow-qualification.md#admissions-and-academic-recovery) describes nine additional cases in `AcademicAdmissionsRecoveryTest`. Direct application program corrections, acceptance and matriculation each roll back fully after a staged process is terminated, preserve the canonical person/original submission and retain their exact accepted receipts after later decisions. Duplicate concurrent matriculation returns one student/pursuit.

Initial final grades remain unchanged while an independent correction request is pending. Approval of a grade or combined academic-unit correction survives process loss without partial request/credit/history updates. Competing officers cannot apply one correction twice; termination of the first allows the second to proceed. Grade/unit corrections preserve original registered quantities, accepted offering references and financial facts. Transfer amendments preserve their accepted course/grade/unit facts; an original retry after a subsequent void cannot restore the earlier award.

These cases use the existing worker/private pipes, ordinary services and fingerprint comparisons. Expected stale-version conflicts have a fixed rejected protocol result; corrupt/storage/unexpected failures still fail the worker. This does not change application behavior, issue a credential, repair projections, send messages or perform external cash movement.

## Registration, payment, early drop and refund

The fictional USD scenario makes account credit and a recorded refund separate steps:

| Accepted action | Registered seats | Account balance |
| --- | ---: | ---: |
| Register with a $12.50 charge | 1 | $12.50 due |
| Record the $12.50 payment | 1 | $0.00 |
| Confirm an early drop and original-charge reversal | 0 | $12.50 credit |
| Record the $12.50 refund against that payment | 0 | $0.00 |
| Post a later $1.00 charge | 0 | $1.00 due |
| Repeat the original refund confirmation | 0 | $1.00 due |

The early drop removes current enrollment/academic projections while retaining the protected registration, academic, drop and financial facts. The refund retains its source payment and allowance. This suite exercises SIS recording; provider checkout, settlement and actual external money movement retain their separate acceptance work.

## Run the bounded checks

Use the project's configured PHP interpreter with PDO SQLite. The process-loss cases require POSIX process signals; they are explicitly skipped on Windows. For this development installation, PhpStorm currently configures `/usr/local/bin/php84`. Set the path appropriate to the installation before running:

```sh
ETSIS_TEST_PHP=/absolute/path/to/configured/php
XDEBUG_MODE=off timeout 35s nice -n 19 "$ETSIS_TEST_PHP" -d memory_limit=128M \
    vendor/bin/phpunit tests/Integration/WorkflowRecoveryTest.php
```

The recovery cases use at most two active child workers, each capped at 96 MiB and ten seconds. Protocol waits stop after four seconds and SQLite busy waits after three. The parent uses a private directory with mode `0700` and creates the database with a restrictive umask. Workers use a minimal private bootstrap and plain fallback translator, without loading institutional services or requiring an inherited application path. Fictional protection keys and review proofs pass through private stdin, never command arguments, jobs or logs. Workers emit fixed protocol phases and minimized fictional results; provider exceptions remain out of their output. Teardown stops remaining children and removes database/journal/WAL artifacts and the private bootstrap/configuration. No scheduled worker, application command, report, schema change or external service is added.

Run `tests/Integration/AcademicAdmissionsRecoveryTest.php` with the same interpreter and bounds for the nine admissions/academic scenarios. `tests/Integration/CoordinatedWaitlistRecoveryTest.php` adds ten coordinated waitlist cases. The native configuration also includes the three registration/stream-position contention cases. Run native batches separately as described in the native guide; no full application suite is required.

The existing 25-student registration check exercises the current synchronous selection limit; the coordinated FIFO waitlist recovery cases use two, three and five students. A separate SQLite service test covers a 25-student FIFO group. Larger populations, long histories, other native engines/versions, host/power failure, backup restoration and institution-led acceptance remain open. [Fresh-install qualification](fresh-install-qualification.md) and the selected MariaDB/PostgreSQL results have their own declared scopes. The shared native runner and exact remaining limits are described in the [native qualification guide](native-workflow-qualification.md). Process termination is not a substitute for those checks.

## Recover an interrupted confirmation

When the original confirmation is still available, repeat that same operation and proof. An accepted retry returns its original receipt; a pending command whose inputs have changed needs a new review. After leaving or reloading the screen, reopen the current registration/account record and inspect its accepted outcome before starting another action. Current enrollment and balance are separate from the historical receipt returned for an older operation.

Registration and drop recovery must preserve the original enrollment/charge lineage. Refund recovery must preserve the payment allocation and current refundable allowance. The workflow checks do not rebuild projections, clear receipts, change protected events or resend external effects.
