const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;

async function fixture(page, options = {}) {
    const id = (n) => String(n).padStart(26, "0");
    const student = id(1),
        section = id(3),
        enrollment = id(5);
    const calls = [],
        errors = [];
    let academicOn = options.knownPeriod ? "2026-01-12" : null;
    let periodReview;
    let date = options.known ? "2026-08-01" : null,
        version = 3,
        reviewed;
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            path = new URL(req.url()).pathname;
        const data = req.postData()
            ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json"))
            : {};
        calls.push({ path, data, csrf: req.headers()["x-csrf-token"] });
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/")
            result = {
                items: [
                    {
                        id: student,
                        person_id: id(2),
                        given_name: "Alex",
                        family_name: "Example",
                        institutional_id: "FICTIONAL",
                        active: true,
                    },
                ],
                next_after: null,
            };
        else if (path.endsWith("/record/"))
            result = {
                given_name: "Alex",
                family_name: "Example",
                institutional_id: "FICTIONAL",
                primary_program: { code: "BA", title: "Fictional program" },
                entry_term: { code: "26/FA" },
                active: true,
            };
        else if (path.endsWith("/transcript/"))
            result = {
                credits: [
                    {
                        credit_type: "institutional",
                        section_id: section,
                        course_code: "CS.101",
                        course_title: "Fictional course",
                        grade_code: "A",
                        status: "A",
                    },
                ],
            };
        else if (path.endsWith("/grade-choices/"))
            result = {
                items: [{ id: id(4), code: "A", points_milli: 4000 }],
                current_grade_code: "A",
                posted: true,
                can_amend: !options.readOnly,
                can_record_academic_period: !!options.period && !options.readOnly && !options.knownOffering,
                academic_on: academicOn,
                can_accept_course: !!options.courseAcceptance && !options.readOnly,
                current_version: version,
                completed_on: date,
                enrollment_id: enrollment,
                effective_on_default: "2026-08-11",
                needs_assignment: false,
                pursuits: [],
            };
        else if (path.endsWith("/academic-period/review/")) {
            if(options.rejectPeriod) return route.fulfill({status:409,json:{error:"The record changed. Reload before reviewing."}});
            periodReview=data.academic_on;
            result={previous_academic_on:academicOn,academic_on:periodReview,reason:data.reason,review_token:"fictional-period-review"};
        }
        else if(path.endsWith("/academic-period/commit/")) {academicOn=periodReview;version++;}
        else if (path.endsWith("/course-publication/choices/")) result = {
            items:[{version_id:id(data.cursor?7:6),version:data.cursor?2:1,code:"CS.101",transcript_title:data.cursor?"Revised <b>fictional</b> title":"Retained course title"}],
            next_cursor:data.cursor?null:"fictional-next",current_version:version,effective_on_default:"2026-08-11",
            current_course_code:"CS.101",current_course_title:"Earlier unknown reference",
        };
        else if (path.endsWith("/course-publication/review/")) {
            if(options.rejectCourse) return route.fulfill({status:409,json:{error:"The academic record changed. Reload before reviewing."}});
            result={before:{course_code:"CS.101",course_title:"Earlier unknown reference"},
                after:{course_code:"CS.101",course_title:"Revised <b>fictional</b> title"},reason:data.reason,
                effective_on:data.effective_on,review_token:"fictional-course-review"};
        }
        else if (path.endsWith("/course-publication/commit/")) version++;
        else if (path.endsWith("/completion-date/review/")) {
            if (options.reject)
                return route.fulfill({
                    status: 409,
                    json: { error: "The academic record changed. Reload it before reviewing." },
                });
            reviewed = data.completed_on;
            result = {
                previous_completed_on: date,
                completed_on: reviewed,
                reason: data.reason,
                review_token: "fictional-completion-review",
            };
        } else if (path.endsWith("/completion-date/commit/")) {
            date = reviewed;
            version++;
        }
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/grade-amend-fixture/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator("#directory-search").fill("a");
    await page.locator("#directory-search").press("Enter");
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await page.getByRole("button", { name: "View transcript", exact: true }).click();
    if(options.directCourse) await page.getByRole("button",{name:"Course publication",exact:true}).click();
    else await page.getByRole("button", { name: "Review final grade", exact: true }).click();
    return { calls, errors, student, section, enrollment };
}

test("registrar reviews actual completion independently from grades with reason, revision and private transport", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Review actual completion date", exact: true }).click();
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("Current completion date: Unknown");
    await expect(drawer).toContainText(
        "separate from instructional end dates, grade posting and correction dates",
    );
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("2026-08-02");
    await page
        .getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true })
        .fill("Fictional <b>source</b> confirmation.");
    await page.getByRole("button", { name: "Review completion date", exact: true }).click();
    await expect(drawer).toContainText("Unknown → 2026-08-02");
    await expect(drawer.locator("b")).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("/completion-date/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Revise completion date", exact: true }).click();
    await expect(
        page.getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true }),
    ).toHaveValue("Fictional <b>source</b> confirmation.");
    await page.getByRole("button", { name: "Review completion date", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    await page.getByRole("button", { name: "Confirm completion date", exact: true }).click();
    await expect(drawer.getByText("Actual completion date", { exact: true }).locator("..").locator("dd")).toHaveText("2026-08-02");
    const reviews = f.calls.filter((c) => c.path.endsWith("/completion-date/review/"));
    expect(reviews[0]).toMatchObject({
        data: {
            enrollment_id: f.enrollment,
            version: 3,
            completed_on: "2026-08-02",
            reason: "Fictional <b>source</b> confirmation.",
        },
        csrf: "fictional-test-token",
    });
    expect(f.calls.find((c) => c.path.endsWith("/completion-date/commit/"))).toMatchObject({
        data: { review_token: "fictional-completion-review" },
        csrf: "fictional-test-token",
    });
    expect(f.calls.some((c) => c.path.includes("/final-grades/"))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("clearing a known completion date requires an explicit reviewed unknown", async ({ page }) => {
    const f = await fixture(page, { known: true });
    await page.getByRole("button", { name: "Review actual completion date", exact: true }).click();
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("");
    await page
        .getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true })
        .fill("Fictional source date withdrawn.");
    await page.getByRole("button", { name: "Review completion date", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2026-08-01 → Unknown");
    await page.getByRole("button", { name: "Confirm completion date", exact: true }).click();
    await expect(page.getByText("Actual completion date", { exact: true }).locator("..").locator("dd")).toHaveText("Unknown");
    expect(f.calls.find((c) => c.path.endsWith("/completion-date/review/"))?.data.completed_on).toBeNull();
    expect(f.errors).toEqual([]);
});

test("ordinary faculty cannot open the registrar completion-date action", async ({ page }) => {
    const f = await fixture(page, { readOnly: true });
    await expect(
        page.getByRole("button", { name: "Review actual completion date", exact: true }),
    ).toHaveCount(0);
    expect(f.calls.some((c) => c.path.includes("/completion-date/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("stale completion review keeps the entered correction available and never confirms it", async ({
    page,
}) => {
    const f = await fixture(page, { reject: true });
    await page.getByRole("button", { name: "Review actual completion date", exact: true }).click();
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("2026-08-02");
    await page
        .getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true })
        .fill("Fictional stale correction.");
    await page.getByRole("button", { name: "Review completion date", exact: true }).click();
    await expect(
        page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }),
    ).toHaveValue("2026-08-02");
    await expect(page.locator("#drawer-body")).toContainText("The academic record changed");
    expect(f.calls.some((c) => c.path.endsWith("/completion-date/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});


test("registrar resolves an unknown course reference with explicit review, paged choices and preserved form values", async ({
    page,
}) => {
    const f = await fixture(page, { courseAcceptance: true, directCourse: true });
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("does not prove which publication applied at registration");
    await page.getByRole("button", { name: "Load more course publications", exact: true }).click();
    await page.getByRole("combobox", { name: "Accepted course publication", exact: true }).selectOption("00000000000000000000000007");
    await page.getByLabel(/Acceptance effective date/).fill("2026-08-10");
    await page
        .getByLabel(/Reason and course-publication evidence/)
        .fill("Fictional <b>evidence</b> confirmed.");
    await page.getByRole("button", { name: "Review course publication acceptance", exact: true }).click();
    await expect(drawer).toContainText("Earlier unknown reference → CS.101 · Revised <b>fictional</b> title");
    await expect(drawer.locator("b")).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("/course-publication/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Change course publication review", exact: true }).click();
    await expect(page.getByLabel(/Reason and course-publication evidence/)).toHaveValue(
        "Fictional <b>evidence</b> confirmed.",
    );
    await expect(page.getByRole("combobox", { name: "Accepted course publication", exact: true })).toHaveValue(
        "00000000000000000000000007",
    );
    await page.getByRole("button", { name: "Review course publication acceptance", exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Confirm course publication acceptance", exact: true }).click();
    expect(f.calls.find((c) => c.path.endsWith("/course-publication/review/"))).toMatchObject({
        csrf: "fictional-test-token",
        data: {
            enrollment_id: f.enrollment,
            version: 3,
            effective_on: "2026-08-10",
            course_version_id: "00000000000000000000000007",
        },
    });
    expect(f.calls.find((c) => c.path.endsWith("/course-publication/commit/"))).toMatchObject({
        data: { review_token: "fictional-course-review" },
    });
    expect(f.calls.some((c) => c.path.includes("/final-grades/") || c.path.includes("/completion-date/"))).toBe(false);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("course publication review failure leaves evidence available without confirming", async ({ page }) => {
    const f = await fixture(page, { courseAcceptance: true, rejectCourse: true });
    await page.getByRole("button", { name: "Review course publication", exact: true }).click();
    await page.getByRole("combobox", { name: "Accepted course publication", exact: true }).selectOption("00000000000000000000000006");
    await page
        .getByLabel(/Reason and course-publication evidence/)
        .fill("Fictional concurrent review.");
    await page.getByRole("button", { name: "Review course publication acceptance", exact: true }).click();
    await expect(page.getByLabel(/Reason and course-publication evidence/)).toHaveValue(
        "Fictional concurrent review.",
    );
    expect(f.calls.some((c) => c.path.endsWith("/course-publication/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("a known registration-time publication is explained without offering a replacement", async ({ page }) => {
    const f = await fixture(page, { directCourse: true });
    await expect(page.locator("#drawer-body")).toContainText("retained registration-time course or offering publication");
    await expect(page.getByRole("button", { name: "Review course publication acceptance", exact: true })).toHaveCount(0);
    expect(f.calls.some((c) => c.path.includes("/course-publication/"))).toBe(false);
    expect(f.errors).toEqual([]);
});


test("registrar reviews a missing institutional source period separately from completion with preserved evidence", async ({
    page,
}) => {
    const f = await fixture(page, { period: true });
    await page.getByRole("button", { name: "Review academic period", exact: true }).click();
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("Do not substitute today's term dates");
    await page.getByLabel("Source academic-period start (blank marks unknown)", { exact: true }).fill("2026-01-12");
    await page
        .getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true })
        .fill("Fictional <b>records</b> evidence.");
    await page.getByRole("button", { name: "Review academic period", exact: true }).click();
    await expect(drawer).toContainText("Unknown → 2026-01-12");
    expect(f.calls.some((c) => c.path.endsWith("/academic-period/commit/"))).toBe(false);
    await expect(drawer.locator("b")).toHaveCount(0);
    await page.getByRole("button", { name: "Revise academic period", exact: true }).click();
    await expect(
        page.getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true }),
    ).toHaveValue("Fictional <b>records</b> evidence.");
    await page.getByRole("button", { name: "Review academic period", exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Confirm academic period", exact: true }).click();
    await expect(drawer.getByText("Recorded academic period start", { exact: true }).locator("..").locator("dd")).toHaveText("2026-01-12");
    expect(f.calls.find((c) => c.path.endsWith("/academic-period/review/"))).toMatchObject({
        csrf: "fictional-test-token",
        data: { enrollment_id: f.enrollment, version: 3, academic_on: "2026-01-12" },
    });
    expect(f.calls.find((c) => c.path.endsWith("/academic-period/commit/"))).toMatchObject({
        csrf: "fictional-test-token",
        data: { review_token: "fictional-period-review" },
    });
    expect(f.calls.some((c) => c.path.includes("/completion-date/") || c.path.includes("/final-grades/"))).toBe(false);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("clearing a source period explicitly records unknown rather than substituting another date", async ({ page }) => {
    const f = await fixture(page, { period: true, knownPeriod: true });
    await page.getByRole("button", { name: "Review academic period", exact: true }).click();
    await page.getByLabel("Source academic-period start (blank marks unknown)", { exact: true }).fill("");
    await page
        .getByRole("textbox", { name: "Reason for recording or correcting this date", exact: true })
        .fill("Fictional source evidence withdrawn.");
    await page.getByRole("button", { name: "Review academic period", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2026-01-12 → Unknown");
    await page.getByRole("button", { name: "Confirm academic period", exact: true }).click();
    expect(f.calls.find((c) => c.path.endsWith("/academic-period/review/"))?.data.academic_on).toBeNull();
    await expect(page.getByText("Recorded academic period start", { exact: true }).locator("..").locator("dd")).toHaveText("Unknown");
    expect(f.errors).toEqual([]);
});

for (const options of [
    { period: true, knownOffering: true },
    { period: true, readOnly: true },
])
    test(`source-period correction is unavailable for ${options.knownOffering ? "a published offering" : "ordinary faculty"}`, async ({
        page,
    }) => {
        const f = await fixture(page, options);
        await expect(page.getByRole("button", { name: "Review academic period", exact: true })).toHaveCount(0);
        expect(f.calls.some((c) => c.path.includes("/academic-period/"))).toBe(false);
        expect(f.errors).toEqual([]);
    });
