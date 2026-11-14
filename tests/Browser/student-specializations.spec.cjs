const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");

async function fixture(
    page,
    {
        readOnly = false,
        adjustments = false,
        cohortChanged = false,
        unknown = false,
        reject = false,
        rejectRequirements = false,
        assigned = false,
        progressStatus = "review",
    } = {},
) {
    const calls = [],
        errors = [];
    const student = id(1),
        assignment = id(2),
        programId = id(3),
        person = id(4);
    const base = `/sis/students/${student}/programs/${assignment}/specializations/`;
    const offered = Array.from({ length: 23 }, (_, n) => ({
        id: id(100 + n),
        code: `CHOICE.${n + 1}`,
        title: n === 0 ? "Original <b>Art</b>" : `Fictional choice ${n + 1}`,
        description: "Plain <script>example</script> text.",
        revision: 1,
    }));
    const program = {
        definition: { code: "BA.ART", title: "Fictional arts" },
        academic_year_code: "2024-2025",
        catalog_revision: 1,
        specializations: { effective: { minimum: 0, maximum: 2 }, offered },
    };
    const summary = (ids) => ({
        program_version_id: id(10),
        program_code: "BA.ART",
        program_title: "Fictional arts",
        academic_year_code: "2024-2025",
        catalog_revision: 1,
        limits: { minimum: 0, maximum: 2 },
        specializations: offered.filter((item) => ids.includes(item.id)),
    });
    let selected = summary([offered[0].id]),
        prepared = null;
    const requirementsPath = `${base}${offered[0].id}/requirements/`;
    const requirements = {
        version_id: id(70),
        version: 1,
        specialization: offered[0],
        program_publication: {
            ...program,
            definition: {
                ...program.definition,
                classifications: {
                    department: null,
                    academic_level: null,
                    award: null,
                },
            },
        },
        definition: {
            name: "Fictional <b>Art</b> requirements",
            min_credits_milli: 6000,
            min_resident_milli: 3000,
            groups: [],
            policies: { group_reuse: "one_group" },
        },
    };
    const reference = {
        requirements_name: requirements.definition.name,
        requirements_version: 1,
    };
    let binding = assigned ? reference : null;
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw
            ? JSON.parse(new URLSearchParams(raw).get("_workspace_json"))
            : null;
        if (data)
            calls.push({
                path,
                data,
                csrf: route.request().headers()["x-csrf-token"],
            });
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/")
            result = {
                items: [
                    {
                        id: student,
                        person_id: person,
                        given_name: "Alex",
                        family_name: "Example",
                        institutional_id: "S-FICTIONAL",
                        active: true,
                    },
                ],
                next_after: null,
            };
        else if (path.endsWith("/record/"))
            result = {
                id: student,
                person_id: person,
                given_name: "Alex",
                family_name: "Example",
                institutional_id: "S-FICTIONAL",
                active: true,
                primary_program: {
                    id: programId,
                    code: "BA.ART",
                    title: "Fictional arts",
                },
                entry_term: { code: "24/FA" },
            };
        else if (path === `/sis/students/${student}/programs/`)
            result = {
                items: [
                    {
                        id: assignment,
                        program_id: programId,
                        program_code: "BA.ART",
                        program_title: "Fictional arts",
                        term_code: "24/FA",
                        catalog_year: "2024-2025",
                        catalog_revision: 1,
                        status: readOnly ? "closed" : "active",
                        is_primary: true,
                    },
                ],
                next_after: null,
            };
        else if (path === requirementsPath)
            result = {
                selection: binding,
                requirements: binding ? requirements : null,
                cohort_changed: cohortChanged,
                can_manage: !readOnly && !cohortChanged,
            };
        else if (path === requirementsPath + "progress/")
            result =
                progressStatus === "review"
                    ? {
                          status: "review",
                          mode: "assigned_specialization",
                          input_status: "verified",
                          current_record_count: 0,
                          context: {
                              mode: "assigned_specialization",
                              specialization: offered[0],
                              name: requirements.definition.name,
                              academic_year_code: "2024-2025",
                              version: 1,
                              group_reuse: "one_group",
                              cohort_changed: cohortChanged,
                          },
                          minimums: [
                              {
                                  key: "min_credits_milli",
                                  required_milli: 6000,
                                  published_milli: 6000,
                                  actual_minimum_milli: 0,
                                  actual_maximum_milli: 0,
                                  status: "below_minimum",
                                  adjusted: false,
                              },
                          ],
                          gpa: {
                              cumulative: { ready: false },
                              overall: { ready: false },
                          },
                          work_summary: {
                              awaiting_grade_count: 0,
                              awaiting_grade_milli: 0,
                              placement_established: false,
                              unused_completed_count: 0,
                          },
                          records: [],
                          issues: [],
                          allocation: {
                              outcome: "infeasible",
                              groups: [],
                              records: [],
                              issues: [],
                              competition: null,
                          },
                          policy: {
                              repeat_credit: "highest_earned",
                              repeat_gpa: "all_eligible",
                              transfer_overall_gpa: "exclude",
                              attempt_order: "academic_period",
                          },
                      }
                    : {
                          status: progressStatus,
                          mode: "assigned_specialization",
                      };
        else if (path === requirementsPath + "choices/")
            result = {
                specialization: offered[0],
                template_id: id(60),
                revision: "requirements-revision",
                current: binding,
                next_cursor: data.cursor ? null : "requirements-page-2",
                items: data.cursor
                    ? []
                    : [
                          {
                              name: requirements.definition.name,
                              version: 1,
                              version_id: id(70),
                              assignable: true,
                              assignment_note:
                                  "Matches this pursuit’s retained program revision.",
                          },
                          {
                              name: "Different program revision",
                              version: 2,
                              version_id: id(71),
                              assignable: false,
                              assignment_note:
                                  "This publication belongs to a different program revision.",
                          },
                      ],
            };
        else if (path === requirementsPath + "review/") {
            if (rejectRequirements)
                return route.fulfill({
                    status: 409,
                    json: {
                        error: "The requirements changed. Review again.",
                    },
                });
            result = {
                before: binding,
                requirements,
                reason: data.reason,
                review_token: "fictional-requirements-review",
            };
        } else if (path === requirementsPath + "commit/") {
            binding = reference;
            result = { operation_id: id(90) };
        } else if (path === base)
            result = {
                assignment_id: assignment,
                scope: { status: readOnly ? "closed" : "active" },
                revision: "fictional-revision",
                program: unknown ? null : program,
                selection: selected,
                cohort_changed: cohortChanged,
                can_manage: !readOnly,
            };
        else if (path === base + "review/") {
            if (reject)
                return route.fulfill({
                    status: 422,
                    json: {
                        message:
                            "The review changed. Reload before confirming.",
                    },
                });
            prepared = summary(data.specialization_ids);
            result = {
                before: selected,
                after: prepared,
                reason: data.reason,
                review_token: "fictional-review-token",
            };
        } else if (path === base + "commit/") {
            selected = prepared;
            result = { operation_id: id(99) };
        } else if (path === base + "history/")
            result = {
                items: [
                    {
                        selection: selected,
                        version: 1,
                        effective_at: "2026-08-10T17:00:00Z",
                        recorded_at: "2026-08-10T17:00:00Z",
                    },
                ],
                next_cursor: null,
            };
        await route.fulfill({
            json: result,
            headers: { "Cache-Control": "private, no-store" },
        });
    });
    await page.goto(
        readOnly
            ? "/admin/student-read-fixture/"
            : adjustments ? "/admin/" : "/admin/specialization-fixture/",
    );
    await page
        .locator('.workspace-nav-link[data-workspace="students"]')
        .click();
    await page.locator("#directory-search").fill("Alex");
    await page.locator("#directory-search").press("Enter");
    await page
        .getByRole("button", { name: "Alex Example", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Specializations", exact: true })
        .click();
    return { calls, errors, offered, base, requirementsPath };
}

test("specialization selection preserves checked choices through search, pages and accessible review", async ({
    page,
}) => {
    const f = await fixture(page);
    await expect(page.getByRole("checkbox")).toHaveCount(20);
    await expect(
        page.getByLabel("CHOICE.1 — Original <b>Art</b>", { exact: true }),
    ).toBeChecked();
    await page
        .getByRole("button", { name: "More choices", exact: true })
        .focus();
    await page.keyboard.press("Enter");
    await expect(page.getByRole("checkbox")).toHaveCount(3);
    await expect(page.locator(":focus")).toContainText("Choices 21–23");
    await page
        .getByLabel("CHOICE.23 — Fictional choice 23", { exact: true })
        .check();
    await page.getByLabel("Find an offered specialization").fill("choice 23");
    await expect(page.getByRole("checkbox")).toHaveCount(1);
    await expect(page.getByRole("checkbox")).toBeChecked();
    await expect(
        page.getByRole("status").filter({ hasText: "2 selected." }),
    ).toBeVisible();
    await page
        .getByLabel("Reason for this selection change")
        .fill("Fictional registrar request.");
    await page.setViewportSize({ width: 390, height: 844 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        scan.violations.map((item) => ({
            id: item.id,
            targets: item.nodes.map((n) => n.target),
        })),
    ).toEqual([]);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page
        .getByRole("button", {
            name: "Review specialization selection",
            exact: true,
        })
        .click();
    await expect(
        page.getByRole("region", { name: "After", exact: true }),
    ).toContainText("Fictional choice 23");
    await page
        .getByRole("button", { name: "Revise selection", exact: true })
        .click();
    await expect(
        page.getByLabel("Reason for this selection change"),
    ).toHaveValue("Fictional registrar request.");
    await page
        .getByRole("button", {
            name: "Review specialization selection",
            exact: true,
        })
        .click();
    await page
        .getByRole("button", {
            name: "Confirm specialization selection",
            exact: true,
        })
        .click();
    await expect(
        page
            .getByRole("status")
            .filter({ hasText: "Specialization selection recorded." }),
    ).toBeVisible();
    await expect(
        page.getByRole("region", { name: "Recorded selection", exact: true }),
    ).toContainText("Fictional choice 23");
    await page
        .getByRole("button", { name: "Specialization history", exact: true })
        .click();
    await expect(
        page.getByRole("region", { name: "Decision 1", exact: true }),
    ).toContainText("Original <b>Art</b>");
    await expect(
        page.locator(".offcanvas-body b, .offcanvas-body script"),
    ).toHaveCount(0);
    expect(
        f.calls.filter((call) => call.path === f.base + "review/")[0].data
            .specialization_ids,
    ).toEqual([f.offered[0].id, f.offered[22].id]);
    expect(f.calls.every((call) => call.csrf === "fictional-test-token")).toBe(
        true,
    );
    expect(
        await page.evaluate(() => [localStorage.length, sessionStorage.length]),
    ).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("rejected selection keeps the draft and closed or unknown pursuits explain their boundary", async ({
    page,
}) => {
    const f = await fixture(page, { reject: true });
    await page
        .getByLabel("CHOICE.2 — Fictional choice 2", { exact: true })
        .check();
    await page
        .getByLabel("Reason for this selection change")
        .fill("Keep this draft.");
    await page
        .getByRole("button", {
            name: "Review specialization selection",
            exact: true,
        })
        .click();
    await expect(
        page.getByLabel("Reason for this selection change"),
    ).toHaveValue("Keep this draft.");
    await expect(
        page.getByLabel("CHOICE.2 — Fictional choice 2", { exact: true }),
    ).toBeChecked();
    expect(f.calls.filter((call) => call.path.endsWith("/commit/"))).toEqual(
        [],
    );
    expect(f.errors).toEqual([]);
    await page.unrouteAll({ behavior: "wait" });
    await fixture(page, { readOnly: true, cohortChanged: true });
    await expect(
        page
            .getByRole("alert")
            .filter({ hasText: "matriculation cohort changed" }),
    ).toBeVisible();
    await expect(
        page.getByText("This pursuit is closed.", { exact: false }),
    ).toBeVisible();
    await expect(page.getByRole("checkbox")).toHaveCount(0);
    await expect(
        page.getByRole("button", {
            name: "Review specialization selection",
            exact: true,
        }),
    ).toHaveCount(0);
    await page.unrouteAll({ behavior: "wait" });
    await fixture(page, { unknown: true });
    await expect(
        page.getByText("This pursuit has no retained program publication.", {
            exact: false,
        }),
    ).toBeVisible();
    await expect(page.getByRole("checkbox")).toHaveCount(0);
});

test("requirements assignment retains the reviewed version through inspection, pagination and keyboard confirmation", async ({
    page,
}) => {
    const f = await fixture(page);
    await page
        .getByRole("button", { name: "Requirements for CHOICE.1", exact: true })
        .click();
    await expect(
        page
            .getByRole("status")
            .filter({ hasText: "No requirements publication" }),
    ).toBeVisible();
    await page
        .getByRole("button", {
            name: "Choose a requirements publication",
            exact: true,
        })
        .click();
    await expect(
        page.getByRole("button", { name: "Review publication 2", exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByText(
            "This publication belongs to a different program revision.",
            { exact: true },
        ),
    ).toBeVisible();
    await page
        .getByRole("button", { name: "Review publication 1", exact: true })
        .click();
    expect(
        f.calls.filter((call) => call.path === f.requirementsPath + "review/"),
    ).toHaveLength(0);
    const reason = page.getByLabel("Reason for assigning these requirements", {
        exact: true,
    });
    await reason.fill("Fictional registrar assignment.");
    await page
        .getByRole("button", { name: "Next requirements page", exact: true })
        .click();
    await expect(reason).toHaveValue("Fictional registrar assignment.");
    await page
        .getByRole("button", {
            name: "Previous requirements page",
            exact: true,
        })
        .click();
    await expect(reason).toHaveValue("Fictional registrar assignment.");
    await page.setViewportSize({ width: 390, height: 844 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page
        .getByRole("button", { name: "Review publication 1", exact: true })
        .click();
    await expect(page.locator(":focus")).toHaveText(
        "Fictional <b>Art</b> requirements",
    );
    await page
        .getByRole("button", {
            name: "Inspect reviewed requirements",
            exact: true,
        })
        .click();
    await expect(
        page
            .getByRole("dialog", { name: "Requirements preview", exact: true })
            .getByRole("heading", {
                name: "Fictional <b>Art</b> requirements",
                exact: true,
            }),
    ).toBeVisible();
    await expect(
        page.getByRole("dialog", { name: "Requirements preview", exact: true }),
    ).toHaveClass(/audit-studio/);
    await page
        .getByRole("button", { name: "Back to builder", exact: true })
        .click();
    await expect(
        page.getByRole("dialog", { name: "Requirements preview", exact: true }),
    ).toHaveCount(0);
    await page
        .getByRole("button", {
            name: "Confirm requirements assignment",
            exact: true,
        })
        .focus();
    await page.keyboard.press("Enter");
    await expect(
        page
            .getByRole("status")
            .filter({ hasText: "Requirements assignment recorded." }),
    ).toBeVisible();
    await expect(
        page.getByText(
            "Fictional <b>Art</b> requirements · Assigned publication 1",
            { exact: true },
        ),
    ).toBeVisible();
    await page
        .getByRole("button", {
            name: "Inspect assigned requirements",
            exact: true,
        })
        .click();
    await expect(
        page
            .getByRole("dialog", { name: "Requirements preview", exact: true })
            .getByRole("heading", {
                name: "Fictional <b>Art</b> requirements",
                exact: true,
            }),
    ).toBeVisible();
    expect(
        f.calls.filter((call) => call.path === f.requirementsPath + "review/"),
    ).toHaveLength(1);
    expect(
        f.calls.find((call) => call.path === f.requirementsPath + "commit/")
            .data,
    ).toEqual({ review_token: "fictional-requirements-review" });
    expect(f.calls.every((call) => call.csrf === "fictional-test-token")).toBe(
        true,
    );
    expect(
        await page.evaluate(() => [localStorage.length, sessionStorage.length]),
    ).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("requirements review failures preserve the reason and earlier cohort assignments remain readable", async ({
    page,
}) => {
    const f = await fixture(page, { rejectRequirements: true });
    await page
        .getByRole("button", { name: "Requirements for CHOICE.1", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "Choose a requirements publication",
            exact: true,
        })
        .click();
    await page
        .getByLabel("Reason for assigning these requirements", { exact: true })
        .fill("Keep the assignment reason.");
    await page
        .getByRole("button", { name: "Review publication 1", exact: true })
        .click();
    await expect(
        page
            .getByRole("alert")
            .filter({ hasText: "The requirements changed." }),
    ).toBeVisible();
    await expect(
        page.getByLabel("Reason for assigning these requirements", {
            exact: true,
        }),
    ).toHaveValue("Keep the assignment reason.");
    expect(
        f.calls.some((call) => call.path === f.requirementsPath + "commit/"),
    ).toBe(false);
    expect(f.errors).toEqual([]);
    await page.unroute("**/sis/**");
    const readOnly = await fixture(page, {
        readOnly: true,
        cohortChanged: true,
        assigned: true,
    });
    await page
        .getByRole("button", { name: "Requirements for CHOICE.1", exact: true })
        .click();
    await expect(
        page.getByRole("alert").filter({ hasText: "The cohort changed" }),
    ).toBeVisible();
    await expect(
        page.getByRole("button", {
            name: "Choose a requirements publication",
            exact: true,
        }),
    ).toHaveCount(0);
    await page
        .getByRole("button", {
            name: "Inspect assigned requirements",
            exact: true,
        })
        .click();
    await expect(
        page
            .getByRole("dialog", { name: "Requirements preview", exact: true })
            .getByRole("heading", {
                name: "Fictional <b>Art</b> requirements",
                exact: true,
            }),
    ).toBeVisible();
    expect(readOnly.errors).toEqual([]);
});

test("assigned specialization progress is an independent read with captured drilldowns and an explicit refresh", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        cohortChanged: true,
    });
    await page
        .getByRole("button", { name: "Requirements for CHOICE.1", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "Review specialization progress",
            exact: true,
        })
        .click();
    const review = page.getByRole("dialog", {
        name: "Specialization progress review",
        exact: true,
    });
    await expect(review).toBeVisible();
    await expect(
        review.getByText("General specialization minimums", { exact: true }),
    ).toBeVisible();
    await expect(
        review.getByText("Approved exceptions for this assignment and pursuit-wide waivers are included", {
            exact: false,
        }),
    ).toBeVisible();
    await expect(
        review
            .getByRole("status")
            .filter({
                hasText:
                    "The cohort changed after this specialization assignment.",
            }),
    ).toBeVisible();
    await expect(
        review.getByRole("button", {
            name: "Open degree progress guide",
            exact: true,
        }),
    ).toHaveCount(0);
    await page.setViewportSize({ width: 390, height: 844 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        scan.violations.map((v) => ({
            id: v.id,
            targets: v.nodes.map((n) => n.target),
        })),
    ).toEqual([]);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await review
        .getByRole("button", {
            name: "Inspect course-group placement",
            exact: true,
        })
        .click();
    const group = page.getByRole("dialog", {
        name: "Specialization course-group placement",
        exact: true,
    });
    await expect(group).toBeVisible();
    await expect(
        group.getByRole("button", {
            name: "Refresh course placement",
            exact: true,
        }),
    ).toHaveCount(0);
    await group
        .getByRole("button", {
            name: "Back to specialization progress",
            exact: true,
        })
        .click();
    await review
        .getByRole("button", {
            name: "Inspect credit and GPA contributions",
            exact: true,
        })
        .click();
    await page
        .getByRole("button", {
            name: "Back to specialization progress",
            exact: true,
        })
        .click();
    expect(
        f.calls.filter(
            (call) => call.path === f.requirementsPath + "progress/",
        ),
    ).toHaveLength(1);
    await review
        .getByRole("button", {
            name: "Refresh specialization review",
            exact: true,
        })
        .focus();
    await page.keyboard.press("Enter");
    await expect
        .poll(
            () =>
                f.calls.filter(
                    (call) => call.path === f.requirementsPath + "progress/",
                ).length,
        )
        .toBe(2);
    await review
        .getByRole("button", {
            name: "Back to specialization requirements",
            exact: true,
        })
        .click();
    await expect(
        page.getByRole("button", {
            name: "Review specialization progress",
            exact: true,
        }),
    ).toBeVisible();
    expect(
        f.calls.some((call) =>
            /degree-requirements.*progress|academic-work.*allocation/.test(
                call.path,
            ),
        ),
    ).toBe(false);
    expect(f.calls.every((call) => call.csrf === "fictional-test-token")).toBe(
        true,
    );
    expect(
        await page.evaluate(() => [localStorage.length, sessionStorage.length]),
    ).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("specialization progress explains a removed assignment and bounded review without partial results", async ({
    page,
}) => {
    await fixture(page, { assigned: true, progressStatus: "needs_selection" });
    await page
        .getByRole("button", { name: "Requirements for CHOICE.1", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "Review specialization progress",
            exact: true,
        })
        .click();
    await expect(
        page
            .getByRole("status")
            .filter({
                hasText: "Assign published requirements to this specialization",
            }),
    ).toBeVisible();
    await expect(
        page.getByRole("heading", {
            name: "General specialization minimums",
            exact: true,
        }),
    ).toHaveCount(0);
    await page.unroute("**/sis/**");
    const f = await fixture(page, {
        assigned: true,
        progressStatus: "limited",
    });
    await page
        .getByRole("button", { name: "Requirements for CHOICE.1", exact: true })
        .click();
    await page
        .getByRole("button", {
            name: "Review specialization progress",
            exact: true,
        })
        .click();
    await expect(
        page
            .getByRole("status")
            .filter({ hasText: "The review reached its work limit." }),
    ).toBeVisible();
    await expect(
        page.getByRole("button", {
            name: "Inspect credit and GPA contributions",
            exact: true,
        }),
    ).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test("specialization exceptions reuse the reviewed editor and send no client-selected scope references", async ({
    page,
}) => {
    const f = await fixture(page, { assigned: true, adjustments: true });
    const base = f.requirementsPath.replace(/requirements\/$/, "adjustments/");
    let approved = false;
    const requests = [];
    const definition = {
        kind: "minimum",
        group_id: null,
        group_version_id: null,
        minimum: "min_credits_milli",
        original_milli: 12000,
        required_milli: 9000,
    };
    await page.route(`**${base}**`, async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const body = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        if (body) requests.push({ path, body, csrf: route.request().headers()["x-csrf-token"] });
        const minima = { degree: { min_credits_milli: approved ? 9000 : 12000 }, groups: {} };
        let result = {
            items: approved ? [{ adjustment_id: id(93), definition, applicable: true }] : [],
            component: f.offered[0],
            targets: [
                {
                    group_id: null,
                    minimum: "min_credits_milli",
                    original_milli: 12000,
                    label: "Specialization",
                },
            ],
            courses: [],
            placement_courses: [],
            effective_minimums: minima,
            selection: { requirements_version_id: id(70) },
            scope: { status: "active" },
            group_labels: {},
            substitution_details: {},
        };
        if (path === base + "review/")
            result = {
                action: "grant",
                adjustment: { adjustment_id: id(93), definition },
                reason: body.reason,
                review_token: "fictional-scoped-review",
                group_labels: {},
                effective_minimums: { degree: { min_credits_milli: 9000 }, groups: {} },
                affected_groups: [],
            };
        if (path === base + "commit/") {
            approved = true;
            result = { operation_id: id(93) };
        }
        await route.fulfill({ json: result });
    });
    await page.getByRole("button", { name: "Requirements for CHOICE.1", exact: true }).click();
    await page.getByRole("button", { name: "Specialization exceptions", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "Specialization CHOICE.1",
    );
    await page.getByRole("button", { name: "Approve an exception", exact: true }).click();
    await page.getByRole("button", { name: "Requirement minimum", exact: true }).click();
    await page
        .getByRole("button", { name: "Specialization · Required credits · 12", exact: true })
        .click();
    await page.getByLabel("New required minimum", { exact: true }).fill("9");
    await page
        .getByLabel("Reason for student exception", { exact: true })
        .fill("Fictional specialization minimum exception.");
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "Specialization minimums after this decision",
    );
    await page.getByRole("button", { name: "Approve reviewed exception", exact: true }).click();
    expect(requests[0].body.definition).toEqual({
        kind: "minimum",
        group_id: null,
        minimum: "min_credits_milli",
        required_milli: 9000,
    });
    expect(requests.every((r) => r.csrf === "fictional-test-token")).toBe(true);
    await page.getByRole("button", { name: "Back to assigned requirements", exact: true }).click();
    await expect(
        page.getByRole("button", { name: "Review specialization progress", exact: true }),
    ).toBeVisible();
    expect(f.errors).toEqual([]);
});
