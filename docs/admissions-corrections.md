# Admissions outcome corrections

Implemented 2026-11-09. Authorized staff can correct an accepted application to rejected, or a rejected application to accepted, **before matriculation**. The original decision stays in the existing protected admissions stream. This extends [submission history](application-submission-history.md), [reviewed decisions](admissions-decisions.md) and [initial matriculation](student-matriculation.md).

## Staff workflow

Open **Admissions → an accepted/rejected application → Correct admissions decision**. Enter a reason and choose **Review admissions correction**. The next screen identifies the person, institutional ID, program and term, shows the previous and proposed outcomes, and explains whether the earlier decision was recorded or is an older status with unknown history.

**Revise admissions correction** preserves the entered reason. Reviewing creates no event or status change. **Confirm admissions correction** records the actual correction, advances the application version once and updates its current outcome atomically with the event receipt and required audit.

A correction takes effect on confirmation. Staff cannot supply a backdated effective date or change the application person, program, entry term or submitted answers through this action. It does not reopen an application for editing. Those changes need their own institutional policies and attributed workflows.

A matriculated application cannot start or commit a new outcome correction here. Its student/program/academic/financial records require the appropriate student-record workflow. If matriculation wins a competing request, the pending correction is rejected. If a correction to rejected wins first, an earlier matriculation review cannot create a student.

Reviews expire after 20 minutes and must be repeated across an institutional date change. Changes to application version/history, person identity, retained-reference context or proposed labels require another review. An accepted → rejected → accepted cycle invalidates an earlier unconfirmed review even though the visible outcome returns to accepted.

## Setup and authority

Apply **`20261109000009_CreateSisAdmissionCorrectionPermission.php`** through the normal migration workflow. It registers the protected **`sis:admissions:correct`** permission and its self-implication only. It creates no tables and grants no role or user authority automatically. It uses the existing portable database abstraction; checks cover the framework's five schema compilers and isolated SQLite execution, not live-server certification.

A correction requires both `sis:admissions:manage` and `sis:admissions:correct`. Ordinary admissions management, person ownership, student-read and registrar permissions alone do not grant this action. The default `admin` role's broad `sis` grant does not include the separate correction permission. `super` retains its existing unrestricted authority.

An authorized access administrator can delegate the permission through a custom office role or an extra user grant. Built-in roles and the protected permission remain uneditable and undeletable. A conflicting pre-existing custom permission name makes migration fail rather than silently claiming that definition. The migration preserves the protected definition on rollback; revoke grants through access administration.

The dedicated permission represents authority to make these reviewed corrections. Institution-specific additional approval chains, backdated outcomes, reopening, rescission after matriculation and document/offer consequences remain later stages; this feature does not infer those policies.

## Retained history and retries

**AdmissionDecisionWasCorrected** retains the preceding recorded decision identity, previous and corrected outcomes, resulting application version and decision-time source references/labels. The trusted actor, reason, operation, correlation/causation and effective/recording times stay in protected context. The original submission and decision events are unchanged. The correction cannot replace the original submitted-form/answer reference.

For an older accepted/rejected application without recorded decisions, confirmation first records **AdmissionDecisionWasObserved**. It captures the current application and source context seen now, with earlier history explicitly unknown. It is not an invented original acceptance/rejection or original decision date. Reads and reviews never manufacture this observation. The subsequent correction is an actual, attributed decision on confirmation.

**Application history** lists the original outcomes, observations and corrections in recording order with bounded pages. Observations show when the status was seen; they do not display that time as the effective date of the unknown original decision. Each actual decision retains its effective and recording times. History pages omit reasons, actor identifiers, fingerprints, answers and raw event envelopes. Current application status is shown separately from historical entries. Cursor pages retain the committed stream head they were opened against; reopening the history includes newer decisions.

An exact retry returns the original accepted result without applying it again. For example, after acceptance is corrected to rejected and later corrected back to accepted, resending the earlier rejection correction leaves today's acceptance intact. After matriculation, resending an already completed acceptance/correction returns its earlier receipt while the application remains matriculated. Current permissions are still required. A fresh correction of a matriculated application remains blocked.

## Consistency and validation

Commands lock the person/application before relevant term/program context and recheck both permissions and actor identity after waiting. Authenticated encrypted review tokens bind purpose, actor, application, intended outcome and reviewed context. Browser mutations retain CSRF protection; responses remain private/no-store and debug collectors stay suppressed.

The status/version compare-and-swap, optional older-state observation, correction event, stream head, operation receipts and required audit commit together or all roll back. Current application review, decision/correction commands and matriculation verify tracked status/version against replay. A conflicting current row stops the request rather than silently changing history. Exact receipts are reconstructed at their original acceptance point, then current projections are verified before returning them.

All authoritative events continue using **`sis_history_events`**. No parallel event store, event conversion loop or historical backfill is added. Existing submission/observation/decision/matriculation schema contracts and recorded event bytes are preserved. Replay reads bounded pages into constant-size state; operational projector checkpoints and rebuild/swap tooling remain subsequent work.

Fictional checks cover both outcomes, older observations, immutable earlier events, replay, repeated corrections, competing matriculation, stale/expired reviews, permission rechecks, protected reasons, rollback, receipt safety and frozen history pages. Migration checks cover idempotent registration, custom-definition conflicts, protected definitions and explicit grants. Real-provider/router checks exercise private responses, CSRF, correction authority and paged reads. Browser checks cover both history boundaries, revision, confirmation, permission-based visibility, pagination, keyboard focus, accessibility and narrow screens. No working database was migrated or used as a fixture.
