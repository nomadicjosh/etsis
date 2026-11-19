const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (number) => String(number).padStart(26, "0");

// All institutional endpoints use fictional responses; no application database is opened.
async function fixture(
    page,
    {
        interruptedCommit = false,
        postedDuringReview = false,
        noScale = false,
        holdCommit = false,
        ambiguousProgram = false,
        coursework = false,
    } = {},
) {
    const section = id(801),
        grade = id(802),
        calls = [],
        posted = new Map(),
        errors = [];
    const rows = [
        {
            id: id(813),
            student_id: id(823),
            given_name: "Alex",
            family_name: "Example",
            institutional_id: "F-1",
            status: "A",
            grade_code: null,
        },
        {
            id: id(812),
            student_id: id(822),
            given_name: "Jamie",
            family_name: "Example",
            institutional_id: "F-2",
            status: "N",
            grade_code: null,
        },
        {
            id: id(811),
            student_id: id(821),
            given_name: "Rowan",
            family_name: "Other",
            institutional_id: "F-3",
            status: "A",
            grade_code: null,
        },
    ];
    let releaseCommit;
    const waitingCommit = new Promise((resolve) => {
        releaseCommit = resolve;
    });
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname;
        const data = req.postData()
            ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json"))
            : {};
        calls.push({ path, data, csrf: req.headers()["x-csrf-token"] });
        let result = { items: [], next_after: null, counts: {}, term: null };
        if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/teaching/")
            result = {
                items: [{ id: section, code: "FIC.101.A", course_title: "Fictional course", capacity: 20 }],
                next_after: null,
            };
        else if (path.includes("/context/roster/"))
            result = {
                items: rows.filter(
                    (row) =>
                        (!data.after || row.id < data.after) &&
                        (!data.search || `${row.given_name} ${row.family_name}`.includes(data.search)) &&
                        (data.grade_status !== "needs_grade" || !posted.has(row.student_id)),
                ),
                next_after: null,
                can_grade: true,
            };
        else if (path.endsWith("/grade-choices/")) {
            const student = path.split("/")[5];
            const alreadyPosted = postedDuringReview && student === rows[0].student_id;
            if (alreadyPosted) posted.set(student, "another-authorized-review");
            result = {
                items:
                    noScale && student === rows[1].student_id
                        ? []
                        : [{ id: grade, code: "P", points_milli: 4000 }],
                posted: alreadyPosted,
                current_grade_code: alreadyPosted ? "P" : null,
                current_version: alreadyPosted ? 1 : 0,
                effective_on_default: "2026-08-11",
                needs_assignment: ambiguousProgram && !data.assignment_id,
                pursuits: [
                    { id: id(831), code: "BA", title: "Fictional arts" },
                    { id: id(832), code: "BS", title: "Fictional science" },
                ],
                coursework:
                    coursework && student === rows[0].student_id
                        ? {
                              available: true,
                              score_display: "96%",
                              grade_code: "P",
                              grade_id: grade,
                              revision: "fictional-coursework-revision",
                          }
                        : null,
            };
        } else if (path.endsWith("/final-grades/review/"))
            result = {
                grade: { code: "P", points_milli: 4000 },
                reason: data.reason,
                review_token: `fictional-${data.student_id}`,
            };
        else if (path.endsWith("/final-grades/commit/")) {
            if (posted.has(data.student_id)) expect(posted.get(data.student_id)).toBe(data.review_token);
            else posted.set(data.student_id, data.review_token);
            if (holdCommit) await waitingCommit;
            if (interruptedCommit) {
                interruptedCommit = false;
                await route.fulfill({
                    status: 503,
                    json: { error: "Fictional interrupted reply. Retry the same confirmation." },
                });
                return;
            }
            result = { recorded: true };
        } else if (path.endsWith("/gradebook-summary/")) {
            await route.fulfill({ status: 422, json: { error: "Fictional unavailable average." } });
            return;
        }
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/");
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await page.getByRole("button", { name: "FIC.101.A", exact: true }).click();
    await page
        .getByRole("searchbox", { name: "Student name or institutional ID", exact: true })
        .fill("Example");
    await page.getByRole("button", { name: "Apply filters", exact: true }).click();
    await expect(page.locator('section[aria-label="Section roster"] tbody tr')).toHaveCount(2);
    await page.getByRole("button", { name: "Review ungraded", exact: true }).click();
    return { calls, posted, errors, rows, releaseCommit, section, grade };
}

async function review(page, grade, note = "") {
    if (grade)
        await page.getByRole("combobox", { name: "Institutional grade", exact: false }).selectOption(grade);
    await page.getByRole("textbox", { name: "Decision note", exact: false }).fill(note);
    await page.getByRole("button", { name: "Review final grade", exact: true }).click();
    await expect(page.locator("#drawer-title")).toHaveText("Confirm final grade");
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        scan.violations.map((item) => ({ id: item.id, targets: item.nodes.map((node) => node.target) })),
    ).toEqual([]);
}

test("ungraded queue retains recipient identity, retries the same confirmation and resets the next grade", async ({
    page,
}) => {
    const f = await fixture(page, { interruptedCommit: true, coursework: true });
    await expect(page.locator("#drawer-body")).toContainText("Alex Example");
    await expect(page.locator("#drawer-body")).toContainText("Applied roster search: Example");
    await page.getByRole("button", { name: "Use suggested grade", exact: true }).click();
    await review(page, null, "Fictional first decision");
    await expect(page.locator("#drawer-body")).toContainText("F-1");
    await page.getByRole("button", { name: "Change review", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Alex Example");
    await expect(page.getByRole("textbox", { name: "Decision note", exact: false })).toHaveValue(
        "Fictional first decision",
    );
    await page.getByRole("button", { name: "Review final grade", exact: true }).click();
    await page.getByRole("button", { name: "Confirm final grade", exact: true }).click();
    await expect(page.locator("#drawer-error")).toContainText("Fictional interrupted reply");
    await expect(page.locator("#drawer-body")).toContainText("Alex Example");
    await page.getByRole("button", { name: "Confirm final grade", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Jamie Example");
    await expect(page.locator("#drawer-body")).not.toContainText("Alex Example");
    await expect(page.getByRole("combobox", { name: "Institutional grade", exact: false })).toHaveValue("");
    await expect(page.getByRole("textbox", { name: "Decision note", exact: false })).toHaveValue("");
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await review(page, f.grade);
    await accessible(page);
    await page.getByRole("button", { name: "Confirm final grade", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("End of this pass");
    expect(f.posted.size).toBe(2);
    const commits = f.calls.filter((call) => call.path.endsWith("/final-grades/commit/"));
    expect(commits).toHaveLength(3);
    expect(commits[0].data).toEqual(commits[1].data);
    const reviews = f.calls.filter((call) => call.path.endsWith("/final-grades/review/"));
    expect(reviews[0].data.coursework_revision).toBe("fictional-coursework-revision");
    expect(reviews[1].data.coursework_revision).toBe("fictional-coursework-revision");
    expect(reviews[2].data).not.toHaveProperty("coursework_revision");
    expect(commits.every((call) => call.csrf === "fictional-test-token")).toBe(true);
    expect(
        f.calls
            .filter(
                (call) => call.path.includes("/context/roster/") && call.data.grade_status === "needs_grade",
            )
            .map((call) => call.data),
    ).toEqual([
        { search: "Example", grade_status: "needs_grade", after: null },
        { search: "Example", grade_status: "needs_grade", after: f.rows[0].id },
        { search: "Example", grade_status: "needs_grade", after: f.rows[1].id },
    ]);
    await page.getByRole("button", { name: "Back to roster", exact: true }).click();
    await expect(
        page.getByRole("searchbox", { name: "Student name or institutional ID", exact: true }),
    ).toHaveValue("Example");
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test("queue does not correct a newly posted grade and allows missing-scale records to be skipped and revisited", async ({
    page,
}) => {
    const f = await fixture(page, { postedDuringReview: true, noScale: true });
    await expect(page.locator("#drawer-body")).toContainText("already has an official grade");
    await expect(page.getByRole("button", { name: "Review final grade", exact: true })).toHaveCount(0);
    await page.getByRole("button", { name: "Next student", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Jamie Example");
    await expect(page.locator("#drawer-body")).toContainText("publish the applicable grading scale");
    await page.getByRole("button", { name: "Skip student", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText(
        "Skipped or newly registered students may still need review",
    );
    await page.getByRole("button", { name: "Restart queue", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Jamie Example");
    expect(f.calls.some((call) => call.path.includes("/final-grades/"))).toBe(false);
    expect(f.posted.size).toBe(1);
    expect(f.errors).toEqual([]);
});

test("accepted confirmation does not reopen a queue the instructor has closed", async ({ page }) => {
    const f = await fixture(page, { holdCommit: true });
    await review(page, f.grade);
    const response = page.waitForResponse((res) =>
        new URL(res.url()).pathname.endsWith("/final-grades/commit/"),
    );
    await page.getByRole("button", { name: "Confirm final grade", exact: true }).click();
    await expect.poll(() => f.posted.size).toBe(1);
    await page.getByRole("button", { name: "Close record", exact: true }).click();
    await expect(page.locator("#record-drawer")).not.toBeVisible();
    await expect(page.locator("#drawer-body")).toBeEmpty();
    f.releaseCommit();
    await response;
    // A subsequent user action runs after the fulfilled response without reopening a detached task.
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await expect(page.getByRole("button", { name: "FIC.101.A", exact: true })).toBeVisible();
    await expect(page.locator("#record-drawer")).not.toBeVisible();
    expect(f.calls.filter((call) => call.path.endsWith("/grade-choices/"))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});

test("queue asks for each student's grading pursuit without carrying a prior program choice", async ({
    page,
}) => {
    const f = await fixture(page, { ambiguousProgram: true });
    await expect(page.locator("#drawer-body")).toContainText("Alex Example");
    await expect(page.locator("#drawer-title")).toHaveText("Choose grading program");
    await page.getByRole("combobox", { name: "Program pursuit", exact: true }).selectOption(id(832));
    await page.getByRole("button", { name: "Use selected program", exact: true }).click();
    await review(page, f.grade);
    expect(f.calls.find((call) => call.path.endsWith("/final-grades/review/")).data.assignment_id).toBe(
        id(832),
    );
    await page.getByRole("button", { name: "Confirm final grade", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Jamie Example");
    await expect(page.locator("#drawer-title")).toHaveText("Choose grading program");
    await expect(page.getByRole("combobox", { name: "Program pursuit", exact: true })).toHaveValue("");
    expect(f.calls.findLast((call) => call.path.endsWith("/grade-choices/")).data).toEqual({});
    await page.getByRole("button", { name: "Skip student", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("End of this pass");
    expect(f.posted.size).toBe(1);
    expect(f.errors).toEqual([]);
});
