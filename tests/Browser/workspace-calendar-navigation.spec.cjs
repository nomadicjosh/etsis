const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const { workspaceTool, recordAction } = require("./support/workspace-actions.cjs");
const id = (n) => String(n).padStart(26, "0");
const body = (page) => page.locator("#drawer-body");
const button = (page, name) => body(page).getByRole("button", { name, exact: true });
async function fixture(page, dense = false) {
    const f = { calls: [], errors: [], fail: null, deny: null, delay: null };
    let release, start;
    f.pending = new Promise((resolve) => {
        start = resolve;
    });
    const held = new Promise((resolve) => {
        release = resolve;
    });
    f.release = release;
    f.types = Array.from({ length: dense ? 20 : 2 }, (_, n) => ({
        id: id(n + 1),
        code: `TYPE.${n + 1}`,
        name_pattern: dense
            ? `Fictional${"LongAcademicCalendarName".repeat(8)} {{START_YEAR}}`
            : `Fall ${n + 1} {{START_YEAR}}`,
        code_pattern: "{{START_YEAR_SHORT}}/FA",
        kind: "standard",
        active: true,
        version: 2,
        dates: Object.fromEntries(
            ["starts_on", "ends_on", "add_drop_ends_on"].map((key, i) => [
                key,
                { month_day: ["08-15", "12-15", "08-29"][i], year: "START_YEAR" },
            ]),
        ),
    }));
    f.years = [2026, 2027].map((year, n) => ({
        id: id(30 + n),
        code: `${year}-${year + 1}`,
        start_year: year,
        end_year: year + 1,
        starts_on: `${year}-08-15`,
        ends_on: `${year + 1}-05-15`,
        term_count: 2,
        legacy: false,
    }));
    f.plan = {
        ...f.years[1],
        operation_id: id(90),
        revision: "a".repeat(64),
        terms: [
            {
                id: id(50),
                term_type_id: id(1),
                name: "Fictional Fall",
                code: "27/FA",
                kind: "standard",
                starts_on: "2027-08-15",
                ends_on: "2027-12-15",
                add_drop_ends_on: "2027-08-29",
            },
        ],
    };
    page.on("pageerror", (error) => f.errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname;
        const data = req.postData()
            ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json"))
            : {};
        f.calls.push({ path, data, csrf: req.headers()["x-csrf-token"] });
        if (path === f.delay) {
            start();
            await held;
        }
        if (path === f.deny)
            return route.fulfill({ status: 403, json: { error: "Fictional access denied." } });
        if (path === f.fail) {
            f.fail = null;
            return route.fulfill({ status: 503, json: { error: "Fictional page unavailable." } });
        }
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/operation-id/") result = { id: id(99) };
        else if (path === "/sis/term-types/" || path === "/sis/academic-years/") {
            const rows = path.includes("term-types") ? f.types : f.years;
            result = {
                items:
                    dense && rows === f.types
                        ? rows
                        : rows.slice(data.after ? 1 : 0, data.after ? 2 : 1),
                next_after: (dense && rows === f.types) || data.after ? null : rows[0].id,
            };
        } else if (/\/term-types\/.*\/save\/$/.test(path)) result = { ok: true };
        else if (
            path === "/sis/academic-years/review/" ||
            path === "/sis/academic-years/create/" ||
            path.includes("/receipts/")
        )
            result = f.plan;
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/");
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    f.open = (name) => workspaceTool(page, name);
    return f;
}
async function reflow(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
    expect(await body(page).evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => v.id)).toEqual([]);
}

test("term-type cancellation and save retain the originating library page", async ({ page }) => {
    const f = await fixture(page);
    await f.open("Term types");
    await button(page, "Next term type page").click();
    await button(page, "Edit TYPE.2").click();
    await button(page, "Back to term types").click();
    await expect(button(page, "Edit TYPE.2")).toBeVisible();
    await button(page, "Edit TYPE.2").click();
    await button(page, "Save term type").click();
    await expect(button(page, "Edit TYPE.2")).toBeVisible();
    expect(f.calls.filter((c) => c.path === "/sis/term-types/").at(-1).data).toEqual({
        after: id(1),
    });
    expect(f.calls.find((c) => c.path.endsWith("/save/"))).toMatchObject({
        data: { version: 2 },
        csrf: "fictional-test-token",
    });
    expect(f.errors).toEqual([]);
});
test("failed library paging retains accepted rows and the retry cursor", async ({ page }) => {
    const f = await fixture(page);
    await f.open("Term types");
    f.fail = "/sis/term-types/";
    await button(page, "Next term type page").click();
    await expect(page.locator("#drawer-error")).toContainText("unavailable");
    await expect(button(page, "Edit TYPE.1")).toBeVisible();
    await expect(button(page, "Previous term type page")).toBeDisabled();
    await button(page, "Next term type page").click();
    await expect(button(page, "Edit TYPE.2")).toBeVisible();
    await button(page, "Previous term type page").click();
    await expect(button(page, "Edit TYPE.1")).toBeVisible();
    expect(f.errors).toEqual([]);
});
test("calendar setup and nested templates retain the year draft and originating year page", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.open("Academic years");
    await button(page, "Next academic year page").click();
    await button(page, "Create academic year").click();
    await page.getByLabel("Start year", { exact: false }).fill("2034");
    await button(page, "Review term types").click();
    await button(page, "Next term type page").click();
    await button(page, "Edit TYPE.2").click();
    await button(page, "Back to term types").click();
    await expect(button(page, "Edit TYPE.2")).toBeVisible();
    await button(page, "Back to academic year setup").click();
    await expect(page.getByLabel("Start year", { exact: false })).toHaveValue("2034");
    await button(page, "Back to academic years").click();
    await expect(
        body(page).getByRole("list", { name: "Academic years", exact: true }),
    ).toContainText("2027-2028");
    expect(f.calls.filter((c) => c.path === "/sis/academic-years/").at(-1).data).toEqual({
        after: id(30),
    });
    expect(f.calls.some((c) => c.path === "/sis/academic-years/create/")).toBe(false);
    expect(f.errors).toEqual([]);
});
test("generated-year review keeps the chosen start year when revising", async ({ page }) => {
    const f = await fixture(page);
    await f.open("Academic years");
    await button(page, "Create academic year").click();
    await page.getByLabel("Start year", { exact: false }).fill("2027");
    await button(page, "Review generated terms").click();
    await expect(body(page).getByRole("list", { name: "Generated academic terms" })).toContainText(
        "27/FA",
    );
    await expect(body(page)).not.toContainText("Loading academic calendar");
    await button(page, "Change start year").click();
    await expect(page.getByLabel("Start year", { exact: false })).toHaveValue("2027");
    expect(f.errors).toEqual([]);
});
test("pending calendar pages disable their paging controls and retain a working setup action", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.open("Term types");
    f.delay = "/sis/term-types/";
    await button(page, "Next term type page").click();
    await f.pending;
    await expect(button(page, "Refresh term types")).toBeDisabled();
    await expect(button(page, "Previous term type page")).toBeDisabled();
    await button(page, "Add term type").click();
    await expect(page.getByLabel("Term type code", { exact: false })).toBeVisible();
    f.release();
    await expect(page.locator("#drawer-title")).toHaveText("Add term type");
    expect(f.errors).toEqual([]);
});
for (const [label, path, tool, action] of [
    ["year library", "/sis/academic-years/", "Academic years", null],
    ["term-type library", "/sis/term-types/", "Term types", null],
    ["new template identifier", "/sis/ui/operation-id/", "Term types", "Add term type"],
    ["year review", "/sis/academic-years/review/", "Academic years", "Review generated terms"],
    ["template save", `/sis/term-types/${id(1)}/save/`, "Term types", "Save term type"],
])
    test(`late ${label} response cannot reopen a closed drawer`, async ({ page }) => {
        const f = await fixture(page);
        if (action) await f.open(tool);
        if (action === "Review generated terms") await button(page, "Create academic year").click();
        if (action === "Save term type") await button(page, "Edit TYPE.1").click();
        f.delay = path;
        if (action) await button(page, action).click();
        else await f.open(tool);
        await f.pending;
        await page.getByRole("button", { name: "Close record", exact: true }).click();
        await expect(page.locator("#record-drawer")).toBeHidden();
        const finished = page.waitForResponse(
            (response) => new URL(response.url()).pathname === path,
        );
        f.release();
        await finished;
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        await expect(page.locator("#record-drawer")).toBeHidden();
        await expect(body(page)).toBeEmpty();
        expect(f.errors).toEqual([]);
    });
test("denied library paging removes previously visible template data", async ({ page }) => {
    const f = await fixture(page);
    await f.open("Term types");
    f.deny = "/sis/term-types/";
    await button(page, "Next term type page").click();
    await expect(body(page)).toContainText("no longer available with your current access");
    await expect(body(page)).not.toContainText("TYPE.1");
    await expect(button(page, "Add term type")).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
test("dense long templates and grouped date fields reflow on phone with enlarged text", async ({
    page,
}) => {
    const f = await fixture(page, true);
    await f.open("Term types");
    await page.setViewportSize({ width: 320, height: 780 });
    await page.addStyleTag({ content: "html { font-size: 200% !important; }" });
    await expect(
        body(page).getByRole("list", { name: "Term types", exact: true }).locator("li"),
    ).toHaveCount(20);
    await reflow(page);
    await button(page, "Edit TYPE.1").click();
    await expect(page.getByRole("group", { name: "Term starts", exact: true })).toBeVisible();
    await reflow(page);
    await page.screenshot({ path: "/tmp/etsis-calendar-template-phone.png" });
    expect(f.errors).toEqual([]);
});
