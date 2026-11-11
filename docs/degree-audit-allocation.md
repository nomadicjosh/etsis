# Structural course allocation engine

`Domain\DegreeAudit\Service\CourseAllocationPlanner` supplies the first structural allocation core. It finds a placement satisfying the supplied group credit/course quantities and required/choose/pool rules, using the retained one-group or multiple-group sharing choice. It backtracks when an earlier placement blocks another group rather than treating the first match as final.

This domain engine now powers an on-demand [student course-group placement review](student-course-placement.md), using verified available academic inputs, exact retained publications and approved exceptions under one lock/read budget. The separate **Check requirement matches** screen remains a paged candidate inspection; its truncated pages are never allocation inputs. A structural placement is not a completed degree audit or graduation decision.

## Required inputs

`AllocationProblem` accepts normalized groups, eligible course resources, an explicit `CourseGroupReuse` value and pursuit-wide waived course IDs. Each group contains its stable ID, credit/course minimums and named rules. Each distinct actual course resource contains eligible choices of group, required-course target, actual awarded credits and supporting academic-work IDs.

The current student placement adapter authorizes the pursuit, verifies available current facts and retained publications, blocks relevant unknown/incompatible definitions, evaluates group-specific grade floors and then applies the retained repeat-credit policy. Broader archive completeness remains an operational reconciliation requirement. It must supply actual retained awards, **not today's catalog credits**. Unknown or omitted work must not be treated as proof that a requirement cannot be met. Current candidate pages and projection rows alone do not establish this complete input set.

The planner does not resolve grades, decide equivalence, infer missing policy, rank repeats or perform database reads. An eligible alternative is represented explicitly by a choice whose actual course identity differs from its required-course target. That choice must already be supported by the verified pursuit-scoped approval and exact publication context.

Repeat-eligible awards for one actual course can contribute a policy-selected credit amount and several supporting work IDs. They remain **one course identity** for course-count and placement purposes. Group-specific eligibility can select different supported awards. The same work ID cannot belong to two course identities, and the same evidence set cannot claim different credit amounts in different choices.

## Placement semantics

- With **one group**, one actual course resource may receive at most one placement across all groups.
- With **multiple groups**, that course can receive one placement in each applicable group. Groups can then be solved independently, avoiding enumeration of every possible subset of groups. This does not create another earned award or an overall credit/GPA total.
- Within a group, one actual course can fill one distinct required-course target. An original course and its approved alternative cannot inflate that one target into two course completions. The same target appearing on several rule cards can satisfy those cards while contributing its credit/course quantity once.
- Every named **all** target must be covered unless the course requirement is waived for this pursuit. A waiver applies wherever that target appears across the supplied groups; it has no effect on another allocation problem/pursuit.
- A **choose** card still requires its published number of actually covered distinct targets. A waiver creates no completion and does not lower that count. A **pool** card lists eligible targets without adding a named-course count.
- Group quantities are independent constraints. Waivers do not create credits or reduce those minimums. Actual earned work for a waived course remains usable when otherwise eligible. A completed zero-credit course can supply a real course-count/named completion without inventing credits.

Group credit sums use integer thousandths. Internal credit bounds saturate at the required quantity; original actual amounts and supporting work IDs remain in returned placements. This avoids unnecessary state distinctions and overflowing large intermediate bounds. No derived total is persisted as academic truth.

## Outcomes

| Outcome | Meaning |
| --- | --- |
| `feasible` | A complete placement satisfies all **supplied structural constraints**. Supporting work IDs and actual amounts accompany its placements. |
| `infeasible` | The bounded problem was fully resolved and no placement satisfies all supplied structural constraints. This is not a failing academic status or a graduation decision. |
| `undetermined` | The operation reached its work limit before proving either result. No partial placements are returned and the student must not be treated as failing requirements. |

The engine returns the first complete feasible plan in its deterministic search order. It does not promise a minimum-size plan, institutional preference optimization or a best partial plan. Infeasible and undetermined results contain no speculative placements.

GPA, residency, specialization exclusions and other constraints outside this input contract are not silently evaluated. Unknown extra group fields are rejected. A future adapter must explicitly refuse unsupported retained requirements, such as an older average group-GPA constraint, rather than drop those fields. Constraints affecting which placement is valid must enter the appropriate evaluator/search; rejecting a student merely because one structural plan fails a later omitted constraint would be incorrect.

## Resource limits and reproducibility

Input validation bounds a problem to 25 groups, 200 distinct eligible course resources, 100 cards per group, 50 targets per card, 200 distinct targets per group, 5,000 eligible placement choices and 20,000 supporting-work references. Pursuit waivers are capped at 5,000 identities. Courses with no eligible choices are excluded from the search after validation. These are engine limits, not permission to truncate a student's history.

The default search budget is 100,000 counted preparation/search checks, with an explicit configurable maximum of 1,000,000. The budget is shared across independent group searches. Input validation and normalization have their own fixed collection limits; the step count is not a wall-clock or total-instruction measurement.

Groups, rules, targets, evidence and choices receive canonical ordering; courses with fewer choices are considered first, followed by stable identity. Optimistic remaining-credit/course/target bounds prune impossible branches without declaring an overestimated bound to be achievable. Only proven failed states are memoized, using fixed-size keys. Search state exists only during one call; repeating a call verifies no cached student facts and uses a fresh budget.

With the same normalized inputs and budget, ordering changes do not alter the chosen placements or result. Exhausting the budget returns `undetermined`, including after earlier branches or shared groups found placements. A larger authorized calculation can be considered later; the engine never silently disables its bound.

## Verification and integration still needed

Fictional pure-domain tests cover backtracking counterexamples, fractional awards, all/choose/pool interactions, pursuit-wide waivers, zero-credit completions, approved-alternative target collisions, repeated cards, repeat evidence, sharing, canonical ordering and interrupted searches. Independent exhaustive enumeration checks 128 small weighted graphs plus 64 mixed named/choice/waiver/alternative cases. Larger fixtures exercise 200 unambiguous courses and 5,000 placements across 25 shared groups under the default budget; these are bounded correctness fixtures, not live-server benchmarks.

The [repeat-credit/GPA calculation core](degree-audit-calculations.md), bounded academic-input verification and authorized group-specific eligibility/repeat adapter are implemented. The adapter keeps all equal-credit evidence alternatives alongside a structurally equivalent representative used by the search; public placements do not claim that representative as an overall award/residency/GPA winner. Next are remaining historical equivalence inputs, full degree constraints and retained evaluation results. Any eventual retained evaluation needs its input/publication references, consistent stream/version boundaries and calculation interpretation. Authoritative academic history stays in the existing protected journal. A structural allocation result alone must never issue a credential or change a student's academic record.
