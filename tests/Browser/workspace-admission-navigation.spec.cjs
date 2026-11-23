const { test, expect } = require("@playwright/test");
const { recordAction } = require("./support/workspace-actions.cjs");
const id = (n) => String(n).padStart(26, "0");
for (const [title, endpoint] of [
    ["application review", "review/"],
    ["Application history", "decision/history/"],
    ["Matriculation history", "matriculate/history/"],
]) {
    test(`late ${title} read cannot reopen a closed drawer`, async ({ page }) => {
        const errors = [];
        let delayed = false,
            release,
            start;
        const pending = new Promise((resolve) => {
            start = resolve;
        });
        const held = new Promise((resolve) => {
            release = resolve;
        });
        page.on("pageerror", (error) => errors.push(error.message));
        await page.route("**/sis/**", async (route) => {
            const path = new URL(route.request().url()).pathname;
            let result = { items: [], next_after: null };
            if (delayed && path === `/sis/applications/${id(1)}/${endpoint}`) {
                start();
                await held;
            }
            if (path === "/sis/ui/metrics/")
                result = { cards: [], intake: [], pipeline: [], queue: [] };
            else if (path.includes("/data/applications/"))
                result = {
                    items: [
                        {
                            id: id(1),
                            given_name: "Fictional",
                            family_name: "Applicant",
                            status: "matriculated",
                        },
                    ],
                    next_after: null,
                };
            else if (path.endsWith("/review/"))
                result = {
                    application: { id: { value: id(1) }, status: "matriculated", version: 0 },
                    person: {
                        id: { value: id(2) },
                        givenName: "Fictional",
                        familyName: "Applicant",
                        institutionalId: { value: "S-FICTIONAL" },
                    },
                    labels: {
                        program_code: "ARTS-UG",
                        program_title: "Fictional Arts",
                        term_code: "26/FA",
                    },
                };
            await route.fulfill({
                json: result,
                headers: { "Cache-Control": "private, no-store" },
            });
        });
        await page.goto("/admin/");
        await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
        if (endpoint === "review/") delayed = true;
        await page.getByRole("button", { name: "Fictional Applicant", exact: true }).click();
        if (endpoint !== "review/") {
            delayed = true;
            await recordAction(page, title);
        }
        await pending;
        await expect(page.locator('#drawer-body [role="status"]').last()).toContainText("Loading");
        await page.getByRole("button", { name: "Close record", exact: true }).click();
        await expect(page.locator("#record-drawer")).toBeHidden();
        const finished = page.waitForResponse(
            (r) => new URL(r.url()).pathname === `/sis/applications/${id(1)}/${endpoint}`,
        );
        release();
        await finished;
        await page.evaluate(
            () =>
                new Promise((resolve) =>
                    requestAnimationFrame(() => requestAnimationFrame(resolve)),
                ),
        );
        await expect(page.locator("#record-drawer")).toBeHidden();
        await expect(page.locator("#drawer-body")).toBeEmpty();
        expect(errors).toEqual([]);
    });
}
