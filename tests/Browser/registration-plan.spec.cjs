const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
const student = id(1),
    a = id(2),
    b = id(3);
function review(options = {}) {
    return {
        student_id: student,
        sections: [a, b].map((section, i) => ({
            section: {
                id: section,
                code: `SEC.${i + 1}`,
                course_code: `COURSE.${i + 1}`,
                course_title: "Fictional <b>course</b>",
                credits_milli: 3000,
                ...(options.variable ? { student_selectable_credits: true, credit_policy: { minimum_milli: 1000, maximum_milli: 6000, increment_milli: 500 } } : {}),
                seats_remaining: 12,
                term_starts_on: "2026-08-01",
                term_ends_on: "2026-12-18",
                active: true,
                prerequisite_courses: [],
            },
            student: {
                issues: [],
                readiness: [],
                enrollment_id: null,
                prerequisites: { status: "not_required", course_statuses: [], missing_count: 0 },
                schedule: {
                    status: "clear",
                    blocked: false,
                    conflict_count: 0,
                    unpublished_count: 0,
                    conflicts: [],
                },
                charges: { currency: "USD", total_minor: 1000, items: [], problems: [] },
            },
        })),
        planned_schedule: {
            unpublished_count: 0,
            blocked: !!options.blocked,
            conflicts: options.blocked ? [{ section_code: "SEC.1", other_section_code: "SEC.2" }] : [],
        },
        totals: { USD: 2000 },
        issues: options.blocked ? ["Selected sections overlap."] : [],
        can_confirm: !options.blocked,
        operation_id: id(9),
        revision: "a".repeat(64),
        review_token: "fictional-plan-proof",
    };
}
async function mock(page, options = {}) {
    const calls = [],
        errors = [];
    let failed = false;
    page.on("pageerror", (e) => errors.push(e.message));
    const courses = [a, b].map((section, i) => ({
        id: section,
        term_id: id(4),
        code: `SEC.${i + 1}`,
        section_code: `SEC.${i + 1}`,
        course_code: `COURSE.${i + 1}`,
        title: "Fictional course",
        term_code: "26/FA",
        credits_milli: 3000,
        capacity: 20,
        enrolled: 0,
        fee_minor: 1000,
        currency: "USD",
        web_registration: 1,
    }));
    await page.route(/\/(?:sis|portal\/api)\//, async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname;
        const data = req.postData()
            ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json"))
            : null;
        if (data) calls.push({ path, data, csrf: req.headers()["x-csrf-token"] });
        let result = { items: [], next_after: null };
        if (path === "/portal/api/me/")
            result = {
                person: { id: id(5), given_name: "Alex", family_name: "Example" },
                students: [{ id: student, primary_program_id: id(6), active: 1, enrollments: [] }],
                applications: [],
                accounts: [],
                news: [],
                restrictions: [],
                transcript_requests: [],
            };
        else if (path === "/portal/api/courses/" || path === "/sis/ui/data/sections/")
            result = { items: courses, next_after: null };
        else if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/")
            result = {
                items: [
                    {
                        id: student,
                        person_id: id(5),
                        given_name: "Alex",
                        family_name: "Example",
                        institutional_id: "FICTIONAL",
                        active: true,
                    },
                ],
                next_after: null,
            };
        else if (path.endsWith("/record/"))
            result = {
                id: student,
                person_id: id(5),
                given_name: "Alex",
                family_name: "Example",
                institutional_id: "FICTIONAL",
                active: true,
                primary_program: { code: "BA", title: "Fictional program" },
                entry_term: { code: "26/FA" },
            };
        else if (path.includes("registration-plan") && path.endsWith("/review/")) {
            if (options.stale)
                return route.fulfill({
                    status: 409,
                    json: { error: "The selected schedule changed. Review it again." },
                });
            result = review(options);
        } else if (path.includes("registration-plan") && path.endsWith("/commit/")) {
            if (options.uncertain && !failed) {
                failed = true;
                return route.fulfill({
                    status: 503,
                    json: { error: "Could not confirm the response. Retry this request." },
                });
            }
            result = { plan_id: id(9), created: 2, registrations: [] };
        }
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    return { calls, errors };
}
async function staffPlan(page, options = {}) {
    const f = await mock(page, options);
    await page.goto("/admin/registration-plan-fixture/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator("#directory-search").fill("Alex");
    await page.locator("#directory-search").press("Enter");
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await page.getByRole("button", { name: "Plan registration", exact: true }).click();
    for (const section of [a, b]) {
        await page.getByRole("combobox", { name: "Add a course section", exact: true }).selectOption(section);
        await page.getByRole("button", { name: "Add section", exact: true }).click();
    }
    return f;
}
test("staff selects a schedule, revises it and confirms one accessible private request", async ({ page }) => {
    const f = await staffPlan(page);
    await page.getByRole("button", { name: "Review schedule", exact: true }).click();
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("20.00");
    await expect(drawer.locator("b")).toHaveCount(0);
    await page.getByRole("button", { name: "Revise selection", exact: true }).click();
    await expect(drawer).toContainText("2 of 10 sections selected");
    await page.getByRole("button", { name: "Review schedule", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations).toEqual([]);
    await page.getByRole("button", { name: "Confirm schedule", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2 registrations created together");
    await expect(page.locator("#drawer-body")).toContainText("Schedule reference");
    const commit = f.calls.find((c) => c.path.endsWith("/registration-plans/commit/"));
    expect(commit).toMatchObject({
        data: { student_id: student, section_ids: [a, b], review_token: "fictional-plan-proof" },
        csrf: "fictional-test-token",
    });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
test("student builds the same reviewed schedule and retries an uncertain confirmation with the original proof", async ({
    page,
}) => {
    const f = await mock(page, { uncertain: true });
    await page.goto("/portal/student/registration/");
    for (const add of await page.getByRole("button", { name: "Add to schedule", exact: true }).all())
        await add.click();
    await expect(page.getByRole("region", { name: "Your planned schedule" })).toContainText(
        "2 of 10 sections selected",
    );
    await page.getByRole("button", { name: "Review schedule", exact: true }).click();
    await expect(page.locator("#campus-dialog-body")).toContainText("Term-wide fees are counted once");
    const guide = page.locator('#campus-dialog-body [data-review-guide]');
    await expect(guide).toHaveCount(1);
    await guide.locator(':scope > summary').click();
    const requestCount = f.calls.length;
    await page.getByRole('button', { name: 'Show proposed charges', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Registration charges', exact: true }).first()).toBeFocused();
    expect(f.calls.length).toBe(requestCount);

    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page })
        .include("#campus-dialog-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations).toEqual([]);
    await page.getByRole("button", { name: "Confirm schedule", exact: true }).click();
    await expect(page.getByRole("button", { name: "Confirm schedule", exact: true })).toBeEnabled();
    await page.getByRole("button", { name: "Confirm schedule", exact: true }).click();
    const commits = f.calls.filter((c) => c.path.endsWith("/registration-plan/commit/"));
    expect(commits).toHaveLength(2);
    expect(commits[0]).toEqual(commits[1]);
    expect(commits[0].csrf).toBe("fictional-test-token");
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
test("blocked proposed meetings disable confirmation and stale reviews retain the selection", async ({
    page,
}) => {
    const f = await staffPlan(page, { blocked: true });
    await page.getByRole("button", { name: "Review schedule", exact: true }).click();
    await expect(page.getByRole("button", { name: "Confirm schedule", exact: true })).toBeDisabled();
    await expect(page.locator("#drawer-body")).toContainText("SEC.1 overlaps SEC.2");
    expect(f.calls.some((c) => c.path.endsWith("/commit/"))).toBe(false);
});

test("a stale review leaves a path back to the unchanged selection without confirming", async ({ page }) => {
    const f = await staffPlan(page, { stale: true });
    await page.getByRole("button", { name: "Review schedule", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("The selected schedule changed");
    await page.getByRole("button", { name: "Revise selection", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2 of 10 sections selected");
    expect(f.calls.some((c) => c.path.endsWith("/commit/"))).toBe(false);
});


test('student chooses different exact quantities per planned section and edits invalidate confirmation', async ({ page }) => {
    const f = await mock(page, { variable: true });
    await page.goto('/portal/student/registration/');
    for (const add of await page.getByRole('button', { name: 'Add to schedule', exact: true }).all()) await add.click();
    await page.getByRole('button', { name: 'Review schedule', exact: true }).click();
    await page.getByLabel('SEC.1', { exact: true }).fill('1.5');
    await page.getByLabel('SEC.2', { exact: true }).fill('4.5');
    await expect(page.getByRole('button', { name: 'Confirm schedule', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Refresh schedule review', exact: true }).click();
    await expect(page.getByLabel('SEC.1', { exact: true })).toHaveValue('1.5');
    await expect(page.getByLabel('SEC.2', { exact: true })).toHaveValue('4.5');
    await page.setViewportSize({ width: 320, height: 812 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect((await new AxeBuilder({ page }).include('#campus-dialog-body').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations).toEqual([]);
    await page.screenshot({ path: '/tmp/etsis-student-credits-phone.png', fullPage: true });
    await page.getByRole('button', { name: 'Confirm schedule', exact: true }).click();
    expect(f.calls.find(call => call.path.endsWith('/commit/')).data.credit_choices).toEqual({ [a]: 1500, [b]: 4500 });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
