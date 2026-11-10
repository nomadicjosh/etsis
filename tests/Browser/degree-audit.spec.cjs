const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => `01K000000000000000000000${String(n).padStart(2, "0")}`;
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target) }))).toEqual([]);
}
async function fixture(page, options = {}) {
    const calls = [],
        errors = [],
        program = id(1),
        year = id(2),
        template = id(3),
        course = id(4),
        courseVersion = id(5),
        group = id(6),
        groupVersion = id(7);
    let sequence = 20,
        opened = Boolean(options.existing),
        degreeRevision = 0,
        degreePublished = false;
    const scaleDecisions = new Map();
    const scaleData = new Map();
    const blankScale = {
        source: "default",
        program_id: null,
        version_id: null,
        maximum_milli: 4000,
        is_default: true,
        definition: { grades: [] },
    };
    if (options.programMaximum)
        scaleData.set(`/sis/programs/${program}/grading-scale/`, {
            source: "program",
            program_id: program,
            version_id: id(19),
            maximum_milli: options.programMaximum,
            is_default: false,
            definition: {
                grades: [
                    {
                        code: "A+",
                        min_score_milli: 97000,
                        max_score_milli: 100000,
                        points_milli: options.programMaximum,
                        passes: true,
                        counts_gpa: true,
                    },
                ],
            },
        });
    const courses = { [course]: { code: "MATH.101", title: "Algebra", credits_milli: 3000 } };
    const baseGroup = {
        name: "General education",
        min_credits_milli: 3000,
        requirement_type: "credits",
        min_courses: 0,
        min_course_grade_points_milli: null,
        max_course_grade_points_milli: options.maximumPoints ?? 4000,
        grade_scale_program_id: null,
        courses: [{ course_id: course, version_id: courseVersion }],
        rules: [{ id: id(10), mode: "all", minimum_courses: 1, course_ids: [course] }],
    };
    if (options.legacyGroup) {
        delete baseGroup.requirement_type;
        delete baseGroup.min_courses;
        delete baseGroup.min_course_grade_points_milli;
        delete baseGroup.max_course_grade_points_milli;
        delete baseGroup.grade_scale_program_id;
        baseGroup.min_gpa_milli = 2000;
    }
    const scale = { maximum_milli: options.maximumPoints ?? 4000, is_default: options.maximumPoints == null };
    const groups = new Map([
        [
            group,
            {
                id: group,
                revision: 0,
                definition: structuredClone(baseGroup),
                versions: [{ version_id: groupVersion, version: 1, definition: structuredClone(baseGroup) }],
            },
        ],
    ]);
    const context = {
        program_id: program,
        program_title: "Computing",
        program_code: "BSCS",
        academic_year_id: year,
        academic_year_code: "2027-2028",
    };
    let definition = {
        name: "B.S. Computing",
        length_months: null,
        min_credits_milli: 0,
        min_resident_milli: 0,
        min_cumulative_gpa_milli: null,
        min_overall_gpa_milli: null,
        groups: [],
        policies: {
            repeat_credit: null,
            repeat_gpa: null,
            transfer_overall_gpa: null,
            waiver: "waive_required_course_everywhere",
        },
    };
    if (options.existing)
        definition = {
            ...definition,
            min_credits_milli: 120000,
            groups: [{ group_id: group, version_id: groupVersion }],
        };
    if (options.legacy)
        definition = {
            ...definition,
            courses: baseGroup.courses,
            groups: [{ id: id(11), ...baseGroup, courses: undefined }],
        };
    function detail(g, v) {
        const entry = groups.get(g);
        return v
            ? { ...entry.versions.find((x) => x.version_id === v), group_id: g, course_details: courses }
            : { ...entry, course_details: courses, grade_point_scale: scale };
    }
    function row() {
        return {
            id: template,
            program_id: program,
            academic_year_id: year,
            revision: degreeRevision,
            definition,
            context,
            grade_scale:
                scaleData.get(`/sis/programs/${program}/grading-scale/`) ||
                scaleData.get("/sis/grading-scales/global/") ||
                blankScale,
            course_details: courses,
            group_details: Object.fromEntries(
                definition.groups
                    .filter((g) => g.group_id)
                    .map((g) => [g.group_id, detail(g.group_id, g.version_id)]),
            ),
            published_version_id: degreePublished ? id(15) : null,
        };
    }
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname,
            raw = req.postData(),
            body = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        calls.push({ path, body, csrf: req.headers()["x-csrf-token"] });
        let response;
        if (
            path === "/sis/grading-scales/global/" ||
            /^\/sis\/programs\/[^/]+\/grading-scale\/$/.test(path)
        ) {
            response = {
                program_id: path.includes("programs") ? program : null,
                scale: scaleData.get(path) || scaleData.get("/sis/grading-scales/global/") || blankScale,
                global_scale: scaleData.get("/sis/grading-scales/global/") || blankScale,
            };
            return route.fulfill({ json: response });
        }
        if (path.includes("/grading-scale/review/") || path === "/sis/grading-scales/global/review/") {
            const token = `scale-review-${sequence++}`;
            scaleDecisions.set(token, { base: path.replace("review/", ""), definition: body.definition });
            return route.fulfill({ json: { ...body, review_token: token } });
        }
        if (path.includes("/grading-scale/commit/") || path === "/sis/grading-scales/global/commit/") {
            const chosen = scaleDecisions.get(body.review_token);
            if (chosen.definition)
                scaleData.set(chosen.base, {
                    source: chosen.base.includes("programs") ? "program" : "global",
                    program_id: chosen.base.includes("programs") ? program : null,
                    version_id: id(sequence++),
                    maximum_milli: Math.max(...chosen.definition.grades.map((g) => g.points_milli)),
                    is_default: false,
                    definition: chosen.definition,
                });
            else scaleData.delete(chosen.base);
            return route.fulfill({ json: { operation_id: id(sequence++) } });
        }
        if (path === "/sis/academic-years/" && Object.hasOwn(body || {}, "search") && !body.search.trim())
            return route.fulfill({ status: 422, json: { message: "Invalid blank search." } });
        if (path === "/sis/ui/metrics/") response = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith("/sis/ui/data/"))
            response = {
                items: path.includes("/programs/")
                    ? [{ id: program, title: "Computing", code: "BSCS" }]
                    : path.includes("/courses/")
                      ? [{ id: course, ...courses[course] }]
                      : [],
                next_after: null,
            };
        else if (path === "/sis/ui/operation-id/") response = { id: id(sequence++) };
        else if (path === "/sis/academic-years/")
            response = {
                items: [
                    { id: year, code: "2027-2028" },
                    { id: id(8), code: "2028-2029" },
                ],
                next_after: null,
            };
        else if (path === "/sis/course-groups/page/")
            response = {
                grade_point_scale: scale,
                items: [...groups.values()]
                    .filter((g) => !body.published_only || g.versions.length)
                    .map((g) => ({
                        id: g.id,
                        name: g.definition.name,
                        revision: g.revision,
                        published_name: g.versions.at(-1)?.definition.name,
                        published_version_id: g.versions.at(-1)?.version_id,
                    })),
                next_after: null,
            };
        else if (path === "/sis/course-groups/create/") {
            const g = { id: body.id, revision: 0, definition: body.definition, versions: [] };
            groups.set(g.id, g);
            response = { ...g, course_details: {}, grade_point_scale: scale };
        } else if (path.startsWith("/sis/course-groups/")) {
            const [, , , gid, action, version] = path.split("/");
            const g = groups.get(gid);
            if (!g) throw new Error(`Unexpected group ${gid}`);
            if (!action) response = { ...g, course_details: courses, grade_point_scale: scale };
            else if (action === "save") {
                g.definition = body.definition;
                g.revision++;
                response = { ...g, course_details: courses, grade_point_scale: scale };
            } else if (action === "review")
                response = {
                    definition: g.definition,
                    course_details: courses,
                    reason: body.reason,
                    review_token: `group-review-${gid}`,
                };
            else if (action === "publish") {
                if (options.expiredGroupReview && calls.filter((c) => c.path === path).length === 1)
                    return route.fulfill({
                        status: 409,
                        json: { error: "This group publication review expired. Prepare a fresh review." },
                    });
                const pub = {
                    version_id: id(sequence++),
                    version: g.versions.length + 1,
                    definition: structuredClone(g.definition),
                };
                g.versions.push(pub);
                response = { operation_id: pub.version_id, last_version: pub.version };
            } else if (action === "versions")
                response = version ? detail(gid, version) : { items: g.versions, next_cursor: null };
        } else if (path.endsWith("/publication/page/"))
            response = {
                items: [
                    { version_id: courseVersion, version: 1, definition: courses[course] },
                    ...(options.newCourseVersion
                        ? [
                              {
                                  version_id: id(16),
                                  version: 2,
                                  definition: {
                                      ...courses[course],
                                      title: "Advanced Algebra",
                                      credits_milli: 4000,
                                  },
                              },
                          ]
                        : []),
                ],
                next_cursor: null,
            };
        else if (path.endsWith("/degree-audits/page/"))
            response = {
                items: opened ? [{ ...row(), academic_year_code: context.academic_year_code }] : [],
                next_after: null,
            };
        else if (path.endsWith("/degree-audits/open/")) {
            opened = true;
            response = row();
        } else if (path === `/sis/degree-audits/${template}/`) response = row();
        else if (path === `/sis/degree-audits/${template}/save/`) {
            definition = body.definition;
            degreeRevision++;
            response = row();
        } else if (path === `/sis/degree-audits/${template}/reuse-groups/`) {
            const gid = id(sequence++);
            groups.set(gid, { id: gid, definition: structuredClone(baseGroup), revision: 0, versions: [] });
            delete definition.courses;
            definition.groups = [{ group_id: gid, version_id: null }];
            degreeRevision++;
            response = row();
        } else if (path === `/sis/degree-audits/${template}/review/`) {
            if (!definition.groups.length || Object.values(definition.policies).includes(null))
                return route.fulfill({
                    status: 422,
                    json: { error: "Choose published groups and institutional policies before publication." },
                });
            response = {
                ...row(),
                reason: body.reason,
                review_token: "fictional-degree-review",
                program_publication: options.linkedProgram
                    ? {
                          version_id: id(18),
                          catalog_revision: 1,
                          academic_year_code: "2027-2028",
                          definition: {
                              code: "BSCS",
                              title: "Published Computer Science",
                              description: null,
                              classifications: { department: null, academic_level: null, award: null },
                          },
                      }
                    : null,
            };
        } else if (path === `/sis/degree-audits/${template}/publish/`) {
            if (options.expiredReview && calls.filter((c) => c.path === path).length === 1)
                return route.fulfill({
                    status: 409,
                    json: { error: "This publication review expired. Prepare a fresh review." },
                });
            degreePublished = true;
            response = { operation_id: id(15), last_version: 1 };
        } else if (path === `/sis/degree-audits/${template}/versions/`)
            response = {
                items: degreePublished ? [{ version_id: id(15), version: 1, definition, context }] : [],
                next_cursor: null,
            };
        else if (path === `/sis/degree-audits/${template}/versions/${id(15)}/`) response = row();
        else if (path.endsWith("/clone/"))
            response = {
                ...row(),
                id: id(9),
                academic_year_id: id(8),
                context: { ...context, academic_year_code: "2028-2029" },
                published_version_id: null,
                revision: 0,
            };
        else if (path.includes("/sis/ui/operations/")) response = { items: [], next_after: null };
        if (response === undefined) throw new Error(`Unexpected fixture request ${path}`);
        await route.fulfill({ json: response, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/");
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    return { calls, errors, program, template, course, courseVersion, group, groupVersion, groups };
}
async function openDegree(page, existing = false) {
    await page.getByRole("link", { name: "Programs", exact: true }).click();
    await page.getByRole("button", { name: "Computing", exact: true }).click();
    await page.getByRole("button", { name: "Degree audit requirements", exact: true }).click();
    if (existing)
        await page.getByRole("button", { name: "Edit 2027-2028 requirements", exact: true }).click();
}
async function attachGroup(page) {
    await page.locator(".audit-source-card > summary").filter({ hasText: "General education" }).click();
    await page.getByRole("checkbox", { name: /Version 1 · General education/ }).check();
    await page.getByRole("button", { name: "Add checked blocks", exact: true }).click();
}
test("independent course group uses cards, checkboxes, requirement types and reviewed publication", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Course group library", exact: true }).click();
    await page.getByRole("button", { name: "Create course group", exact: true }).click();
    await page.getByLabel("Course group name", { exact: true }).fill("Shared core <b>fictional</b>");
    await page.getByRole("button", { name: "Create group draft", exact: true }).click();
    await page.getByLabel("Group required credits", { exact: true }).fill("3");
    await page.getByRole("button", { name: "Add requirement", exact: true }).click();
    await page.locator(".audit-source-card > summary").filter({ hasText: "MATH.101" }).click();
    await page.getByRole("checkbox", { name: /Version 1 · MATH.101/ }).check();
    await page.getByRole("button", { name: "Add checked courses", exact: true }).click();
    await expect(page.locator(".audit-canvas")).toContainText("MATH.101 · Algebra");
    await page.getByRole("button", { name: "Choose a number", exact: true }).click();
    await page.getByLabel("Requirement 1 number of courses", { exact: true }).fill("1");
    await page.getByRole("button", { name: "Eligible courses", exact: true }).click();
    await expect(page.getByRole("button", { name: "Eligible courses", exact: true })).toHaveAttribute(
        "aria-pressed",
        "true",
    );
    await page.getByRole("button", { name: "Add requirement", exact: true }).click();
    const move = page.getByRole("button", { name: "Move requirement 2 up", exact: true });
    await move.focus();
    await page.keyboard.press("Enter");
    await expect(move).toBeFocused();
    await page.getByRole("button", { name: "Remove requirement 1", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Save & review publication", exact: true }).click();
    await page.getByLabel("Reason for publication", { exact: true }).fill("Fictional shared block approval");
    await page.getByRole("button", { name: "Prepare publication", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Eligible courses");
    await expect(page.getByRole("dialog")).toContainText("Shared core <b>fictional</b>");
    expect(f.calls.some((c) => c.path.endsWith("/publish/"))).toBe(false);
    await accessible(page);
    await page.getByRole("button", { name: "Publish reviewed version", exact: true }).click();
    await page.getByRole("button", { name: "Inspect version 1", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("MATH.101 · Algebra");
    const create = f.calls.find((c) => c.path === "/sis/course-groups/create/");
    expect(create.body.definition).not.toHaveProperty("program_id");
    const save = f.calls.find((c) => c.path.includes("/course-groups/") && c.path.endsWith("/save/"));
    expect(save).toMatchObject({
        body: {
            definition: {
                min_credits_milli: 3000,
                courses: [{ course_id: f.course, version_id: f.courseVersion }],
                rules: [{ mode: "pool", minimum_courses: 0 }],
            },
        },
        csrf: "fictional-test-token",
    });
    expect(f.errors).toEqual([]);
});
test("degree composer selects pinned blocks, keeps unsaved fields while editing the library and publishes", async ({
    page,
}, testInfo) => {
    const f = await fixture(page, { linkedProgram: true });
    await openDegree(page);
    await page.getByRole("button", { name: "Set up an academic year", exact: true }).click();
    expect(f.calls.find((c) => c.path === "/sis/academic-years/").body).toEqual({});
    await page.getByLabel("Academic year search", { exact: true }).fill("  2027  ");
    await page.getByRole("dialog").getByRole("button", { name: "Search", exact: true }).click();
    expect(f.calls.filter((c) => c.path === "/sis/academic-years/").at(-1).body).toEqual({ search: "2027" });
    await page.getByLabel("Academic year search", { exact: true }).fill("  ");
    await page.getByRole("dialog").getByRole("button", { name: "Search", exact: true }).click();
    await page.getByRole("button", { name: "Use 2027-2028", exact: true }).click();
    await page
        .getByLabel("Requirement title", { exact: true })
        .fill("B.S. Computing <script>fictional()</script>");
    await page.getByLabel("Total earned credits required", { exact: true }).fill("120");
    await page.getByLabel("Institutional resident credits required", { exact: true }).fill("30");
    await page.getByLabel("Highest earned award per course", { exact: true }).check();
    await page.getByLabel("All eligible graded attempts", { exact: true }).check();
    await page.getByLabel("Exclude transfer awards", { exact: true }).check();
    await attachGroup(page);
    await page.getByRole("button", { name: "Edit block 1 in library", exact: true }).click();
    await page.getByLabel("Course group name", { exact: true }).fill("Changed working group");
    await page.getByRole("button", { name: "Save group draft", exact: true }).click();
    await expect(page.locator(".audit-status")).toContainText("Course group draft saved.");
    await page.getByRole("button", { name: "Return to degree draft", exact: true }).click();
    await expect(page.getByLabel("Requirement title", { exact: true })).toHaveValue(
        "B.S. Computing <script>fictional()</script>",
    );
    await expect(page.locator(".audit-canvas")).toContainText("General education");
    await expect(page.locator(".audit-canvas")).not.toContainText("Changed working group");
    await page.screenshot({ path: testInfo.outputPath("degree-builder.png") });
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Save & review publication", exact: true }).click();
    await page.getByLabel("Reason for publication", { exact: true }).fill("Fictional degree approval");
    await page.getByRole("button", { name: "Prepare publication", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("120 total credits");
    await expect(page.getByRole("dialog")).toContainText("Linked program: BSCS — Published Computer Science");
    await expect(page.getByRole("dialog")).toContainText("General education");
    await accessible(page);
    await page.getByRole("button", { name: "Publish reviewed version", exact: true }).click();
    await page.getByRole("button", { name: "Inspect version 1", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("MATH.101 · Algebra");
    const save = f.calls.find((c) => c.path === `/sis/degree-audits/${f.template}/save/`);
    expect(save).toMatchObject({
        body: {
            definition: {
                groups: [{ group_id: f.group, version_id: f.groupVersion }],
                min_credits_milli: 120000,
            },
        },
        csrf: "fictional-test-token",
    });
    expect(save.body.definition).not.toHaveProperty("courses");
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).toHaveCount(0);
    expect(
        await page.evaluate(
            () =>
                document.activeElement !== document.body &&
                document.activeElement.getClientRects().length > 0,
        ),
    ).toBe(true);
});
test("dragging published blocks and courses has the same result as keyboard selection", async ({ page }) => {
    const f = await fixture(page, { existing: true });
    await openDegree(page, true);
    await page.getByRole("button", { name: "Remove block 1", exact: true }).click();
    await page.locator(".audit-source-card > summary").click();
    await page.locator(".audit-version-card").dragTo(page.locator(".audit-panel").nth(1));
    await expect(page.locator(".audit-canvas")).toContainText("General education");
    await page.getByRole("button", { name: "Edit block 1 in library", exact: true }).click();
    await page.getByRole("button", { name: "Add requirement", exact: true }).click();
    await page.locator(".audit-source-card > summary").click();
    await page.locator(".audit-version-card").dragTo(page.locator(".audit-rule-card").nth(1));
    await expect(page.locator(".audit-rule-card").nth(1)).toContainText("MATH.101 · Algebra");
    await page.getByRole("button", { name: "Save group draft", exact: true }).click();
    expect(f.calls.filter((c) => c.path.endsWith("/save/")).at(-1).body.definition.rules).toHaveLength(2);
    expect(f.errors).toEqual([]);
});
test("legacy inline draft conversion is explicit and publication errors stay in the builder", async ({
    page,
}) => {
    const f = await fixture(page, { existing: true, legacy: true });
    await openDegree(page, true);
    await expect(page.getByRole("dialog")).toContainText("this step publishes nothing");
    await expect(page.getByRole("group", { name: "Group planning summary", exact: true })).toContainText(
        "1 distinct course · 3 catalog credits",
    );
    expect(f.calls.some((c) => c.path.endsWith("/reuse-groups/"))).toBe(false);
    await page.getByRole("button", { name: "Move draft groups into the library", exact: true }).click();
    await expect(page.locator(".audit-canvas")).toContainText("Unpublished group");
    await page.getByRole("button", { name: "Save & review publication", exact: true }).click();
    await page.getByLabel("Reason for publication", { exact: true }).fill("Fictional test");
    await page.getByRole("button", { name: "Prepare publication", exact: true }).click();
    await expect(page.getByRole("dialog").getByRole("alert")).toContainText(
        "Choose published groups and institutional policies",
    );
    await expect(page.getByLabel("Reason for publication", { exact: true })).toHaveValue("Fictional test");
    expect(f.calls.some((c) => c.path.endsWith("/publish/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("selected published requirements clone to another year with the same block references", async ({
    page,
}) => {
    const f = await fixture(page, { existing: true });
    await openDegree(page, true);
    await page.getByLabel("Highest earned award per course", { exact: true }).check();
    await page.getByLabel("All eligible graded attempts", { exact: true }).check();
    await page.getByLabel("Exclude transfer awards", { exact: true }).check();
    await page.getByRole("button", { name: "Save & review publication", exact: true }).click();
    await page.getByLabel("Reason for publication", { exact: true }).fill("Fictional clone source");
    await page.getByRole("button", { name: "Prepare publication", exact: true }).click();
    await page.getByRole("button", { name: "Publish reviewed version", exact: true }).click();
    await page.getByRole("button", { name: "Clone version 1 to another academic year", exact: true }).click();
    expect(f.calls.filter((c) => c.path === "/sis/academic-years/").at(-1).body).toEqual({});
    await page.getByRole("button", { name: "Use 2028-2029", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("2028-2029 · Computing");
    await expect(page.locator(".audit-canvas")).toContainText("Pinned version 1");
    expect(f.calls.find((c) => c.path.endsWith("/clone/"))).toMatchObject({
        body: { version_id: id(15), program_id: f.program, academic_year_id: id(8) },
        csrf: "fictional-test-token",
    });
    expect(f.calls.filter((c) => c.path.endsWith("/publish/"))).toHaveLength(1);
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test("course version replacement is reviewed across the working group without changing publications", async ({
    page,
}) => {
    const f = await fixture(page, { newCourseVersion: true });
    await page.getByRole("button", { name: "Course group library", exact: true }).click();
    await page.getByRole("button", { name: "Build General education", exact: true }).click();
    const planning = page.getByRole("group", { name: "Group planning summary", exact: true });
    await expect(planning).toContainText("3 catalog credits");
    await page.getByLabel("Course group name", { exact: true }).fill("Unsaved new name");
    await page.locator(".audit-source-card > summary").click();
    await page.getByRole("checkbox", { name: /Version 2 · MATH.101/ }).check();
    await page.getByRole("button", { name: "Add checked courses", exact: true }).click();
    await expect(
        page.getByRole("group", { name: "Review course version changes", exact: true }),
    ).toContainText("3 → 4 credits. Used in requirements 1.");
    expect(f.calls.some((c) => c.path.endsWith("/save/") || c.path.endsWith("/publish/"))).toBe(false);
    await expect(planning).toContainText("3 catalog credits");
    await accessible(page);
    await page.getByRole("button", { name: "Adopt selected course versions", exact: true }).click();
    await expect(planning).toContainText("4 catalog credits");
    await expect(page.locator(".audit-canvas")).toContainText("MATH.101 · Advanced Algebra");
    await expect(page.getByLabel("Course group name", { exact: true })).toHaveValue("Unsaved new name");
    await page.getByRole("button", { name: "Save group draft", exact: true }).click();
    expect(f.calls.find((c) => c.path.endsWith("/save/"))).toMatchObject({
        body: { definition: { courses: [{ course_id: f.course, version_id: id(16) }] } },
    });
    expect(f.groups.get(f.group).versions[0].definition.courses[0].version_id).toBe(f.courseVersion);
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test("group quantity switches between credits and course count with an individual grade-point floor", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Course group library", exact: true }).click();
    await page.getByRole("button", { name: "Build General education", exact: true }).click();
    await page.getByRole("button", { name: "Require courses", exact: true }).click();
    await page.getByLabel("Number of courses required", { exact: true }).fill("1");
    await page.getByLabel("Minimum grade points for each course (optional)", { exact: true }).fill("2");
    await expect(page.getByLabel("Group required credits", { exact: true })).toBeHidden();
    await expect(page.getByLabel("Group minimum GPA (optional)", { exact: true })).toHaveCount(0);
    await page.getByRole("button", { name: "Save group draft", exact: true }).click();
    const save = f.calls.find((c) => c.path.includes("/course-groups/") && c.path.endsWith("/save/"));
    expect(save.body.definition).toMatchObject({
        requirement_type: "courses",
        min_courses: 1,
        min_credits_milli: 0,
        min_course_grade_points_milli: 2000,
    });
    expect(save.body.definition).not.toHaveProperty("min_gpa_milli");
    await page.getByRole("button", { name: "Requirements preview", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("1 course");
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "at least 2 out of 4.0 grade points",
    );
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test("automatic grade scale uses configured maximum and leaves the minimum to the institution", async ({
    page,
}) => {
    const f = await fixture(page, { maximumPoints: 5000 });
    await page.getByRole("button", { name: "Course group library", exact: true }).click();
    await page.getByRole("button", { name: "Build General education", exact: true }).click();
    await expect(page.getByLabel("Maximum grade points", { exact: true })).toContainText(
        "Out of 5.0 grade points · From grade definitions",
    );
    const minimum = page.getByLabel("Minimum grade points for each course (optional)", { exact: true });
    await expect(minimum).toHaveValue("");
    await expect(minimum).toHaveAttribute("max", "5");
    await minimum.fill("5.1");
    await page.getByRole("button", { name: "Save group draft", exact: true }).click();
    await expect(page.locator("dialog [role=alert]")).toContainText("cannot exceed");
    expect(f.calls.some((c) => c.path.endsWith("/save/"))).toBe(false);
    await minimum.fill("3.5");
    await page.getByRole("button", { name: "Save group draft", exact: true }).click();
    expect(f.calls.find((c) => c.path.endsWith("/save/")).body.definition).toMatchObject({
        min_course_grade_points_milli: 3500,
        max_course_grade_points_milli: 5000,
    });
    await page.getByRole("button", { name: "Requirements preview", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("at least 3.5 out of 5.0 grade points");
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test("earlier average group GPA is never copied into a per-course grade-point threshold", async ({
    page,
}) => {
    const f = await fixture(page, { legacyGroup: true });
    await page.getByRole("button", { name: "Course group library", exact: true }).click();
    await page.getByRole("button", { name: "Build General education", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Earlier average group GPA: 2");
    await page.getByRole("button", { name: "Use credit/course settings", exact: true }).click();
    await expect(
        page.getByLabel("Minimum grade points for each course (optional)", { exact: true }),
    ).toHaveValue("");
    await expect(page.getByLabel("Maximum grade points", { exact: true })).toContainText(
        "Out of 4.0 grade points · Default until grade definitions are configured",
    );
    await page.getByRole("button", { name: "Save group draft", exact: true }).click();
    const definition = f.calls.find((c) => c.path.endsWith("/save/")).body.definition;
    expect(definition).toMatchObject({
        requirement_type: "credits",
        min_course_grade_points_milli: null,
        max_course_grade_points_milli: 4000,
    });
    expect(definition).not.toHaveProperty("min_gpa_milli");
    expect(f.groups.get(f.group).versions[0].definition.min_gpa_milli).toBe(2000);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test("global scale editor distinguishes score percentages from GPA points and explicitly approves", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Grading scales", exact: true }).click();
    await page.getByRole("button", { name: "Add grade", exact: true }).click();
    await page.getByLabel("Grade", { exact: true }).fill("A+");
    await page.getByLabel("Minimum score (%)", { exact: true }).fill("97");
    await page.getByLabel("Maximum score (%)", { exact: true }).fill("100");
    await page.getByLabel("Grade points", { exact: true }).fill("4");
    await page.getByLabel("Earns course credit", { exact: true }).check();
    await page.getByLabel("Count in GPA", { exact: true }).check();
    await page
        .getByLabel("Reason for grading scale change", { exact: true })
        .fill("Fictional institutional scale");
    await page.getByRole("button", { name: "Review grading scale", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("A+: 97–100% · 4 grade points");
    expect(f.calls.some((c) => c.path.endsWith("grading-scales/global/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Change scale review", exact: true }).click();
    await expect(page.getByLabel("Minimum score (%)", { exact: true })).toHaveValue("97");
    await expect(page.getByLabel("Reason for grading scale change", { exact: true })).toHaveValue(
        "Fictional institutional scale",
    );
    await page.setViewportSize({ width: 390, height: 844 });
    await accessible(page);
    await page.getByRole("button", { name: "Review grading scale", exact: true }).click();
    await page.getByRole("button", { name: "Approve reviewed grading scale", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Draft maximum: 4.0 grade points.");
    expect(
        f.calls.find((c) => c.path.endsWith("grading-scales/global/review/")).body.definition.grades[0],
    ).toEqual({
        code: "A+",
        min_score_milli: 97000,
        max_score_milli: 100000,
        points_milli: 4000,
        passes: true,
        counts_gpa: true,
    });
    expect(f.calls.filter((c) => c.path.endsWith("grading-scales/global/commit/"))).toHaveLength(1);
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test("program scale explicitly switches from global inheritance to an independent override", async ({
    page,
}) => {
    const f = await fixture(page, { existing: true });
    await openDegree(page, true);
    await page.getByRole("button", { name: "Program grading scale", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Using the institution’s global scale.");
    await page.getByRole("button", { name: "Create program override", exact: true }).click();
    await page.getByRole("button", { name: "Add grade", exact: true }).click();
    await page.getByLabel("Grade", { exact: true }).fill("A+");
    await page.getByLabel("Minimum score (%)", { exact: true }).fill("97");
    await page.getByLabel("Maximum score (%)", { exact: true }).fill("100");
    await page.getByLabel("Grade points", { exact: true }).fill("5");
    await page.getByLabel("Earns course credit", { exact: true }).check();
    await page
        .getByLabel("Reason for grading scale change", { exact: true })
        .fill("Fictional graduate policy");
    await page.getByRole("button", { name: "Review grading scale", exact: true }).click();
    await page.getByRole("button", { name: "Approve reviewed grading scale", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Draft maximum: 5.0 grade points.");
    expect(f.calls.filter((c) => c.path.endsWith("grading-scales/global/commit/"))).toHaveLength(0);
    await page.getByRole("button", { name: "Use global scale", exact: true }).click();
    await page
        .getByLabel("Reason for grading scale change", { exact: true })
        .fill("Fictional restore global policy");
    await page.getByRole("button", { name: "Review grading scale", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Restore this program’s use of the global scale.");
    await page.getByRole("button", { name: "Approve reviewed grading scale", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Using the institution’s global scale.");
    expect(
        f.calls.filter((c) => c.path.endsWith(`/programs/${f.program}/grading-scale/commit/`)),
    ).toHaveLength(2);
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test("a reusable group can use a program grading context without becoming program-owned", async ({
    page,
}) => {
    const f = await fixture(page, { programMaximum: 5000 });
    await page.getByRole("button", { name: "Course group library", exact: true }).click();
    await page.getByRole("button", { name: "Build General education", exact: true }).click();
    await page.getByRole("button", { name: "Choose grading context", exact: true }).click();
    await page.getByRole("button", { name: "Use BSCS grading context", exact: true }).click();
    await expect(page.getByLabel("Maximum grade points", { exact: true })).toContainText(
        "Out of 5.0 grade points · Program grading scale",
    );
    await page.getByLabel("Minimum grade points for each course (optional)", { exact: true }).fill("4.5");
    await page.getByRole("button", { name: "Save group draft", exact: true }).click();
    expect(f.calls.find((c) => c.path.endsWith("/save/")).body.definition).toMatchObject({
        grade_scale_program_id: f.program,
        max_course_grade_points_milli: 5000,
        min_course_grade_points_milli: 4500,
    });
    expect(f.calls.find((c) => c.path.endsWith("/save/")).body).not.toHaveProperty("program_id");
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test("degree preview exposes the resolved program scale with its separate score range and GPA points", async ({
    page,
}) => {
    const f = await fixture(page, { existing: true, programMaximum: 5000 });
    await openDegree(page, true);
    await page.getByRole("button", { name: "Requirements preview", exact: true }).click();
    await page.getByText("Program grading scale · Maximum 5.0 grade points", { exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("A+: 97–100% · 5 grade points · Counts in GPA");
    expect(f.calls.some((c) => c.path.endsWith("/publish/"))).toBe(false);
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test("expired degree publication can be refreshed with its reason and exact definition intact", async ({
    page,
}) => {
    const f = await fixture(page, { existing: true, expiredReview: true });
    await openDegree(page, true);
    await page.getByLabel("Highest earned award per course", { exact: true }).check();
    await page.getByLabel("All eligible graded attempts", { exact: true }).check();
    await page.getByLabel("Exclude transfer awards", { exact: true }).check();
    await page.getByRole("button", { name: "Save & review publication", exact: true }).click();
    await page.getByLabel("Reason for publication", { exact: true }).fill("Fictional renewed approval");
    await page.getByRole("button", { name: "Prepare publication", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Confirm within 20 minutes");
    await page.getByRole("button", { name: "Publish reviewed version", exact: true }).click();
    const alert = page.getByRole("dialog").getByRole("alert");
    await expect(alert).toContainText("publication review expired");
    await expect(alert).toBeFocused();
    await page.getByRole("button", { name: "Refresh publication review", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Confirm reviewed publication", exact: true }),
    ).toBeVisible();
    await expect(page.getByRole("dialog")).toContainText("Fictional renewed approval");
    await expect(page.getByRole("dialog")).toContainText("General education");
    const reviews = f.calls.filter((c) => c.path.endsWith(`/degree-audits/${f.template}/review/`));
    expect(reviews).toHaveLength(2);
    expect(reviews.map((c) => c.body)).toEqual([
        { reason: "Fictional renewed approval" },
        { reason: "Fictional renewed approval" },
    ]);
    expect(f.calls.filter((c) => c.path.endsWith(`/degree-audits/${f.template}/save/`))).toHaveLength(1);
    await accessible(page);
    await page.getByRole("button", { name: "Publish reviewed version", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Published requirement versions", exact: true }),
    ).toBeVisible();
    expect(f.errors).toEqual([]);
});

test("expired course group review refreshes without saving or publishing until confirmed", async ({
    page,
}) => {
    const f = await fixture(page, { expiredGroupReview: true });
    await page.getByRole("button", { name: "Course group library", exact: true }).click();
    await page.getByRole("button", { name: "Build General education", exact: true }).click();
    await page.getByRole("button", { name: "Save & review publication", exact: true }).click();
    await page.getByLabel("Reason for publication", { exact: true }).fill("Fictional renewed group approval");
    await page.getByRole("button", { name: "Prepare publication", exact: true }).click();
    await expect(page.getByRole("dialog")).toContainText("Confirm within 20 minutes");
    await page.getByRole("button", { name: "Publish reviewed version", exact: true }).click();
    const alert = page.getByRole("dialog").getByRole("alert");
    await expect(alert).toContainText("group publication review expired");
    await expect(alert).toBeFocused();
    f.groups.get(f.group).definition.name = "Fictional shared core after staff review";
    await page.getByRole("button", { name: "Refresh publication review", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Confirm reviewed publication", exact: true }),
    ).toBeVisible();
    await expect(page.getByRole("dialog")).toContainText("Fictional renewed group approval");
    await expect(page.getByRole("dialog")).toContainText("Fictional shared core after staff review");
    await expect(page.getByRole("dialog")).toContainText("MATH.101 · Algebra");
    const reviews = f.calls.filter((c) => c.path === `/sis/course-groups/${f.group}/review/`);
    expect(reviews).toHaveLength(2);
    expect(reviews.map((c) => c.body)).toEqual([
        { reason: "Fictional renewed group approval" },
        { reason: "Fictional renewed group approval" },
    ]);
    expect(f.calls.filter((c) => c.path === `/sis/course-groups/${f.group}/save/`)).toHaveLength(1);
    expect(f.groups.get(f.group).versions).toHaveLength(1);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Publish reviewed version", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Published requirement versions", exact: true }),
    ).toBeVisible();
    expect(f.groups.get(f.group).versions).toHaveLength(2);
    expect(f.errors).toEqual([]);
});

test("group planning updates quantities and counts a shared course once without background writes", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Course group library", exact: true }).click();
    await page.getByRole("button", { name: "Build General education", exact: true }).click();
    const plan = page.getByRole("group", { name: "Group planning summary", exact: true });
    await expect(plan).toContainText("1 distinct course · 3 catalog credits · 1 requirement card");
    const credits = page.getByLabel("Group required credits", { exact: true });
    await credits.fill("4.5");
    await expect(plan).toContainText("required credits exceed the catalog credits");
    await expect(credits).toBeFocused();
    await credits.fill("");
    await expect(plan).toContainText("Enter a valid required total");
    await credits.fill("3");
    await expect(plan).not.toContainText("required credits exceed");
    await page.getByRole("button", { name: "Require courses", exact: true }).click();
    await page.getByLabel("Number of courses required", { exact: true }).fill("2");
    await expect(plan).toContainText("required course count exceeds");
    await page.getByLabel("Number of courses required", { exact: true }).fill("1");
    await expect(plan).not.toContainText("required course count exceeds");
    await page.getByRole("button", { name: "Add requirement", exact: true }).click();
    await page.locator(".audit-source-card > summary").click();
    await page.getByRole("checkbox", { name: /Version 1 · MATH.101/ }).check();
    await page.getByRole("button", { name: "Add checked courses", exact: true }).click();
    await expect(plan).toContainText("1 distinct course · 3 catalog credits · 2 requirement cards");
    await page.getByRole("button", { name: "Remove MATH.101 from requirement 1", exact: true }).click();
    await expect(plan).toContainText("1 distinct course · 3 catalog credits");
    await page.getByRole("button", { name: "Remove MATH.101 from requirement 2", exact: true }).click();
    await expect(plan).toContainText("0 distinct courses · 0 catalog credits");
    await expect(plan).toContainText("Add published courses to plan this group");
    expect(f.calls.some((call) => /\/(save|review|publish)\/$/.test(call.path))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});
