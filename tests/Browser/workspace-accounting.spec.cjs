const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
const body = (page) => page.locator("#drawer-body");
async function fixture(page) {
    const state = { calls: [], errors: [], deny: false, stale: false, closed: false, saved: null };
    const account = (n, code, title) => ({ id: id(n), code, title, currency: "USD" });
    const ar = account(1, "AR", "Fictional receivable"), revenue = account(2, "REV", "Fictional income <script>"),
        bank = account(3, "BANK", "Fictional bank");
    state.definition = { currency: "USD", timezone: "America/Los_Angeles", receivable: ar, charge: revenue,
        payment: bank, refund: bank, charge_sources: {}, payment_methods: {} };
    const period = () => ({ id: id(5), position: 4, definition: { book_id: id(4), currency: "USD", label: "Fictional August",
        starts_on: "2026-08-01", ends_on: "2026-08-31", closed: state.closed, movement_count: 21, ar_net: "123456" } });
    page.on("pageerror", (error) => state.errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const request = route.request(), path = new URL(request.url()).pathname;
        const value = request.postData() ? JSON.parse(new URLSearchParams(request.postData()).get("_workspace_json")) : {};
        state.calls.push({ path, value, csrf: request.headers()["x-csrf-token"] });
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/ledger/") result = { items: [ar, revenue, bank], next_after: null };
        else if (path === "/sis/accounting/") result = { currency: "USD", book: { id: id(4), position: 2,
            definition: state.definition }, periods: [period()], next_after: null };
        else if (path === "/sis/accounting/mapping/review/") {
            state.saved = value;
            if (state.holdReview) await state.holdReview;
            result = { action: "mapping", definition: state.definition, review_token: "fictional-review" };
        } else if (path === "/sis/accounting/periods/review/") {
            state.saved = value;
            result = { action: "open", definition: { ...period().definition, ...value }, review_token: "fictional-review" };
        } else if (path.endsWith("/reconcile/")) {
            if (state.deny) return route.fulfill({ status: 403, json: { error: "Fictional access lost." } });
            result = { items: [{ journal_id: id(6), reference: "SA.FICTIONAL", posted_on: "2026-08-10", ar_effect_minor: 123456 }],
                checked: value.continuation ? 21 : 20, total: 21, ar_net_minor: "123456", currency: "USD",
                done: !!value.continuation, continuation: value.continuation ? "fictional-complete" : "fictional-next" };
        } else if (path === `/sis/accounting/periods/${id(5)}/review/`) {
            state.saved = value;
            result = { action: value.action, definition: { ...period().definition, closed: value.action === "close" },
                review_token: "fictional-review" };
        } else if (path === "/sis/accounting/confirm/") {
            if (state.stale) { state.stale = false; return route.fulfill({ status: 409,
                json: { error: "Fictional accounting source changed. Review again." } }); }
            if (state.saved.action) state.closed = state.saved.action === "close";
            result = { id: id(5), action: state.saved.action || "mapping", position: 5 };
        } else if (path === `/sis/accounts/${id(7)}/`) result = {
            id: id(7), currency: "USD", balance_minor: 123456, position: 1, institutional_id: "FICTIONAL.007", entries: [],
        };
        else if (path === `/sis/journals/${id(6)}/`) result = { id: id(6), reference: "SA.FICTIONAL", position: 1,
            posted_on: "2026-08-10", currency: "USD", reversed: false, lines: [
                { account_id: ar.id, account_code: ar.code, account_title: ar.title, amount_minor: 123456 },
                { account_id: revenue.id, account_code: revenue.code, account_title: revenue.title, amount_minor: -123456 }],
            accounting: { book_id: id(4), period_id: id(5), source: { account_id: id(7) } } };
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/workspace/accounts/");
    await page.getByRole("button", { name: "Accounting controls", exact: false }).click();
    return state;
}

test("mapping reviews preserve drafts on stale confirmation and require a fresh review after editing", async ({ page }) => {
    const state = await fixture(page);
    await body(page).getByRole("button", { name: "Review mapping setup", exact: true }).click();
    await page.getByLabel("Reason for this accounting decision").fill("Fictional institutional approval.");
    await body(page).getByRole("button", { name: "Review accounting change", exact: true }).click();
    await expect(body(page)).toContainText("Fictional income <script>");
    await expect(body(page).locator("script")).toHaveCount(0);
    state.stale = true;
    await body(page).getByRole("button", { name: "Confirm accounting change", exact: true }).click();
    await expect(page.getByLabel("Reason for this accounting decision")).toHaveValue("Fictional institutional approval.");
    await expect(body(page).getByRole("button", { name: "Confirm accounting change", exact: true })).toHaveCount(0);
    await body(page).getByRole("button", { name: "Review accounting change", exact: true }).click();
    await page.getByLabel("Accounts-receivable code").fill("AR.REVISED");
    await expect(body(page).getByRole("button", { name: "Confirm accounting change", exact: true })).toHaveCount(0);
    expect(state.saved.charge_sources).toEqual([]);
    expect(state.saved.payment_methods).toEqual([]);
    expect(state.errors).toEqual([]);
});

test("paged verification links journals back to the reconciliation and supports reviewed closing", async ({ page }) => {
    const state = await fixture(page);
    await body(page).getByRole("button", { name: "Reconcile period", exact: true }).click();
    await expect(body(page)).toContainText("20 of 21 entries verified");
    await expect(body(page).getByRole("button", { name: "Review period closing", exact: true })).toHaveCount(0);
    await body(page).getByRole("button", { name: "View journal", exact: true }).click();
    await expect(body(page)).toContainText("Automatic student-account journal");
    await expect(body(page)).toContainText("Fictional income <script>");
    await expect(body(page).locator("script")).toHaveCount(0);
    await expect(body(page).getByRole("button", { name: "Reverse journal", exact: true })).toHaveCount(0);
    await body(page).getByRole("button", { name: "Open student account", exact: true }).click();
    await expect(body(page)).toContainText("FICTIONAL.007");
    await body(page).getByRole("button", { name: "Back to journal", exact: true }).click();
    await body(page).getByRole("button", { name: "Back to reconciliation", exact: true }).click();
    await body(page).getByRole("button", { name: "Verify next entries", exact: true }).click();
    await body(page).getByRole("button", { name: "Review period closing", exact: true }).click();
    await page.getByLabel("Reason for this accounting decision").fill("Close the reconciled fictional period.");
    await body(page).getByRole("button", { name: "Review accounting change", exact: true }).click();
    await body(page).getByRole("button", { name: "Confirm accounting change", exact: true }).click();
    await expect(body(page)).toContainText("Closed");
    expect(state.saved.reconciliation).toBe("fictional-complete");
    expect(state.calls.filter((call) => call.path.includes("accounting/") && call.value.review_token)
        .every((call) => call.csrf === "fictional-test-token")).toBe(true);
    expect(state.errors).toEqual([]);
});

test("a late mapping review cannot approve a draft edited while the request was pending", async ({ page }) => {
    const state = await fixture(page);
    await body(page).getByRole("button", { name: "Review mapping setup", exact: true }).click();
    await page.getByLabel("Reason for this accounting decision").fill("Fictional first draft.");
    let release;
    state.holdReview = new Promise((resolve) => { release = resolve; });
    await body(page).getByRole("button", { name: "Review accounting change", exact: true }).click();
    await expect.poll(() => !!state.saved).toBe(true);
    await page.getByLabel("Reason for this accounting decision").fill("Fictional revised draft.");
    release();
    state.holdReview = null;
    await expect(body(page).getByRole("button", { name: "Review accounting change", exact: true })).toBeEnabled();
    await expect(body(page).getByRole("button", { name: "Confirm accounting change", exact: true })).toHaveCount(0);
    await body(page).getByRole("button", { name: "Review accounting change", exact: true }).click();
    await expect(body(page).getByRole("button", { name: "Confirm accounting change", exact: true })).toBeVisible();
    expect(state.saved.reason).toBe("Fictional revised draft.");
    expect(state.errors).toEqual([]);
});

test("the closed-period Bootstrap menu remains in the narrow viewport and opens a reasoned reopening", async ({ page }) => {
    const state = await fixture(page);
    state.closed = true;
    await page.setViewportSize({ width: 320, height: 800 });
    await body(page).getByRole("button", { name: "Refresh accounting controls", exact: true }).click();
    await body(page).getByRole("button", { name: "Fictional August", exact: true }).click();
    const action = page.getByRole("button", { name: "Review period reopening", exact: true });
    await expect(action).toBeVisible();
    const rect = await action.boundingBox();
    expect(rect.x).toBeGreaterThanOrEqual(0);
    expect(rect.x + rect.width).toBeLessThanOrEqual(321);
    await action.click();
    await page.getByLabel("Reason for this accounting decision").fill("Reopen for a fictional attributed correction.");
    await body(page).getByRole("button", { name: "Review accounting change", exact: true }).click();
    await body(page).getByRole("button", { name: "Confirm accounting change", exact: true }).click();
    await expect(body(page)).toContainText("Open");
    expect(state.saved.action).toBe("reopen");
    expect(state.errors).toEqual([]);
});

test("accounting screens reflow at 320px and clear private results on access loss", async ({ page }) => {
    const state = await fixture(page);
    await page.setViewportSize({ width: 320, height: 800 });
    const scan = await new AxeBuilder({ page }).withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"]).analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await body(page).getByRole("button", { name: "Reconcile period", exact: true }).click();
    state.deny = true;
    await body(page).getByRole("button", { name: "Verify next entries", exact: true }).click();
    await expect(body(page)).toContainText("Accounting access is no longer available");
    await expect(body(page)).not.toContainText("SA.FICTIONAL");
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});
