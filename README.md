# etSIS

## Version 10 - A New Beginning

## Screenshots

Screenshots use a test campus and demonstration data. No real student or staff records are shown.

### Campus portal

The public portal welcomes prospects and connects students with applications, courses, campus updates, and student services.

![Campus portal with academic artwork, application links, and student service navigation](docs/screenshots/portal.png)

### Backend dashboard

The staff workspace brings campus metrics, student intake, admissions, and review queues into one dashboard.

![Backend dashboard showing fictional student metrics, an intake chart, admissions counts, and staff review queues](docs/screenshots/admin-dashboard.png)

## Setup documentation

[Term types, academic years and terms](docs/academic-calendar-setup.md) explains calendar setup and automatically derived matriculation cohorts. [Initial student matriculation](docs/student-matriculation.md) explains the admissions review, first-program history and retry behavior.

## Development priorities

Security, student privacy and historical academic integrity lead continued development. Read the [event-sourcing direction](docs/event-sourcing.md), [architecture and retrofit review](docs/event-sourcing-architecture.md), and [legacy academic field review](docs/academic-history-field-review.md) before changing academic or financial workflows. These reviews distinguish existing capabilities from required implementation work.

[Institutional credit history](docs/academic-credit-history.md) now connects registration, reviewed final grades, reasoned registrar corrections and early/late drops in the shared journal. Credits retain known grading-policy references; older facts are explicitly observed without invented prior authors or dates. [Transfer award history](docs/transfer-credit-history.md) now preserves reviewed source facts, effective-dated corrections and void/reinstatement grading policy in that same journal.

[Registration history](docs/registration-history.md) now preserves accepted seats, early/late releases and committed section decision order, with registrar-only access to restricted early-drop history.

The [single protected event store](docs/event-history-foundation.md) serves the framework and domain ports. Its migration converts and verifies existing User/financial events before removing the old event table. Academic writers and richer command attribution still require the documented staged conversion.

The first [published course-definition workflow](docs/course-publication.md) preserves reviewed course codes, catalog/transcript titles, descriptions, fixed credits and prerequisite identities. Empty sections can explicitly retain a selected publication; offering-plan publication now reviews and retains those choices with activation in one transaction. New institutional credits preserve the selected reference. Complete section/policy definitions and event-sourced academic attempts remain in progress.

The [course group library and degree audit builder](docs/degree-audit-builder.md) provide independent reusable course groups and visual program/year compositions, with checkboxes, drag-and-drop and expandable requirement previews. Degrees retain exact published group versions and institutional policy inputs. A [reviewed student-program assignment](docs/student-degree-requirements.md) retains a specific degree version and actual pursuit start date. Protected [student exceptions](docs/student-degree-exceptions.md) now support course waivers, reviewed substitutions and credit/GPA minimum reductions within one program pursuit. Course groups support credit or course-count totals and optional per-course grade-point minimums. [Global/program grading scales](docs/grading-scale-setup.md) retain score ranges, GPA treatment and approved publication snapshots. The scale editor supplies immutable grade definitions to final-grade, transfer and late-drop workflows; existing records retain their exact grade IDs. Student evaluation and specialization exclusions remain subsequent stages.
