const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (suffix) => `01K000000000000000000000${String(suffix).padStart(2, "0")}`;
async function fixture(page, options = {}) {
    const calls = [],
        errors = [],
        operations = new Map();
    const student = id(41),
        award = id(42),
        equivalency = id(43),
        institution = id(44),
        external = id(45),
        course = id(46),
        creditId = id(47);
    let serial = 70,
        interrupted = false;
    let periodReview;
    let periodCommitted = false,
        periodInterrupted = false;
    const periodDecisions = [];
    const value = {
        id: award,
        student_id: student,
        equivalency_id: equivalency,
        institution_id: institution,
        given_name: "Alex",
        family_name: "Example",
        institutional_id: "S-FICTIONAL",
        institution_name: "Fictional College",
        external_code: "EXT.101",
        completed_on: "2026-05-01",
        term_code: "26/FA",
        effective_on_default: "2026-08-10",
        earlier_history_unknown: !!options.legacy,
        history_recorded: !options.legacy && !options.untracked,
        academic_on: options.periodKnown ? "2026-01-12" : null,
        grade_code: "TR",
        counts_gpa: false,
        credit: {
            id: creditId,
            source_id: award,
            credit_type: "transfer",
            course_code: "MATH.101",
            course_title: "Fictional algebra",
            attempted_milli: 3000,
            completed_milli: 3000,
            grade_points_milli: 0,
            grade_id: id(48),
            version: 0,
            status: "A",
        },
    };
    if (options.legacy) {
        const before = {
            ...value.credit,
            attempted_milli: 2000,
            completed_milli: 2000,
            grade_code: "TR",
            counts_gpa: false,
        };
        value.credit.version = 1;
        operations.set(id(55), {
            operation_id: id(55),
            award_id: award,
            credit_id: creditId,
            actor_id: id(49),
            action: "amend",
            version: 1,
            created_at: "2026-07-01T17:00:00+00:00",
            before,
            after: { ...value.credit, grade_code: "TR", counts_gpa: false },
            reason: "Earlier fictional receipt.",
            history_source: "legacy_receipt",
            effective_at: null,
        });
    }
    const entry = {
        id: equivalency,
        institution_id: institution,
        external_course_id: external,
        course_id: course,
        institution_code: "EXT",
        institution_name: "Fictional College",
        external_code: "EXT.101",
        external_title: "External algebra",
        course_code: "MATH.101",
        course_title: "Fictional algebra",
        credits_milli: 3000,
        awarded_grade: "TR",
        minimum_grade_rank: 2,
        starts_on: "2020-01-01",
        ends_on: null,
        counts_gpa: 0,
        points_milli: 0,
        revision: "a".repeat(64),
    };
    const endpoint = `/sis/students/${student}/transfer-awards/${award}/`;
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname,
            raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        calls.push({ path, data, csrf: route.request().headers()["x-csrf-token"] });
        let result = {},
            status = 200;
        if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/operation-id/") result = { id: id(serial++) };
        else if (path.startsWith("/sis/ui/data/"))
            result = { items: path.includes("/equivalencies/") ? [entry] : [], next_after: null };
        else if (path === `/sis/ui/transfer/equivalencies/${equivalency}/`) result = entry;
        else if (path.endsWith("/awards/"))
            result = {
                items: [
                    { ...value, credits_milli: value.credit.completed_milli, status: value.credit.status },
                ],
                next_after: null,
            };
        else if (path === endpoint) result = structuredClone(value);
        else if (path === endpoint + "source-period/review/") {
            if (options.rejectPeriod) {
                status = 409;
                result = { error: "The transfer award changed. Reload before reviewing its period." };
            } else {
                periodReview = {
                    previous_academic_on: value.academic_on,
                    academic_on: data.academic_on,
                    reason: data.reason,
                    review_token: "fictional-source-period-review",
                };
                result = periodReview;
            }
        } else if (path === endpoint + "source-period/commit/") {
            if (!periodCommitted) {
                periodDecisions.push({
                    previous_academic_on: periodReview.previous_academic_on,
                    academic_on: periodReview.academic_on,
                    action: "period_recorded",
                    effective_at: periodReview.academic_on || "2026-08-10",
                    recorded_at: "2026-08-10",
                });
                value.academic_on = periodReview.academic_on;
                value.credit.version++;
                periodCommitted = true;
            }
            result = { operation_id: id(90), first_version: 2, last_version: 2, event_ids: [id(91)] };
            if (options.interruptPeriod && !periodInterrupted) {
                periodInterrupted = true;
                status = 500;
                result = { error: "The confirmation response was interrupted. Try confirming again." };
            }
        } else if (path === endpoint + "source-period/history/") {
            result = {
                items: options.untracked
                    ? []
                    : data.cursor
                      ? periodDecisions
                      : [{ action: "award", effective_at: "2026-05-01", recorded_at: "2026-08-01" }],
                next_cursor: !data.cursor && periodDecisions.length ? "fictional-frozen-source-cursor" : null,
                earlier_history_unknown: !!options.untracked,
            };
        } else if (path === endpoint + "preview/") {
            const before = { ...value.credit, grade_code: value.grade_code, counts_gpa: value.counts_gpa };
            const after = {
                ...before,
                status: data.action === "void" ? "V" : "A",
                attempted_milli: data.credits_milli || 0,
                completed_milli: data.credits_milli || 0,
                grade_code: data.awarded_grade || null,
                grade_points_milli: data.awarded_grade === "B" ? data.credits_milli * 3 : 0,
                counts_gpa: data.awarded_grade === "B",
                version: before.version + 1,
            };
            result = {
                record: structuredClone(value),
                request: {
                    ...data,
                    awarded_grade: data.awarded_grade || null,
                    credits_milli: data.credits_milli || null,
                },
                before,
                after,
                revision: "b".repeat(64),
            };
        } else if (path === endpoint + "confirm/") {
            if (!operations.has(data.operation_id)) {
                const before = {
                    ...value.credit,
                    grade_code: value.grade_code,
                    counts_gpa: value.counts_gpa,
                };
                value.credit = {
                    ...value.credit,
                    status: data.action === "void" ? "V" : "A",
                    attempted_milli: data.credits_milli || 0,
                    completed_milli: data.credits_milli || 0,
                    grade_points_milli: data.awarded_grade === "B" ? data.credits_milli * 3 : 0,
                    version: value.credit.version + 1,
                };
                value.grade_code = data.awarded_grade;
                value.counts_gpa = data.awarded_grade === "B";
                operations.set(data.operation_id, {
                    operation_id: data.operation_id,
                    award_id: award,
                    credit_id: creditId,
                    actor_id: id(49),
                    action: data.action,
                    version: value.credit.version,
                    created_at: "2026-08-10T17:00:00+00:00",
                    before,
                    after: { ...value.credit, grade_code: value.grade_code, counts_gpa: value.counts_gpa },
                    reason: data.reason,
                    history_source: "journal",
                    effective_at: data.effective_on + "T07:00:00.000000Z",
                });
            }
            result = operations.get(data.operation_id);
            if (options.interrupt && !interrupted) {
                interrupted = true;
                status = 500;
                result = { error: "Unable to complete the request." };
            }
        } else if (path.startsWith(endpoint + "changes/")) result = operations.get(path.split("/").at(-2));
        else if (path === endpoint + "history/")
            result = { items: [...operations.values()].reverse(), next_after: null };
        await route.fulfill({ status, json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto(options.limited ? "/admin/transfer-only-fixture/" : "/admin/transfer-fixture/");
    await page.locator('.workspace-nav-link[data-workspace="institutions"]').click();
    await page.locator('#directory-tabs [data-workspace="equivalencies"]').click();
    await page.getByRole("button", { name: "Open EXT.101 → MATH.101", exact: true }).click();
    if (!options.limited) await page.getByRole("button", { name: "Review award", exact: true }).click();
    return { calls, errors, operations, endpoint };
}
async function correction(page, action = "Correct credits or grade") {
    await page.getByRole("button", { name: action, exact: true }).click();
    if (action !== "Void this award") {
        if (action === "Correct credits or grade")
            await expect(page.getByLabel("Credits awarded", { exact: false })).toHaveValue("3");
        await page.getByLabel("Credits awarded", { exact: false }).fill("3.125");
        await page.getByLabel("Awarded grade code", { exact: false }).fill("B");
    }
    await page
        .getByLabel("Registrar reason", { exact: false })
        .fill("Fictional correction <script>private</script>");
    await expect(page.getByRole("textbox", { name: "Effective date", exact: true })).toHaveValue(
        "2026-08-10",
    );
    await page.getByRole("textbox", { name: "Effective date", exact: true }).fill("2026-08-09");
    await page.getByRole("button", { name: "Review proposed correction", exact: true }).click();
}

test("registrar reviews a correction before saving and sees encrypted-history values as escaped text", async ({
    page,
}) => {
    const f = await fixture(page);
    await correction(page);
    await expect(
        page.getByRole("region", { name: "Transfer correction comparison", exact: true }),
    ).toContainText("3.125");
    expect(f.calls.filter((call) => call.path.endsWith("/confirm/"))).toHaveLength(0);
    await page.screenshot({ path: test.info().outputPath("transfer-correction-review.png"), fullPage: true });
    await page.getByRole("button", { name: "Confirm academic correction", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Saved correction");
    const write = f.calls.find((call) => call.path.endsWith("/confirm/"));
    expect(write).toMatchObject({
        data: {
            action: "amend",
            credits_milli: 3125,
            awarded_grade: "B",
            effective_on: "2026-08-09",
            revision: "b".repeat(64),
            operation_id: id(70),
        },
        csrf: "fictional-test-token",
    });
    await page.getByRole("button", { name: "Correction history", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Fictional correction <script>private</script>");
    await expect(page.locator("#drawer-body script")).toHaveCount(0);
    await expect(page.locator("#drawer-body")).toContainText("Effective: 2026-08-09");
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test("voiding and reinstatement are explicit reviewed actions", async ({ page }) => {
    const f = await fixture(page);
    await correction(page, "Void this award");
    await expect(page.locator('#drawer-body input[name="credits_milli"]')).toHaveCount(0);
    await page.getByRole("button", { name: "Confirm academic correction", exact: true }).click();
    await page.getByRole("button", { name: "Review current award", exact: true }).click();
    await expect(page.getByRole("button", { name: "Correct credits or grade", exact: true })).toHaveCount(0);
    await correction(page, "Reinstate this award");
    await page.getByRole("button", { name: "Confirm academic correction", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Saved reinstatement");
    expect(f.calls.filter((call) => call.path.endsWith("/confirm/")).map((call) => call.data.action)).toEqual(
        ["void", "reinstate"],
    );
    expect(f.errors).toEqual([]);
});

test("interrupted confirmation retains the same operation and permits receipt-based recovery", async ({
    page,
}) => {
    const f = await fixture(page, { interrupt: true });
    await correction(page);
    await page.getByRole("button", { name: "Confirm academic correction", exact: true }).click();
    await expect(page.locator("#drawer-error")).toBeVisible();
    await expect(page.getByRole("button", { name: "Change correction", exact: true })).toBeDisabled();
    await page.getByRole("button", { name: "Check saved correction", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Saved correction");
    expect(f.operations.size).toBe(1);
    expect(f.calls.filter((call) => call.path.endsWith("/confirm/"))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});

test("transfer catalog access alone does not expose academic correction controls or endpoints", async ({
    page,
}) => {
    const f = await fixture(page, { limited: true });
    await expect(page.getByRole("button", { name: "Review award", exact: true })).toHaveCount(0);
    expect(f.calls.some((call) => call.path.includes("/transfer-awards/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("an unchanged retry after an interrupted confirmation uses one durable academic operation", async ({
    page,
}) => {
    const f = await fixture(page, { interrupt: true });
    await correction(page);
    await page.getByRole("button", { name: "Confirm academic correction", exact: true }).click();
    await expect(page.locator("#drawer-error")).toBeVisible();
    await page.getByRole("button", { name: "Confirm academic correction", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Saved correction");
    const writes = f.calls.filter((call) => call.path.endsWith("/confirm/"));
    expect(writes).toHaveLength(2);
    expect(writes[1].data).toEqual(writes[0].data);
    expect(f.operations.size).toBe(1);
    expect(f.errors).toEqual([]);
});

test("changing a review preserves the effective date and private correction fields", async ({ page }) => {
    const f = await fixture(page);
    await correction(page);
    await page.getByRole("button", { name: "Change correction", exact: true }).click();
    await expect(page.getByRole("textbox", { name: "Effective date", exact: true })).toHaveValue(
        "2026-08-09",
    );
    await expect(page.getByLabel("Credits awarded", { exact: false })).toHaveValue("3.125");
    await expect(page.getByLabel("Registrar reason", { exact: false })).toHaveValue(
        "Fictional correction <script>private</script>",
    );
    await page.getByRole("textbox", { name: "Effective date", exact: true }).fill("2026-08-08");
    await page.getByRole("button", { name: "Review proposed correction", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Effective date: 2026-08-08");
    expect(f.calls.some((c) => c.path.endsWith("/confirm/"))).toBe(false);
    expect(f.errors).toEqual([]);
});
test("earlier saved receipts show their limited provenance without an invented effective date", async ({
    page,
}) => {
    const f = await fixture(page, { legacy: true });
    await expect(page.locator("#drawer-body")).toContainText("Earlier award decisions are unknown");
    await page.getByRole("button", { name: "Correction history", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText(
        "Earlier saved receipt · effective time unknown",
    );
    await expect(page.locator("#drawer-body")).toContainText("Earlier fictional receipt.");
    expect(f.calls.some((c) => c.path.endsWith("/confirm/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("registrar reviews a source period independently from completion, credits and the posting term", async ({
    page,
}) => {
    const f = await fixture(page);
    await expect(page.locator("#drawer-body")).toContainText("Sending institution's period start: Unknown");
    await page.getByRole("button", { name: "Review source academic period", exact: true }).click();
    const date = page.getByLabel("Sending institution's period start (blank marks unknown)", { exact: true });
    await expect(date).toHaveJSProperty("required", false);
    await expect(date).toHaveAttribute("max", "2026-05-01");
    await date.fill("2026-01-12");
    const reason = page.getByRole("textbox", { name: "Reason and source evidence", exact: true });
    await expect(reason).toHaveJSProperty("required", true);
    await reason.fill("Fictional <b>source</b> transcript verification.");
    await page.getByRole("button", { name: "Review source period", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Unknown → 2026-01-12");
    await expect(page.locator("#drawer-body b")).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("source-period/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Revise source period", exact: true }).click();
    await expect(page.getByRole("textbox", { name: "Reason and source evidence", exact: true })).toHaveValue(
        "Fictional <b>source</b> transcript verification.",
    );
    await page.getByRole("button", { name: "Review source period", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    await page.getByRole("button", { name: "Confirm source period", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText(
        "Sending institution's period start: 2026-01-12",
    );
    await expect(page.locator("#drawer-body")).toContainText("Completed 2026-05-01 · Posting term 26/FA");
    const review = f.calls.find((c) => c.path.endsWith("source-period/review/"));
    expect(review).toMatchObject({
        data: {
            version: 0,
            academic_on: "2026-01-12",
            reason: "Fictional <b>source</b> transcript verification.",
        },
        csrf: "fictional-test-token",
    });
    expect(f.calls.find((c) => c.path.endsWith("source-period/commit/"))).toMatchObject({
        data: { review_token: "fictional-source-period-review" },
        csrf: "fictional-test-token",
    });
    expect(f.calls.some((c) => c.path === f.endpoint + "confirm/")).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("clearing source period is explicit and curated history pages preserve the previous date", async ({
    page,
}) => {
    const f = await fixture(page, { periodKnown: true });
    await page.getByRole("button", { name: "Review source academic period", exact: true }).click();
    await page
        .getByLabel("Sending institution's period start (blank marks unknown)", { exact: true })
        .fill("");
    await page
        .getByRole("textbox", { name: "Reason and source evidence", exact: true })
        .fill("Fictional source evidence withdrawn.");
    await page.getByRole("button", { name: "Review source period", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2026-01-12 → Unknown");
    await page.getByRole("button", { name: "Confirm source period", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Sending institution's period start: Unknown");
    await page.getByRole("button", { name: "Source period history", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Award established");
    await page.getByRole("button", { name: "Next source history page", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2026-01-12 → Unknown");
    await expect(page.locator("#drawer-body")).not.toContainText("Fictional source evidence withdrawn");
    await page.getByRole("button", { name: "Previous source history page", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Award established");
    expect(f.calls.find((c) => c.path.endsWith("source-period/review/"))?.data.academic_on).toBeNull();
    expect(
        f.calls.some(
            (c) =>
                c.path.endsWith("source-period/history/") &&
                c.data.cursor === "fictional-frozen-source-cursor",
        ),
    ).toBe(true);
    expect(f.errors).toEqual([]);
});

test("untracked source history cannot invent a sending period", async ({ page }) => {
    const f = await fixture(page, { untracked: true });
    await expect(
        page.getByRole("button", { name: "Review source academic period", exact: true }),
    ).toHaveCount(0);
    await page.getByRole("button", { name: "Source period history", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Earlier source history is unknown");
    await expect(page.locator("#drawer-body")).toContainText("No recorded source decisions on this page");
    expect(f.calls.some((c) => c.path.endsWith("source-period/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("a stale source review keeps entered fields and never confirms the date", async ({ page }) => {
    const f = await fixture(page, { rejectPeriod: true });
    await page.getByRole("button", { name: "Review source academic period", exact: true }).click();
    await page
        .getByLabel("Sending institution's period start (blank marks unknown)", { exact: true })
        .fill("2026-01-12");
    await page
        .getByRole("textbox", { name: "Reason and source evidence", exact: true })
        .fill("Fictional concurrent source review.");
    await page.getByRole("button", { name: "Review source period", exact: true }).click();
    await expect(page.locator("#drawer-error")).toContainText("The transfer award changed");
    await expect(
        page.getByLabel("Sending institution's period start (blank marks unknown)", { exact: true }),
    ).toHaveValue("2026-01-12");
    expect(f.calls.some((c) => c.path.endsWith("source-period/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("an interrupted confirmation reuses the same reviewed source-period command", async ({ page }) => {
    const f = await fixture(page, { interruptPeriod: true });
    await page.getByRole("button", { name: "Review source academic period", exact: true }).click();
    await page
        .getByLabel("Sending institution's period start (blank marks unknown)", { exact: true })
        .fill("2026-01-12");
    await page
        .getByRole("textbox", { name: "Reason and source evidence", exact: true })
        .fill("Fictional reviewed source.");
    await page.getByRole("button", { name: "Review source period", exact: true }).click();
    await page.getByRole("button", { name: "Confirm source period", exact: true }).click();
    await expect(page.locator("#drawer-error")).toBeVisible();
    await page.getByRole("button", { name: "Confirm source period", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText(
        "Sending institution's period start: 2026-01-12",
    );
    const calls = f.calls.filter((c) => c.path.endsWith("source-period/commit/"));
    expect(calls).toHaveLength(2);
    expect(calls[0].data).toEqual(calls[1].data);
    expect(f.errors).toEqual([]);
});
