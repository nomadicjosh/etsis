const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const { recordAction } = require("./support/workspace-actions.cjs");
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
    f.tasks = Array.from({ length: dense ? 20 : 2 }, (_, n) => ({
        id: id(n + 1),
        given_name: dense ? "Fictional".repeat(16) : "Fictional",
        family_name: n ? "Second reviewer case" : "First reviewer case",
        institutional_id: `S-FICTIONAL-${n}`,
        status: "in_review",
        created_at: "2026-11-22T12:00:00Z",
    }));
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
        else if (path === "/sis/ui/operation-id/") result = { id: id(90) };
        else if (path === "/sis/finance-reviews/")
            result = {
                items: dense ? f.tasks : f.tasks.slice(data.after ? 1 : 0, data.after ? 2 : 1),
                next_after: dense || data.after ? null : id(1),
            };
        else if (/\/finance-reviews\/[A-Z0-9]{26}\/$/.test(path)) {
            const item = f.tasks.find((row) => path.includes(row.id));
            result = {
                ...item,
                student: { id: id(10), ...item },
                source: { id: id(11), starts_on: "2026-11-01", ends_on: null, voided: false },
                assigned: true,
                assigned_to_me: true,
                version: 3,
                can_view_accounts: true,
            };
        } else if (path.endsWith("/history/"))
            result = {
                items: [
                    {
                        previous_status: "open",
                        status: "in_review",
                        note: "Fictional private <script>reason</script>",
                        actor_name: "Fictional Reviewer",
                        created_at: "2026-11-22",
                        postings: [],
                    },
                ],
                next_after: data.after ? null : id(20),
            };
        else if (path.endsWith("/accounts/"))
            result = {
                items: [
                    {
                        id: id(data.after ? 41 : 40),
                        term_code: data.after ? "27/SP" : "26/FA",
                        currency: "USD",
                    },
                ],
                next_after: data.after ? null : id(40),
            };
        else if (/\/accounts\/[A-Z0-9]{26}\/$/.test(path))
            result = {
                id: id(41),
                student_id: id(10),
                institutional_id: "S-FICTIONAL",
                currency: "USD",
                balance_minor: 0,
                position: 2,
                entries: [],
            };
        else if (path.endsWith("/change/")) result = { ok: true };
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/");
    f.open = () => page.getByRole("button", { name: "Finance reviews", exact: false }).click();
    f.second = async () => {
        await f.open();
        await page.getByLabel("Review status", { exact: false }).selectOption("in_review");
        await button(page, "Filter finance reviews").click();
        await button(page, "Next finance reviews page").click();
        await button(page, "Review finance task").click();
        await expect(body(page)).toContainText("Second reviewer case");
    };
    return f;
}

test("finance task returns retain filtered queue and accepted page through progress cancellation", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.second();
    await button(page, "Record review progress / keep assignment").click();
    await page.getByLabel("Private finance reason / outcome").fill("Unsaved fictional note");
    await button(page, "Back to finance task").click();
    await button(page, "Back to finance queue").click();
    await expect(page.getByLabel("Review status", { exact: false })).toHaveValue("in_review");
    await expect(body(page)).toContainText("Second reviewer case");
    expect(f.calls.filter((c) => c.path === "/sis/finance-reviews/").at(-1).data).toEqual({
        status: "in_review",
        after: id(1),
    });
    expect(f.calls.some((c) => c.path.endsWith("/change/"))).toBe(false);
    expect(f.errors).toEqual([]);
});
test("billing statements return to their originating account page then the finance task and queue", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.second();
    await button(page, "Review student accounts").click();
    await button(page, "Next billing accounts").click();
    await button(page, "Open 27/SP account").click();
    await button(page, "Back to billing accounts").click();
    await expect(button(page, "Open 27/SP account")).toBeVisible();
    await button(page, "Back to finance task").click();
    await button(page, "Back to finance queue").click();
    await expect(body(page)).toContainText("Second reviewer case");
    expect(f.errors).toEqual([]);
});
test("failed queue paging retains the accepted filter, page and retry cursor", async ({ page }) => {
    const f = await fixture(page);
    await f.open();
    f.fail = "/sis/finance-reviews/";
    await button(page, "Next finance reviews page").click();
    await expect(page.locator("#drawer-error")).toContainText("unavailable");
    await expect(body(page)).toContainText("First reviewer case");
    await expect(button(page, "Previous finance reviews page")).toBeDisabled();
    await button(page, "Next finance reviews page").click();
    await expect(body(page)).toContainText("Second reviewer case");
    expect(f.errors).toEqual([]);
});
test("initial queue read failure can retry and then apply a status filter", async ({ page }) => {
    const f = await fixture(page);
    f.fail = "/sis/finance-reviews/";
    await f.open();
    await expect(page.locator("#drawer-error")).toContainText("unavailable");
    await button(page, "Refresh finance reviews page").click();
    await page.getByLabel("Review status", { exact: false }).selectOption("completed");
    await button(page, "Filter finance reviews").click();
    await expect
        .poll(() => f.calls.filter((c) => c.path === "/sis/finance-reviews/").at(-1)?.data)
        .toEqual({ status: "completed", after: "" });
    expect(f.errors).toEqual([]);
});
test("progress saves retain task history page and filtered queue return", async ({ page }) => {
    const f = await fixture(page);
    await f.second();
    await button(page, "Next finance history page").click();
    await button(page, "Record review progress / keep assignment").click();
    await page.getByLabel("Private finance reason / outcome").fill("Fictional checked progress");
    await button(page, "Save finance review change").click();
    await expect(button(page, "Previous finance history page")).toBeEnabled();
    expect(f.calls.find((c) => c.path.endsWith("/change/"))).toMatchObject({
        data: {
            note: "Fictional checked progress",
            version: 3,
            action: "claim",
            operation_id: id(90),
        },
        csrf: "fictional-test-token",
    });
    await button(page, "Back to finance queue").click();
    await expect(body(page)).toContainText("Second reviewer case");
    expect(f.errors).toEqual([]);
});
for (const [label, path, action] of [
    ["queue", "/sis/finance-reviews/", null],
    ["task", `/sis/finance-reviews/${id(1)}/`, "Review finance task"],
    ["accounts", `/sis/finance-reviews/${id(1)}/accounts/`, "Review student accounts"],
    ["progress identifier", "/sis/ui/operation-id/", "Record review progress / keep assignment"],
    ["progress save", `/sis/finance-reviews/${id(1)}/change/`, "Save finance review change"],
])
    test(`late finance ${label} cannot reopen a closed drawer`, async ({ page }) => {
        const f = await fixture(page);
        if (action) await f.open();
        if (action && action !== "Review finance task")
            await button(page, "Review finance task").click();
        if (action === "Save finance review change") {
            await button(page, "Record review progress / keep assignment").click();
            await page.getByLabel("Private finance reason / outcome").fill("Fictional note");
        }
        f.delay = path;
        if (action) await button(page, action).click();
        else await f.open();
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
test("denied history paging clears private notes and task controls", async ({ page }) => {
    const f = await fixture(page);
    await f.open();
    await button(page, "Review finance task").click();
    await expect(body(page)).toContainText("Fictional private <script>reason</script>");
    f.deny = `/sis/finance-reviews/${id(1)}/history/`;
    await button(page, "Next finance history page").click();
    await expect(body(page)).toContainText("no longer available with your current access");
    await expect(body(page)).not.toContainText("Fictional private");
    await expect(button(page, "Complete finance review")).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
test("long finance tasks and Bootstrap actions reflow at phone and enlarged text sizes", async ({
    page,
}) => {
    const f = await fixture(page, true);
    await f.open();
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: "html { font-size: 200% !important; }" });
    await expect(
        body(page).getByRole("list", { name: "Finance review tasks" }).locator("li"),
    ).toHaveCount(20);
    expect(await body(page).evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await button(page, "Review finance task").first().click();
    await button(page, "More review actions").click();
    const item = button(page, "Cancel finance review");
    await expect(item).toBeVisible();
    expect(
        await item.evaluate((el) => {
            const r = el.getBoundingClientRect();
            return r.left >= 0 && r.right <= innerWidth;
        }),
    ).toBe(true);
    expect(await item.evaluate((el) => getComputedStyle(el).textAlign)).toBe("start");
    await page.keyboard.press("Escape");
    await button(page, "Complete finance review").click();
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => v.id)).toEqual([]);
    expect(await body(page).evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.screenshot({ path: "/tmp/etsis-finance-review-phone.png" });
    expect(f.errors).toEqual([]);
});

async function journals(page) {
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await page.locator('#directory-tabs [data-workspace="journals"]').click();
}
test("journal details and individual lines use grouped fields with accessible phone reflow", async ({
    page,
}) => {
    const f = await fixture(page);
    await journals(page);
    await page.locator("#directory-create").click();
    await expect(page.getByRole("group", { name: "Journal details", exact: true })).toBeVisible();
    await expect(page.getByRole("group", { name: "Line 2", exact: true })).toBeVisible();
    await button(page, "Add journal line").click();
    await expect(page.getByRole("group", { name: "Line 3", exact: true })).toBeVisible();
    await button(page, "Remove line 2").click();
    await expect(page.getByRole("group", { name: "Line 2", exact: true })).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: "html { font-size: 200% !important; }" });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => v.id)).toEqual([]);
    expect(await body(page).evaluate((el) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    expect(f.errors).toEqual([]);
});
test("a delayed journal operation identifier cannot reopen a closed drawer or start ledger lookups", async ({
    page,
}) => {
    const f = await fixture(page);
    await journals(page);
    f.delay = "/sis/ui/operation-id/";
    await page.locator("#directory-create").click();
    await f.pending;
    await page.getByRole("button", { name: "Close record", exact: true }).click();
    await expect(page.locator("#record-drawer")).toBeHidden();
    const finished = page.waitForResponse((r) => new URL(r.url()).pathname === f.delay);
    f.release();
    await finished;
    await page.evaluate(
        () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
    );
    await expect(page.locator("#record-drawer")).toBeHidden();
    expect(f.calls.some((c) => c.path.includes("/data/ledger/"))).toBe(false);
    expect(f.errors).toEqual([]);
});
test("billing setup groups fee identity and amount without posting a charge", async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await page.locator('#directory-tabs [data-workspace="billing"]').click();
    await page.locator("#directory-create").click();
    await expect(page.getByRole("group", { name: "Fee identity", exact: true })).toBeVisible();
    await expect(
        page.getByRole("group", { name: "Amount & application", exact: true }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Close record", exact: true }).click();
    expect(f.calls.some((c) => c.path === "/sis/billing-rules/")).toBe(false);
    expect(f.errors).toEqual([]);
});
