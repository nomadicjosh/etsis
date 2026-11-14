const { expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target) }))).toEqual([]);
}
async function fixture(page, options = {}) {
    const student = id(1),
        assignment = id(2),
        program = id(3),
        template = id(4),
        version = id(5),
        group = id(6),
        course = id(7);
    const base = `/sis/students/${student}/programs/${assignment}/degree-requirements/`,
        calls = [],
        errors = [];
    const requirements = {
        program_publication: options.programPublication || null,
        template_id: template,
        version_id: version,
        version: 1,
        schema_version: 3,
        context: { program_title: "Computing", academic_year_code: "2026-2027" },
        definition: {
            name: "B.S. Computing <b>fictional</b>",
            min_credits_milli: 120000,
            min_resident_milli: 30000,
            length_months: 48,
            groups: [{ group_id: group, version_id: id(8) }],
            policies: {
                repeat_credit: "highest_earned",
                repeat_gpa: "all_eligible",
                transfer_overall_gpa: "exclude",
                waiver: "waive_required_course_everywhere",
                group_reuse: "one_group",
            },
        },
        group_details: {
            [group]: {
                version: 1,
                definition: {
                    name: "General education",
                    min_credits_milli: 3000,
                    min_gpa_milli: null,
                    rules: [{ id: id(9), mode: "all", minimum_courses: 1, course_ids: [course] }],
                },
                course_details: { [course]: { code: "MATH.101", title: "Algebra", credits_milli: 3000 } },
            },
        },
    };
    if (options.groupMinimums) {
        requirements.grade_scale = {
            maximum_milli: options.requirementGradeMaximum || 4000,
            source: "program",
            program_id: program,
            version_id: id(32),
            is_default: false,
            definition: { grades: [] },
        };
        const block = requirements.group_details[group];
        Object.assign(block.definition, {
            requirement_type: "courses",
            min_credits_milli: 0,
            min_courses: 2,
            min_course_grade_points_milli: 2000,
            max_course_grade_points_milli: 4000,
            grade_scale_program_id: null,
            courses: [
                { course_id: course, version_id: id(12) },
                { course_id: id(30), version_id: id(31) },
            ],
            rules: [{ id: id(9), mode: "pool", minimum_courses: 0, course_ids: [course, id(30)] }],
        });
        delete block.definition.min_gpa_milli;
        block.course_details[id(30)] = { code: "ENG.101", title: "Fictional English", credits_milli: 3000 };
    }
    let selection = options.assigned
        ? { pursuit_started_on: "2026-08-01", requirements_version_id: version }
        : null;
    const adjustmentBase = base.replace("degree-requirements/", "degree-adjustments/");
    let activeAdjustments = options.activeWaiver
            ? [{ adjustment_id: id(20), definition: { kind: "waiver", course_id: course }, applicable: true }]
            : [],
        nextAdjustment = 21;
    if (options.approvedReplacement) activeAdjustments = [{
        adjustment_id: id(20), applicable: true,
        definition: {
            kind: "substitution", course_id: course,
            replacement_course_id: id(15), replacement_version_id: id(16),
        },
    }];
    if (options.previewApprovals) {
        activeAdjustments = [
            { adjustment_id: id(20), definition: { kind: "waiver", course_id: course }, applicable: true },
            {
                adjustment_id: id(21),
                definition: {
                    kind: "minimum",
                    group_id: group,
                    group_version_id: id(8),
                    minimum: "min_courses",
                    original_milli: 2000,
                    required_milli: 1000,
                },
                applicable: true,
            },
            {
                adjustment_id: id(22),
                definition: {
                    kind: "minimum",
                    group_id: group,
                    group_version_id: id(8),
                    minimum: "min_course_grade_points_milli",
                    original_milli: 2000,
                    required_milli: 1700,
                },
                applicable: true,
            },
            {
                adjustment_id: id(23),
                definition: {
                    kind: "minimum",
                    group_id: null,
                    group_version_id: null,
                    minimum: "min_credits_milli",
                    original_milli: 120000,
                    required_milli: 108000,
                },
                applicable: true,
            },
            {
                adjustment_id: id(24),
                definition: {
                    kind: "substitution",
                    course_id: id(30),
                    replacement_course_id: id(15),
                    replacement_version_id: id(16),
                },
                applicable: true,
            },
            {
                adjustment_id: id(25),
                definition: {
                    kind: "minimum",
                    group_id: null,
                    group_version_id: null,
                    minimum: "min_credits_milli",
                    original_milli: 120000,
                    required_milli: 60000,
                },
                applicable: false,
            },
        ];
    }
    const adjustmentHistory = [],
        preparedAdjustments = new Map(),
        receipts = new Set();
    const targets = [
        {
            group_id: null,
            group_version_id: null,
            minimum: "min_credits_milli",
            original_milli: 120000,
            label: "Degree",
        },
        {
            group_id: group,
            group_version_id: id(8),
            minimum: "min_credits_milli",
            original_milli: 3000,
            label: "General education",
        },
    ];
    if (options.groupMinimums) {
        targets.splice(
            1,
            1,
            {
                group_id: group,
                group_version_id: id(8),
                minimum: "min_courses",
                original_milli: 2000,
                label: "General education",
            },
            {
                group_id: group,
                group_version_id: id(8),
                minimum: "min_course_grade_points_milli",
                original_milli: 2000,
                label: "General education",
            },
        );
    }
    const courses = [
        {
            course_id: course,
            code: "MATH.101",
            title: "Algebra",
            groups: ["General education"],
            placement_groups: [
                {
                    id: group,
                    name: "General education",
                    course_code: "MATH.101",
                    course_title: "Retained <b>Algebra</b>",
                },
            ],
        },
    ];
    function effective(items = activeAdjustments) {
        const out = {
            degree: {
                min_credits_milli: 120000,
                min_resident_milli: 30000,
                min_cumulative_gpa_milli: null,
                min_overall_gpa_milli: null,
            },
            groups: {
                [group]: options.groupMinimums
                    ? {
                          requirement_type: "courses",
                          min_credits_milli: 0,
                          min_courses: 2,
                          min_course_grade_points_milli: 2000,
                          max_course_grade_points_milli: 4000,
                      }
                    : { min_credits_milli: 3000, min_gpa_milli: null },
            },
            waived_course_ids: [],
            substitutions: {},
            accepted_course_versions: {},
            placements: {},
            substitution_conflicts: {},
        };
        for (const p of items) {
            if (p.applicable === false) continue;
            const d = p.definition;
            if (d.kind === "waiver") out.waived_course_ids.push(d.course_id);
            else if (d.kind === "course_version") (out.accepted_course_versions[d.course_id] ||= {})[d.version_id]=true;
            else if (d.kind === "placement")
                out.placements[d.course_id] = {
                    group_id: d.group_id,
                    group_version_id: d.group_version_id,
                    course_version_id: d.course_version_id,
                };
            else if (d.kind === "substitution")
                out.substitutions[d.course_id] = {
                    course_id: d.replacement_course_id,
                    version_id: d.replacement_version_id,
                };
            else if (d.group_id === null) out.degree[d.minimum] = d.required_milli;
            else
                out.groups[d.group_id][d.minimum] =
                    d.minimum === "min_courses" ? d.required_milli / 1000 : d.required_milli;
        }
        if (options.previewConflict) {
            out.substitution_conflicts[id(30)] = "Resolve the overlapping course waiver and substitution.";
            delete out.substitutions[id(30)];
        }
        return out;
    }
    const replacement = {
        course_id: id(15),
        version_id: id(16),
        version: 1,
        code: "SCI.201",
        title: "Fictional <b>Science</b>",
        credits_milli: 4000,
    };
    const acceptedPublication={...replacement,course_id:courses[0].course_id,version_id:id(17),version:2,code:"MATH.101",title:"Revised Algebra <b>fictional</b>"};
    const substitutionDetails = (items = activeAdjustments) =>
        Object.fromEntries(
            items
                .filter((p) => ["substitution","course_version"].includes(p.definition.kind) || (p.definition.kind === "placement" && p.definition.substitution_adjustment_id))
                .map((p) => [p.adjustment_id, p.definition.kind==="course_version" ? acceptedPublication : replacement]),
        );
    let cursorCount = 0;
    page.on("pageerror", (e) => errors.push(e.message));
    function allocationReview() {
        const outcome = options.allocationOutcome || "feasible";
        const courseIds = Array.from({ length: 21 }, (_, index) => id(200 + index));
        const rules = options.allocationCreditRule
            ? [
                  {
                      id: id(99),
                      mode: "credits",
                      minimum_courses: 0,
                      minimum_credits_milli: 6000,
                      course_ids: courseIds.slice(0, 3),
                  },
              ]
            : options.allocationNamedRules
              ? [
                    { id: id(99), mode: "all", minimum_courses: 3, course_ids: courseIds.slice(0, 3) },
                    { id: id(98), mode: "choose", minimum_courses: 2, course_ids: courseIds.slice(0, 3) },
                ]
              : [{ id: id(99), mode: "pool", minimum_courses: 0, course_ids: courseIds }];
        const eligible =
            outcome === "infeasible" ? (options.allocationNamedRules ? [courseIds[1]] : []) : courseIds;
        const gaps = ["needs_review", "unsupported"].includes(outcome)
            ? null
            : {
                  has_shortfall: outcome === "infeasible",
                  credit_upper_bound_milli: options.allocationCourseCount
                      ? 0
                      : Math.min(63000, eligible.length * 3000),
                  credit_shortfall_milli: options.allocationCourseCount
                      ? 0
                      : Math.max(0, 63000 - eligible.length * 3000),
                  course_upper_bound: eligible.length,
                  course_shortfall: options.allocationCourseCount ? Math.max(0, 4 - eligible.length) : 0,
                  eligible_target_ids: eligible,
                  rules: rules.map((rule) => ({
                      id: rule.id,
                      required_courses: rule.mode === "all" ? 2 : rule.mode === "choose" ? 2 : 0,
                      eligible_target_count: eligible.length,
                      course_upper_bound: eligible.length,
                      eligible_target_ids: eligible,
                      eligible_course_ids: eligible,
                      shortfall_courses: rule.mode === "pool" ? 0 : Math.max(0, 2 - eligible.length),
                      ...(rule.mode === "credits"
                          ? {
                                required_credits_milli: 6000,
                                credit_upper_bound_milli: 2000,
                                credit_shortfall_milli: 4000,
                            }
                          : {}),
                  })),
              };
        const result = {
            status: "review",
            outcome,
            input_status: options.allocationHistoryGaps ? "history_gaps" : "verified",
            current_record_count: 21,
            context: {
                name: requirements.definition.name,
                academic_year_code: "2026-2027",
                version: 1,
                cohort_changed: Boolean(options.changed),
                group_reuse: options.shareCourses ? "multiple_groups" : "one_group",
            },
            issues:
                outcome === "unsupported"
                    ? ["group_average_gpa_unsupported"]
                    : outcome === "needs_review"
                      ? ["course_version_unknown"]
                      : [],
            groups: [
                {
                    id: group,
                    name: "General education <b>fictional</b>",
                    version_id: id(8),
                    requirement_type: options.allocationCourseCount ? "courses" : "credits",
                    required_credits_milli: options.allocationCourseCount ? 0 : 63000,
                    required_courses: options.allocationCourseCount ? 4 : 0,
                    minimum_grade_points_milli: 2000,
                    waived_courses: [courseIds[0]],
                    courses: Object.fromEntries(
                        courseIds.map((id, i) => [
                            id,
                            { code: `REQ.${i}`, title: "Retained requirement <b>fictional</b>" },
                        ]),
                    ),
                    rules,
                    gaps,
                    placements:
                        outcome !== "feasible"
                            ? []
                            : courseIds.map((target, index) => ({
                                  course_id: id(300 + index),
                                  course_code: index === 20 ? "LAST.101" : `MATH.${index}`,
                                  course_title: "Actual course <b>fictional</b>",
                                  target_course_id: target,
                                  target_code: `REQ.${index}`,
                                  target_title: "Retained requirement <b>fictional</b>",
                                  via_substitution: index === 0,
                                  credits_milli: 3000,
                                  work_options: index === 0 ? [[id(100)], [id(101)]] : [[id(100 + index)]],
                              })),
                },
            ],
            records: courseIds.map((target, index) => ({
                id: id(100 + index),
                course_code: index === 20 ? "LAST.101" : `MATH.${index}`,
                course_title: "Retained work <b>fictional</b>",
                statuses: [
                    outcome === "needs_review"
                        ? "course_version_unknown"
                        : outcome === "infeasible"
                          ? "in_progress"
                          : "candidate",
                ],
                uses: outcome !== "feasible" ? [] : [{ group_id: group, alternative: index === 0 }],
                preferred_group_id: options.allocationPreference && index === 0 ? group : null,
            })),
        };
        if (options.allocationCompetition) {
            const first = result.groups[0];
            first.required_credits_milli = options.allocationCourseCount ? 0 : 6000;
            first.required_courses = options.allocationCourseCount ? 2 : 0;
            first.gaps = {
                ...gaps,
                has_shortfall: false,
                credit_shortfall_milli: 0,
                credit_upper_bound_milli: first.required_credits_milli,
                course_shortfall: 0,
                course_upper_bound: 3,
                eligible_target_ids: courseIds.slice(0, 3),
            };
            result.groups.push({
                ...first,
                id: id(81),
                name: "Professional studies <script>fictional</script>",
            });
            result.competition = {
                conflicts: [
                    {
                        group_ids: [group, id(81)],
                        required_credits_milli: options.allocationCourseCount ? 0 : 12000,
                        credit_upper_bound_milli: options.allocationCourseCount ? 0 : 9000,
                        credit_shortfall_milli: options.allocationCourseCount ? 0 : 3000,
                        required_courses: options.allocationCourseCount ? 4 : 2,
                        course_upper_bound: 3,
                        course_shortfall: options.allocationCourseCount ? 1 : 0,
                    },
                ],
                more_conflicts: !!options.competitionMore,
            };
        }
        if (options.allocationRuleSubstitution) {
            const actual=id(300), target=id(200), named=id(99), dynamic=id(98);
            const g=result.groups[0];
            result.current_record_count=1;
            result.records=result.records.slice(0,1).map(r=>({...r,course_code:"SCI.201",uses:[{group_id:group,alternative:false}]}));
            g.required_credits_milli=3000;g.waived_courses=[];
            g.courses={
                [target]:{code:"REQ.0",title:"Retained requirement <b>fictional</b>"},
                [actual]:{code:"SCI.201",title:"Accepted Science <b>fictional</b>"},
            };
            g.rules=[{id:named,mode:"all",minimum_courses:1,course_ids:[target]},
                {id:dynamic,mode:"credits",minimum_courses:0,minimum_credits_milli:3000,course_ids:[actual],
                    eligibility:{id:id(700),rule_id:id(700),revision:1,code:"DA.SCI",title:"Fictional elective rule",
                        definition:{tree:{condition:"AND",rules:[{id:"course_level_number",operator:"greater",value:100}]},references:{}}}}];
            g.placements=[{course_id:actual,course_code:"SCI.201",course_title:"Accepted Science <b>fictional</b>",
                target_course_id:target,target_code:"REQ.0",target_title:"Retained requirement <b>fictional</b>",
                via_substitution:true,credits_milli:3000,work_options:[[id(100)]],rule_credits_milli:{[named]:3000,[dynamic]:3000}}];
            g.gaps={has_shortfall:false,credit_upper_bound_milli:3000,credit_shortfall_milli:0,
                course_upper_bound:1,course_shortfall:0,eligible_target_ids:[target,actual],
                rules:[{id:named,required_courses:1,eligible_target_count:1,course_upper_bound:1,
                    shortfall_courses:0,eligible_target_ids:[target],eligible_course_ids:[actual]},
                    {id:dynamic,required_courses:0,eligible_target_count:2,course_upper_bound:1,
                        shortfall_courses:0,eligible_target_ids:[target,actual],eligible_course_ids:[actual],
                        required_credits_milli:3000,credit_upper_bound_milli:3000,credit_shortfall_milli:0}]};
        }
        if (options.allocationCoverage) {
            const g = result.groups[0];
            g.coverage = {
                applied_credits_milli: 3000,
                applied_courses: 1,
                rules: {
                    [g.rules[0].id]: {
                        mode: "all",
                        applied_courses: 1,
                        applied_credits_milli: 3000,
                        covered_courses: 1,
                        required_courses: 1,
                        required_credits_milli: null,
                        waived_courses: 0,
                    },
                    [g.rules[1].id]: {
                        mode: "credits",
                        applied_courses: 1,
                        applied_credits_milli: 3000,
                        covered_courses: 1,
                        required_courses: null,
                        required_credits_milli: 3000,
                        waived_courses: 0,
                    },
                },
            };
        }
        if (options.allocationEvidence) {
            result.records.forEach((record, i) => Object.assign(record, {
                grade_state: 'retained', credit_type: i === 1 ? 'transfer' : 'institutional',
                grading_republication_verified: !!options.identicalGrading && i===0,
                retained_grade: {code: i === 1 ? 'B <b>fictional</b>' : 'A', points_milli:i === 1 ? 3000 : 4000, grading_publication_retained:i !== 1},
                recorded_earned_milli: 3000, completed_on: i === 0 ? '2026-12-15' : null,
                term_code:i === 1 ? 'CURRENT.UNRETAINED' : '26/FA', term_label_retained:i !== 1,
            }));
            if (options.allocationEvidence === 'pool') {
                const g = result.groups[0];
                const p = {...g.placements[0], credits_milli:63000, work_options:[result.records.map(r=>r.id)]};
                g.placements = [p];
            }
        }
        if (options.namedCourseReview) {
            result.groups[0].course_review = courseIds.map((course, index) => ({
                course_id: course, course_code: `REQ.${index}`, course_title: 'Retained requirement <b>fictional</b>',
                required: index !== 1, waived: index === 2,
                placement: outcome === 'feasible' ? (index === 0 ? 'proposed' : 'not_used') : 'not_established',
                attempts: index !== 0 ? [] : result.records.slice(0, 12).map((record, i) => ({
                    work_id: record.id, status: i === 1 ? 'in_progress' : i === 2 ? 'insufficient_grade' : i === 3 ? 'course_version_unknown' : 'candidate',
                    via_substitution: i === 0, selected: outcome === 'feasible' ? i === 0 : null,
                })),
            }));
            result.records[1].grade_state = 'awaiting_grade';
            result.records[1].retained_grade = null;
            result.records[1].recorded_earned_milli = 0;
            result.records[2].retained_grade = { code: 'D', points_milli: 1000, grading_publication_retained: true };
        }
        return result;
    }
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname,
            raw = req.postData(),
            body = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        calls.push({ path, body, csrf: req.headers()["x-csrf-token"] });
        let response;
        if (path === "/sis/ui/metrics/") response = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/")
            response = {
                items: [
                    {
                        id: student,
                        person_id: id(10),
                        given_name: "Alex",
                        family_name: "Example",
                        institutional_id: "FICTIONAL-1",
                        active: true,
                    },
                ],
                next_after: null,
            };
        else if (path === `/sis/students/${student}/record/`)
            response = {
                id: student,
                person_id: id(10),
                given_name: "Alex",
                family_name: "Example",
                institutional_id: "FICTIONAL-1",
                active: true,
                primary_program: { id: program, code: "BS.CS", title: "Computing" },
                entry_term: { id: id(11), code: "26/FA" },
                revision: "fictional-student",
            };
        else if (path === `/sis/students/${student}/programs/`)
            response = {
                items: [
                    {
                        id: assignment,
                        program_id: program,
                        program_code: "BS.CS",
                        program_title: "Computing",
                        term_code: "26/FA",
                        catalog_year: "2026-2027",
                        catalog_revision: 1,
                        status: "active",
                        is_primary: true,
                        revision: "fictional-assignment",
                    },
                ],
                next_after: null,
            };
        else if (path.startsWith("/sis/ui/operations/") || path.includes("/restrictions/"))
            response = { items: [], next_after: null };
        else if (path === base)
            response = {
                student_id: student,
                assignment_id: assignment,
                scope: { status: "active" },
                selection,
                requirements: selection ? requirements : null,
                anticipated_completion_on: selection ? "2030-08-01" : null,
                cohort_changed: Boolean(options.changed),
            };
        else if (path === `${base}progress/` || path === `${base}combined/`) {
            if (options.rejectProgress)
                return route.fulfill({
                    status: 409,
                    json: {
                        error: "Degree progress could not be verified. Ask a registrar to review this record.",
                    },
                });
            if (!selection) response = { status: "needs_selection" };
            else if (options.progressLimit) response = { status: "limited" };
            else {
                const allocation = allocationReview();
                const none = options.progressNoGpa,
                    zero = options.progressZeroGpa;
                if (none) {
                    allocation.outcome = "infeasible";
                    allocation.groups.forEach((g) => {
                        g.placements = [];
                    });
                }
                if (options.progressWorkMix) {
                    allocation.records.forEach((r, i) => {
                        if (i >= 18) r.uses = [];
                    });
                    allocation.groups.forEach((g) => {
                        g.required_credits_milli = 54000;
                        g.placements = g.placements.slice(0, 18);
                        g.rules[0].course_ids = g.rules[0].course_ids.slice(0, 18);
                        g.courses = Object.fromEntries(Object.entries(g.courses).slice(0, 18));
                        if (g.gaps) {
                            g.gaps.credit_upper_bound_milli = 54000;
                            g.gaps.eligible_target_ids = g.gaps.eligible_target_ids.slice(0, 18);
                        }
                    });
                }
                const gpa = (overall) => ({
                    ready: !overall || !options.progressUnknownOverall,
                    milli:
                        (overall && options.progressUnknownOverall) || none
                            ? null
                            : zero
                              ? 0
                              : overall
                                ? 3000
                                : 4000,
                    credits_milli: overall && options.progressUnknownOverall ? null : none ? 0 : 6000,
                    quality_points_milli:
                        overall && options.progressUnknownOverall
                            ? null
                            : none || zero
                              ? 0
                              : overall
                                ? 18000
                                : 24000,
                });
                response = {
                    status: "review",
                    input_status: options.progressHistoryGaps ? "history_gaps" : "verified",
                    current_record_count: 21,
                    context: allocation.context,
                    allocation,
                    minimums: [
                        {
                            key: "min_credits_milli",
                            required_milli: 12000,
                            published_milli: 12000,
                            adjusted: false,
                            actual_minimum_milli: none || zero ? 0 : 63000,
                            actual_maximum_milli: none || zero ? 0 : 63000,
                            status: none || zero ? "below_minimum" : "satisfied",
                        },
                        {
                            key: "min_resident_milli",
                            required_milli: 6000,
                            published_milli: 6000,
                            adjusted: false,
                            actual_minimum_milli: options.progressResidentRange ? 3000 : 6000,
                            actual_maximum_milli: options.progressResidentRange ? 9000 : 6000,
                            status: options.progressResidentRange ? "unknown" : "satisfied",
                        },
                        {
                            key: "min_cumulative_gpa_milli",
                            required_milli: 3500,
                            published_milli: 3750,
                            adjusted: true,
                            actual_minimum_milli: none ? null : zero ? 0 : 4000,
                            actual_maximum_milli: none ? null : zero ? 0 : 4000,
                            status: none ? "unknown" : zero ? "below_minimum" : "satisfied",
                        },
                        {
                            key: "min_overall_gpa_milli",
                            required_milli: 3500,
                            published_milli: 3500,
                            adjusted: false,
                            actual_minimum_milli:
                                options.progressUnknownOverall || none ? null : zero ? 0 : 3000,
                            actual_maximum_milli:
                                options.progressUnknownOverall || none ? null : zero ? 0 : 3000,
                            status: options.progressUnknownOverall || none ? "unknown" : "below_minimum",
                        },
                    ],
                    gpa: { cumulative: gpa(false), overall: gpa(true) },
                    issues: options.progressUnknownOverall
                        ? [{ component: "overall", code: "grading_policy_differs" }]
                        : [],
                    records: allocation.records.map((r, index) => ({
                        id: r.id,
                        ...(options.allocationEvidence ? {
                            grade_state: options.progressWorkMix && index===18 ? 'unknown' : options.progressWorkMix && index===19 ? 'awaiting_grade' : r.grade_state,
                            retained_grade: options.progressWorkMix && [18,19].includes(index) ? null : r.retained_grade,
                            grading_republication_verified:r.grading_republication_verified,
                            credit_type:r.credit_type,recorded_earned_milli:options.progressWorkMix && [18,19].includes(index)?0:r.recorded_earned_milli,
                            completed_on:r.completed_on,
                        } : {}),
                        course_code: r.course_code,
                        course_title: r.course_title,
                        credit:
                            options.progressWorkMix && index === 18
                                ? "grade_unknown"
                                : options.progressWorkMix && index === 19
                                  ? "in_progress"
                                  : "included",
                        cumulative_gpa: index === 1 ? "transfer_excluded" : "included",
                        overall_gpa: options.progressUnknownOverall ? "undetermined" : "included",
                        academic_state:
                            options.progressWorkMix && index === 19
                                ? "awaiting_grade"
                                : options.progressWorkMix && index === 18
                                  ? "grade_unknown"
                                  : "completed",
                        placement:
                            allocation.outcome !== "feasible"
                                ? "not_established"
                                : options.progressWorkMix && index >= 18
                                  ? index === 20
                                      ? "not_used"
                                      : "unplaced"
                                  : index === 0
                                    ? "alternative"
                                    : "proposed",
                        uses:
                            allocation.outcome !== "feasible" || (options.progressWorkMix && index >= 18)
                                ? []
                                : [{ group_id: group, alternative: index === 0 }],
                        attempted_milli: 3000,
                        term_id: options.progressWorkMix && index === 20 ? id(600) : id(601),
                        term_code:
                            options.progressWorkMix && index === 20
                                ? options.progressUnknownTerm
                                    ? null
                                    : "27/SP"
                                : "26/FA",
                        term_label_retained: !(options.progressUnknownTerm && index === 20),
                    })),
                    work_summary: {
                        placement_established: allocation.outcome === "feasible",
                        unused_completed_count:
                            allocation.outcome === "feasible" ? (options.progressWorkMix ? 1 : 0) : null,
                        awaiting_grade_count: options.progressWorkMix ? 1 : 0,
                        awaiting_grade_milli: options.progressWorkMix
                            ? options.progressPendingLimit
                                ? null
                                : 3000
                            : 0,
                    },
                    policy: {
                        repeat_credit: "highest_earned",
                        repeat_gpa: "all_eligible",
                        transfer_overall_gpa: "include_eligible",
                        attempt_order: "academic_period",
                    },
                };
            }
            if (path === `${base}combined/`) {
                if (options.combinedIssue) response = { status: "needs_review", issues: [options.combinedIssue] };
                if (response.status === "review") {
                    const parts = ["degree", "specialization"].map(kind => {
                        const progress = structuredClone(response);
                        progress.mode = "combined_component";
                        progress.context = { ...progress.context, mode: "combined_component", kind,
                            requirements_version_id: kind === "degree" ? version : id(71),
                            specialization: kind === "degree" ? null : { id: id(70), code: "DATA", title: "Data <b>fictional</b>" },
                            name: kind === "degree" ? requirements.definition.name : "Data <b>fictional</b> requirements" };
                        if (options.combinedInfeasible) {
                            progress.allocation.outcome = "infeasible";
                            for (const g of progress.allocation.groups) { g.placements = []; g.coverage = null; }
                            for (const r of progress.allocation.records) r.uses = [];
                            for (const r of progress.records) { r.uses = []; r.placement = "not_established"; }
                            progress.work_summary.placement_established = false;
                            progress.work_summary.unused_completed_count = null;
                        }
                        return { kind, progress };
                    });
                    response = { status: "review", outcome: options.combinedInfeasible ? "infeasible" : "feasible",
                        sharing: options.combinedSharing || "one_component", input_status: response.input_status,
                        current_record_count: response.current_record_count, components: parts };
                }
                response.mode = "combined_curriculum";
            }
        } else if (path === `${base}academic-work/allocation/`) {
            if (options.rejectAllocation)
                return route.fulfill({
                    status: 409,
                    json: {
                        error: "Course-group placement could not be verified. Ask a registrar to review this record.",
                    },
                });
            if (!selection) response = { status: "needs_selection" };
            else if (options.allocationLimit) response = { status: "limited" };
            else {
                response = allocationReview();
            }
        } else if (path === `${base}academic-work/calculations/`) {
            if (options.rejectCalculations)
                return route.fulfill({
                    status: 409,
                    json: {
                        error: "Credits and GPA could not be verified. Ask a registrar to review this record.",
                    },
                });
            if (!selection) response = { status: "needs_selection" };
            else if (options.calculationLimit) response = { status: "limited" };
            else
                response = {
                    status: "review",
                    input_status: options.calculationHistoryGaps ? "history_gaps" : "verified",
                    current_record_count: 21,
                    context: {
                        name: requirements.definition.name,
                        academic_year_code: "2026-2027",
                        version: 1,
                        cohort_changed: Boolean(options.changed),
                    },
                    policy: {
                        repeat_credit: "highest_earned",
                        repeat_gpa: "all_eligible",
                        transfer_overall_gpa: "exclude",
                        attempt_order: options.retainedOrdering || null,
                    },
                    totals: {
                        credit_ready: true,
                        earned_milli: 3000,
                        institutional_minimum_milli: 0,
                        institutional_maximum_milli: 3000,
                        gpa_ready: !options.unknownCalculationGpa,
                        gpa_milli:
                            options.unknownCalculationGpa || options.noGpaCredits
                                ? null
                                : options.zeroCalculationGpa
                                  ? 0
                                  : 3500,
                        gpa_credits_milli: options.unknownCalculationGpa
                            ? null
                            : options.noGpaCredits
                              ? 0
                              : 6000,
                        quality_points_milli: options.unknownCalculationGpa
                            ? null
                            : options.noGpaCredits || options.zeroCalculationGpa
                              ? 0
                              : 21000,
                    },
                    issues: options.unknownCalculationGpa
                        ? [{ component: "gpa", code: "grading_policy_differs" }]
                        : [],
                    records: Array.from({ length: 21 }, (_, index) => ({
                        id: id(100 + index),
                        course_code: index === 20 ? "LAST.101" : `MATH.${100 + index}`,
                        course_title: "Fictional algebra <b>unsafe</b>",
                        ordering_on: options.retainedOrdering ? "2026-08-24" : null,
                        credit_type: options.transferOrdering && index === 0 ? "transfer" : "institutional",
                        credit: index === 20 ? "voided" : "equivalent_credit_alternative",
                        gpa: options.unknownCalculationGpa ? "undetermined" : "included",
                    })),
                };
        } else if (path === `${base}academic-work/verify/`) {
            if (options.rejectInputVerification)
                return route.fulfill({
                    status: 409,
                    json: {
                        error: "Academic records and their history could not be verified. Ask a registrar to review this record.",
                    },
                });
            response = {
                status: options.inputLimit
                    ? "limited"
                    : options.inputHistoryGaps
                      ? "history_gaps"
                      : "verified",
                current_record_count: options.inputLimit ? null : 2,
            };
        } else if (path === `${base}academic-work/` || path === `${base}academic-work/matches/`) {
            if (path.endsWith("/matches/") && options.rejectAcademicMatches)
                return route.fulfill({ status: 403, json: { message: "Access denied." } });
            const record = {
                course_version_id: id(12),
                section_version_id: id(13),
                id: id(40),
                course_id: course,
                course_code: "MATH.101",
                course_title: "Retained algebra",
                credit_type: "institutional",
                term_code: "26/FA",
                term_label_retained: false,
                completed_on: null,
                attempted_milli: 3000,
                completed_milli: 3000,
                quality_points_milli: 12000,
                grade: {
                    code: "A",
                    points_milli: 4000,
                    passes: true,
                    counts_gpa: true,
                },
                grade_recorded: true,
                status: "A",
                history_recorded: true,
                earlier_history_unknown: false,
            };
            if (body?.after && options.rejectAcademicPage)
                return route.fulfill({
                    status: 403,
                    json: { message: "Access denied." },
                });
            response = {
                items: body?.after
                    ? [
                          {
                              ...record,
                              id: id(42),
                              course_code: "ENG.101",
                              course_title: "Fictional late drop <b>unsafe</b>",
                              status: "W",
                              completed_milli: 0,
                              quality_points_milli: 0,
                              grade: null,
                              grade_recorded: false,
                          },
                          {
                              ...record,
                              id: id(43),
                              course_code: "TR.101",
                              credit_type: "transfer",
                              term_label_retained: true,
                              status: "V",
                              completed_milli: 0,
                              quality_points_milli: 0,
                              grade: null,
                              grade_recorded: false,
                          },
                      ]
                    : [
                          record,
                          {
                              ...record,
                              id: id(41),
                              course_code: "OLD.101",
                              course_title: "Observed older work",
                              grade: null,
                              history_recorded: false,
                              earlier_history_unknown: true,
                          },
                      ],
                next_after: body?.after ? null : id(41),
            };
            if (path.endsWith("/matches/")) {
                response.match_limit = 25;
                response.matches_context = selection
                    ? {
                          requirements_version_id: version,
                          version: 1,
                          name: "B.S. Computing <b>fictional</b>",
                          academic_year_code: "2026-2027",
                          cohort_changed: Boolean(options.changed),
                          approval_conflicts: Boolean(options.approvalConflicts),
                          group_reuse: "one_group",
                      }
                    : null;
                response.items = response.items.map((item) => ({
                    ...item,
                    requirement_matches:
                        item.id === id(41)
                            ? []
                            : [
                                  {
                                      group_name: "General education",
                                      required_course_code: "MATH.101",
                                      required_course_title: "Algebra <b>fictional</b>",
                                      via_substitution: item.credit_type === "transfer",
                                      minimum_grade_points_milli: 2700,
                                      status:
                                          item.status === "V"
                                              ? "voided"
                                              : item.status === "W"
                                                ? "dropped"
                                                : "candidate",
                                      explanation:
                                          item.status === "V"
                                              ? "A voided award cannot satisfy a course requirement."
                                              : item.status === "W"
                                                ? "A dropped course cannot satisfy a completed-course requirement."
                                                : "Candidate for allocation; this work has not been applied to the requirement.",
                                  },
                              ],
                    more_requirement_matches: Boolean(options.moreMatches) && item.id === id(40),
                }));
            }
        } else if (path === `${base}choices/`) {
            cursorCount++;
            response = {
                template_id: template,
                items: options.noPublications
                    ? []
                    : [
                          {
                              version_id: version,
                              version: 1,
                              schema_version: options.requirementSchema || 3,
                              assignable: !options.catalogMismatch,
                              assignment_note: options.catalogMismatch
                                  ? "This requirement version belongs to a different program catalog revision."
                                  : "Matches the student's assigned program catalog revision.",
                              name: requirements.definition.name,
                              academic_year_code: "2026-2027",
                          },
                      ],
                next_cursor: options.paging && !body.cursor ? "fictional-next" : null,
            };
        } else if (path === `${base}review/`) {
            if (options.reject)
                return route.fulfill({
                    status: 422,
                    json: { error: "The cohort changed. Review the selection again." },
                });
            response = {
                selection: {
                    pursuit_started_on: body.pursuit_started_on,
                    requirements_version_id: body.requirements_version_id,
                },
                requirements,
                anticipated_completion_on: "2030-08-01",
                reason: body.reason,
                review_token: "fictional-assignment-review",
            };
        } else if (path === `${base}assign/`) {
            selection = { pursuit_started_on: "2026-08-01", requirements_version_id: version };
            response = { operation_id: id(12), last_version: 1 };
        } else if (path === `${base}history/`)
            response = {
                items: selection
                    ? [
                          {
                              ...selection,
                              version: 1,
                              effective_at: "2026-08-10T17:00:00Z",
                              recorded_at: "2026-08-10T17:00:00Z",
                          },
                      ]
                    : [],
                next_cursor: null,
            };
        else if (path === adjustmentBase || path === `${adjustmentBase}preview/`)
            response = {
                ...(path.endsWith("preview/") ? { requirements: selection ? requirements : null } : {}),
                items: activeAdjustments,
                substitution_details: substitutionDetails(),
                targets,
                courses,
                placement_courses: [
                    ...courses,
                    ...activeAdjustments
                        .filter(p => p.applicable && p.definition.kind === "substitution" && p.definition.course_id === course)
                        .map(p => ({
                            course_id: replacement.course_id, code: replacement.code, title: replacement.title,
                            version: replacement.version, substitution_adjustment_id: p.adjustment_id,
                            required_course_code: "MATH.101", required_course_title: "Retained <b>Algebra</b>",
                            groups: ["General education"],
                            placement_groups: [{
                                id: group, name: "General education", course_code: replacement.code,
                                course_title: replacement.title, required_course_code: "MATH.101",
                                required_course_title: "Retained <b>Algebra</b>",
                            }],
                        })),
                ],
                effective_minimums: effective(),
                group_labels: { [group]: "General education" },
                grade_scale: requirements.grade_scale || null,
                selection,
                scope: { status: "active" },
                cohort_changed: Boolean(options.changed),
            };
        else if (path === `${adjustmentBase}replacement-courses/`)
            response = {
                items: [{ id: replacement.course_id, code: "SCI.202", title: "Current catalog label" }],
                next_after: null,
            };
        else if (path === `${adjustmentBase}replacement-courses/${replacement.course_id}/versions/`)
            response = { items: options.unpublishedReplacement ? [] : [replacement], next_cursor: null };
        else if (path === `${adjustmentBase}replacement-courses/${acceptedPublication.course_id}/versions/`)
            response={items:[acceptedPublication],next_cursor:null};
        else if (path === `${adjustmentBase}review/`) {
            const d = body.definition,
                previous = activeAdjustments.find(
                    (p) =>
                        p.definition.kind === d.kind &&
                        (d.kind !== "minimum"
                            ? p.definition.course_id === d.course_id && (d.kind!=="course_version" || p.definition.version_id===d.version_id)
                            : p.definition.group_id === d.group_id && p.definition.minimum === d.minimum),
                );
            const enriched =
                d.kind === "placement"
                    ? {
                          ...d,
                          course_version_id: d.substitution_adjustment_id ? replacement.version_id : id(14),
                          group_version_id: id(8),
                          ...(d.substitution_adjustment_id ? { required_course_id: course } : {}),
                      }
                    : d.kind !== "minimum"
                      ? d
                      : {
                            ...d,
                            original_milli: targets.find(
                                (t) => t.group_id === d.group_id && t.minimum === d.minimum,
                            ).original_milli,
                            group_version_id: d.group_id ? id(8) : null,
                        };
            const p = {
                adjustment_id: id(nextAdjustment++),
                supersedes_adjustment_id: previous?.adjustment_id || null,
                definition: enriched,
                applicable: true,
            };
            response = {
                action: "grant",
                adjustment: p,
                previous: previous || null,
                reason: body.reason,
                affected_groups: d.kind !== "minimum" || d.group_id ? ["General education"] : [],
                group_labels: { [group]: "General education" },
                grade_scale: requirements.grade_scale || null,
                substitution_details: (["substitution","course_version"].includes(d.kind) || (d.kind === "placement" && d.substitution_adjustment_id)) ? { [p.adjustment_id]: d.kind==="course_version" ? acceptedPublication : replacement } : {},
                effective_minimums: effective([...activeAdjustments.filter((x) => x !== previous), p]),
                review_token: `fictional-exception-${p.adjustment_id}`,
            };
            preparedAdjustments.set(response.review_token, response);
        } else if (path === `${adjustmentBase}revoke-review/`) {
            const previous = activeAdjustments.find((p) => p.adjustment_id === body.adjustment_id);
            response = {
                action: "revoke",
                adjustment: { adjustment_id: body.adjustment_id },
                previous,
                reason: body.reason,
                affected_groups: [],
                effective_minimums: effective(activeAdjustments.filter((p) => p !== previous)),
                group_labels: { [group]: "General education" },
                grade_scale: requirements.grade_scale || null,
                review_token: "fictional-revocation",
            };
            preparedAdjustments.set(response.review_token, response);
        } else if (path === `${adjustmentBase}commit/`) {
            const r = preparedAdjustments.get(body.review_token);
            if (!receipts.has(body.review_token)) {
                if (r.action === "revoke")
                    activeAdjustments = activeAdjustments.filter(
                        (p) => p.adjustment_id !== r.adjustment.adjustment_id,
                    );
                else {
                    activeAdjustments = activeAdjustments.filter(
                        (p) => p.adjustment_id !== r.adjustment.supersedes_adjustment_id,
                    );
                    activeAdjustments.push(r.adjustment);
                }
                adjustmentHistory.push({
                    ...r.adjustment,
                    action: r.action,
                    version: adjustmentHistory.length + 1,
                    effective_at: "2026-08-10T17:00:00Z",
                    recorded_at: "2026-08-10T17:00:00Z",
                });
                receipts.add(body.review_token);
            }
            response = { operation_id: id(90), last_version: adjustmentHistory.length };
        } else if (path === `${adjustmentBase}history/`)
            response = { items: adjustmentHistory, next_cursor: null };
        else if (path.startsWith("/sis/ui/data/") || path.startsWith("/sis/ui/context/"))
            response = { items: [], next_after: null };
        if (response === undefined) throw new Error(`Unexpected fixture request: ${path}`);
        await route.fulfill({ json: response, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto(options.readOnly ? "/admin/student-read-fixture/" : "/admin/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator("#directory-search").fill("Alex");
    await page.locator("#directory-search").press("Enter");
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await page.getByRole("button", { name: "Degree requirements", exact: true }).click();
    return {
        adjustmentBase,
        calls,
        errors,
        base,
        template,
        version,
        get cursorCount() {
            return cursorCount;
        },
    };
}

module.exports = { fixture, accessible, id };
