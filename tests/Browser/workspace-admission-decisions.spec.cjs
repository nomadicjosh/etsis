const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (number) => String(number).padStart(26, "0");
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
}
for (const accept of [true, false]) {
    test(`admissions decisions review, revise, confirm and retain ${accept ? "acceptance" : "rejection"} history`, async ({
        page,
    }) => {
        const app = id(1),
            person = id(2);
        let status = "submitted";
        const posted = [],
            errors = [];
        const outcome = accept ? "accepted" : "rejected";
        const source = {
            program_code: "BS.CS",
            program_title: "Fictional computing",
            entry_term_code: "26/FA",
            submission: accept
                ? { form_name: "Original fictional form", form_version: 1 }
                : null,
        };
        page.on("pageerror", (error) => errors.push(error.message));
        await page.route("**/sis/**", async (route) => {
            const path = new URL(route.request().url()).pathname;
            const raw = route.request().postData();
            const data = raw
                ? JSON.parse(new URLSearchParams(raw).get("_workspace_json"))
                : null;
            if (data) posted.push({ path, data });
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
                    application: {
                        id: { value: app },
                        status,
                        version: status === "submitted" ? 0 : 1,
                    },
                    person: {
                        id: { value: person },
                        givenName: "Fictional",
                        familyName: "Applicant",
                        institutionalId: { value: "S-FICTIONAL" },
                    },
                    labels: {
                        program_code: source.program_code,
                        program_title: source.program_title,
                        term_code: source.entry_term_code,
                    },
                };
            else if (path.endsWith("/decision/review/"))
                result = {
                    status: outcome,
                    source,
                    person: {
                        given_name: "Fictional",
                        family_name: "Applicant",
                        institutional_id: "S-FICTIONAL",
                    },
                    effective_on: "2026-08-10",
                    review_token: "fictional-protected-decision-token",
                };
            else if (path.endsWith("/decision/commit/")) {
                status = outcome;
                result = { status };
            } else if (path.endsWith("/decision/history/"))
                result = {
                    history_recorded: true,
                    earlier_history_unknown: true,
                    current_status: status,
                    source,
                    recorded_at: "2026-08-10T10:00:00-07:00",
                    decision: {
                        application_id: app,
                        decision_id: id(3),
                        status: outcome,
                        version: 1,
                        effective_at: "2026-08-10T10:00:00-07:00",
                    },
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
            .getByRole("button", {
                name: accept ? "Accept application" : "Reject application",
                exact: true,
            })
            .click();
        await page
            .getByLabel("Reason for admissions decision")
            .fill("Fictional protected decision reason");
        await accessible(page);
        await page
            .getByRole("button", {
                name: "Review admissions decision",
                exact: true,
            })
            .click();
        await expect(
            page.getByRole("heading", {
                name: `Fictional Applicant · ${outcome}`,
                exact: true,
            }),
        ).toBeFocused();
        await expect(page.locator("#drawer-body")).toContainText(
            "BS.CS · Fictional computing · 26/FA",
        );
        await expect(page.locator("#drawer-body")).toContainText(
            accept
                ? "Original fictional form · submitted form revision 1"
                : "entered directly",
        );
        expect(
            posted.filter((call) => call.path.endsWith("/commit/")),
        ).toHaveLength(0);
        await page
            .getByRole("button", {
                name: "Revise admissions decision",
                exact: true,
            })
            .click();
        await expect(
            page.getByLabel("Reason for admissions decision"),
        ).toHaveValue("Fictional protected decision reason");
        await page
            .getByRole("button", {
                name: "Review admissions decision",
                exact: true,
            })
            .click();
        await page.setViewportSize({ width: 390, height: 844 });
        await accessible(page);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
        await page
            .getByRole("button", {
                name: accept ? "Confirm acceptance" : "Confirm rejection",
                exact: true,
            })
            .click();
        if (accept)
            await expect(
                page.getByRole("button", {
                    name: "Matriculate student",
                    exact: true,
                }),
            ).toBeVisible();
        else
            await expect(
                page.getByRole("button", {
                    name: "Matriculate student",
                    exact: true,
                }),
            ).toHaveCount(0);
        await page
            .getByRole("button", {
                name: "Application history",
                exact: true,
            })
            .click();
        await expect(
            page.getByRole("heading", {
                name: accept ? "Application accepted" : "Application rejected",
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.locator("#drawer-body")).toContainText(
            "original submission time is unknown",
        );
        await expect(page.locator("#drawer-body")).not.toContainText(
            "Fictional protected decision reason",
        );
        expect(
            posted.find((call) => call.path.endsWith("/commit/")).data,
        ).toEqual({ review_token: "fictional-protected-decision-token" });
        expect(
            posted.find(
                (call) =>
                    call.path.endsWith("/review/") &&
                    call.path.includes("/decision/"),
            ).data,
        ).toEqual({
            decision: accept ? "accept" : "reject",
            reason: "Fictional protected decision reason",
        });
        await accessible(page);
        expect(
            await page.evaluate(
                () => localStorage.length + sessionStorage.length,
            ),
        ).toBe(0);
        expect(errors).toEqual([]);
    });
}
