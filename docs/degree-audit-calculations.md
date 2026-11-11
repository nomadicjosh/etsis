# Retained repeat-credit and GPA calculations

`AcademicPolicyEvaluator` now calculates credit and GPA contributions for a supplied set of retained academic facts. It is a pure domain component: it performs no database reads, changes no academic records and retains no evaluation. The [student Credits & GPA review](student-credit-gpa-review.md) now uses this engine under a shared student lock and replay budget. The [combined degree-progress review](student-degree-progress.md) now adds general minimum comparisons and distinct GPA scopes from the same verified inputs. The separate candidate-matching screen and existing transcript/report calculations keep their existing behavior.

`AuditCalculationPolicy::fromRequirements()` reads the repeat-credit, repeat-GPA and transfer-GPA choices and grading-publication reference from an already verified requirement publication. Missing choices remain unknown; unsupported values are rejected. It never substitutes today's installation settings. The result retains these supplied policy choices.

## Credit treatment

For each stable actual course identity:

- **Highest earned** uses the largest eligible completed award amount. Equal highest awards remain explicit alternatives; no record is silently chosen using its ID, input order or grade-posting time.
- **All eligible** includes every eligible completed award, with each work identity counted once. Several awards remain one distinct course identity when preparing course-count allocation.
- Passing zero-credit completions retain their supporting work references without adding credits. An ungraded registration, failed award, removed early drop, void or late drop creates no earned credit.
- Waivers and course-sharing settings do not change overall awards or GPA. They belong to requirement placement and do not create another earned record.

Tied highest awards can have different institutional origins. The result therefore retains the alternative evidence and returns minimum/maximum institutional-origin credit bounds. For example, one institutional three-credit award tied with a three-credit transfer award produces three earned credits and an institutional-origin range of zero through three. It does not silently choose the institutional record to meet a residency minimum. These bounds are not a complete institutional residency policy; richer exclusions and final residency evaluation remain future integration work.

## GPA treatment

GPA uses retained **credit-weighted quality points** divided by eligible attempted credits. `AcademicGpaScope::Overall` follows the retained transfer-GPA policy; `Institutional` supplies cumulative GPA by excluding transfers before repeat selection. Credit totals and resident-origin bounds retain their full award scope in both results, and the original policy object is unchanged. Per-grade points determine highest-grade selection; percentage score boundaries are not GPA units.

| Retained setting | Calculation |
| --- | --- |
| All eligible graded attempts | Sum every eligible attempted-credit/quality-point contribution once. |
| Highest eligible grade | Compare retained per-grade points for the course, then retain the highest candidates. |
| Latest eligible attempt | Use the retained institutional ordering choice: academic term/instructional period by default for new selections, or actual completion date when explicitly selected. A completion date, ULID, posting time or correction timestamp is never silently substituted. |
| Exclude transfer | Remove transfer contributions before GPA grade/scale checks. Their accepted credits retain their independent credit treatment. |
| Include eligible transfer | Include accepted transfer records only when their retained grade explicitly counts in GPA and their grading context is compatible. |

A passing grade is not required for GPA inclusion. An F that counts in GPA contributes its attempted credits and zero quality points. Likewise, a late-drop grade can contribute to GPA when its retained definition says so, while earning zero credit. The default ungraded W marker and a grade explicitly excluded from GPA add no GPA denominator. Zero-credit courses do not acquire artificial GPA weight.

For example, two institutional three-credit completions with grades worth 3.0 and 4.0 supply six GPA credits and 21 quality points under **all eligible**, producing 3.5. **Highest earned** can still count only three earned credits. **Highest grade** produces GPA 4.0 independently of that credit choice.

## Unknowns, ordering and ties

The calling workflow must resolve the applicable retained institutional chronology. The approved default for new ordering selections is **academic term/instructional period**; institutions can explicitly select **actual completion date**. `PolicyAttempt` separates the actual completion date from `academicOn`, the retained academic-period ordering date. `fromAcademicWork()` copies known completion facts and accepts an optional separately verified academic-period start; without that evidence academic ordering stays unknown. A later completion or grade correction does not move an older academic period under the default choice. Institutional work currently often has no completion date in that read. The domain policy accepts this configurable ordering choice. `fromRequirements()` reads `policies.repeat_attempt_order` from the verified selected publication and leaves a missing choice unknown. The [builder now retains this choice](repeat-attempt-ordering.md), defaulting only new blank drafts to academic-period ordering. The current-work review resolves the retained section instructional start for competing institutional GPA attempts under the same request budget; it never applies that default retroactively. [Institutional completion dates](academic-completion-dates.md) can now be explicitly recorded or corrected in the existing academic stream; [source academic periods for transfers](transfer-academic-periods.md) now have a separate reasoned workflow. The verified transfer view supplies those dates directly to the calculation; institutional periods still require retained section evidence.

Latest-GPA selection needs ordering when several eligible attempts compete. A sole eligible attempt needs no ordering comparison. Missing retained ordering policy produces `ordering_policy_unknown`; missing dates produce `chronology_unknown`; same-date ties remain alternatives only when their GPA contributions are numerically equivalent.

Equal highest-grade or latest-date candidates with identical attempted credits and quality points remain alternative evidence for **one** contribution. Different weights produce `ambiguous_repeat`, not an arbitrary winner. For example, equal grade points on three- and four-credit attempts can change overall GPA weighting when other courses are present. The engine does not invent an institutional tie-break rule.

A positive GPA denominator requires the selected retained grading publication and each included grade's exact publication reference. Unknown or different publications produce an undetermined GPA; no numerical conversion or display-default 4.0 scale is assumed. This conservative gate is not a complete grading-compatibility policy. Explicit institutional compatibility/conversion decisions still need their own design and retained evidence.

## Result contract and exact arithmetic

`AcademicPolicyResult` separates credit and GPA readiness. An unknown credit component has null earned/residency totals and no usable credit pools. An unknown GPA component has null GPA and no usable GPA groups. Issues and per-record dispositions explain the blocking facts; otherwise-ready contributions are marked undetermined when their aggregate cannot be established. Partial totals are never presented as complete totals.

A known GPA component with no eligible GPA credits is represented by `GpaTotals(0, 0)`: its GPA value and minimum comparison are undefined, not a failing zero. A known F-only GPA has positive attempted credits, zero quality points and a defined GPA of zero. Callers must preserve that distinction.

All quantities use integer thousandths. `PolicyAttempt` checks known grade/credit/quality consistency against the retained snapshot, with no current-grade lookup. `GpaTotals` retains the exact numerator and denominator. Its three-decimal value is truncated rather than rounded up; comparing that exact floor against a three-decimal minimum cannot turn a below-minimum GPA into a pass. Long division avoids overflowing a large intermediate remainder. Aggregate additions are checked; an arithmetic limit makes the affected component undetermined.

Inputs are capped at 1,000 academic records and duplicate work IDs are rejected, including an earlier and corrected projection of the same record. Per-course grouping and canonical identity ordering make repeat evaluations independent of incoming row order. No cross-request cache or student payload logging is introduced.

## Integration requirements

The current-work review authorizes the pursuit and verifies available sources and selected policy/publication references within one bounded transaction. The eventual complete degree evaluator still needs wider completeness reconciliation and remaining grading compatibility and chronology evidence. An ordinary page of academic work—even when each returned row verifies—does not establish absence of omitted records or complete history. Unknown earlier facts must remain explicit. The engine itself cannot prove completeness or authorize a read.

Overall calculations use the complete applicable work set. Group allocation must separately apply the group's retained course/publication, grade-floor and exception eligibility before evaluating repeat credit for that group. A larger overall award that is below a group's grade floor must not erase a smaller qualified award; conversely, filtering one group must not remove other attempts from overall GPA. Equal-credit alternatives remain available for an authorized allocation adapter to qualify. The [structural placement adapter](student-course-placement.md) now applies group eligibility before this repeat-credit step and retains all equal-credit evidence alternatives. Archive-wide completeness and remaining degree constraints still need integration.

No schema, event format, migration or stored policy meaning is changed by this calculation stage. Any later retained evaluation needs its policy/input/version boundaries and calculation interpretation. A numerical result alone never changes standing, releases a transcript or grants a degree.

Fictional tests cover every supported policy combination, unequal/tied awards, unknown grades/scales/chronology, late drops, F grades, zero-credit work, duplicate identities, fractional arithmetic and a 1,000-record fixture. Independent integer fractions check exact GPA division. Integration tests consume existing verified institutional/transfer ledger reads, preserve earlier captured inputs after a grade correction, reject implicit use of a later grading publication, handle late-drop GPA and transfer voids, and verify that calculation creates no events.
