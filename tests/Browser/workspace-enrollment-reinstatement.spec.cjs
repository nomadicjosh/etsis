const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
async function fixture(page, mode = "retry") {
    const calls = [],
        errors = [];
    let restored = false,
        confirms = 0,
        reviews = 0;
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname;
        const data = req.postData()
            ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json"))
            : {};
        calls.push({ path, data, csrf: req.headers()["x-csrf-token"] });
        let result = { items: [], next_after: null },
            status = 200;
        if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/")
            result.items = [
                {
                    id: id(1),
                    given_name: "Alex",
                    family_name: "Example",
                    institutional_id: "FICTIONAL",
                    active: true,
                },
            ];
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
            result.items = [
                {
                    enrollment_id: id(2),
                    section_id: id(3),
                    section_code: "HIST.101.A",
                    term_code: "26/FA",
                    status: restored ? "A" : "W",
                    current_records_removed: false,
                    earlier_history_unknown: false,
                    items: [
                        {
                            action: "registered",
                            section_sequence: 1,
                            effective_at: "2026-08-10T17:00:00Z",
                            recorded_at: "2026-08-10T17:00:00Z",
                        },
                        {
                            action: "late_drop",
                            section_sequence: 2,
                            effective_at: "2026-08-20T17:00:00Z",
                            recorded_at: "2026-08-20T17:00:00Z",
                        },
                        ...(restored
                            ? [
                                  {
                                      action: "reinstated",
                                      section_sequence: 3,
                                      effective_at: "2026-08-21T17:00:00Z",
                                      recorded_at: "2026-08-21T17:00:00Z",
                                  },
                              ]
                            : []),
                    ],
                },
            ];
        else if (path.endsWith("/reinstatement/review/")) {
            reviews++;
            result = {
                operation_id: id(reviews + 10),
                enrollment_id: id(2),
                revision: "a".repeat(64),
                review_token: `fictional-review-${reviews}`,
                reason: data.reason,
                can_confirm: mode !== "blocked" || reviews > 1,
                previous_status: "W",
                restored_status: "A",
                withdrawn_at: "2026-08-20T17:00:00Z",
                attempted_milli: 3000,
                financial_effect: "preserve_existing_postings",
                section: {
                    code: "HIST.101.A",
                    section_version_id: null,
                    prerequisite_courses: [],
                    corequisite_courses: [],
                },
                student: {
                    given_name: "Alex",
                    family_name: "Example",
                    schedule: {
                        status: "verified",
                        conflict_count: 0,
                        unpublished_count: 0,
                        blocked: false,
                    },
                    readiness: [
                        {
                            label: "Waitlist priority",
                            status: mode === "blocked" && reviews === 1 ? "blocked" : "met",
                            message: "Earlier accepted waitlist requests have priority.",
                            next_step: "Review the queue.",
                        },
                    ],
                    prerequisites: { status: "met" },
                    corequisites: { status: "not_required" },
                },
            };
            if (mode === "validation" && reviews === 1) {
                status = 422;
                result = {
                    error: "Please correct the highlighted fields.",
                    errors: [
                        {
                            field: "reason",
                            message:
                                "Give a clear reason for this fictional withdrawal correction.",
                        },
                    ],
                };
            }
        } else if (path.endsWith("/reinstatement/confirm/")) {
            confirms++;
            if (mode === "denied") {
                status = 403;
                result = { error: "Access changed." };
            } else if (mode === "retry" && confirms === 1) {
                status = 503;
                result = { error: "Fictional interrupted response. Retry the same confirmation." };
            } else {
                restored = true;
                result = {
                    enrollment_id: id(2),
                    academic_credit_id: id(4),
                    status: "A",
                    version: 2,
                };
            }
        }
        await route.fulfill({
            status,
            json: result,
            headers: { "Cache-Control": "private, no-store" },
        });
    });
    await page.goto("/admin/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator("#directory-search").fill("Alex");
    await page.locator("#directory-search").press("Enter");
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await page.getByRole("button", { name: "Registration history", exact: true }).click();
    await page.getByRole("button", { name: "Reinstate this withdrawal", exact: true }).click();
    return { calls, errors };
}
async function review(page) {
    await page
        .getByLabel("Reason for reinstating this withdrawal")
        .fill("Fictional reviewed correction <img src=x onerror=alert(1)>");
    await page.getByRole("button", { name: "Review reinstatement", exact: true }).click();
    await expect(
        page.getByRole("button", { name: "Confirm reinstatement", exact: true }),
    ).toBeVisible();
}
test("registrar reviews preserved sources and money, retries the same proof and reads both historical decisions", async ({
    page,
}) => {
    const f = await fixture(page);
    await review(page);
    await expect(page.locator("#drawer-body")).toContainText(
        "Existing tuition, fee, payment and refund postings stay unchanged",
    );
    await expect(page.locator("#drawer-body")).toContainText(
        "An original offering publication was not recorded",
    );
    await expect(page.locator("#drawer-body img")).toHaveCount(0);
    await page.setViewportSize({ width: 320, height: 800 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => v.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
    await page.getByRole("button", { name: "Confirm reinstatement", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Fictional interrupted response");
    await page.getByRole("button", { name: "Confirm reinstatement", exact: true }).click();
    await expect(page.getByRole("status")).toContainText("Enrollment reinstated");
    const confirms = f.calls.filter((c) => c.path.endsWith("/reinstatement/confirm/"));
    expect(confirms).toHaveLength(2);
    expect(confirms[0]).toEqual(confirms[1]);
    expect(confirms[0].csrf).toBe("fictional-test-token");
    await page.getByRole("button", { name: "View registration history", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Withdrawal reinstated");
    await expect(page.locator("#drawer-body")).toContainText("Late drop / withdrawal");
    await expect(
        page.getByRole("button", { name: "Reinstate this withdrawal", exact: true }),
    ).toHaveCount(0);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
test("blocked review can be revised or refreshed with its reason retained", async ({ page }) => {
    const f = await fixture(page, "blocked");
    await review(page);
    await expect(
        page.getByRole("button", { name: "Confirm reinstatement", exact: true }),
    ).toBeDisabled();
    await page.getByText("Registration checks", { exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText(
        "Earlier accepted waitlist requests have priority",
    );
    await page.getByRole("button", { name: "Revise reason", exact: true }).click();
    await expect(page.getByLabel("Reason for reinstating this withdrawal")).toHaveValue(
        /Fictional reviewed correction/,
    );
    await page.getByRole("button", { name: "Review reinstatement", exact: true }).click();
    await page.getByRole("button", { name: "Refresh reinstatement review", exact: true }).click();
    await expect(
        page.getByRole("button", { name: "Confirm reinstatement", exact: true }),
    ).toBeEnabled();
    expect(f.calls.filter((c) => c.path.endsWith("/reinstatement/review/"))).toHaveLength(3);
    await page.getByRole("button", { name: "Confirm reinstatement", exact: true }).click();
    await expect(page.getByRole("status")).toContainText("Enrollment reinstated");
    expect(f.errors).toEqual([]);
});
test("authority loss clears the reason and proof from the current task", async ({ page }) => {
    const f = await fixture(page, "denied");
    await review(page);
    await page.getByRole("button", { name: "Confirm reinstatement", exact: true }).click();
    await expect(page.getByRole("alert")).toContainText("Access to reinstatement changed");
    await expect(
        page.getByRole("button", { name: "Confirm reinstatement", exact: true }),
    ).toHaveCount(0);
    await expect(page.locator("#drawer-body")).not.toContainText("Fictional reviewed correction");
    expect(f.errors).toEqual([]);
});

test("an interrupted confirmation response cannot replace the revised reason form", async ({
    page,
}) => {
    const f = await fixture(page, "delayed");
    await review(page);
    let pending;
    await page.route("**/reinstatement/confirm/", (route) => {
        pending = route;
    });
    await page.getByRole("button", { name: "Confirm reinstatement", exact: true }).click();
    await expect.poll(() => Boolean(pending)).toBe(true);
    await page.getByRole("button", { name: "Revise reason", exact: true }).click();
    const response = page.waitForResponse((url) => url.url().endsWith("/reinstatement/confirm/"));
    await pending.fulfill({ json: { enrollment_id: id(2), status: "A", version: 2 } });
    await response;
    await expect(page.getByLabel("Reason for reinstating this withdrawal")).toHaveValue(
        /Fictional reviewed correction/,
    );
    await expect(
        page.getByRole("button", { name: "Review reinstatement", exact: true }),
    ).toBeVisible();
    await expect(page.locator("#drawer-body")).not.toContainText("Enrollment reinstated.");
    expect(f.errors).toEqual([]);
});

test("reason validation explains the field error and preserves editing", async ({ page }) => {
    const f = await fixture(page, "validation");
    await page
        .getByLabel("Reason for reinstating this withdrawal")
        .fill("Fictional invalid reason");
    await page.getByRole("button", { name: "Review reinstatement", exact: true }).click();
    await expect(page.locator("#drawer-body [role=alert]")).toContainText("Give a clear reason");
    await expect(page.getByLabel("Reason for reinstating this withdrawal")).toHaveValue(
        "Fictional invalid reason",
    );
    await review(page);
    await expect(
        page.getByRole("button", { name: "Confirm reinstatement", exact: true }),
    ).toBeEnabled();
    expect(f.errors).toEqual([]);
});
