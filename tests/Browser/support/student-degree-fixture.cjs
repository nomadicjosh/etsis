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
        { course_id: course, code: "MATH.101", title: "Algebra", groups: ["General education"] },
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
            substitution_conflicts: {},
        };
        for (const p of items) {
            if (p.applicable === false) continue;
            const d = p.definition;
            if (d.kind === "waiver") out.waived_course_ids.push(d.course_id);
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
    const substitutionDetails = (items = activeAdjustments) =>
        Object.fromEntries(
            items
                .filter((p) => p.definition.kind === "substitution")
                .map((p) => [p.adjustment_id, replacement]),
        );
    let cursorCount = 0;
    page.on("pageerror", (e) => errors.push(e.message));
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
        else if (path === `${base}academic-work/verify/`) {
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
        else if (path === `${adjustmentBase}review/`) {
            const d = body.definition,
                previous = activeAdjustments.find(
                    (p) =>
                        p.definition.kind === d.kind &&
                        (d.kind !== "minimum"
                            ? p.definition.course_id === d.course_id
                            : p.definition.group_id === d.group_id && p.definition.minimum === d.minimum),
                );
            const enriched =
                d.kind !== "minimum"
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
                substitution_details: d.kind === "substitution" ? { [p.adjustment_id]: replacement } : {},
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
