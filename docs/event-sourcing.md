# Event sourcing direction

Security, student privacy and historical academic integrity are the highest development priorities. This document supersedes the earlier assumption that only finance should be event sourced. The [architecture and retrofit review](event-sourcing-architecture.md) makes the direction actionable and distinguishes current capabilities from required changes. The [legacy academic field review](academic-history-field-review.md) identifies missing data and its correct ownership.

The rationale below describes benefits, not a claim that the current application already has these protections. Event streams still require authorization, concurrency control, protected storage, explicit retention and tested replay. Accepted server-side ordering can be recorded; client click order and clock precision alone do not establish fairness. Retrospective projections can use only facts actually captured at the time.

A **Student Information System (SIS)** is actually filled with other high-stakes business processes that can significantly benefit from event sourcing. In an academic setting, *how* a student arrived at a specific status is often just as critical as their current status.

These core areas of an SIS heavily benefit from an event-sourced model:

1\. Academic Records &amp; Degree Audits

In a traditional CRUD database, if a registrar fixes a typo or updates a student's grade, the original data is often lost or buried in an unstructured database log.

- **The Benefit:** Graduating a student requires validating their exact academic timeline. Event sourcing records `CourseEnrolled`, `GradeAssigned`, `GradeAmended` (with a reason code), and `IncompleteStatusExtended`.
- **Why it matters:** If a student challenges their graduation eligibility or a transcript discrepancy arises years later, you can replay the stream to see exactly when a grade changed and who authorized it.

2\. Course Registration &amp; Enrollment Waitlists

Course registration is a high-concurrency, time-sensitive event where students battle for limited seats.

- **The Benefit:** Seat allocation still needs a concurrency boundary and atomic capacity validation. Within that boundary, record explicit events such as: `SeatRequested`, `WaitlistJoined`, `SeatAllocated`, or `RegistrationDropped`.
- **Why it matters:** You can use these events to build a high-performance Read Model (using CQRS) for the frontend. If a student complains that they clicked "Register" before a class filled up, the event log can establish the order in which the server durably accepted requests and made allocation decisions. It cannot prove the order of clicks on different client devices.

3\. Admissions &amp; Recruitment Funnels

The journey from a prospective applicant to a fully matriculated student involves a complex, multi-stage pipeline.

- **The Benefit:** Events like `ApplicationSubmitted`, `DocumentUploaded`, `InterviewScheduled`, `ScholarshipOffered`, and `AdmissionAccepted` preserve the full velocity of the funnel.
- **Why it matters:** Business requirements change constantly. Next year, the dean might ask: *"What is the average number of days between a student receiving a scholarship offer and accepting admission?"* With CRUD, if you didn't think to track that specific timestamp beforehand, you can't answer it. With event sourcing, a new projection can replay the historical events if those events actually recorded the necessary offers, decisions and timestamps. Missing historical facts cannot be reconstructed from current state alone.

4\. Student Status &amp; Enrollment Lifecycle

Tracking whether a student is Active, Part-Time, Suspended, on Medical Leave, or Graduated has massive funding and legal compliance implications.

- **The Benefit:** Capturing events like `LeaveOfAbsenceApproved`, `SuspensionLifted`, or `CreditLoadDroppedBelowFullTime`.
- **Why it matters:** Government reporting (like visa compliance for international students or federal financial aid eligibility) requires knowing the *exact day* a student stopped being full-time. Events must explicitly distinguish when a change became effective from when it was recorded, retain the applicable policy version, and support reproducible as-of queries.

⚠️ A Critical Warning for your Architecture

While these areas benefit from event sourcing, **do not event-source the entire SIS**.

Event sourcing introduces a lot of cognitive overhead, eventual consistency complexities, and boilerplate code. Treat your SIS as a collection of **Bounded Contexts** (Domain-Driven Design):

| Bounded Context                                           | Best Persistence Pattern | Reason                                                                                                      |
|-----------------------------------------------------------|--------------------------|-------------------------------------------------------------------------------------------------------------|
| **Student Finance / Ledger**                              | **Event Sourcing**       | Non-negotiable audit trail, balance sheet recreation.                                                       |
| **Course Registration**                                   | **Event Sourcing**       | High concurrency, strict ordering, timeline verification.                                                   |
| **Academic Records**                                      | **Event Sourcing**       | Historical academic evidence, transcript history and attributable corrections.                                              |
| **Person profiles: students, parents, staff, faculty and other people** | **Event Sourcing** | Preserve attributed identity, contact, address, emergency-contact and privacy changes under the canonical person identity, with a mandatory linked sign-in account for every new person. Protect sensitive values and apply institutional retention controls. |
| **Campus Facilities**               | **Standard CRUD**        | Low complexity, standard data storage needs.                                                                |

Use event sourcing where the institution needs the original facts and an attributable sequence of changes: finance, registration, academic records, consequential lifecycle/admissions decisions, published academic definitions and person profiles. Reference setup, physical facilities, working drafts, report definitions/sharing and ordinary settings remain CRUD where appropriate, with authorization, concurrency checks, auditing and retention. A reporting projection derived from an existing event is a read model, not another event-sourced workflow.

The profile boundary was clarified by the user on 2026-11-14. Student, parent, staff and faculty roles share the canonical person identity; do not duplicate the same personal facts into independent role-specific profile histories. Role-specific academic or employment decisions can retain their own domain boundaries. Existing recorded User events must be preserved, while missing earlier person history stays explicitly unknown. The [person/account foundation](person-identity-and-accounts.md) now coordinates creation, names, directory privacy and institutional IDs with the existing User credential stream. Existing preferred-name, phone and mailing-address changes now share that person stream. Ordered emergency contacts now share that stream and one owner/staff editor. Dated purpose-specific addresses now share the same person stream, with explicit mailing-source selection and retained earlier text/history. Employment title/active-status changes now retain their own HR decisions in the same journal, referencing the canonical person without copying shared personal details. Further role-specific decisions and operational rebuild/retention controls still need their remaining history work.
