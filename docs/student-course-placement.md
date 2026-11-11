# Student course-group placement review

Open **Students → the student → Student programs → Degree requirements → Review course placement**. The same action is available from **Academic work**. The review loads on demand and uses the pursuit's selected degree publication, exact group/course publications, current verified academic work and approved exceptions.

This reviews **course-group placement**. It does not evaluate overall degree credit/GPA/residency minimums, specialization rules, standing, graduation eligibility or issued transcripts. It saves no academic changes or retained audit result.

## Using the review

The result distinguishes a complete supported placement, no complete placement for the supplied current work, missing/conflicting evidence, an unsupported retained rule and a search or read limit. None of these changes the student's academic status. An unsuccessful or interrupted search returns no speculative partial placement.

Each course-group card shows its effective credit or course-count requirement. Choose **Inspect group …** to see its retained course requirements and proposed placements. Expand a requirement card to see the exact published course list and approved waivers. Course lists are created only when expanded. Requirement cards and placements have separate 20-item pages; the review does not build thousands of controls on opening.

A proposed placement shows the actual course, the required-course target, any approved substitution, actual eligible earned credits and supporting award alternatives. Equal-credit evidence remains explicit: the review does not choose an institutional-origin or GPA winner merely to display a placement. Multiple eligible awards can support one distinct actual course when the retained repeat-credit policy allows them.

**How current work was considered** explains eligible work, insufficient grades, in-progress work, drops, voids, unknown publications and repeat/sharing effects. Work and placement pages use the same captured result without another request. **Refresh course placement** performs a fresh bounded review; **Back to previous screen** returns to the degree or work screen.

## Rules applied

- Group eligibility uses retained passing-grade facts, exact course publications or approved substitution publications, and the group's effective per-course grade-point floor. Grade-point comparisons require compatible retained grading publications; no conversion or current 4.0 display default supplies missing evidence.
- Eligibility is checked **before** the retained repeat-credit policy is applied for each actual-course/group/target combination. A larger award that fails the group's grade floor cannot hide a smaller qualified award. These group choices do not remove attempts from overall GPA or overall earned-credit calculations.
- All/choose/pool cards and group credit/course quantities enter one exact bounded placement search. One-group sharing prevents placing the same actual course in several groups. Multiple-group sharing permits a placement in each eligible group without creating another earned award or another GPA contribution.
- A pursuit-wide waiver removes a required course wherever required. It creates no earned credit and does not reduce group quantities or choose counts. Actual earned work for that waived course can still meet unchanged quantity requirements when eligible.
- Applicable approved group credit/course minimum and per-course grade-floor reductions affect the search. An approved substitution supplies an alternative for the original target; the original and alternative cannot inflate that target into two distinct completions. Conflicting approvals require registrar review.

Overall degree minimum exceptions remain retained inputs for later complete evaluation; they are not silently treated as satisfied here. Earlier average group-GPA requirements are explicitly unsupported by this structural review. A group cannot be declared satisfied after dropping an unsupported constraint.

## Unknown facts and historical protection

Relevant unknown grades, missing/different course publications, incompatible grading context and conflicting approvals produce **needs review**, with no definitive placements. A work record unrelated to every selected course group does not block this structural check merely because its grade is unknown; overall GPA/degree evaluation has a separate scope.

Older unbound institutional work and transfer awards without retained accepted-course publication/equivalence evidence are not automatically matched to today's curriculum. That context still needs additional capture/review support. The reviewer never creates a historical publication binding from today's catalog.

Known in-progress, non-passing, late-drop and voided work cannot supply completed-course placement. Early-drop references are verified internally and omitted from current records, counts and returned evidence. Unknown earlier history and a changed matriculation cohort are identified. The check covers available surviving current/history references; it does not certify a complete archive or reconstruct earlier cumulative results.

## Bounds and access

Every request verifies owner or `sis:student:read` access and the student-program scope, then holds the student lock while reading the selection, retained requirements, group/course publications, approvals and academic inputs. It rechecks authority before returning. Private POST requests retain framework CSRF protection, private/no-store responses and debug suppression.

The same 400-page/2,000-event replay allowance covers those reads, including replacement-course publications. Academic discovery remains capped at 200 distinct sources. Preparation allows at most 5,000 relevant work/target eligibility checks and 20,000 selected evidence references; it never feeds the truncated 25-match display pages to the planner. The existing planner bounds groups/resources/choices and defaults to 100,000 preparation/search steps. Exhaustion yields a controlled incomplete result; the API accepts no larger budget or alternate policy.

Only 20 current work records, requirement cards or placements are displayed per relevant page. Required course lists load into the DOM on expansion. Current snapshots live only in the open review, with no browser storage, persistent result cache, new event or receipt. Failed nested reads abort the read transaction before a controlled failure result is returned.

Private endpoint: `/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/allocation/`.

This slice requires no migration or stored event/schema conversion. The [structural engine](degree-audit-allocation.md) and [repeat-credit/GPA engine](degree-audit-calculations.md) retain their separate responsibilities. Next are remaining historical transfer/equivalence inputs, complete degree minimum/constraint integration and reproducible retained evaluations with controlled graduation decisions.
