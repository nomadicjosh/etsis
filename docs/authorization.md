# Database authorization

`Application\Provider\RbacServiceProvider` binds `Domain\Access\Repository\AccessRepository` to `Infrastructure\Persistence\Repository\SqlAccessRepository`. Access services live in `Domain\Access\Service`; protection of user account edits lives in `Domain\User\Service\UserAccountAccess`. The existing Codefy `Gate` contract and `gate('sis:student:read')` calls remain compatible. Runtime authorization does not load `config/rbac.php`, `RbacLoader`, a JSON permission file, or a PHP role/permission graph.

The migration `20261106000005_CreateSisAccessTables.php` creates seven tables with ULID primary keys: roles, permissions, role permissions, user roles, user permissions, permission implications, and a serialization/bootstrap marker. Foreign keys and unique indexes enforce valid, distinct assignments. Definition names and both directions of assignment/implication pairs are indexed. The existing users key width is retained for compatibility.

## Roles and authority

The legacy `20160916002812_initial_schema.php` seeds role IDs 8–12: Super Administrator, Faculty, Parent, Student, and Staff. `20170706151405_db_schema_update.php` revises Super Administrator's permissions but adds no other default roles. The new equivalents are `super`, `faculty`, `parent`, `student`, and `staff`. The current skeleton's `admin`, `user`, and `manager` roles are retained as well.

| Role | Default authority |
| --- | --- |
| `super` | Every Gate permission check, including future permissions. Only an actual holder of this protected role can assign or revoke it. |
| `admin` | Explicit administration, website and SIS permission groups. No automatic access to future standalone permissions or access management. |
| `faculty` | Dashboard, own profile and explicitly shared/owned report execution. Class access still requires the section's assigned faculty identity. |
| `student` | Dashboard and own profile. Existing academic and account services authorize owned records. |
| `parent` | Dashboard and own profile. No dependent records are exposed without a future relationship/consent policy. |
| `staff` | Dashboard and own profile. Office privileges are assigned explicitly through custom roles or direct grants. |
| `user`, `manager` | Existing dashboard and own-profile baseline for compatibility. New accounts receive `user`. |

Legacy inquiry, raw SQL, FTP, impersonation, and broad staff screen permissions are not converted into unrestricted campus-wide management rights. This is intentional: modern owner and assigned-faculty policies remain in force. An institution can create a registrar, HR, bursar or other custom role and explicitly grant appropriate existing backend permissions.

All seed definitions have `is_system = 1`. Neither super administrators nor ordinary access administrators can edit/delete those definitions or change system role permission lists. Assigning/removing system roles on users is supported, with additional protection for `super`. Custom definitions can have their descriptions changed or be deleted. Names are immutable application identifiers, rather than editable labels. Deleting a custom definition removes its associated grants atomically.

`admin:access:manage` authorizes administration reads and writes. It is a separate permission, outside the `admin` permission group. Super users have it automatically; a super user may delegate it to other users or custom roles. Such delegated managers cannot promote themselves to `super`, remove another user's super role, or edit a super account through the existing profile editor. A super account's role must be revoked before account deletion, and the final super role assignment cannot be revoked.

Normal role grants and direct user grants are additive. There are no implicit permission-name wildcards, explicit-deny rules, executable database rule classes, or role nesting. The protected `super` role is the sole unrestricted authority. Permissions such as `sis:student:read` are ordinary text values, bound as parameters. Colons do not require special database column syntax. Names accept 1–80 lowercase ASCII letters/digits, colons, underscores and hyphens, starting with a letter.

## Registrar duties and academic access

Registrar is an institutional job function, not an automatic grant to every staff member. Create custom roles and/or assign individual permissions in the Access workspace. The protected `staff` baseline remains dashboard and `own` profile only. The existing broad `admin` role includes the seeded SIS functions; `super` remains unrestricted.

| Duty | Required permission |
| --- | --- |
| Maintain sending institutions, external courses, equivalencies and award credit | `sis:transfer:manage` |
| Read student academic records and transcripts for evaluation | `sis:student:read` |
| Edit academic programs, terms, courses, sections and grade definitions | `sis:catalog:manage` |
| Process official transcript requests and record fulfillment | `sis:transcript:manage` |
| Change student activity/program assignments or staff registration | `sis:registration:manage` |

A recommended transfer evaluator role combines `sis:transfer:manage` and `sis:student:read`. This enables review of the student's current program, activity, and existing academic credits inside the award form. Student-read currently authorizes campus-wide academic reads; it does not implement advisor/cohort-based row restrictions. It does not grant financial accounts, employment, account administration, program mutation, or transcript-request processing. Academic standing and advisor policies are still future work.

A catalog specialist or transcript clerk can receive only their respective duty. A registrar supervisor can combine these duties in one custom role, while selected users may receive extra individual grants. No role-name convention implicitly grants these permissions. Revoking a direct grant leaves an inherited role grant effective; revoke both sources to remove a duty.

For institutions that separate evaluation from posting, transfer management alone supports a minimal recipient lookup (name, institutional ID, activity and internal references), read-only institutional course selection, and the awards for the reviewed equivalency. It does not grant full academic history. The academic preview is unavailable without `sis:student:read`; the corresponding server endpoints enforce that check independently. Transfer permission never authorizes catalog mutation or official transcript-request queues. All staff workspace users also need `admin:dashboard`, supplied by the baseline staff role.

## Reads and writes

After resolving the signed-in identity, each `Gate::can()` performs one prepared existence query for that user and permission, with indexed joins for role grants, direct grants and the protected super role. It returns at most one row. It does not hydrate all roles or permission arrays, or execute one query per assigned role. The implication table preserves seeded group grants (`sis`, `admin`, `webmaster`) without runtime graph traversal. Custom permissions have an exact-match self implication and require explicit grants.

There is no stale permission cache: a grant/revocation affects the next permission check, including within the same request. Concurrent requests already in progress retain the usual database transaction semantics. Administration writes and existing account edits/deletions acquire one database row lock before rechecking authority. Definition/assignment writes and metadata audit records share the same portable transaction. Administration lists use ULID cursors and are capped at 100 rows. Permission checking does not take the administration write lock.

The SQLite integration suite includes a role with 2,500 permissions and verifies that checking one permission issues a single query and its query plan uses indexes rather than scanning permission/grant tables. This validates query structure, not production latency on every database engine. New tables compile for all five framework databases; only SQLite has been executed locally.

## Initial migration and first super administrator

Run the normal project migration command using the configured PHP 8.4+ interpreter:

```sh
/usr/local/bin/php84 codex migrate
```

The new migration reads `config/rbac.php` **once** as the initial system seed, preserves current `users.role` assignments by seeded role name, and falls back to `user` for unrecognized values. It imports user IDs/roles in bounded batches and does not read password/token fields. The persisted marker prevents a rerun from restoring revoked assignments or reapplying config changes. Existing `admin` users remain ordinary administrators; they are not silently promoted to `super`. This migration imports the current application's users, not legacy person-role tables from another database. The legacy numeric role IDs listed above are reference mappings for a future data importer.

If no super administrator exists, a deployment operator can select an existing account explicitly:

```sh
/usr/local/bin/php84 codex sis:access:bootstrap USER_ULID
```

The bootstrap operation is console-only, transactional, audited, and refuses to run once a super user exists. No default privileged account or password is created. Existing account creation always grants the `user` role atomically with the user projection. The old single `users.role` field and `RoleWasChanged` event are compatibility metadata and no longer authorize requests. Profile forms do not assign effective roles. Use **Roles & access** and the **Access** tab in the person workspace to manage assignments. No migration or bootstrap command has been run against an institutional database during development.

## Administration endpoints

All endpoints require a signed-in identity with `admin:access:manage`, and retain global browser CSRF protection, private/no-store responses, debug suppression, and generic error normalization. Bodies use the existing parsed-body conventions. The administration UI is available in the campus workspace.

| Method | Path under `/sis/access` | Operation |
| --- | --- | --- |
| GET, POST | `/roles/` or `/permissions/` | List definitions or create a custom definition (`name`, optional `description`). `is_system` cannot be set by clients. |
| PATCH | `/roles/{id}/` or `/permissions/{id}/` | Update a custom definition's description. |
| DELETE | `/roles/{id}/` or `/permissions/{id}/` | Delete a custom definition and its grants. |
| GET | `/roles/{id}/permissions/` | List directly assigned permission IDs. |
| PUT, DELETE | `/roles/{id}/permissions/{target}/` | Grant/revoke a permission on a custom role. |
| GET | `/users/{id}/roles/` | List a user's assigned role IDs. |
| PUT, DELETE | `/users/{id}/roles/{target}/` | Assign/revoke a role for a user. Super assignments require a super actor. |
| GET | `/users/{id}/permissions/` | List a user's additional permission IDs. |
| PUT, DELETE | `/users/{id}/permissions/{target}/` | Grant/revoke an individual permission independently of roles. |

List query parameters are `limit` (1–100, default 100) and optional `after` (the previous `next_after` ULID). Responses use `items` for definitions or `target_ids` for assignments, plus `next_after`. Definition objects expose `id`, `name`, `description`, and `is_system`. Assignment PUT/DELETE calls are idempotent. Revoking an individual grant leaves inherited access intact, and revoking a role leaves independent direct grants intact. Audit entries carry actor/subject IDs and action codes, never request payloads or credentials.
# Framework storage integration

`RbacServiceProvider` binds the framework `StorageResource` interface to `Infrastructure\Security\Rbac\SqlStorageResource`. The original framework `Auth`/`Sentinel` implementation remains in use. Its RBAC dependency now resolves against the same database definitions used by access administration.

`load()` does not hydrate roles or permission graphs. Named lookups query the database on demand, and a role's `checkAccess()` performs one existence query, including system permission implications and the protected `super` role. User authorization continues through `UserAuth` and `SqlAccessRepository::allows()`, preserving multiple roles and additional individual permissions. Explicit `getPermissions()` enumeration is available, but authorization never needs to enumerate a role's permissions.

Storage mutations commit immediately through `AccessService`, preserving administrator checks, system-definition protection, audit metadata and last-administrator safeguards; `save()` therefore has no deferred snapshot to persist. Bulk `clear()`, role inheritance, changes to protected permission implications and executable rule classes are rejected. This avoids a second configuration-backed permission graph or an alternative write path around access administration.

## Database role names and bounded editors

`Domain\User\ValueObject\UserRole` validates the same identifier syntax as access definitions, through `Domain\Access\ValueObject\AccessName`. It can replay built-in and custom names without changing an enum. The enum in `Domain\User\Enum` supplies built-in labels only; it is not an authorization allowlist. Historical `users.role` and role events remain compatibility metadata. Creating or editing a sign-in profile cannot grant access, including when a caller supplies `role=super`. Public and staff account creation retain the protected default `user` assignment. Actual assignments require the access service and its database definition checks.

POST `/sis/access/directory/{roles|permissions}/` supplies the workspace's paged editor. Its protected body supports `search`, `after`, `limit` (1–100, UI default 50), optional `grant`/`subject`, and `assigned_only`. Grant kinds are `role_permission`, `user_role` and `user_permission`; definition/grant mismatches are rejected. A page returns definition metadata, direct `assigned` state, `effective` state for permission pickers, `assignable`, and `next_after`. Permission-group and role inheritance are distinguished from direct checkboxes. Removing a direct grant does not remove inherited access. Super role assignment is disabled for delegated managers and remains checked again during writes. System role grant lists remain read-only.

The editor replaces its current page on navigation. It no longer downloads every definition and assignment or stops silently at 5,000 definitions. A person's Access tab loads only when opened. Definition and assignment state are read together in one bounded query per page, in addition to authorization/subject checks. Indexed runtime authorization remains unchanged. Tests include 2,500 permissions, one-query page reads, escaped searches, cursor boundaries, inherited/direct distinctions and protected super controls.


Report request cases reuse `sis:reporting:run` for own requests and `sis:reporting:manage` for the specialist queue. Requesters can never list another user’s cases. Assignment does not grant report ownership, report administration or data-source access. Only the assigned specialist may clarify or hand off a report; taking over a case requires a reason. The specialist must own the selected report or have `sis:reporting:admin`. Handoff adds an explicit report share, never a role or direct data permission. Current source-data grants are still required for advanced execution. Cancelling/reopening a case does not revoke existing shares; sharing changes remain explicit report-owner/administrator actions. Original actors and current authority are required to recover mutation receipts.

## Degree requirement assignments

`sis:degree-audit:assign` is a protected, independent office permission; it is not implied by `sis`, catalog, registration or ordinary staff access. Assignment requires this permission **and** `sis:student:read`. Super administrators retain their existing universal authority. An access administrator may grant it to a custom registrar role or as an additional user permission. The new migration registers the definition without modifying existing grants/revocations. Owners may inspect their assigned requirements and curated decision references without authoring access. See [the reviewed assignment workflow](student-degree-requirements.md).

## Student requirement exceptions

`sis:degree-audit:adjust` plus `sis:student:read` is required to approve/revoke a course waiver or minimum exception. Catalog, registration and requirement assignment alone do not grant this. It is an independent protected system permission with no automatic expansion of office/user grants; access administrators may grant it to a custom registrar role or user. Owner/student-read authority permits curated reads only. [Setup and scope](student-degree-exceptions.md) describe the one-pursuit boundary and immutable approval history.
