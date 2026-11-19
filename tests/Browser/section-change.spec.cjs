const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
const student = id(1),
    from = id(2),
    to = id(3);
function review(blocked = false) {
    return {
        from: { code: "MATH.101.A", effective_on: "2026-08-10", grace_ends_on: "2026-08-15" },
        target: {
            section: {
                id: to,
                code: "MATH.101.B",
                course_code: "MATH.101",
                course_title: "Fictional <b>Algebra</b>",
                credits_milli: 3000,
                seats_remaining: 12,
                term_starts_on: "2026-08-01",
                term_ends_on: "2026-12-18",
                active: true,
                prerequisite_courses: [],
            },
            students: [
                {
                    id: student,
                    issues: blocked ? ["Missing required coursework."] : [],
                    readiness: [],
                    enrollment_id: null,
                    prerequisites: { status: blocked ? "missing" : "not_required", course_statuses: [] },
                    schedule: {
                        status: "clear",
                        blocked: false,
                        conflict_count: 0,
                        unpublished_count: 0,
                        conflicts: [],
                    },
                    charges: { currency: "USD", total_minor: 1500, items: [], problems: [] },
                },
            ],
        },
        financial: {
            currency: "USD",
            credit_minor: 1000,
            new_charge_minor: 1500,
            net_change_minor: 500,
            problems: [],
        },
        can_confirm: !blocked,
        operation_id: id(9),
        revision: "a".repeat(64),
        review_token: "fictional-change-proof",
    };
}
async function mock(page, options = {}) {
    const calls = [],
        errors = [];
    let attempted = false;
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route(/\/(?:sis|portal\/api)\//, async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname;
        const data = req.postData()
            ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json"))
            : null;
        if (data) calls.push({ path, data, csrf: req.headers()["x-csrf-token"] });
        let result = { items: [], next_after: null };
        const enrollment = {
            section_id: from,
            section_code: "MATH.101.A",
            course_code: "MATH.101",
            status: "A",
        };
        const person = {
            id: student,
            person_id: id(5),
            given_name: "Alex",
            family_name: "Example",
            institutional_id: "FICTIONAL",
            active: true,
            primary_program: { code: "BA", title: "Fictional program" },
            entry_term: { code: "26/FA" },
        };
        if (path === "/portal/api/me/")
            result = {
                person: { id: id(5), given_name: "Alex", family_name: "Example" },
                students: [
                    {
                        id: student,
                        active: true,
                        enrollments: attempted
                            ? [{ ...enrollment, section_id: to, section_code: "MATH.101.B" }]
                            : [enrollment],
                    },
                ],
                applications: [],
                accounts: [],
                news: [],
                restrictions: [],
                transcript_requests: [],
            };
        else if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/") result = { items: [person], next_after: null };
        else if (path.endsWith("/record/")) result = person;
        else if (path.includes("/context/enrollments/")) result = { items: [enrollment], next_after: null };
        else if (path.includes("/section-changes/choices/"))
            result = {
                from_code: "MATH.101.A",
                grace_ends_on: "2026-08-15",
                items: [{ id: to, code: "MATH.101.B", capacity: 20, fee_minor: 1500, currency: "USD" }],
                next_after: null,
            };
        else if (path.includes("/section-changes/review/")) {
            if (options.delay) await page.waitForTimeout(400);
            if (options.stale)
                return route.fulfill({ status: 409, json: { error: "The section changed. Review again." } });
            result = review(options.blocked);
        } else if (path.includes("/section-changes/commit/")) {
            if (options.uncertain && !attempted) {
                attempted = true;
                return route.fulfill({
                    status: 503,
                    json: { error: "Response interrupted. Retry the same confirmation." },
                });
            }
            attempted = true;
            result = { change_id: id(9), financial: { currency: "USD", net_change_minor: 500 } };
        }
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    return { calls, errors };
}
async function start(page, staff, options = {}) {
    const f = await mock(page, options);
    if (staff) {
        await page.goto("/admin/registration-plan-fixture/");
        await page.locator('.workspace-nav-link[data-workspace="students"]').click();
        await page.locator("#directory-search").fill("Alex");
        await page.locator("#directory-search").press("Enter");
        await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    } else await page.goto("/portal/student/registration/");
    await page.getByRole("button", { name: "Change section", exact: true }).first().click();
    await page.getByRole("button", { name: "Review MATH.101.B", exact: true }).click();
    return f;
}
for (const staff of [true, false])
    test(`${staff ? "staff" : "student"} reviews an accessible section change and retries the same private proof`, async ({
        page,
    }) => {
        const f = await start(page, staff, { uncertain: true });
        const root = page.locator(staff ? "#drawer-body" : "#campus-dialog-body");
        await expect(root).toContainText("Original course-charge credit");
        await expect(root.locator("b")).toHaveCount(0);
        await page.setViewportSize({ width: 375, height: 812 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        expect(
            (
                await new AxeBuilder({ page })
                    .include(staff ? "#drawer-body" : "#campus-dialog-body")
                    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
                    .analyze()
            ).violations,
        ).toEqual([]);
        const confirm = page.getByRole("button", { name: "Confirm section change", exact: true });
        await confirm.click();
        await expect(confirm).toBeEnabled();
        await confirm.click();
        if (staff)
            await expect(root).toContainText(
                "The original registration and replacement were saved together.",
            );
        else await expect(page.locator("#campus-dialog")).toBeHidden();
        const commits = f.calls.filter((c) => c.path.endsWith("/section-changes/commit/"));
        expect(commits).toHaveLength(2);
        expect(commits[0].data).toEqual(commits[1].data);
        expect(commits[0]).toMatchObject({
            data: {
                student_id: student,
                from_section_id: from,
                to_section_id: to,
                operation_id: id(9),
                revision: "a".repeat(64),
                review_token: "fictional-change-proof",
            },
            csrf: "fictional-test-token",
        });
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(f.errors).toEqual([]);
    });
test("blocked replacement can be revised but not confirmed", async ({ page }) => {
    const f = await start(page, true, { blocked: true });
    await expect(page.getByRole("button", { name: "Confirm section change", exact: true })).toBeDisabled();
    await page.getByRole("button", { name: "Choose another section", exact: true }).click();
    await expect(page.getByRole("searchbox", { name: "Find a section" })).toBeVisible();
    await page.getByRole("searchbox", { name: "Find a section" }).fill("MATH.101");
    await page.locator("#drawer-body").getByRole("button", { name: "Search", exact: true }).click();
    expect(f.calls.filter((c) => c.path.endsWith("/section-changes/commit/"))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});
test("closing a student review ignores its late response", async ({ page }) => {
    const f = await start(page, false, { delay: true });
    await page.locator("#campus-dialog .btn-close").click();
    await page.waitForTimeout(500);
    await expect(page.locator("#campus-dialog")).toBeHidden();
    await expect(page.getByRole("button", { name: "Confirm section change", exact: true })).toHaveCount(0);
    expect(f.calls.filter((c) => c.path.endsWith("/section-changes/commit/"))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});
