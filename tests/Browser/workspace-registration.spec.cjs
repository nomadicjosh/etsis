const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;

async function fixture(page, completedInitially = false, missingPrerequisites = false, variable = false) {
    const id = (suffix) => `01K000000000000000000000${suffix}`;
    const section = id("81"),
        first = id("82"),
        second = id("83"),
        operation = id("84");
    let commits = 0,
        completed = completedInitially;
    const sent = [],
        errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    const receipt = {
        batch_id: operation,
        section_id: section,
        created: 1,
        existing: 1,
        registrations: [
            { student_id: first, enrollment_id: id("85"), created: false },
            { student_id: second, enrollment_id: id("86"), created: true },
        ],
    };
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        if (data) sent.push({ path, data, csrf: route.request().headers()["x-csrf-token"] });
        let result = {},
            status = 200;
        if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith("/sis/ui/data/")) {
            const datasets = {
                registration: [
                    {
                        id: section,
                        code: "MATH.101.A",
                        course_title: "Algebra",
                        term_code: "26/FA",
                        capacity: 20,
                        active: 1,
                    },
                ],
                sections: [{ id: section, code: "MATH.101.A", course_title: "Algebra", term_code: "26/FA" }],
                registrationstudents: [
                    {
                        id: first,
                        given_name: "Alex",
                        family_name: "Example",
                        institutional_id: "S-EXAMPLE-1",
                        active: 1,
                    },
                    {
                        id: second,
                        given_name: "Jamie",
                        family_name: "Example",
                        institutional_id: "S-EXAMPLE-2",
                        active: 1,
                    },
                ],
            };
            result = { items: datasets[path.split("/")[4]] || [], next_after: null };
        } else if (path.endsWith("/registration-batches/review/"))
            result = {
                operation_id: operation,
                revision: "a".repeat(64),
                section: {
                    id: section,
                    code: "MATH.101.A",
                    course_code: "MATH.101",
                    course_title: "Algebra <script>fictional</script>",
                    prerequisite_courses: [{ code: "PRE.101", title: "Fictional preparation <img>" }],
                    credits_milli: 3000,
                    ...(variable ? { student_selectable_credits: true, credit_policy: { minimum_milli: 1000, maximum_milli: 6000, increment_milli: 500 } } : {}),
                    seats_remaining: 1,
                    fee_minor: 1250,
                    currency: "USD",
                    active: true,
                },
                students: data.student_ids.map((student) => ({
                    id: student,
                    ...(variable && student === second ? { credits_milli: data.credit_choices?.[student] ?? null } : {}),
                    given_name: student === first ? "Alex" : "Jamie",
                    family_name: "Example",
                    institutional_id: student === first ? "S-EXAMPLE-1" : "S-EXAMPLE-2",
                    active: true,
                    enrollment_id: student === first ? id("85") : null,
                    enrollment_status: student === first ? "N" : null,
                    readiness:
                        student === first
                            ? []
                            : [
                                  {
                                      code: "prerequisites",
                                      label: "Required coursework",
                                      status: missingPrerequisites ? "blocked" : "met",
                                      message: missingPrerequisites
                                          ? "Required coursework has not been verified for this program pursuit."
                                          : "Check passed.",
                                      next_step: missingPrerequisites
                                          ? "Review Required courses with the registrar."
                                          : null,
                                  },
                              ],
                    issues: [],
                    schedule: {
                        status: "clear",
                        blocked: false,
                        conflict_count: 0,
                        unpublished_count: 0,
                        conflicts: [],
                    },
                    charges: {
                        total_minor: student === first ? 0 : 1250,
                        currency: "USD",
                        items: [],
                        problems: [],
                    },
                    prerequisites: {
                        status:
                            student === first ? "not_rechecked" : missingPrerequisites ? "missing" : "met",
                        course_statuses: student === first ? [] : [missingPrerequisites ? "missing" : "met"],
                        missing_count: missingPrerequisites && student === second ? 1 : 0,
                    },
                })),
            };
        else if (path.endsWith("/registration-batches/commit/")) {
            ++commits;
            completed = true;
            if (commits === 1) {
                status = 500;
                result = {
                    error: "Fictional interrupted response. Check the receipt before starting another batch.",
                };
            } else result = receipt;
        } else if (path === `/sis/registration-batches/${operation}/`) {
            if (completed) result = receipt;
            else {
                status = 404;
                result = { error: "Record not found." };
            }
        }
        await route.fulfill({ status, json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    return { section, first, second, operation, sent, errors };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        scan.violations.map((item) => ({ id: item.id, targets: item.nodes.map((node) => node.target) })),
    ).toEqual([]);
}
async function review(page, f) {
    await page.goto("/admin/registration-fixture/");
    await expect(page.locator('.workspace-nav-link[data-workspace="courses"]')).toHaveCount(0);
    await expect(page.locator('.workspace-nav-link[data-workspace="students"]')).toHaveCount(0);
    await page.locator('.workspace-nav-link[data-workspace="registration"]').click();
    await page.getByRole("button", { name: "MATH.101.A", exact: true }).click();
    await expect(page.getByRole("combobox", { name: /^Course section/ })).toHaveValue(f.section);
    await page.getByRole("combobox", { name: /^Find a student/ }).selectOption(f.first);
    await page.getByRole("button", { name: "Add student to group", exact: true }).click();
    await page.getByRole("button", { name: "Add student to group", exact: true }).click();
    await expect(page.getByRole("region", { name: "Selected students", exact: true })).toContainText(
        "1 selected student",
    );
    await page.getByRole("combobox", { name: /^Find a student/ }).selectOption(f.second);
    await page.getByRole("button", { name: "Add student to group", exact: true }).click();
    await page.getByRole("button", { name: "Review selected group", exact: true }).click();
    await expect(
        page.getByRole("region", { name: "Reviewed registration group", exact: true }),
    ).toContainText("Already enrolled — keep");
    await expect(page.locator("#drawer-body")).toContainText("$12.50 per new registration");
    await expect(page.locator("#drawer-body")).toContainText("<script>fictional</script>");
    await expect(page.locator("#drawer-body script")).toHaveCount(0);
    expect(f.sent.some((item) => item.path.endsWith("/commit/"))).toBe(false);
}

test("registrars review a group and retry an interrupted confirmation with the same operation reference", async ({
    page,
}) => {
    const f = await fixture(page);
    await review(page, f);
    await page.getByRole("button", { name: "Check batch receipt", exact: true }).click();
    await expect(page.locator("#drawer-error")).toContainText("No completed receipt exists yet");
    await page.getByRole("button", { name: "Change selection", exact: true }).click();
    await expect(page.getByRole("region", { name: "Selected students", exact: true })).toContainText(
        "2 selected students",
    );
    await page.getByRole("button", { name: "Review selected group", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Confirm group registration", exact: true }).click();
    await expect(page.locator("#drawer-error")).toContainText("Fictional interrupted response");
    await expect(page.locator("#drawer-body")).toContainText(f.operation);
    await page.getByRole("button", { name: "Confirm group registration", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText(
        "1 registration created. 1 existing registration kept.",
    );
    const requests = f.sent.filter((item) => item.path.endsWith("/commit/"));
    expect(requests).toHaveLength(2);
    expect(requests[0].data).toEqual(requests[1].data);
    expect(requests[0]).toMatchObject({
        csrf: "fictional-test-token",
        data: {
            section_id: f.section,
            student_ids: [f.first, f.second],
            operation_id: f.operation,
            revision: "a".repeat(64),
        },
    });
    expect(
        f.sent.some((item) => item.path.includes("/data/students/") || item.path.includes("/data/accounts/")),
    ).toBe(false);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test("receipt checks recover a completed batch without submitting a second confirmation", async ({
    page,
}) => {
    const f = await fixture(page);
    await review(page, f);
    await page.getByRole("button", { name: "Confirm group registration", exact: true }).click();
    await expect(page.locator("#drawer-error")).toContainText("Fictional interrupted response");
    await page.getByRole("button", { name: "Check batch receipt", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText(
        "1 registration created. 1 existing registration kept.",
    );
    expect(f.sent.filter((item) => item.path.endsWith("/commit/"))).toHaveLength(1);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test("staff can find an earlier receipt by reference without reselecting or changing students", async ({
    page,
}) => {
    const f = await fixture(page, true);
    await page.goto("/admin/registration-fixture/");
    await page.locator('.workspace-nav-link[data-workspace="registration"]').click();
    await page.getByRole("button", { name: "Register a group", exact: true }).click();
    await page.getByRole('combobox', { name: /^Course section/u }).selectOption(f.section);
    await page.getByRole('combobox', { name: /^Find a student/u }).selectOption(f.first);
    await page.getByRole('button', { name: 'Add student to group', exact: true }).click();
    await page.getByRole('button', { name: 'Find a completed batch', exact: true }).click();
    await page.getByRole('button', { name: 'Back to registration', exact: true }).click();
    await expect(page.getByRole('combobox', { name: /^Course section/u })).toHaveValue(f.section);
    await expect(page.getByRole('region', { name: 'Selected students', exact: true })).toContainText('1 selected student');
    await page.getByRole("button", { name: "Find a completed batch", exact: true }).click();
    await page.getByLabel("Batch reference", { exact: false }).fill(f.operation);
    await page.getByRole("button", { name: "Check completed batch", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText(f.operation);
    await expect(page.locator("#drawer-body")).toContainText(
        "1 registration created. 1 existing registration kept.",
    );
    await page.getByRole('button', { name: 'Back to registration', exact: true }).click();
    await expect(page.getByRole('combobox', { name: /^Course section/u })).toHaveValue(f.section);
    await expect(page.getByRole('region', { name: 'Selected students', exact: true })).toContainText('1 selected student');
    expect(f.sent.some((item) => item.path.endsWith("/review/") || item.path.endsWith("/commit/"))).toBe(
        false,
    );
    expect(f.errors).toEqual([]);
});

test("missing prerequisites are visible before confirmation and cannot submit a partial group", async ({
    page,
}) => {
    const f = await fixture(page, false, true);
    await review(page, f);
    await expect(
        page.getByRole("region", { name: "Reviewed registration group", exact: true }),
    ).toContainText("Missing 1 prerequisite");
    await expect(
        page.getByRole("button", { name: "Confirm group registration", exact: true }),
    ).toBeDisabled();
    await expect(page.getByRole("status").filter({ hasText: "Some selected students" })).toBeVisible();
    await expect(page.locator("#drawer-body")).not.toContainText("PRE.101");
    await page.getByText("Required courses", { exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("PRE.101");
    await expect(page.locator("#drawer-body")).toContainText(
        "Passing coursework not verified for this pursuit",
    );
    await expect(page.locator("#drawer-body img")).toHaveCount(0);
    await page.getByText("Registration checks", { exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Review Required courses with the registrar.");
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.sent.some((item) => item.path.endsWith("/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Change selection", exact: true }).click();
    await expect(page.getByRole("region", { name: "Selected students", exact: true })).toContainText(
        "2 selected students",
    );
    expect(f.errors).toEqual([]);
});


test('group registration guide opens proposed charge details without committing or fetching', async ({ page }) => {
    const f = await fixture(page);
    await review(page, f);
    const count = f.sent.length;
    await page.locator('[data-review-guide] > summary').click();
    await expect(page.locator('[data-review-guide]')).toContainText('Keep existing registrations');
    await page.getByRole('button', { name: 'Show proposed charges', exact: true }).click();
    await expect(page.locator('[data-charge-review] > summary').first()).toBeFocused();
    await expect(page.getByRole('region', { name: 'Registration charges', exact: true }).first()).toBeVisible();
    expect(f.sent.length).toBe(count);
    expect(f.sent.some(item => item.path.endsWith('/commit/'))).toBe(false);
    await accessible(page);
    expect(f.errors).toEqual([]);
});


test('variable group credits require refreshing review and retain exact input for interrupted confirmation', async ({ page }) => {
    const f = await fixture(page, false, false, true);
    await review(page, f);
    const input = page.getByLabel('Jamie Example', { exact: true });
    await input.fill('2.5');
    await expect(page.getByRole('button', { name: 'Confirm group registration', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Refresh review', exact: true }).click();
    await expect(input).toHaveValue('2.5');
    expect(f.sent.filter(call => call.path.endsWith('/review/')).at(-1).data.credit_choices).toEqual({ [f.second]: 2500 });
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.getByRole('button', { name: 'Confirm group registration', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('Fictional interrupted response');
    await page.getByRole('button', { name: 'Confirm group registration', exact: true }).click();
    const commits = f.sent.filter(call => call.path.endsWith('/commit/'));
    expect(commits[0].data.credit_choices).toEqual({ [f.second]: 2500 });
    expect(commits[0].data).toEqual(commits[1].data);
    expect(f.errors).toEqual([]);
});
