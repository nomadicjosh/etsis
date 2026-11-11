# Domain organization

The existing `src/Domain/User` context is the project template. It groups the business workflow and its command/query handlers inside the domain, including the context's services, DTOs, validation and repository contracts. The migrated SIS contexts use the same organization and the PSR-4 namespaces already declared in `composer.json`.

| Location | Responsibility | Examples |
| --- | --- | --- |
| `Domain/{Context}/` | Entities, aggregates and business models | `Person`, `AdmissionApplication`, `StudentAccount` |
| `Domain/{Context}/Service/` | Business workflows, policies and service contracts | `AdmissionsService`, `AccessService`, `InstitutionalIdGenerator` |
| `Domain/{Context}/Repository/` | Persistence contracts | `AdmissionsRepository`, `AccessRepository`, `AccountRepository` |
| `Domain/{Context}/Command/` | Business commands and their handlers | `MatriculateStudentCommand`, `RegisterStudentCommand` |
| `Domain/{Context}/Query/` | Business queries and their handlers | `FindStudentQuery`, `StudentTranscriptQuery` |
| `Domain/{Context}/Dto/` | Input/output data shapes | `StudentProfile`, `StudentAccountProfile`, existing user data transformers |
| `Domain/{Context}/Validator/` | Context-specific validation, following User's existing validators | `StoreUserValidator`, `UpdateProfileValidator` |
| `Domain/{Context}/Enum/`, `ValueObject/`, `Exception/` | Typed business values, statuses and failures | `EntryType`, `InstitutionalId`, `InstitutionalIdUnavailableException` |
| `Domain/Shared/` | Shared domain contracts, values and failures | `EntityId`, `Clock`, `UnitOfWork`, `StudentAccess` |
| `Application/Http/` | HTTP controllers, middleware, request parsing and response serialization | `SisAccessController`, `SisPrivacyMiddleware`, `FormInput` |
| `Application/Console/` | Console entry points | `BootstrapAccessCommand` |
| `Application/Provider/` | Framework registration and dependency bindings | `SisServiceProvider`, `RbacServiceProvider` |
| `Infrastructure/` | Concrete database, identity, security, encryption, clock and transport adapters | `SqlAccessRepository`, `EventSourcedAccountRepository`, `GateStudentAccess` |

Domain code depends on domain-owned contracts rather than importing Application or Infrastructure classes. Providers bind those contracts to concrete adapters. The existing framework command/query interfaces and User's established DTO/validator conventions remain in use; this organization does not require event sourcing for every context. Financial event sourcing and existing stored event class names are preserved. The governing [event-sourcing architecture](event-sourcing-architecture.md) now extends the target to registration, academic facts and consequential lifecycle/admissions transitions; several existing SQL write paths still require conversion. Historically meaningful catalog/section/policy definitions need immutable published revisions, as detailed in the [legacy field review](academic-history-field-review.md).

The framework's command and query buses have separate dependency containers. Their configuration delegates domain services back to the application's configured provider bindings. User command handling also delegates the user aggregate repository, projection and transactional event store, as well as the access repository and user account policy, so user creation/projection and protected account updates can resolve their authorization dependencies.

This refactor changes PHP namespaces and file locations, including shared contract/exception locations. Controllers, providers, adapters, bus configuration, migrations and tests reference the new names. Stored financial event classes, permission names, HTTP paths and workflow behavior are preserved. The later single event-store migration replaces the original event table after verified conversion. Existing generic framework services and helpers in Application retain their original locations.

`DomainBoundaryTest` checks that Domain source does not introduce dependencies on Application or Infrastructure namespaces. Workflow and router tests continue to verify behavior across the three layers.


The protected journal port and immutable context/page DTOs live in `Domain/Shared/EventSourcing`; authenticated encoding, reviewed schema readers and SQL persistence live in `Infrastructure/EventSourcing`. This uses the installed Codefy event types and Defuse encryption. The framework adapter delegates to the same journal; there is one event table, with supporting heads and receipts. Authorization remains in calling domain workflows; no generic HTTP history reader/writer is exposed. See [the implemented foundation](event-history-foundation.md) for boundaries and pending domain conversions.

`Domain/Catalog` now owns `CourseDefinition`, `CoursePublication` and its registered publication event. `CoursePublicationService` coordinates authorized review and publication through the shared journal and existing catalog contracts; its HTTP controller only parses inputs and delegates. Incremental replay consumes verified pages and retains only the latest version identity in aggregate memory. See [course publication](course-publication.md) for the current boundary and remaining version pinning.

During alpha, evolve one current domain model rather than introducing numbered wrapper classes for each field addition. CourseDefinition owns course facts, CourseText, an optional SubjectSnapshot and CourseClassifications; PublishedCourseDefinition adds publication identity. Publication revisions remain historical business facts. Necessary schema changes and migrations are authorized, and external compatibility is stabilized during release-candidate planning.

`ProgramDefinition` and `ProgramClassifications` describe the working/published program facts. `ProgramPublicationService` coordinates authorized draft saves and academic-year publication through domain ports; `PublishedProgramReader` verifies the year-specific definition inside authorized entry commands. SQL repositories maintain the catalog/reference projections, and the HTTP controller parses inputs without owning publication rules. Degree-audit requirement composition remains a separate domain workflow.

## Nullable and optional parameters

Use an explicit `= null` for nullable inputs that callers may omit. A nullable type alone accepts an explicit null but still requires an argument; omission raises `ArgumentCountError`, a subtype of `TypeError`. Put optional parameters after required parameters, and use named arguments when reordering DTO constructors. An optional calculation value stays unknown when omitted; do not turn it into zero or today's policy.

Required nullable inputs still have a purpose. For example, an expected projection version or owner identity can require the caller to deliberately pass a value or null. Preserve those concurrency and authorization contracts rather than making every nullable command input optional. The read-cursor and calculation DTO contracts in `Domain/DegreeAudit` explicitly allow appropriate omissions.
