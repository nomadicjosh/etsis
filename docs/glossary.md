# etSIS system glossary

This is a plain-language reference for terms used in etSIS screens, documentation and the development roadmap. Each definition explains what the term means in this project. The linked workflow guides and [roadmap](development-roadmap.md) describe the current implementation and remaining work.

For the technical language in the roadmap, start with [journal](#journal-event-journal), [projection](#projection-read-model) and [ULID](#ulid).

Find terms by topic:

- [People, accounts and access](#people-accounts-and-access)
- [Academic setup and curriculum](#academic-setup-and-curriculum)
- [Registration and academic work](#registration-and-academic-work)
- [History and reviewed decisions](#history-and-reviewed-decisions)
- [Student finance](#student-finance)
- [Reports and selected populations](#reports-and-selected-populations)
- [Server processing and technical terms](#server-processing-and-technical-terms)
- [Websites and integrations](#websites-and-integrations)

## People, accounts and access

### Person / canonical person

The main identity record for a human being. *Canonical* means this is the identity other records refer to. One person can be a student, employee and faculty member at the same time; adding a capability keeps the same person and their existing history. See [person identity and accounts](person-identity-and-accounts.md).

### Sign-in account

The account a person uses to authenticate and access etSIS. It is linked to the person. It is separate from a student's financial account. A staff-created account awaits private initial password setup.

### Institutional ID

The institution's identifier for a person, such as the student or employee number staff recognize. It is separate from the internal database identifier. A change of role does not require a second person identity.

### Role, permission and scope

A **role** groups permissions, such as an institution-defined Registrar role. A **permission** allows a particular action, such as reading student records. **Scope** determines which records that action covers, such as a student's own record or a faculty member's assigned section. Employment, department membership or a screen's visibility alone does not establish every permission. See [authorization](authorization.md).

### Authentication / authorization

**Authentication** establishes who signed in. **Authorization** checks whether that person can perform this action on this record. Being signed in does not by itself authorize access to another person's information.

### Owner

In an owner-only workflow, the person whose record or private artifact is being accessed. For example, a student can read their own permitted portal records. A separate staff permission is needed to read another student's record.

### Delegation

Assigning work or responsibility to another authorized colleague. A case assignment does not grant the colleague the permissions needed to read or decide that case. See [office queues](transcript-worklists.md).

### Super administrator

An enabled account holding the protected `super` role. This is distinct from an ordinary administrator role. Maintenance-mode sign-in checks require this protected role. See [maintenance mode](maintenance-mode.md).

## Academic setup and curriculum

### Academic program / program code

A defined path of study offered by the institution. The **program code** is its institution-chosen label, such as `ARTS-UG` or `BA.ENG`. etSIS does not derive the award, academic level or department from that spelling; those are explicit settings. See [program publication](program-publication.md).

### Pursuit / student-program assignment

One student's particular instance of pursuing a program. The program describes the offering; the pursuit records this student's participation, start, status and retained curriculum context. Historical pursuits remain separate when the student changes programs. See [student-program assignments](student-program-assignments.md).

### Primary program / primary pursuit

The recorded program pursuit used as the student's primary context. Other pursuits and their histories remain separate. A primary designation does not itself prove that the pursuit is active, registration-eligible or graduated. See [primary-program history](student-primary-program-history.md).

### Award / degree

The qualification a program offers, such as a Bachelor of Arts or certificate. Configuring an offered award does not confer that qualification on a student. Actual graduation or credential-award decisions are separate from program setup and degree progress.

### Curriculum component

A selectable part of a program's curriculum, such as a major, minor, concentration, track or specialization. The interface groups these under **Majors, minors & specializations**. Published selection rules determine which combinations are required or allowed.

### Major / minor / specialization

A **major** identifies the main field of study; a **minor** identifies an additional curriculum area. A **specialization**, concentration or track identifies a configured focus. etSIS retains the chosen component types and requirements. A linked focus can require a particular major. For a broad `ARTS-UG` program, English and History can be separate major choices; the program need not be duplicated for each major. See the [worked broad-program example](program-publication.md#example-a-broad-ba-program-with-one-major-and-an-optional-minor).

### Institutional ceiling / program selection limits

The **institutional ceiling** sets the overall boundary for curriculum-component selections. A program sets its own permitted counts within that boundary, including type-specific counts where configured. For one required major and an optional minor, the published rules must allow the two components together. See [component selection rules](program-publication.md#majors-minors-and-curriculum-component-types).

### Department / school or college

A **department** is an organizational unit, such as English. A **school or college** can group departments, such as a College of Arts & Sciences. These describe responsibility; they are separate from academic level and award. A department can serve several academic levels, and a broad program can leave its own department blank. See [curriculum ownership](program-publication.md#departments-and-curriculum-ownership).

### Academic level / course level

**Academic level** classifies study, such as undergraduate or graduate. **Course level** is a separate institution-defined course classification, such as introductory (100 level) or advanced (600 level). Program and course codes do not automatically establish these classifications. See [course classifications](course-classifications.md).

### Course / course code / course number

A **course** is a catalog definition used to create offerings. Its **course code** identifies it in the institution's catalog, for example `HIST 101`. Its optional **course number** can be `101`; that number alone does not distinguish History from `MUSIC 101`. The subject and full course code provide the surrounding identity. See [course publication](course-publication.md) and [subjects](course-subjects.md).

### Course section / offering

A particular offering of a course that students register into, with its own term, dates, teaching arrangements, capacity and policies. One course can have several sections in the same or different terms. See [section publication](section-publication.md).

### Meeting plan

The published schedule of instructional meetings for a section. Working bookings and their approval are separate steps; changing a booking does not silently replace a retained published meeting plan. See the [meeting-plan guide](workspace-guides.md#meeting-plan-publication).

### Academic year / term / term type

An **academic year** organizes the institution's academic calendar and curriculum publications. A **term** is a configured teaching or enrollment period. A **term type** classifies it, such as semester or quarter. The institution defines the actual dates. See [calendar setup](academic-calendar-setup.md).

### Cohort / catalog year

In the student-program workflow, the retained academic-year program publication selected for that pursuit. The normal selection comes from the entry term; an authorized reviewed cohort change can select another publication. Later catalog edits do not automatically move existing students. Degree and component requirements are selected through their own workflows. See [cohort changes](student-program-assignments.md#review-a-cohort-change).

### Publication / retained definition

A deliberately accepted version of a course, program, policy or other meaningful definition. A **retained definition** is the exact publication kept with a student's decision or assignment. Updating today's draft does not rewrite that earlier context. See [program publications](program-publication.md).

### Degree requirements / course group

**Degree requirements** describe the published credits, GPA, courses, groups and policies used to evaluate a pursuit. A **course group** is a reusable set of course requirements, such as a set of electives. A program's descriptive publication and its degree requirements are separate publications. See the [degree-audit builder](degree-audit-builder.md).

### Contact hours / clock hours

**Contact hours** measure instructional contact under an institutional policy. etSIS's fallback uses 50 instructional minutes per contact hour, expressed per typical teaching week; the institution can publish another rule. **Clock hours** describe elapsed time, with 60 minutes in an hour. Neither is automatically an academic-credit or payroll award. See [teaching contact hours](human-resources.md#institutional-time-review-and-teaching-contact-policy).

### CEU — continuing education unit

A separate unit used for continuing education and professional development. Offered CEUs and a student's earned CEUs are separate recorded quantities. They are not academic college credits, are not used as academic transfer credits, and appear through the dedicated CEU record/transcript workflow. See [earned CEUs](earned-ceu-records.md).

## Registration and academic work

### Registration / enrollment

An accepted placement of a student into a course section. Registration checks, seat allocation, academic-attempt creation and applicable charges are coordinated by the registration workflow. A preview or waitlist request is not an accepted enrollment. See [registration history](registration-history.md).

### Academic-credit record / attempt

The student's record of a particular institutional or transfer course attempt and its accepted academic outcome. A record can exist while a final grade is pending or with zero earned credits. It is distinct from the course's catalog definition and from a financial posting. See [academic work](student-academic-work.md).

### Registered / attempted / earned credits

**Registered credits** describe the accepted enrollment quantity, including reviewed corrections. **Attempted credits** describe the academic quantity attached to the attempt. **Earned credits** describe the accepted award. These quantities have separate purposes and need not always match. See [credit policies](course-credit-policies.md) and [registered-credit corrections](registered-credit-corrections.md).

### Institutional credit / transfer credit

**Institutional credit** is recorded for the institution's own academic work. **Transfer credit** is an accepted award through the formal transfer-evaluation workflow. Recording an external course or creating an equivalency alone does not award credit to a student. See [transfer-credit history](transfer-credit-history.md).

### External course / transfer equivalency

An **external course** describes a sending institution's course. A **transfer equivalency** records its approved relationship to a receiving institution's course and applicable award rules. The individual student's transfer award is a separate decision. See [transfer publications](transfer-course-publications.md).

### Earlier-work reuse

Explicit approval to make eligible earlier work available to another pursuit under the supported institutional rules. It is separate from formal transfer evaluation. The project's required policy for work from a previously graduated program is formal transfer evaluation into the receiving pursuit; a general reuse approval is insufficient. Active or closed pursuit status alone does not prove graduation. See [completed-degree work](student-academic-work.md#work-from-a-previously-completed-degree).

### Prerequisite / co-requisite

A **prerequisite** is a requirement that must be satisfied for registration, such as eligible earlier coursework with the required grade. A **co-requisite** requires a companion enrollment under its configured rules. Their source, grade, date and pursuit conditions remain part of the registration check. See [course publication](course-publication.md) and [registration](registration-history.md).

### Waitlist / FIFO

A **waitlist** records requests for a section without enrolling or charging the student. **FIFO** means first in, first out: the supported queue uses accepted server-side request order and current eligibility. Browser click times do not establish accepted queue order. Each section must allow waitlisting. See [section waitlists](registration-history.md#per-section-waitlists).

### Drop / withdrawal / reinstatement

An **early drop** can remove current enrollment and academic rows and reverse the applicable charge while preserving restricted history. A **late withdrawal** retains the attempt with its accepted withdrawal outcome. **Reinstatement** is a separate reviewed decision to restore an eligible tracked withdrawal; it does not automatically resolve money or reopen every closed attempt. See [registration history](registration-history.md).

### Hiatus / institutional withdrawal

A student lifecycle case describing a break from study or institutional withdrawal. It is separate from a drop in one course section. The guided plan coordinates explicitly selected course outcomes and preserves the case's reasoned decisions. See [student activity history](student-activity-history.md).

### Completion date / posting term / recorded time

The **completion date** states when the work was actually completed, when known. The **posting term** states where it belongs in the local academic record. **Recorded time** states when etSIS accepted the decision. These are separate facts; a later correction does not mean the course was completed later. See [academic dates](academic-completion-dates.md).

### Gradebook / final grade

The **gradebook** contains working assignments, marks and calculation settings for a section. A **final grade** is an authorized academic decision, with its accepted grading evidence. A gradebook suggestion does not automatically become the student's authoritative final grade. See [academic-credit history](academic-credit-history.md).

### GPA — grade point average

A weighted academic result calculated from eligible credits and grade points under the retained grading, repeat and transfer policies. In the combined degree-progress review, institutional-only **cumulative GPA** is distinct from policy-controlled **overall GPA**, which can include eligible transfer work. Read the scope shown by the particular review. See [credits and GPA](student-credit-gpa-review.md#general-degree-minimums-and-distinct-gpa-scopes).

### Student load / academic standing / hold

**Load** classifies a student's study quantity under a policy, such as full-time. **Standing** records an academic status, such as probation. A **hold** restricts a particular action under its applicable dates and rules. They are separate decisions; one label does not automatically establish every restriction or financial-aid outcome. See [load](enrollment-load.md) and [standing](academic-standing.md).

### Degree audit / degree progress / graduation

A **degree audit** evaluates academic work against requirements. **Degree progress** presents the supported results, shortages and unresolved evidence for a pursuit. **Graduation** or credential conferral is a separate authorized award decision. A favorable review, a closed pursuit or a program's offered degree does not by itself establish graduation. See [degree progress](student-degree-progress.md) and its remaining [roadmap work](development-roadmap.md#3-student-degree-audit-evaluation).

### Exception / waiver / substitution

An **exception** is an explicitly approved adjustment within a pursuit and its retained requirements. A **waiver** removes a specified course requirement; it does not grant earned credit or automatically reduce every minimum. A **substitution** approves a particular replacement. See [student degree exceptions](student-degree-exceptions.md).

### Transcript / prepared original

A **transcript** presents the student's academic record using an approved layout and scope. A **prepared original** preserves the values and layout captured for that document. Later corrections can affect a newly prepared transcript while leaving an earlier issued original intact. Ordinary academic and CEU transcripts have separate scopes. See [transcript worklists](transcript-worklists.md) and [CEU transcripts](earned-ceu-records.md#separate-academic-and-ceu-transcripts).

## History and reviewed decisions

### Event / business event

A recorded fact that a consequential business action was accepted, such as a grade correction or payment posting. Opening a drawer, receiving a notification or clicking a button is not automatically a business event. Events describe accepted facts, rather than proposed actions.

### Journal (event journal)

The protected record of authoritative business events. etSIS uses one shared domain event journal, with related events organized into streams. For example, correcting a grade records a new accepted fact while preserving the earlier grade decision. This journal is distinct from a [financial journal](#financial-journal-general-ledger).

### Stream / stream position / stream head

A **stream** is the ordered history for one business record, such as one academic-credit attempt. Its **position** is the sequence number of an event within that history. Its **head** identifies the latest accepted position and associated integrity information. A position is not a student number, academic rank or browser click time.

### Event sourcing

Keeping meaningful accepted events as the authority from which the relevant state can be *reconstructed*. etSIS applies this selectively to consequential academic, person, lifecycle and financial workflows. Reference lists and working drafts can use ordinary editable storage. See [event-sourcing direction](event-sourcing.md).

### Projection (read model)

A prepared representation of data used to answer reads efficiently. For example, the academic-date projection supplies the currently accepted completion date to reports without replaying the journal each time. An event-derived projection can be reconstructed from verified recorded history; it does not replace that history. See [institutional academic-date reporting](institutional-academic-date-reporting.md).

For example, a recorded C grade is corrected to B. The journal retains both decisions; the current projection shows B. An earlier prepared transcript keeps its captured C; a newly prepared transcript uses B.

### Snapshot / retained context

A captured set of values kept for a particular decision, document or report. A student's retained course title, grading policy or curriculum publication explains the rules accepted at that time. A snapshot cannot supply earlier facts that were never recorded.

### Audit trail / access audit

A record of an action taken by a user or process, including some private reads. It serves a different purpose from the academic or financial event itself. For example, `waitlist.queue_read` records access to the queue; that entry alone does not mean someone joined, moved in or left the waitlist.

### Actor / reason / effective date / recorded time

The **actor** is the person or authorized process responsible for a decision. The **reason** explains why. The **effective date** is when the decision applies in the business record. **Recorded time** is when the system accepted it. A decision entered today can have a separately authorized earlier effective date; the recording time remains today's actual acceptance time.

### Review / confirmation / stale review

A **review** captures a proposed change and its relevant records, policies and authority. **Confirmation** asks the server to accept that reviewed change. A review becomes **stale** when something it depends on changes, or its permitted review period expires. Refreshing prepares a new proposal; it does not silently accept the old one.

### Expected version / concurrency

The **expected version** states which revision the caller reviewed. **Concurrency** means more than one request can act at the same time. The system rechecks the relevant versions and rules to prevent one request from silently overwriting another or allocating the last seat twice. Editor revisions, publication revisions and event positions are context-specific numbers.

### Operation ID / idempotency / operation receipt

An **operation ID** identifies a particular request. **Idempotency** means retrying that same accepted request returns its original outcome rather than performing it twice. An **operation receipt** retains that outcome for recovery. A retry does not restore old state after a later correction, and a receipt is not by itself proof that an external payment settled.

### Correlation reference

A reference connecting related actions across a wider workflow. For example, it can connect the separate registration and financial consequences of one decision. It helps follow the relationship without treating every related event as the same stream or request.

### Atomic transaction / rollback

An **atomic transaction** accepts its coupled database changes together. A **rollback** cancels those uncommitted changes when a required step fails. For example, a failed registration confirmation must not leave an accepted seat with a missing required academic or financial write.

### Replay / rebuild

**Replay** reads recorded events in their accepted order to reconstruct the supported state. A **rebuild** produces a replacement projection from that history. Replay must not send messages again, repeat payments, grant access or invent missing events. See the [history architecture](event-sourcing-architecture.md).

### Reconciliation / checkpoint

**Reconciliation** compares recorded evidence with the relevant current records or projection. A **checkpoint** retains information about the history processed so far, such as its position and integrity reference. Checkpoints and projections still require verification; they do not replace the journal or its backups. Follow the particular guide for whether a command only inspects or can deliberately repair a projection.

### Unknown / NULL / zero

**Unknown**, often stored as `NULL`, means the fact is not available or was not recorded. **Zero** is an actual recorded quantity of none. An unknown completion date or credit quantity must not be treated as a known date or zero just to finish a calculation.

### Immutable / correction

**Immutable** means an accepted historical fact is preserved. A **correction** records a new attributed decision that changes the accepted current result while retaining the original. It does not mean that staff can never correct a mistake.

## Student finance

### Student account / balance

A **student account** holds recorded financial postings for the student in its applicable context and currency. Its **balance** reflects those postings. A sign-in account and a student's earned academic credits are different records. See [Finance workflows](workflow-review.md).

### Charge / payment

A **charge** records an amount assessed to the student. A **payment** records money received or an accepted provider outcome under the payment workflow. A proposed registration charge is not yet a payment request or evidence that money was received.

### Account credit / withdrawal credit

An **account credit** reduces a charge under the accepted financial rules. A **withdrawal credit** is the reduction calculated from an institution's applicable published withdrawal schedule. Neither awards academic credit nor automatically returns cash. See [withdrawal credit schedules](withdrawal-credit-schedules.md).

### Refund / reversal

A **refund** records an authorized return of money through the supported payment workflow. A **reversal** offsets an earlier posting while preserving its history. These are separate from deleting a notification, dropping a course or awarding an account credit. See [payment refunds](workflow-review.md#refund-a-payment).

### Billing rule / retained charge source

A **billing rule** defines when and how an assessment is proposed. A **retained charge source** records the pricing and classification accepted for an actual posting. Later changes to today's prices do not silently reprice that original charge. See [account charge reports](account-charge-reports.md).

### Financial journal (general ledger)

A **financial journal** records accounting entries, commonly with debit and credit lines. The **general ledger**, or **GL**, organizes accounting postings by account. This is a different use of *journal* from the shared business-event journal. Automatic student-account-to-GL mappings and period-closing work have their own [roadmap status](development-roadmap.md#6-finance-and-institutional-integrations).

## Reports and selected populations

### Dataset / source / field

A **dataset** is an authorized starting set of report records, such as academic credits. A **source** supplies the relevant facts; a **field** is an available value, such as completion date. Hidden filters and related sources retain their own access requirements. See [Report Studio](workflow-review.md).

### Join / named copy

A **join** connects related records, such as students and their registrations. A **named copy** gives a supported relationship another independent branch, for example one branch for Fall registrations and another for Spring. Adding a join does not grant access to its data.

### Grain / fan-out / distinct count

**Grain** means what one record represents: one student, one registration or one payment. **Fan-out** occurs when a join produces several rows for the same original record. A **distinct count** counts unique chosen references rather than every joined row. Three registrations for one student are three registration records and one distinct student.

### Aggregate / summary

In a report, an **aggregate** combines values, such as a count, sum, average, minimum or maximum. A **summary** groups those results by selected fields. A maximum date by itself does not select all the other values from that same record; whole-record latest/earliest selection is a separate operation.

### Dynamic report / static result

A **dynamic report** runs its saved definition against current supported sources. A **static result** preserves the values captured when it was prepared. Later corrections can change a fresh dynamic result without rewriting the static one. Current authorization and retention controls still apply to saved results.

### Recorded cutoff / as-of report

A report using supported facts recorded on or before a selected point in time. It can only use history actually captured. Some labels can remain explicitly current, and a recorded cutoff is distinct from a business effective date. See [historical financial reporting](workflow-review.md#historical-student-financial-reports).

### Population / reviewed handoff

A **population** is a selected set of students matching an authorized source. A **reviewed handoff** passes an explicit selection to a permitted workflow, such as group registration, billing or messaging. Matching a report does not automatically register, bill or contact everyone in it. See [report-selected registration](workflow-review.md#register-students-selected-from-a-saved-report).

### Export / CSV

An **export** prepares report data for an authorized download. **CSV** is a plain-text format that spreadsheet applications can open. Complete background exports are prepared on the server with size, source-access and retention limits; a displayed page and a complete export are different scopes. See [complete report exports](workflow-review.md#complete-report-exports).

## Server processing and technical terms

### ULID

A generated, 26-character internal record identifier, such as `01K00000000000000000000001`. The name expands to *Universally Unique Lexicographically Sortable Identifier*: the identifiers can be ordered as text. Staff normally use meaningful codes or institutional IDs instead. A ULID identifies a record; it does not grant permission or prove the order of registration clicks or academic attempts.

### Primary key / foreign key

A **primary key** identifies a database row. A **foreign key** links a row to a record in another table. For example, a student's reference to their person record connects the two without copying the person's identity. New SIS records generally use ULIDs for these internal identities.

### CRUD

Short for *create, read, update and delete*: ordinary record management. Reference data and working drafts often use this approach, with appropriate permissions and change auditing. It is distinct from preserving consequential business decisions as events.

### CQRS

Short for *Command Query Responsibility Segregation*. In plain language, changing records and reading them have separate responsibilities. A registration command enforces enrollment rules; a report reads an appropriate prepared source. Reports do not need to replay the event journal to answer ordinary questions.

### Domain / DDD / aggregate

A **domain** is an area of institutional work, such as Admissions or Registration. **DDD**, or *domain-driven design*, organizes software around those business concepts and rules. An **aggregate** is a business model that keeps a related set of rules and changes consistent, such as a student account. This meaning of aggregate is separate from a report's sum or count. See [domain organization](domain-organization.md).

### Repository / adapter / DTO

A **repository** is a contract for storing or retrieving a particular kind of business data. An **adapter** implements a connection to storage or another service. A **DTO**, or *data transfer object*, carries a defined set of values between parts of the application. These are developer terms, not additional student records or office procedures.

### Background job / worker / scheduler

A **background job** is server work prepared for processing outside the browser request. A **worker** performs it; a **scheduler** starts eligible work at configured times. Uploading a file still involves a browser request, but the accepted import runs on the server. Background processing still has configured file, record, time and memory limits. See [server imports](catalog-imports.md#server-processing-progress-and-notifications).

### Queue / lease / outbox

A **queue** holds work awaiting processing. A **lease** temporarily assigns work to a worker to coordinate competing workers. An **outbox** retains pending external effects for controlled delivery. A queued or leased notice is not proof of successful delivery. These technical queues are distinct from an academic waitlist.

### Dry run / apply

A **dry run** inspects or previews the supported work without applying its business changes. **Apply** deliberately accepts the effect described by that command. Defaults and flags vary, so follow the command's own guide. For example, academic-date reconciliation inspects by default and requires `--apply` to install a verified replacement index.

### Migration / import / database conversion

A **migration** makes a controlled database structure or data change for an application version. An **import** brings external records through an intake workflow with identity and dependency checks. A **database conversion** moves an installation to another database engine. etSIS recommends choosing the engine at installation and keeping it; conversion needs separate verification. See [installation](../README.md#install-and-setup-for-alpha-testing) and [conversion notes](postgresql-conversion.md).

### PDO / database driver

**PDO** is the PHP database interface used by the application. A **driver** connects it to a database family, such as PostgreSQL or MariaDB/MySQL. Portable code and compiler tests support several engines, while actual installation/workflow qualification has its own scope. See [fresh-install qualification](fresh-install-qualification.md).

### Cache

A disposable stored copy used to avoid repeating work. Losing or clearing a cache must not erase authoritative event history. A cache is different from a database backup, a retained transcript or an encrypted artifact needed for recovery.

### Artifact / retention / backup

An **artifact** is a stored output or working file, such as a prepared transcript, reviewed import or report export. **Retention** determines how long it must be kept and when removal is allowed. A **backup** is a recovery copy of required data and files. Encrypted records also require their applicable keys for recovery; creating a new key does not recover old encrypted data.

### Encryption / sealed data / masking

**Encryption** protects stored contents using a key. **Sealed data** includes protection intended to detect unauthorized changes as well as conceal the protected contents. **Masking** limits what is displayed, such as showing only the last four digits of an identifier. Masking is a display rule, not a replacement for encrypted storage or access checks. See [person identifiers](person-ssns.md).

### CSRF / private, no-store response

**CSRF**, or *cross-site request forgery*, describes an attempt to cause an unwanted authenticated browser action; the application's request checks help protect mutations. A **private, no-store response** instructs caches not to retain that response. Neither setting replaces the action's record-access checks or changes database retention.

### UTC / local institutional date

**UTC** is a shared time reference used for recorded timestamps. A **local institutional date** is interpreted under the workflow's applicable institutional timezone. A local business date and a UTC timestamp are not interchangeable, especially around midnight.

### Tenant / SaaS

A **tenant** is an institution's installation context in a hosted service. **SaaS**, or *software as a service*, means the provider operates the software for its customers. The roadmap's multi-tenant plan calls for institution separation and separate databases; the term does not mean all of that hosting work is complete. See [multi-tenancy](development-roadmap.md#9-multi-tenancy).

## Websites and integrations

### Portal / workspace

The **portal** presents public or signed-in self-service features. The **workspace** presents authorized staff workflows. A person's role, ownership and assignments determine the records and actions available in each; opening one area does not grant access to all records.

### Application Form Library / website form

The **Form Library** designs admissions application forms for the account-based application workflow. A **website form** collects contact inquiries and similar public messages. Website submissions do not automatically create people, accounts or admission applications. Their reply access and deletion use their own workflow. See [website forms](website-forms.md).

### Page builder / layout / block

The **page builder** edits supported website content. A **layout** supplies the surrounding page structure; a **block** supplies a piece of content inside it. Static HTML blocks allow direct content editing. Server-rendered PHP blocks use their supported settings or designers for controlled edits. See [website publishing](website-publishing-and-menus.md).

### Notification / business history

A **notification** is a personal notice about an outcome or action needing attention. Deleting a notification removes that personal item, not the related registration, payment or academic decision. Notification preferences belong to the person's settings. See [workspace notifications](workspace-notifications.md).

### API / REST / webhook

An **API**, or *application programming interface*, is a defined way for systems to exchange requests and data. **REST** describes a commonly used approach to web APIs. A **webhook** is a request one service sends to a configured receiver when something happens. Each integration still needs its own permitted data, authentication and delivery rules. See [website newsletter integrations](website-newsletters.md).

### LMS / Moodle

An **LMS**, or *learning management system*, manages teaching activities and learning content. **Moodle** is the LMS integrated with this project. SIS enrollment records and LMS activity remain separate, with defined synchronization responsibilities. See [optional Moodle integration](optional-moodle-integration.md).

### SSO / OIDC / synchronization

**SSO**, or *single sign-on*, lets a supported sign-in flow connect access across applications. **OIDC**, or *OpenID Connect*, is a sign-in protocol; institutional OIDC remains an additional integration goal. The implemented opt-in [Moodle direct launch](moodle-direct-launch.md) has its own protected sign-in flow. **Synchronization** exchanges selected records, such as enrollment changes; it is a separate process from sign-in. A queued sync is not proof that the other system has applied it.

### Accessibility / WCAG 2.2 AA

**Accessibility** means people can use the interface with different abilities, devices and assistive technologies. **WCAG 2.2 AA** is the project's target for student-facing views, covering needs such as keyboard operation, focus, contrast, readable errors and narrow-screen reflow. The roadmap separately tracks verification of those requirements.
