const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
const exact = { rounding: "none", decimal_places: null, cap_at_hundred: false };
async function setup(page, canManage) {
    const section = id(1),
        calls = [],
        errors = [];
    let reject = true;
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        let result = {};
        if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/teaching/")
            result = {
                items: [
                    {
                        id: section,
                        code: "ART.101.A",
                        course_code: "ART.101",
                        course_title: "Fictional studio",
                        term_code: "26/FA",
                        capacity: 10,
                    },
                ],
                next_after: null,
            };
        else if (path.startsWith("/sis/ui/data/")) result = { items: [], next_after: null };
        else if (path === `/sis/ui/context/roster/${section}/`)
            result = { items: [], next_after: null, can_grade: true };
        else if (path === `/sis/ui/context/assignments/${section}/`)
            result = { items: [], next_after: null };
        else if (path === `/sis/sections/${section}/gradebook/calculation/`) {
            if (data) {
                calls.push({ data, csrf: route.request().headers()["x-csrf-token"] });
                if (canManage && reject) {
                    reject = false;
                    await route.fulfill({
                        status: 409,
                        json: { error: "Gradebook setup changed. Reload setup." },
                    });
                    return;
                }
            }
            result = {
                mode: "points",
                weights: {},
                categories: [],
                assignments: [],
                revision: "a".repeat(64),
                grade_policy: exact,
                can_manage_grade_policy: canManage,
            };
        }
        await route.fulfill({ json: result });
    });
    await page.goto("/admin/");
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await page.getByRole("button", { name: "ART.101.A", exact: true }).click();
    await page.getByRole("button", { name: "Gradebook setup", exact: true }).click();
    return { calls, errors };
}

test("authorized policy editor preserves a rejected draft and saves explicit rounding and cap", async ({
    page,
}) => {
    const fixture = await setup(page, true);
    await page.setViewportSize({ width: 320, height: 1000 });
    await page.getByLabel("Rounding rule", { exact: true }).selectOption("half_even");
    await page.getByLabel("Decimal places", { exact: true }).selectOption("2");
    await page.getByLabel("Cap the final-grade percentage at 100% before rounding").check();
    await expect(page.getByRole("group", { name: "Final-grade comparison policy" })).toContainText(
        "cap at 100% first; nearest, ties to even to 2 decimal places",
    );
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => v.id)).toEqual([]);
    await page.getByRole("button", { name: "Save setup", exact: true }).click();
    await expect(page.getByLabel("Rounding rule", { exact: true })).toHaveValue("half_even");
    await expect(page.getByLabel("Decimal places", { exact: true })).toHaveValue("2");
    await expect(
        page.getByLabel("Cap the final-grade percentage at 100% before rounding"),
    ).toBeChecked();
    expect(fixture.calls).toHaveLength(1);
    expect(fixture.calls[0].csrf).toBeTruthy();
    expect(fixture.calls[0].data.grade_policy).toEqual({
        rounding: "half_even",
        decimal_places: 2,
        cap_at_hundred: true,
    });
    expect(fixture.errors).toEqual([]);
});

test("faculty without policy duty can save setup with the existing rules retained", async ({
    page,
}) => {
    const fixture = await setup(page, false);
    await expect(page.getByLabel("Rounding rule", { exact: true })).toBeDisabled();
    await expect(page.getByLabel("Decimal places", { exact: true })).toBeDisabled();
    await expect(
        page.getByLabel("Cap the final-grade percentage at 100% before rounding"),
    ).toBeDisabled();
    await expect(page.getByRole("group", { name: "Final-grade comparison policy" })).toContainText(
        "coursework grading policy duty",
    );
    await page.getByRole("button", { name: "Save setup", exact: true }).click();
    await expect.poll(() => fixture.calls.length).toBe(1);
    expect(fixture.calls[0].data.grade_policy).toEqual(exact);
    expect(fixture.errors).toEqual([]);
});
