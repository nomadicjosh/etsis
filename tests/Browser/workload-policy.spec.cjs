const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
async function setup(page) {
    const calls = [],
        errors = [];
    const employee = id(1),
        section = id(2),
        entry = id(3);
    let version = 0,
        minutes = null,
        policyVersion = 0,
        status = "pending",
        stale = true;
    let rules = {
        daily_minutes: null,
        weekly_minutes: null,
        overlaps: false,
        mode: "advisory",
        timezone: "America/Los_Angeles",
        contact_unit_minutes: 50,
        contact_rounding: "nearest",
    };
    const person = {
        id: employee,
        person_id: id(4),
        given_name: "Alex",
        family_name: "Example",
        institutional_id: "FIC-100",
        job_title: "Fictional instructor",
        active: true,
        is_self: false,
        revision: "a".repeat(64),
        earlier_history_unknown: false,
    };
    const time = {
        id: entry,
        employee_id: employee,
        status,
        version: 0,
        starts_at: "2026-08-10T16:00:00Z",
        ends_at: "2026-08-10T18:00:00Z",
        duration_seconds: 7200,
    };
    const calculation = (value) =>
        value == null
            ? null
            : {
                  weekly_instruction_minutes: value,
                  weekly_contact_milli: Math.round((value * 1000) / rules.contact_unit_minutes),
                  unit_minutes: rules.contact_unit_minutes,
                  rounding: rules.contact_rounding,
                  policy_version: policyVersion,
                  policy_publication_id: policyVersion ? id(9) : null,
              };
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()["x-csrf-token"] });
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/employees/") result = { items: [person], next_after: null };
        else if (path === "/sis/ui/data/sections/")
            result = {
                items: [
                    {
                        id: section,
                        code: "HIST 101.A",
                        course_id: id(6),
                        term_id: id(7),
                        term_code: "26/FA",
                        course_code: "HIST 101",
                        course_title: "Fictional History",
                        capacity: 20,
                        fee_minor: 0,
                        currency: "USD",
                        active: 1,
                    },
                ],
                next_after: null,
            };
        else if (path === `/sis/employees/${employee}/`) result = person;
        else if (path === `/sis/employees/${employee}/time-entries/`)
            result = { items: [{ ...time, status }], next_after: null };
        else if (path === `/sis/timesheets/${entry}/`)
            result = {
                ...person,
                ...time,
                id: entry,
                employee_id: employee,
                status,
                can_approve_exception: true,
                time_review: {
                    scope: "affected_weeks",
                    policy: {
                        version: 1,
                        publication_id: id(9),
                        rules: { ...rules, daily_minutes: 60, mode: "exception_required" },
                    },
                    flags: ["daily_limit"],
                    days: [{ date: "2026-08-10", seconds: 7200 }],
                    weeks: [{ week: "2026-08-10", seconds: 7200 }],
                    requires_exception: true,
                    revision: "b".repeat(64),
                },
            };
        else if (path === `/sis/timesheets/${entry}/decision/`) {
            if (stale) {
                stale = false;
                return route.fulfill({
                    status: 409,
                    json: { error: "Time entries changed. Reload the review." },
                });
            }
            status = data.approve ? "approved" : "rejected";
            result = { status: "decided" };
        } else if (path === "/sis/time-policy/")
            result = {
                rules,
                version: policyVersion,
                publication_id: policyVersion ? id(9) : null,
            };
        else if (path === "/sis/time-policy/review/")
            result = { ...data, publication_id: id(9), review_token: "fictional-reviewed-policy" };
        else if (path === "/sis/time-policy/publish/") {
            rules = data.rules;
            policyVersion++;
            result = { status: "published" };
        } else if (path === `/sis/sections/${section}/contact-hours/`) {
            if (data) {
                minutes = data.weekly_minutes;
                version++;
                result = { status: "saved" };
            } else
                result = {
                    weekly_minutes: minutes,
                    version,
                    section_code: "HIST 101.A",
                    calculation: calculation(minutes),
                    policy: {
                        unit_minutes: rules.contact_unit_minutes,
                        rounding: rules.contact_rounding,
                        version: policyVersion,
                    },
                    selected_recorded: true,
                    selected: {
                        weekly_instruction_minutes: 150,
                        weekly_contact_milli: 3000,
                        unit_minutes: 50,
                        rounding: "nearest",
                        policy_version: 0,
                        policy_publication_id: null,
                    },
                };
        }
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/");
    return { calls, errors };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) }))).toEqual(
        [],
    );
}
test("institutional time and contact policy keeps the draft through review and publishes explicitly", async ({
    page,
}) => {
    const { calls, errors } = await setup(page);
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page
        .getByRole("button", { name: "Institutional time review policy", exact: true })
        .click();
    await expect(
        page.getByLabel("Instructional minutes per contact hour", { exact: true }),
    ).toHaveValue("50");
    await page.getByLabel("Instructional minutes per contact hour", { exact: true }).fill("60");
    await page.getByLabel("Daily limit in minutes (optional)", { exact: true }).fill("480");
    await page
        .getByLabel("Reason for policy publication", { exact: true })
        .fill("Fictional institution workload review.");
    await page.getByRole("button", { name: "Review time policy", exact: true }).click();
    await page.getByRole("button", { name: "Back to policy settings", exact: true }).click();
    await expect(
        page.getByLabel("Instructional minutes per contact hour", { exact: true }),
    ).toHaveValue("60");
    await page.getByRole("button", { name: "Review time policy", exact: true }).click();
    await page.setViewportSize({ width: 320, height: 900 });
    await accessible(page);
    await page.getByRole("button", { name: "Confirm time policy", exact: true }).click();
    await expect(page.getByText("Timesheet policy published.", { exact: true })).toBeVisible();
    expect(
        calls.find((c) => c.path.endsWith("/time-policy/publish/")).data.rules.contact_unit_minutes,
    ).toBe(60);
    expect(calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    expect(errors).toEqual([]);
});
test("section contact-time editing distinguishes unknown, zero and retained weekly hours", async ({
    page,
}) => {
    const { calls, errors } = await setup(page);
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole("link", { name: "Sections", exact: true }).click();
    await page.getByRole("button", { name: "HIST 101.A", exact: true }).click();
    await page.getByRole("button", { name: "Contact hours", exact: true }).click();
    await expect(
        page.getByText("Weekly instructional contact time is unknown.", { exact: true }),
    ).toBeVisible();
    await page.getByLabel("Weekly instructional minutes (optional)", { exact: true }).fill("150");
    await page.getByRole("button", { name: "Save working contact time", exact: true }).click();
    await expect(page.getByText(/3.000 weekly contact hours/)).toHaveCount(2);
    await page.getByLabel("Weekly instructional minutes (optional)", { exact: true }).fill("0");
    await page.getByRole("button", { name: "Save working contact time", exact: true }).click();
    await expect(page.getByText(/0.000 weekly contact hours/)).toBeVisible();
    await expect(page.getByText(/3.000 weekly contact hours/)).toBeVisible();
    await page.setViewportSize({ width: 320, height: 900 });
    await accessible(page);
    expect(calls.filter((c) => c.path.endsWith("/contact-hours/"))).toHaveLength(2);
    expect(errors).toEqual([]);
});
test("time exception approval shows flags, preserves the reason after stale rejection and binds the displayed review", async ({
    page,
}) => {
    const { calls, errors } = await setup(page);
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await page.getByRole("button", { name: "Review time entry", exact: true }).click();
    await expect(page.getByText("Daily time limit exceeded", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Approve time entry", exact: true }).click();
    await page
        .getByRole("textbox", { name: "Reason for this time-policy exception", exact: true })
        .fill("Fictional verified extra instruction.");
    await page.getByRole("button", { name: "Confirm time approval", exact: true }).click();
    await expect(
        page.getByRole("textbox", { name: "Reason for this time-policy exception", exact: true }),
    ).toHaveValue("Fictional verified extra instruction.");
    await expect(page.locator("#record-drawer")).toContainText("Time entries changed.");
    const decision = calls.find((c) => c.path.endsWith("/decision/"));
    expect(decision.data.review_revision).toBe("b".repeat(64));
    expect(decision.data.exception_reason).toBe("Fictional verified extra instruction.");
    await page.getByRole("button", { name: "Back to record", exact: true }).click();
    await page.getByRole("button", { name: "Approve time entry", exact: true }).click();
    await page
        .getByRole("textbox", { name: "Reason for this time-policy exception", exact: true })
        .fill("Fictional verified extra instruction.");
    await page.getByRole("button", { name: "Confirm time approval", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("approved");
    expect(errors).toEqual([]);
});
