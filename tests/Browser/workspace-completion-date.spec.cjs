const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;

async function fixture(page, options = {}) {
    const id = (n) => String(n).padStart(26, "0");
    const student = id(1),
        section = id(3),
        enrollment = id(5);
    const calls = [],
        errors = [];
    let date = options.known ? "2026-08-01" : null,
        version = 3,
        reviewed;
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname;
        const data = req.postData()
            ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json"))
            : {};
        calls.push({ path, data, csrf: req.headers()["x-csrf-token"] });
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/")
            result = {
                items: [
                    {
                        id: student,
                        person_id: id(2),
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
                given_name: "Alex",
                family_name: "Example",
                institutional_id: "FICTIONAL",
                primary_program: { code: "BA", title: "Fictional program" },
                entry_term: { code: "26/FA" },
                active: true,
            };
        else if (path.endsWith("/transcript/"))
            result = {
                credits: [
                    {
                        credit_type: "institutional",
                        section_id: section,
                        course_code: "CS.101",
                        course_title: "Fictional course",
                        grade_code: "A",
                        status: "A",
                    },
                ],
            };
        else if (path.endsWith("/grade-choices/"))
            result = {
                items: [{ id: id(4), code: "A", points_milli: 4000 }],
                current_grade_code: "A",
                posted: true,
                can_amend: !options.readOnly,
                current_version: version,
                completed_on: date,
                enrollment_id: enrollment,
                effective_on_default: "2026-08-11",
                needs_assignment: false,
                pursuits: [],
            };
        else if (path.endsWith("/completion-date/review/")) {
            if (options.reject)
                return route.fulfill({
                    status: 409,
                    json: { error: "The academic record changed. Reload it before reviewing." },
                });
            reviewed = data.completed_on;
            result = {
                previous_completed_on: date,
                completed_on: reviewed,
                reason: data.reason,
                review_token: "fictional-completion-review",
            };
        } else if (path.endsWith("/completion-date/commit/")) {
            date = reviewed;
            version++;
        }
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/grade-amend-fixture/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator("#directory-search").fill("a");
    await page.locator("#directory-search").press("Enter");
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await page.getByRole("button", { name: "View transcript", exact: true }).click();
    await page.getByRole("button", { name: "Review final grade", exact: true }).click();
    return { calls, errors, student, section, enrollment };
}

test("registrar reviews actual completion independently from grades with reason, revision and private transport", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Review actual completion date", exact: true }).click();
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("Current completion date: Unknown");
    await expect(drawer).toContainText(
        "separate from instructional end dates, grade posting and correction dates",
    );
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("2026-08-02");
    await page
        .getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true })
        .fill("Fictional <b>source</b> confirmation.");
    await page.getByRole("button", { name: "Review completion date", exact: true }).click();
    await expect(drawer).toContainText("Unknown → 2026-08-02");
    await expect(drawer.locator("b")).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("/completion-date/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Revise completion date", exact: true }).click();
    await expect(
        page.getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true }),
    ).toHaveValue("Fictional <b>source</b> confirmation.");
    await page.getByRole("button", { name: "Review completion date", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    await page.getByRole("button", { name: "Confirm completion date", exact: true }).click();
    await expect(drawer).toContainText("Actual completion date: 2026-08-02");
    const reviews = f.calls.filter((c) => c.path.endsWith("/completion-date/review/"));
    expect(reviews[0]).toMatchObject({
        data: {
            enrollment_id: f.enrollment,
            version: 3,
            completed_on: "2026-08-02",
            reason: "Fictional <b>source</b> confirmation.",
        },
        csrf: "fictional-test-token",
    });
    expect(f.calls.find((c) => c.path.endsWith("/completion-date/commit/"))).toMatchObject({
        data: { review_token: "fictional-completion-review" },
        csrf: "fictional-test-token",
    });
    expect(f.calls.some((c) => c.path.includes("/final-grades/"))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("clearing a known completion date requires an explicit reviewed unknown", async ({ page }) => {
    const f = await fixture(page, { known: true });
    await page.getByRole("button", { name: "Review actual completion date", exact: true }).click();
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("");
    await page
        .getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true })
        .fill("Fictional source date withdrawn.");
    await page.getByRole("button", { name: "Review completion date", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2026-08-01 → Unknown");
    await page.getByRole("button", { name: "Confirm completion date", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Actual completion date: Unknown");
    expect(f.calls.find((c) => c.path.endsWith("/completion-date/review/"))?.data.completed_on).toBeNull();
    expect(f.errors).toEqual([]);
});

test("ordinary faculty cannot open the registrar completion-date action", async ({ page }) => {
    const f = await fixture(page, { readOnly: true });
    await expect(
        page.getByRole("button", { name: "Review actual completion date", exact: true }),
    ).toHaveCount(0);
    expect(f.calls.some((c) => c.path.includes("/completion-date/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("stale completion review keeps the entered correction available and never confirms it", async ({
    page,
}) => {
    const f = await fixture(page, { reject: true });
    await page.getByRole("button", { name: "Review actual completion date", exact: true }).click();
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("2026-08-02");
    await page
        .getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true })
        .fill("Fictional stale correction.");
    await page.getByRole("button", { name: "Review completion date", exact: true }).click();
    await expect(
        page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }),
    ).toHaveValue("2026-08-02");
    await expect(page.locator("#drawer-body")).toContainText("The academic record changed");
    expect(f.calls.some((c) => c.path.endsWith("/completion-date/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});
