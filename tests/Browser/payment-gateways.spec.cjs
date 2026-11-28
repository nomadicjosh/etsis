const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
const drawer = (page) => page.locator("#drawer-body");
async function fixture(page) {
    const asset = { id: id(1), code: "CLEAR", title: "Fictional clearing <script>", currency: "USD", kind: "asset" };
    const connection = { id: id(2), version: 1, name: "Fictional tuition", provider: "stripe", merchant: "acct_fictional",
        live: false, enabled: true, currency: "USD", asset };
    const state = { calls: [], errors: [], stale: false, deny: false, preview: null, next: false };
    page.on("pageerror", (error) => state.errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const value = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json")) : {};
        state.calls.push({ path, value, csrf: req.headers()["x-csrf-token"] });
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/payment-setup/directory/connections/") {
            if (state.deny) return route.fulfill({ status: 403, json: { error: "Fictional access changed." } });
            result = { items: [connection], next_after: state.next ? id(20) : null, enabled: true, test_allowed: true };
        } else if (path === "/sis/payment-setup/directory/routes/") result = { items: [{ id: id(3), purpose: "account",
            currency: "USD", version: 1, connection_id: connection.id, selected_connection: connection }], next_after: null };
        else if (path === `/sis/payment-setup/connections/${id(2)}/`) result = connection;
        else if (path === "/sis/payment-setup/assets/") result = { items: [asset, { ...asset, id: id(4), kind: null,
            code: "OLD", title: "Fictional earlier account" }], next_after: null };
        else if (path === "/sis/payment-setup/routing/") result = { id: id(3), purpose: "account", currency: "USD",
            version: 1, connection_id: connection.id, selected_connection: connection };
        else if (path.startsWith("/sis/payment-setup/review/")) {
            const { secret, webhook, ...safe } = value;
            state.preview = safe;
            result = { review_id: id(5), review_token: "fictional-proof", reason: value.reason,
                preview: { ...safe, ...(safe.asset_id ? { asset } : {}), ...(safe.connection_id ? { selected_connection: connection } : {}) } };
        } else if (path === "/sis/payment-setup/confirm/") {
            if (state.stale) { state.stale = false; return route.fulfill({ status: 409, json: { error: "Fictional configuration changed." } }); }
            result = { id: connection.id, status: "saved" };
        }
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/workspace/accounts/");
    await page.getByRole("button", { name: "Payment gateways", exact: false }).click();
    return state;
}
async function newConnection(page) {
    await drawer(page).getByRole("button", { name: "Add Stripe connection", exact: true }).click();
    await page.getByLabel("Connection name", { exact: true }).fill("Fictional new connection");
    await page.getByLabel("Gateway clearing asset").selectOption(id(1));
    await page.getByLabel("Stripe secret API key").fill("sk_test_fictional");
    await page.getByLabel("Stripe webhook signing secret").fill("whsec_fictional");
    await page.getByLabel("Payment mode", { exact: true }).selectOption("false");
    await page.getByLabel("Reason for this payment setup change").fill("Approve fictional provider settings.");
}

test("new connection review clears credentials and sends only the proof at confirmation", async ({ page }) => {
    const state = await fixture(page);
    await newConnection(page);
    await drawer(page).getByRole("button", { name: "Review payment setup", exact: true }).click();
    await expect(page.getByLabel("Stripe secret API key")).toHaveValue("");
    await expect(page.getByLabel("Stripe webhook signing secret")).toHaveValue("");
    await expect(drawer(page)).toContainText("Fictional clearing <script>");
    await expect(drawer(page).locator("script")).toHaveCount(0);
    await drawer(page).getByRole("button", { name: "Confirm payment setup", exact: true }).click();
    await expect(drawer(page)).toContainText("Verified Stripe merchant");
    const accepted = state.calls.find((c) => c.path === "/sis/payment-setup/confirm/");
    expect(accepted.value).toEqual({ review_id: id(5), review_token: "fictional-proof" });
    expect(accepted.csrf).toBe("fictional-test-token");
    expect(await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toContain("sk_test_");
    expect(state.errors).toEqual([]);
});

test("stale review preserves ordinary fields and requires a new review", async ({ page }) => {
    const state = await fixture(page);
    await drawer(page).getByRole("button", { name: "View connection", exact: true }).click();
    await drawer(page).getByRole("button", { name: "Edit connection", exact: true }).click();
    await page.getByLabel("Connection name", { exact: true }).fill("Fictional revised name");
    await page.getByLabel("Reason for this payment setup change").fill("Approve fictional name correction.");
    await drawer(page).getByRole("button", { name: "Review payment setup", exact: true }).click();
    state.stale = true;
    await drawer(page).getByRole("button", { name: "Confirm payment setup", exact: true }).click();
    await expect(page.getByLabel("Connection name", { exact: true })).toHaveValue("Fictional revised name");
    await expect(drawer(page).getByRole("button", { name: "Confirm payment setup", exact: true })).toHaveCount(0);
    await drawer(page).getByRole("button", { name: "Review payment setup", exact: true }).click();
    await page.getByLabel("Connection name", { exact: true }).fill("Fictional further edit");
    await expect(drawer(page).getByRole("button", { name: "Confirm payment setup", exact: true })).toHaveCount(0);
    await drawer(page).getByRole("button", { name: "Back to record", exact: true }).click();
    await expect(drawer(page)).toContainText("Verified Stripe merchant");
});

test("clearing-account detour retains the connection draft and removes credentials", async ({ page }) => {
    await fixture(page);
    await newConnection(page);
    await drawer(page).getByRole("button", { name: "Create clearing asset", exact: true }).click();
    await expect(page.getByLabel("Account code", { exact: true })).toBeVisible();
    await drawer(page).getByRole("button", { name: "Back to payment setup", exact: true }).click();
    await expect(page.getByLabel("Connection name", { exact: true })).toHaveValue("Fictional new connection");
    await expect(page.getByLabel("Stripe secret API key")).toHaveValue("");
    await expect(page.getByLabel("Gateway clearing asset")).toHaveValue(id(1));
});

test("routing review identifies the selected merchant and supports deliberate unassignment", async ({ page }) => {
    const state = await fixture(page);
    await drawer(page).getByRole("button", { name: "Payment routing", exact: true }).click();
    await drawer(page).getByRole("button", { name: "Review route assignment", exact: true }).click();
    await expect(page.getByLabel("Payment connection", { exact: true })).toHaveValue(id(2));
    await page.getByLabel("Reason for this payment setup change").fill("Approve fictional route assignment.");
    await drawer(page).getByRole("button", { name: "Review payment setup", exact: true }).click();
    await expect(drawer(page)).toContainText("acct_fictional");
    await page.getByLabel("Payment connection", { exact: true }).selectOption("");
    await drawer(page).getByRole("button", { name: "Review payment setup", exact: true }).click();
    expect(state.preview.connection_id).toBeNull();
});

test("unknown chart classifications are explicit reviewed decisions", async ({ page }) => {
    const state = await fixture(page);
    await drawer(page).getByRole("button", { name: "Clearing accounts", exact: true }).click();
    await drawer(page).getByRole("button", { name: "Classify existing account", exact: true }).click();
    await page.getByLabel("Account classification", { exact: true }).selectOption("asset");
    await page.getByLabel("Reason for this payment setup change").fill("Classify fictional earlier asset.");
    await drawer(page).getByRole("button", { name: "Review payment setup", exact: true }).click();
    expect(state.preview.kind).toBe("asset");
    expect(state.preview.id).toBe(id(4));
});

test("narrow-screen setup and Bootstrap action menu remain visible and pass focused accessibility scans", async ({ page }) => {
    const state = await fixture(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await drawer(page).getByRole("button", { name: "View connection", exact: true }).click();
    await drawer(page).getByRole("button", { name: "More connection actions", exact: false }).click();
    const menu = page.locator(".dropdown-menu.show");
    await expect(menu).toBeVisible();
    const box = await menu.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(321);
    await page.keyboard.press("Escape");
    const audit = await new AxeBuilder({ page }).include("#record-drawer").withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"]).analyze();
    expect(audit.violations).toEqual([]);
    expect(await drawer(page).evaluate((e) => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
    await drawer(page).getByRole("button", { name: "Edit connection", exact: true }).click();
    const formAudit = await new AxeBuilder({ page }).include("#record-drawer").withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"]).analyze();
    expect(formAudit.violations).toEqual([]);
    expect(await drawer(page).evaluate((e) => e.scrollWidth <= e.clientWidth + 1)).toBe(true);
    expect(state.errors).toEqual([]);
});

test("access loss clears the active setup form", async ({ page }) => {
    const state = await fixture(page);
    state.deny = true;
    await drawer(page).getByRole("button", { name: "Connections", exact: true }).click();
    await expect(drawer(page)).toContainText("Your payment setup access changed");
    await expect(drawer(page).locator("input[type=password]")).toHaveCount(0);
});


test("closing a connection task clears its credential inputs", async ({ page }) => {
    await fixture(page);
    await newConnection(page);
    const secret = await page.getByLabel("Stripe secret API key").elementHandle();
    const webhook = await page.getByLabel("Stripe webhook signing secret").elementHandle();
    await page.keyboard.press("Escape");
    await expect(page.locator("#record-drawer")).not.toBeVisible();
    expect(await secret.evaluate((input) => input.value)).toBe("");
    expect(await webhook.evaluate((input) => input.value)).toBe("");
    await expect(drawer(page).locator('input[type="password"]')).toHaveCount(0);
});
