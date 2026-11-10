const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
async function fixture(page, limited = false) {
    const calls = [],
        errors = [],
        student = id(1),
        cursor = id(2);
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
                active: true,
                primary_program: { code: "BA", title: "Fictional program" },
                entry_term: { code: "26/FA" },
            };
        else if (path.endsWith("/registration-history/"))
            result = {
                items: [
                    {
                        enrollment_id: data.after ? id(3) : cursor,
                        section_code: data.after ? "HIST.101" : "MATH.101 <img src=x onerror=alert(1)>",
                        term_code: "26/FA",
                        status: data.after ? "W" : "D",
                        current_records_removed: !data.after,
                        earlier_history_unknown: !!data.after,
                        items: [
                            {
                                action: data.after ? "observed_existing" : "registered",
                                section_sequence: data.after ? null : 1,
                                effective_at: "2026-08-10T17:00:00Z",
                                recorded_at: "2026-08-10T17:00:00Z",
                            },
                            {
                                action: data.after ? "late_drop" : "early_drop",
                                section_sequence: data.after ? 6 : 3,
                                effective_at: "2026-08-11T07:00:00Z",
                                recorded_at: "2026-08-11T17:00:00Z",
                            },
                        ],
                    },
                ],
                next_after: data.after ? null : cursor,
            };
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto(limited ? "/admin/student-read-fixture/" : "/admin/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator('#directory-search').fill('a');
    await page.locator('#directory-search').press('Enter');
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    return { calls, errors, student, cursor };
}
test("registrars read paged enrollment decisions and restricted early drops without exposing raw streams", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Registration history", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Current records removed during add/drop");
    await expect(page.locator("#drawer-body")).toContainText("MATH.101 <img src=x onerror=alert(1)>");
    await expect(page.locator("#drawer-body img")).toHaveCount(0);
    await expect(
        page.getByRole("region", { name: "Recorded registration decisions", exact: true }),
    ).toContainText("Early drop");
    await page.getByRole("button", { name: "Next history page", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Earlier enrollment decisions are unknown");
    await expect(
        page.getByRole("region", { name: "Recorded registration decisions", exact: true }),
    ).toContainText("Late drop / withdrawal");
    expect(f.calls.filter((c) => c.path.endsWith("/registration-history/")).at(-1)).toMatchObject({
        data: { after: f.cursor },
        csrf: "fictional-test-token",
    });
    await page.setViewportSize({ width: 390, height: 844 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => v.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Previous history page", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Current records removed during add/drop");
    await page.getByRole("button", { name: "Back to student", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Primary program");
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
test("ordinary academic readers do not receive restricted early-drop history controls", async ({ page }) => {
    const f = await fixture(page, true);
    await expect(page.getByRole("button", { name: "Registration history", exact: true })).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("/registration-history/"))).toBe(false);
    expect(f.errors).toEqual([]);
});
