const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
}
for (const known of [true, false]) {
    test(`admissions corrections retain ${known ? "recorded" : "earlier unknown"} decisions through review and history`, async ({
        page,
    }) => {
        const app = id(1),
            person = id(2);
        let status = known ? "accepted" : "rejected";
        const initial = status,
            target = known ? "rejected" : "accepted",
            posted = [],
            errors = [];
        const source = {
            program_code: "BS.CS",
            program_title: "Fictional computing",
            entry_term_code: "26/FA",
            submission: null,
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
                    application: { id: { value: app }, status },
                    person: {
                        id: { value: person },
                        givenName: "Fictional",
                        familyName: "Applicant",
                        institutionalId: { value: "S-FICTIONAL" },
                    },
                    labels: {
                        program_code: "BS.CS",
                        program_title: source.program_title,
                        term_code: "26/FA",
                    },
                };
            else if (path.endsWith("/correction/review/"))
                result = {
                    before: { status, history_recorded: known },
                    status: target,
                    source,
                    person: {
                        given_name: "Fictional",
                        family_name: "Applicant",
                        institutional_id: "S-FICTIONAL",
                    },
                    effective_on: "2026-08-10",
                    review_token: "fictional-correction-token",
                };
            else if (path.endsWith("/correction/commit/")) {
                status = target;
                result = { status };
            } else if (path.endsWith("/decision/history/"))
                result = {
                    history_recorded: true,
                    earlier_history_unknown: !known,
                    current_status: status,
                    submission: null,
                    source,
                    decision: { status: target },
                    next_cursor: "fictional-history-cursor",
                    items: [
                        {
                            kind: known ? "decision" : "observation",
                            status: initial,
                            source,
                            effective_at: known
                                ? "2026-08-01T12:00:00-07:00"
                                : null,
                            recorded_at: "2026-08-10T10:00:00-07:00",
                        },
                        {
                            kind: "correction",
                            previous_status: initial,
                            status: target,
                            source,
                            effective_at: "2026-08-10T10:01:00-07:00",
                            recorded_at: "2026-08-10T10:01:00-07:00",
                        },
                    ],
                };
            else if (path.endsWith("/decision/history/page/"))
                result = {
                    history_recorded: true,
                    current_status: status,
                    earlier_history_unknown: !known,
                    source,
                    next_cursor: null,
                    items: [
                        {
                            kind: "correction",
                            previous_status: target,
                            status: initial,
                            source,
                            effective_at: "2026-08-11T10:00:00-07:00",
                            recorded_at: "2026-08-11T10:00:00-07:00",
                        },
                    ],
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
                name: "Correct admissions decision",
                exact: true,
            })
            .click();
        await page
            .getByLabel("Reason for admissions correction")
            .fill("Fictional protected correction reason");
        await accessible(page);
        await page
            .getByRole("button", {
                name: "Review admissions correction",
                exact: true,
            })
            .click();
        await expect(
            page.getByRole("heading", {
                name: `${initial} → ${target}`,
                exact: true,
            }),
        ).toBeFocused();
        if (!known)
            await expect(page.locator("#drawer-body")).toContainText(
                "no earlier decision date will be invented",
            );
        expect(
            posted.filter((call) => call.path.endsWith("/commit/")),
        ).toHaveLength(0);
        await page
            .getByRole("button", {
                name: "Revise admissions correction",
                exact: true,
            })
            .click();
        await expect(
            page.getByLabel("Reason for admissions correction"),
        ).toHaveValue("Fictional protected correction reason");
        await page
            .getByRole("button", {
                name: "Review admissions correction",
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
                name: "Confirm admissions correction",
                exact: true,
            })
            .click();
        await page
            .getByRole("button", { name: "Application history", exact: true })
            .click();
        await expect(
            page.getByRole("heading", {
                name: `Decision corrected: ${initial} → ${target}`,
                exact: true,
            }),
        ).toBeVisible();
        if (known)
            await expect(
                page.getByRole("heading", {
                    name: "Application accepted",
                    exact: true,
                }),
            ).toBeVisible();
        else
            await expect(page.locator("#drawer-body")).toContainText(
                "Earlier decision timing is unknown",
            );
        await expect(page.locator("#drawer-body")).not.toContainText(
            "Fictional protected correction reason",
        );
        await page
            .getByRole("button", {
                name: "Next application history page",
                exact: true,
            })
            .click();
        await expect(
            page.getByRole("heading", {
                name: `Decision corrected: ${target} → ${initial}`,
                exact: true,
            }),
        ).toBeVisible();
        expect(
            posted.find((call) => call.path.endsWith("/history/page/")).data,
        ).toEqual({ cursor: "fictional-history-cursor" });
        await accessible(page);
        await page
            .getByRole("button", {
                name: "Previous application history page",
                exact: true,
            })
            .click();
        await expect(
            page.getByRole("heading", {
                name: `Decision corrected: ${initial} → ${target}`,
                exact: true,
            }),
        ).toBeVisible();
        expect(
            posted.find((call) => call.path.endsWith("/correction/commit/"))
                .data,
        ).toEqual({ review_token: "fictional-correction-token" });
        expect(
            await page.evaluate(
                () => localStorage.length + sessionStorage.length,
            ),
        ).toBe(0);
        expect(errors).toEqual([]);
    });
}

test("ordinary admissions staff do not see correction controls", async ({
    page,
}) => {
    const app = id(1),
        person = id(2);
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
                              status: "accepted",
                          },
                      ]
                    : [],
                next_after: null,
            };
        else if (path.endsWith("/review/"))
            result = {
                application: { id: { value: app }, status: "accepted" },
                person: {
                    id: { value: person },
                    givenName: "Fictional",
                    familyName: "Applicant",
                    institutionalId: { value: "S-FICTIONAL" },
                },
            };
        await route.fulfill({ json: result });
    });
    await page.goto("/admin/admissions-only-fixture/");
    await page
        .locator('.workspace-nav-link[data-workspace="applications"]')
        .click();
    await page
        .getByRole("button", { name: "Fictional Applicant", exact: true })
        .click();
    await expect(
        page.getByRole("button", { name: "Matriculate student", exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole("button", {
            name: "Correct admissions decision",
            exact: true,
        }),
    ).toHaveCount(0);
});
