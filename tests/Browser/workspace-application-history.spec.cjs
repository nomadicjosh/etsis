const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
for (const decided of [false, true]) {
    test(`application history retains actual submission ${decided ? "through acceptance" : "before a decision"}`, async ({
        page,
    }) => {
        const app = id(1),
            person = id(2),
            status = decided ? "accepted" : "submitted",
            errors = [];
        const source = {
            program_code: "BS.CS",
            program_title: "Original fictional computing",
            entry_term_code: "26/FA",
            submission: {
                form_name: "Original fictional application",
                form_version: 1,
            },
        };
        page.on("pageerror", (error) => errors.push(error.message));
        await page.route("**/sis/**", async (route) => {
            const path = new URL(route.request().url()).pathname;
            let result = {};
            if (path === "/sis/ui/metrics/")
                result = { cards: [], intake: [], pipeline: [], queue: [] };
            else if (path.startsWith("/sis/ui/data/"))
                result = {
                    items: path.includes("/applications/")
                        ? [
                              {
                                  id: app,
                                  given_name: "Fictional",
                                  family_name: "Applicant",
                                  status,
                              },
                          ]
                        : [],
                    next_after: null,
                };
            else if (path === `/sis/applications/${app}/review/`)
                result = {
                    application: { id: { value: app }, status },
                    person: {
                        id: { value: person },
                        givenName: "Fictional",
                        familyName: "Applicant",
                        institutionalId: { value: "S-FICTIONAL" },
                    },
                    labels: {
                        program_code: "BS.CS",
                        program_title: "Current fictional computing",
                        term_code: "26/FA",
                    },
                };
            else if (path.endsWith("/decision/history/"))
                result = {
                    history_recorded: decided,
                    earlier_history_unknown: false,
                    current_status: status,
                    submission: {
                        source,
                        channel: "form",
                        authorization: decided ? "admissions_staff" : "owner",
                        effective_at: "2026-08-10T10:00:00-07:00",
                        recorded_at: "2026-08-10T10:00:01-07:00",
                    },
                    ...(decided
                        ? {
                              source: {
                                  ...source,
                                  program_title: "Updated fictional computing",
                              },
                              recorded_at: "2026-08-12T12:00:00-07:00",
                              decision: {
                                  status: "accepted",
                                  effective_at: "2026-08-12T12:00:00-07:00",
                              },
                          }
                        : {}),
                };
            else throw new Error(`Unexpected request: ${path}`);
            await route.fulfill({
                json: result,
                headers: { "Cache-Control": "private, no-store" },
            });
        });
        await page.goto("/admin/");
        await page
            .locator('.workspace-nav-link[data-workspace="applications"]')
            .click();
        await page
            .getByRole("button", { name: "Fictional Applicant", exact: true })
            .click();
        await page
            .getByRole("button", { name: "Application history", exact: true })
            .click();
        const body = page.locator("#drawer-body");
        await expect(
            page.getByRole("heading", {
                name: "Application submitted",
                exact: true,
            }),
        ).toBeVisible();
        await expect(body).toContainText("Original fictional computing");
        await expect(body).toContainText(
            "Original fictional application · submitted form revision 1",
        );
        await expect(body).toContainText("2026-08-10T10:00:00-07:00");
        if (decided) {
            await expect(
                page.getByRole("heading", {
                    name: "Application accepted",
                    exact: true,
                }),
            ).toBeVisible();
            await expect(body).toContainText("Updated fictional computing");
            await expect(body).toContainText(
                "Entered by authorized admissions staff",
            );
        } else {
            await expect(body).toContainText(
                "No acceptance or rejection has been recorded yet.",
            );
            await expect(body).toContainText(
                "Submitted by the applicant’s account.",
            );
        }
        await expect(body).not.toContainText(
            "original submission time is unknown",
        );
        await page.setViewportSize({ width: 390, height: 844 });
        const scan = await new AxeBuilder({ page })
            .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
            .analyze();
        expect(scan.violations.map((item) => item.id)).toEqual([]);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
        const back = page.getByRole("button", {
            name: "Back to application",
            exact: true,
        });
        await back.focus();
        await page.keyboard.press("Enter");
        await expect(
            page.getByRole("button", {
                name: decided ? "Matriculate student" : "Accept application",
                exact: true,
            }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => localStorage.length + sessionStorage.length,
            ),
        ).toBe(0);
        expect(errors).toEqual([]);
    });
}
