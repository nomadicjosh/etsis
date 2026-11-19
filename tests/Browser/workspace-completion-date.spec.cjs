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
    const quantities = {
        attempted_milli: 3000,
        completed_milli: 1000,
        grade_points_milli: 9999,
        expected_completed_milli: 0,
        expected_grade_points_milli: 12000,
    };
    let gradeReviews = 0;
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
        else if (path === "/sis/ui/data/terms/")
            result = {
                items: [
                    { id: id(10), code: "26/FA" },
                    { id: id(11), code: "26/SP" },
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
                        grade_code: options.missingGrade ? null : options.withdrawn ? "W" : "A",
                        grade_id: options.missingGrade ? null : id(4),
                        completed_milli: options.reconcile ? 1000 : options.withdrawn ? 0 : 3000,
                        grade_points_milli: options.reconcile ? 9999 : options.withdrawn ? 0 : 12000,
                        status: options.withdrawn ? "W" : "A",
                    },
                ],
            };
        else if (path.endsWith("/grade-choices/"))
            result = {
                items: [{ id: id(4), code: "A", points_milli: 4000 }],
                current_grade_code: options.missingGrade ? null : options.withdrawn ? "W" : "A",
                withdrawal_grade_correction: !!options.withdrawn,
                missing_withdrawal_grade: !!options.missingGrade,
                requires_quantity_reconciliation: !!options.reconcile,
                withdrawal_quantities: options.reconcile ? quantities : null,
                can_correct_credit_units:
                    !!options.unitCorrection && !options.readOnly && !options.missingGrade,
                current_credit_quantities: {
                    attempted_milli: 3000,
                    completed_milli: options.withdrawn ? 0 : 3000,
                    grade_points_milli: options.withdrawn ? 0 : 12000,
                },
                pending_grade_change: options.pending ? { id: id(8), status: "pending" } : null,
                can_correct_posting_term: !!options.placement && !options.readOnly,
                posting_term_id: id(10),
                posting_term_code: "26/FA",
                posted: true,
                can_amend: !options.readOnly,
                can_record_academic_period:
                    !!options.period && !options.readOnly && !options.knownOffering && !options.reconcile,
                academic_on: academicOn,
                can_correct_course: !!options.courseCorrection && !options.readOnly,
                course_id: id(12),
                can_accept_course: !!options.courseAcceptance && !options.readOnly && !options.reconcile,
                current_version: version,
                completed_on: date,
                enrollment_id: enrollment,
                effective_on_default: "2026-08-11",
                needs_assignment: false,
                pursuits: [],
            };
        else if (path.endsWith("/final-grades/review/")) {
            gradeReviews++;
            result = {
                grade: { code: "A", points_milli: 4000 },
                reason: data.reason,
                withdrawal_grade_correction: !!options.withdrawn,
                missing_withdrawal_grade: !!options.missingGrade,
                withdrawal_quantity_reconciliation: !!options.reconcile,
                withdrawal_quantities: quantities,
                earlier_history_unknown: true,
                review_token: `fictional-reconciliation-${gradeReviews}`,
            };
            if (data.attempted_milli !== undefined) {
                result.credit_unit_correction = {
                    previous_attempted_milli: 3000,
                    attempted_milli: data.attempted_milli,
                };
                result.credit_unit_summary = {
                    previous_attempted_milli: 3000,
                    previous_completed_milli: options.withdrawn ? 0 : 3000,
                    previous_grade_points_milli: options.withdrawn ? 0 : 12000,
                    attempted_milli: data.attempted_milli,
                    completed_milli: options.withdrawn ? 0 : data.attempted_milli,
                    grade_points_milli: data.attempted_milli * 4,
                };
            }
        } else if (path === `/sis/grade-changes/${id(8)}/`) {
            result = {
                id: id(8),
                institutional_id: "FICTIONAL",
                course_code: "CS.101",
                status: "pending",
                before: { code: "W" },
                grade: { code: "A" },
                effective_at: "2026-08-11T00:00:00Z",
                reason: "Fictional protected reconciliation evidence.",
                withdrawal_grade_correction: !!options.withdrawn,
                withdrawal_quantity_reconciliation: !!options.reconcile,
                withdrawal_quantities: quantities,
                can_approve: true,
                can_reject: true,
                can_cancel: false,
            };
            if (options.unitCorrection) {
                result.credit_unit_correction = { previous_attempted_milli: 3000, attempted_milli: 4500 };
                result.credit_unit_summary = {
                    previous_attempted_milli: 3000,
                    previous_completed_milli: 3000,
                    previous_grade_points_milli: 12000,
                    attempted_milli: 4500,
                    completed_milli: 4500,
                    grade_points_milli: 18000,
                };
            }
        } else if (path.endsWith("/grade-changes/" + id(8) + "/review/")) {
            result = {
                outcome: data.outcome,
                reason: data.reason,
                review_token: "fictional-independent-review",
            };
        } else if (path.endsWith("/academic-details/review/")) {
            if (options.reject)
                return route.fulfill({
                    status: 409,
                    json: { error: "The academic record changed. Reload before reviewing." },
                });
            reviewed = data.completed_on;
            periodReview = data.academic_on;
            result = {
                previous_academic_on: academicOn,
                previous_completed_on: date,
                academic_on: periodReview,
                completed_on: reviewed,
                previous_posting_term_code_display: "26/FA",
                posting_term_code: "26/SP",
                reason: data.reason,
                review_token: "fictional-details-review",
            };
        } else if (path.endsWith("/academic-details/commit/")) {
            version++;
            academicOn = periodReview;
            date = reviewed;
        } else if (path.endsWith("/academic-dates/review/")) {
            if (options.reject || options.rejectPeriod)
                return route.fulfill({
                    status: 409,
                    json: { error: "The academic record changed. Reload it before reviewing." },
                });
            reviewed = data.completed_on;
            periodReview = data.academic_on;
            result = {
                previous_academic_on: academicOn,
                previous_completed_on: date,
                academic_on: periodReview,
                completed_on: reviewed,
                reason: data.reason,
                review_token: "fictional-dates-review",
            };
        } else if (path.endsWith("/academic-dates/commit/")) {
            version += Number(academicOn !== periodReview) + Number(date !== reviewed);
            academicOn = periodReview;
            date = reviewed;
        } else if (path.endsWith("/course-publication/courses/"))
            result = {
                items: [{ id: id(data.after ? 15 : 13), code: "ENG.201", title: "Fictional writing" }],
                next_after: data.after ? null : id(13),
            };
        else if (path.endsWith("/course-publication/choices/"))
            result = {
                items: [
                    {
                        version_id: id(data.course_id === id(13) ? 14 : data.cursor ? 7 : 6),
                        version: data.cursor ? 2 : 1,
                        code: data.course_id === id(13) ? "ENG.201" : "CS.101",
                        transcript_title: data.cursor
                            ? "Revised <b>fictional</b> title"
                            : "Retained course title",
                    },
                ],
                next_cursor: data.cursor ? null : "fictional-next",
                current_version: version,
                effective_on_default: "2026-08-11",
                current_course_code: data.course_id === id(13) ? "ENG.201" : "CS.101",
                current_course_title: "Earlier unknown reference",
            };
        else if (path.endsWith("/course-publication/review/")) {
            if (options.rejectCourse)
                return route.fulfill({
                    status: 409,
                    json: { error: "The academic record changed. Reload before reviewing." },
                });
            result = {
                before: { course_code: "CS.101", course_title: "Earlier unknown reference" },
                after: {
                    course_code: data.course_id === id(13) ? "ENG.201" : "CS.101",
                    course_title: "Revised <b>fictional</b> title",
                },
                reason: data.reason,
                effective_on: data.effective_on,
                review_token: "fictional-course-review",
            };
        } else if (path.endsWith("/course-publication/commit/")) version++;
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto(options.withdrawn ? "/admin/closed-grade-fixture/" : "/admin/grade-amend-fixture/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator("#directory-search").fill("a");
    await page.locator("#directory-search").press("Enter");
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await page.getByRole("button", { name: "View transcript", exact: true }).click();
    if (options.directCourse)
        await page.getByRole("button", { name: "Course publication", exact: true }).click();
    else
        await page
            .getByRole("button", {
                name: options.missingGrade
                    ? "Resolve withdrawn grade"
                    : options.withdrawn
                      ? "Correct withdrawn grade"
                      : "Review final grade",
                exact: true,
            })
            .click();
    return { calls, errors, student, section, enrollment };
}

test("registrar reviews actual completion independently from grades with reason, revision and private transport", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("button", { name: "Review academic dates", exact: true }).click();
    const drawer = page.locator("#drawer-body");
    await expect(drawer.getByText("Current completion date", { exact: true }).locator("..")).toContainText(
        "Unknown",
    );
    await expect(drawer).toContainText(
        "separate from instructional end dates, grade posting and correction dates",
    );
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("2026-08-02");
    await page
        .getByRole("textbox", { name: "Reason for date change", exact: true })
        .fill("Fictional <b>source</b> confirmation.");
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await expect(drawer).toContainText("Unknown → 2026-08-02");
    await expect(drawer.locator("b")).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("/academic-dates/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Revise dates", exact: true }).click();
    await expect(page.getByRole("textbox", { name: "Reason for date change", exact: true })).toHaveValue(
        "Fictional <b>source</b> confirmation.",
    );
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    await page.getByRole("button", { name: "Confirm dates", exact: true }).click();
    await expect(
        drawer.getByText("Actual completion date", { exact: true }).locator("..").locator("dd"),
    ).toHaveText("2026-08-02");
    const reviews = f.calls.filter((c) => c.path.endsWith("/academic-dates/review/"));
    expect(reviews[0]).toMatchObject({
        data: {
            enrollment_id: f.enrollment,
            version: 3,
            completed_on: "2026-08-02",
            reason: "Fictional <b>source</b> confirmation.",
        },
        csrf: "fictional-test-token",
    });
    expect(f.calls.find((c) => c.path.endsWith("/academic-dates/commit/"))).toMatchObject({
        data: { review_token: "fictional-dates-review" },
        csrf: "fictional-test-token",
    });
    expect(f.calls.some((c) => c.path.includes("/final-grades/"))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("clearing a known completion date requires an explicit reviewed unknown", async ({ page }) => {
    const f = await fixture(page, { known: true });
    await page.getByRole("button", { name: "Review academic dates", exact: true }).click();
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("");
    await page
        .getByRole("textbox", { name: "Reason for date change", exact: true })
        .fill("Fictional source date withdrawn.");
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2026-08-01 → Unknown");
    await page.getByRole("button", { name: "Confirm dates", exact: true }).click();
    await expect(
        page.getByText("Actual completion date", { exact: true }).locator("..").locator("dd"),
    ).toHaveText("Unknown");
    expect(f.calls.find((c) => c.path.endsWith("/academic-dates/review/"))?.data.completed_on).toBeNull();
    expect(f.errors).toEqual([]);
});

test("ordinary faculty cannot open the registrar completion-date action", async ({ page }) => {
    const f = await fixture(page, { readOnly: true });
    await expect(page.getByRole("button", { name: "Review academic dates", exact: true })).toHaveCount(0);
    expect(f.calls.some((c) => c.path.includes("/academic-dates/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("stale completion review keeps the entered correction available and never confirms it", async ({
    page,
}) => {
    const f = await fixture(page, { reject: true });
    await page.getByRole("button", { name: "Review academic dates", exact: true }).click();
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("2026-08-02");
    await page
        .getByRole("textbox", { name: "Reason for date change", exact: true })
        .fill("Fictional stale correction.");
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await expect(
        page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }),
    ).toHaveValue("2026-08-02");
    await expect(page.locator("#drawer-body")).toContainText("The academic record changed");
    expect(f.calls.some((c) => c.path.endsWith("/academic-dates/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("registrar resolves an unknown course reference with explicit review, paged choices and preserved form values", async ({
    page,
}) => {
    const f = await fixture(page, { courseAcceptance: true, directCourse: true });
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("does not prove which publication applied at registration");
    await page.getByRole("button", { name: "Load more course publications", exact: true }).click();
    await page
        .getByRole("combobox", { name: "Accepted course publication", exact: true })
        .selectOption("00000000000000000000000007");
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
    await expect(
        page.getByRole("combobox", { name: "Accepted course publication", exact: true }),
    ).toHaveValue("00000000000000000000000007");
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
    expect(
        f.calls.some((c) => c.path.includes("/final-grades/") || c.path.includes("/academic-dates/")),
    ).toBe(false);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("course publication review failure leaves evidence available without confirming", async ({ page }) => {
    const f = await fixture(page, { courseAcceptance: true, rejectCourse: true });
    await page.getByRole("button", { name: "Review course publication", exact: true }).click();
    await page
        .getByRole("combobox", { name: "Accepted course publication", exact: true })
        .selectOption("00000000000000000000000006");
    await page.getByLabel(/Reason and course-publication evidence/).fill("Fictional concurrent review.");
    await page.getByRole("button", { name: "Review course publication acceptance", exact: true }).click();
    await expect(page.getByLabel(/Reason and course-publication evidence/)).toHaveValue(
        "Fictional concurrent review.",
    );
    expect(f.calls.some((c) => c.path.endsWith("/course-publication/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("a known registration-time publication is explained without offering a replacement", async ({
    page,
}) => {
    const f = await fixture(page, { directCourse: true });
    await expect(page.locator("#drawer-body")).toContainText(
        "retained registration-time course or offering publication",
    );
    await expect(
        page.getByRole("button", { name: "Review course publication acceptance", exact: true }),
    ).toHaveCount(0);
    expect(f.calls.some((c) => c.path.includes("/course-publication/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("registrar reviews institutional source chronology together with preserved evidence", async ({
    page,
}) => {
    const f = await fixture(page, { period: true });
    await page.getByRole("button", { name: "Review academic dates", exact: true }).click();
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("Do not substitute today's term dates");
    await page
        .getByLabel("Source academic-period start (blank marks unknown)", { exact: true })
        .fill("2026-01-12");
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("2026-08-02");
    await page
        .getByRole("textbox", { name: "Reason for date change", exact: true })
        .fill("Fictional <b>records</b> evidence.");
    expect(
        await page
            .locator('#drawer-body input[type="date"]')
            .evaluateAll((fields) => fields.map((field) => field.name)),
    ).toEqual(["academic_on", "completed_on"]);
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await expect(drawer).toContainText("Unknown → 2026-01-12");
    expect(f.calls.some((c) => c.path.endsWith("/academic-dates/commit/"))).toBe(false);
    await expect(drawer.locator("b")).toHaveCount(0);
    await page.getByRole("button", { name: "Revise dates", exact: true }).click();
    await expect(
        page.getByLabel("Source academic-period start (blank marks unknown)", { exact: true }),
    ).toHaveValue("2026-01-12");
    await expect(
        page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }),
    ).toHaveValue("2026-08-02");
    await expect(page.getByRole("textbox", { name: "Reason for date change", exact: true })).toHaveValue(
        "Fictional <b>records</b> evidence.",
    );
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Confirm dates", exact: true }).click();
    await expect(
        drawer.getByText("Recorded academic period start", { exact: true }).locator("..").locator("dd"),
    ).toHaveText("2026-01-12");
    expect(f.calls.find((c) => c.path.endsWith("/academic-dates/review/"))).toMatchObject({
        csrf: "fictional-test-token",
        data: {
            enrollment_id: f.enrollment,
            version: 3,
            academic_on: "2026-01-12",
            completed_on: "2026-08-02",
        },
    });
    expect(f.calls.filter((c) => c.path.endsWith("/academic-dates/commit/"))).toHaveLength(1);
    expect(f.calls.find((c) => c.path.endsWith("/academic-dates/commit/"))).toMatchObject({
        csrf: "fictional-test-token",
        data: { review_token: "fictional-dates-review" },
    });
    expect(f.calls.some((c) => c.path.includes("/final-grades/"))).toBe(false);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("clearing a source period explicitly records unknown rather than substituting another date", async ({
    page,
}) => {
    const f = await fixture(page, { period: true, knownPeriod: true });
    await page.getByRole("button", { name: "Review academic dates", exact: true }).click();
    await page.getByLabel("Source academic-period start (blank marks unknown)", { exact: true }).fill("");
    await page
        .getByRole("textbox", { name: "Reason for date change", exact: true })
        .fill("Fictional source evidence withdrawn.");
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("2026-01-12 → Unknown");
    await page.getByRole("button", { name: "Confirm dates", exact: true }).click();
    expect(f.calls.find((c) => c.path.endsWith("/academic-dates/review/"))?.data.academic_on).toBeNull();
    await expect(
        page.getByText("Recorded academic period start", { exact: true }).locator("..").locator("dd"),
    ).toHaveText("Unknown");
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
        if (options.knownOffering) {
            await page.getByRole("button", { name: "Review academic dates", exact: true }).click();
            await expect(
                page.getByLabel("Source academic-period start (blank marks unknown)", { exact: true }),
            ).toHaveCount(0);
            await expect(page.locator("#drawer-body")).toContainText("cannot be changed here");
        } else
            await expect(
                page.getByRole("button", { name: "Review academic dates", exact: true }),
            ).toHaveCount(0);
        expect(f.calls.some((c) => c.path.includes("/academic-dates/"))).toBe(false);
        expect(f.errors).toEqual([]);
    });

test("withdrawn source-period review preserves identity and cannot set a completion date", async ({
    page,
}) => {
    const f = await fixture(page, { withdrawn: true, period: true });
    const drawer = page.locator("#drawer-body");
    await page.getByRole("button", { name: "Review academic dates", exact: true }).click();
    await expect(drawer).toContainText("Alex Example");
    await expect(drawer).toContainText("FICTIONAL");
    await expect(drawer).toContainText("cannot be marked completed here");
    await expect(drawer.locator('[name="completed_on"]')).toHaveCount(0);
    await page
        .getByLabel("Source academic-period start (blank marks unknown)", { exact: true })
        .fill("2026-01-12");
    await page
        .getByRole("textbox", { name: "Reason for date change", exact: true })
        .fill("Fictional source evidence.");
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await expect(drawer).toContainText("Unknown → 2026-01-12");
    await expect(drawer).toContainText("remains withdrawn with no earned credit");
    await expect(drawer.getByText("Actual completion date", { exact: true })).toHaveCount(0);
    await page.getByRole("button", { name: "Revise dates", exact: true }).click();
    await expect(
        page.getByLabel("Source academic-period start (blank marks unknown)", { exact: true }),
    ).toHaveValue("2026-01-12");
    await expect(drawer.locator('[name="completed_on"]')).toHaveCount(0);
    await page.getByRole("button", { name: "Review dates", exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    expect(
        (
            await new AxeBuilder({ page })
                .include("#drawer-body")
                .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
                .analyze()
        ).violations,
    ).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Confirm dates", exact: true }).click();
    expect(f.calls.find((c) => c.path.endsWith("/academic-dates/review/"))).toMatchObject({
        csrf: "fictional-test-token",
        data: { enrollment_id: f.enrollment, version: 3, academic_on: "2026-01-12", completed_on: null },
    });
    expect(f.calls.filter((c) => c.path.endsWith("/academic-dates/commit/"))).toHaveLength(1);
    expect(f.calls.some((c) => c.path.includes("/final-grades/"))).toBe(false);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("withdrawn course acceptance keeps student context and evidence through repeated revisions", async ({
    page,
}) => {
    const f = await fixture(page, { withdrawn: true, courseAcceptance: true });
    const drawer = page.locator("#drawer-body");
    await page.getByRole("button", { name: "Review course publication", exact: true }).click();
    await expect(drawer).toContainText("This attempt remains withdrawn");
    await page
        .getByRole("combobox", { name: "Accepted course publication", exact: true })
        .selectOption("00000000000000000000000006");
    await page.getByLabel(/Reason and course-publication evidence/).fill("Fictional <b>source</b> evidence.");
    for (let revision = 0; revision < 2; revision++) {
        await page.getByRole("button", { name: "Review course publication acceptance", exact: true }).click();
        await expect(drawer).toContainText("Alex Example");
        await expect(drawer).toContainText("FICTIONAL");
        await expect(drawer.locator("b")).toHaveCount(0);
        await page.getByRole("button", { name: "Change course publication review", exact: true }).click();
        await expect(drawer).toContainText("Alex Example");
        await expect(drawer).toContainText("This attempt remains withdrawn");
        await expect(page.getByLabel(/Reason and course-publication evidence/)).toHaveValue(
            "Fictional <b>source</b> evidence.",
        );
        await expect(
            page.getByRole("combobox", { name: "Accepted course publication", exact: true }),
        ).toBeFocused();
    }
    await page.getByRole("button", { name: "Review course publication acceptance", exact: true }).click();
    await page.getByRole("button", { name: "Confirm course publication acceptance", exact: true }).click();
    expect(f.calls.filter((c) => c.path.endsWith("/course-publication/commit/"))).toHaveLength(1);
    expect(
        f.calls.some((c) => c.path.includes("/final-grades/") || c.path.includes("/academic-dates/")),
    ).toBe(false);
    expect(f.errors).toEqual([]);
});

test("a withdrawn attempt with a protected offering does not expose a completion editor", async ({
    page,
}) => {
    const f = await fixture(page, { withdrawn: true, period: true, knownOffering: true });
    await expect(page.getByRole("button", { name: "Review academic dates", exact: true })).toHaveCount(0);
    expect(f.calls.some((c) => c.path.includes("/academic-dates/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

for (const missingGrade of [false, true])
    test(`withdrawn quantity reconciliation requires explicit evidence${missingGrade ? " and a missing-grade confirmation" : ""}`, async ({
        page,
    }) => {
        const f = await fixture(page, { withdrawn: true, reconcile: true, missingGrade });
        const drawer = page.locator("#drawer-body");
        await expect(drawer).toContainText("Recorded credit quantities");
        await expect(drawer).toContainText("Alex Example");
        await expect(drawer.getByRole("button", { name: "Review academic dates", exact: true })).toHaveCount(
            0,
        );
        await page
            .getByRole("combobox", { name: "Institutional grade", exact: true })
            .selectOption("00000000000000000000000004");
        await page
            .getByRole("textbox", { name: "Reason for correction", exact: true })
            .fill("Fictional <b>source</b> evidence.");
        if (missingGrade)
            await page
                .getByLabel("I verified the grade against institutional records", { exact: true })
                .check();
        const checkbox = page.getByLabel(
            "I verified the withdrawn credit quantities against institutional records",
            { exact: true },
        );
        await expect(checkbox).not.toBeChecked();
        await page.getByRole("button", { name: "Review final grade", exact: true }).click();
        expect(f.calls.some((c) => c.path.endsWith("/final-grades/review/"))).toBe(false);
        await checkbox.check();
        await page.setViewportSize({ width: 390, height: 844 });
        expect(
            (
                await new AxeBuilder({ page })
                    .include("#drawer-body")
                    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
                    .analyze()
            ).violations,
        ).toEqual([]);
        await page.getByRole("button", { name: "Review final grade", exact: true }).click();
        await expect(drawer).toContainText("Reviewed credit quantities");
        await expect(drawer).toContainText("1 → 0");
        await expect(drawer).toContainText("9.999 → 12");
        await expect(drawer.locator("b")).toHaveCount(0);
        expect(f.calls.some((c) => c.path.endsWith("/final-grades/commit/"))).toBe(false);
        await page.getByRole("button", { name: "Change review", exact: true }).click();
        await expect(checkbox).toBeChecked();
        await expect(page.getByRole("textbox", { name: "Reason for correction", exact: true })).toHaveValue(
            "Fictional <b>source</b> evidence.",
        );
        await expect(drawer).toContainText("Recorded credit quantities");
        await page.getByRole("button", { name: "Review final grade", exact: true }).click();
        expect(
            (
                await new AxeBuilder({ page })
                    .include("#drawer-body")
                    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
                    .analyze()
            ).violations,
        ).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.getByRole("button", { name: "Confirm final grade", exact: true }).click();
        const reviews = f.calls.filter((c) => c.path.endsWith("/final-grades/review/"));
        expect(reviews).toHaveLength(2);
        expect(reviews[0]).toMatchObject({
            csrf: "fictional-test-token",
            data: {
                enrollment_id: f.enrollment,
                version: 3,
                reconcile_withdrawal_quantities: true,
                ...(missingGrade ? { grade_evidence: true } : {}),
            },
        });
        expect(f.calls.filter((c) => c.path.endsWith("/final-grades/commit/"))).toHaveLength(1);
        expect(
            f.calls.some((c) => c.path.includes("/academic-dates/") || c.path.includes("/accounts/")),
        ).toBe(false);
        expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
        expect(f.errors).toEqual([]);
    });

test("independent registrar review shows the original and reconciled quantities before approval", async ({
    page,
}) => {
    const f = await fixture(page, { withdrawn: true, reconcile: true, pending: true });
    const drawer = page.locator("#drawer-body");
    await page.getByRole("button", { name: "Review correction", exact: true }).click();
    await expect(drawer).toContainText("1 → 0");
    await expect(drawer).toContainText("9.999 → 12");
    await page.getByRole("combobox", { name: "Decision", exact: true }).selectOption("approved");
    await page
        .getByRole("textbox", { name: "Reason for decision", exact: true })
        .fill("Fictional independent approval.");
    await page.getByRole("button", { name: "Review decision", exact: true }).click();
    await expect(drawer).toContainText("FICTIONAL");
    await expect(drawer).toContainText("1 → 0");
    expect(f.calls.some((c) => c.path.endsWith("/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Change review", exact: true }).click();
    await expect(page.getByRole("textbox", { name: "Reason for decision", exact: true })).toHaveValue(
        "Fictional independent approval.",
    );
    await page.getByRole("button", { name: "Review decision", exact: true }).click();
    await page.setViewportSize({ width: 390, height: 844 });
    expect(
        (
            await new AxeBuilder({ page })
                .include("#drawer-body")
                .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
                .analyze()
        ).violations,
    ).toEqual([]);
    await page.getByRole("button", { name: "Confirm decision", exact: true }).click();
    expect(
        f.calls.filter((c) => c.path.includes("/grade-changes/") && c.path.endsWith("/commit/")),
    ).toHaveLength(1);
    expect(f.calls.some((c) => c.path.includes("/accounts/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("read-only grading context cannot expose the reconciliation controls", async ({ page }) => {
    const f = await fixture(page, { withdrawn: true, reconcile: true, readOnly: true });
    await expect(
        page.getByLabel("I verified the withdrawn credit quantities against institutional records", {
            exact: true,
        }),
    ).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("/final-grades/review/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

for (const withdrawn of [false, true])
    test(`grade and credit-unit review stays together for ${withdrawn ? "withdrawn" : "active"} work`, async ({
        page,
    }) => {
        const f = await fixture(page, { unitCorrection: true, withdrawn });
        const drawer = page.locator("#drawer-body");
        const enabled = page.getByLabel("Correct credit units", { exact: true });
        const units = page.getByLabel("Corrected attempted credits", { exact: true });
        await expect(enabled).not.toBeChecked();
        await expect(units).toBeHidden();
        await enabled.check();
        await expect(units).toBeFocused();
        await units.fill("4.5");
        await page
            .getByRole("combobox", { name: "Institutional grade", exact: true })
            .selectOption("00000000000000000000000004");
        await page
            .getByRole("textbox", { name: "Reason for correction", exact: true })
            .fill("Fictional <b>credit</b> evidence.");
        await page.getByRole("button", { name: "Review final grade", exact: true }).click();
        await expect(drawer).toContainText("Reviewed credit units");
        await expect(drawer).toContainText("3 → 4.5");
        await expect(drawer).toContainText(withdrawn ? "0 → 0" : "3 → 4.5");
        await expect(drawer).toContainText("financial adjustment separately");
        await expect(drawer.locator("b")).toHaveCount(0);
        expect(f.calls.some((c) => c.path.endsWith("/final-grades/commit/"))).toBe(false);
        await page.getByRole("button", { name: "Change review", exact: true }).click();
        await expect(enabled).toBeChecked();
        await expect(units).toHaveValue("4.5");
        await expect(page.getByRole("textbox", { name: "Reason for correction", exact: true })).toHaveValue(
            "Fictional <b>credit</b> evidence.",
        );
        await page.setViewportSize({ width: 390, height: 844 });
        expect(
            (
                await new AxeBuilder({ page })
                    .include("#drawer-body")
                    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
                    .analyze()
            ).violations,
        ).toEqual([]);
        await page.getByRole("button", { name: "Review final grade", exact: true }).click();
        expect(
            (
                await new AxeBuilder({ page })
                    .include("#drawer-body")
                    .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
                    .analyze()
            ).violations,
        ).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await page.getByRole("button", { name: "Confirm final grade", exact: true }).click();
        expect(f.calls.filter((c) => c.path.endsWith("/final-grades/review/"))).toHaveLength(2);
        expect(f.calls.find((c) => c.path.endsWith("/final-grades/review/"))).toMatchObject({
            csrf: "fictional-test-token",
            data: { enrollment_id: f.enrollment, version: 3, attempted_milli: 4500 },
        });
        expect(f.calls.filter((c) => c.path.endsWith("/final-grades/commit/"))).toHaveLength(1);
        expect(
            f.calls.some((c) => c.path.includes("/accounts/") || c.path.includes("/academic-dates/")),
        ).toBe(false);
        expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
        expect(f.errors).toEqual([]);
    });

test("ordinary grade review omits the disabled credit-unit field", async ({ page }) => {
    const f = await fixture(page, { unitCorrection: true });
    await page
        .getByRole("combobox", { name: "Institutional grade", exact: true })
        .selectOption("00000000000000000000000004");
    await page
        .getByRole("textbox", { name: "Reason for correction", exact: true })
        .fill("Fictional grade-only correction.");
    await page.getByRole("button", { name: "Review final grade", exact: true }).click();
    await expect(
        page.locator("#drawer-body").getByText("Reviewed credit units", { exact: true }),
    ).toHaveCount(0);
    const data = f.calls.find((c) => c.path.endsWith("/final-grades/review/"))?.data;
    expect(data).toBeDefined();
    expect(data).not.toHaveProperty("attempted_milli");
    expect(data).not.toHaveProperty("correct_credit_units");
    expect(f.errors).toEqual([]);
});

test("independent review retains grade and unit changes in one confirmation", async ({ page }) => {
    const f = await fixture(page, { unitCorrection: true, pending: true });
    await page.getByRole("button", { name: "Review correction", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Reviewed credit units");
    await expect(page.locator("#drawer-body")).toContainText("3 → 4.5");
    await page.getByRole("combobox", { name: "Decision", exact: true }).selectOption("approved");
    await page
        .getByRole("textbox", { name: "Reason for decision", exact: true })
        .fill("Fictional independent unit evidence.");
    await page.getByRole("button", { name: "Review decision", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("3 → 4.5");
    await expect(page.locator("#drawer-body")).toContainText("12 → 18");
    expect(f.calls.some((c) => c.path.endsWith("/commit/"))).toBe(false);
    await page.setViewportSize({ width: 390, height: 844 });
    expect(
        (
            await new AxeBuilder({ page })
                .include("#drawer-body")
                .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
                .analyze()
        ).violations,
    ).toEqual([]);
    await page.getByRole("button", { name: "Confirm decision", exact: true }).click();
    expect(
        f.calls.filter((c) => c.path.includes("/grade-changes/") && c.path.endsWith("/commit/")),
    ).toHaveLength(1);
    expect(f.errors).toEqual([]);
});

test("academic placement and dates share one accessible reviewed correction without calendar inference", async ({
    page,
}) => {
    const f = await fixture(page, { placement: true, period: true });
    await page.getByRole("button", { name: "Academic details", exact: true }).click();
    await page
        .getByRole("combobox", { name: "Academic posting term", exact: true })
        .selectOption(String(11).padStart(26, "0"));
    await expect(
        page.getByLabel("Source academic-period start (blank marks unknown)", { exact: true }),
    ).toHaveValue("");
    await expect(
        page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }),
    ).toHaveValue("");
    await page
        .getByLabel("Source academic-period start (blank marks unknown)", { exact: true })
        .fill("2026-01-04");
    await page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }).fill("2026-05-12");
    await page
        .getByRole("textbox", { name: "Reason for academic correction", exact: true })
        .fill("Fictional <b>evidence</b>.");
    await page.getByRole("button", { name: "Review details", exact: true }).click();
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("26/FA → 26/SP");
    await expect(drawer).toContainText("Unknown → 2026-05-12");
    expect(f.calls.some((c) => c.path.endsWith("/academic-details/commit/"))).toBe(false);
    await expect(drawer.locator("b")).toHaveCount(0);
    await page.getByRole("button", { name: "Revise details", exact: true }).click();
    await expect(page.getByRole("combobox", { name: "Academic posting term", exact: true })).toHaveValue(
        String(11).padStart(26, "0"),
    );
    await page.getByRole("button", { name: "Review details", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    await page.getByRole("button", { name: "Confirm details", exact: true }).click();
    await expect.poll(() => f.calls.find((c) => c.path.endsWith("/academic-details/commit/"))).toBeTruthy();
    expect(f.calls.find((c) => c.path.endsWith("/academic-details/review/"))).toMatchObject({
        data: {
            posting_term_id: String(11).padStart(26, "0"),
            academic_on: "2026-01-04",
            completed_on: "2026-05-12",
            effective_on: "2026-08-11",
            enrollment_id: f.enrollment,
            version: 3,
        },
        csrf: "fictional-test-token",
    });
    expect(f.calls.find((c) => c.path.endsWith("/academic-details/commit/"))).toMatchObject({
        data: { review_token: "fictional-details-review" },
        csrf: "fictional-test-token",
    });
    expect(f.calls.some((c) => c.path.includes("/final-grades/"))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("withdrawn placement keeps protected offering dates and omits completion controls", async ({ page }) => {
    const f = await fixture(page, { placement: true, withdrawn: true, knownOffering: true });
    await page.getByRole("button", { name: "Academic details", exact: true }).click();
    await expect(
        page.getByLabel("Actual completion date (blank marks unknown)", { exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByLabel("Source academic-period start (blank marks unknown)", { exact: true }),
    ).toHaveCount(0);
    await page
        .getByRole("combobox", { name: "Academic posting term", exact: true })
        .selectOption(String(11).padStart(26, "0"));
    await page
        .getByRole("textbox", { name: "Reason for academic correction", exact: true })
        .fill("Fictional withdrawn placement evidence.");
    await page.getByRole("button", { name: "Review details", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("26/FA → 26/SP");
    expect(f.calls.find((c) => c.path.endsWith("/academic-details/review/"))?.data).toMatchObject({
        academic_on: null,
        completed_on: null,
    });
    expect(f.errors).toEqual([]);
});

test("stale placement review retains entries and never confirms", async ({ page }) => {
    const f = await fixture(page, { placement: true, reject: true });
    await page.getByRole("button", { name: "Academic details", exact: true }).click();
    await page
        .getByRole("combobox", { name: "Academic posting term", exact: true })
        .selectOption(String(11).padStart(26, "0"));
    await page
        .getByRole("textbox", { name: "Reason for academic correction", exact: true })
        .fill("Fictional placement evidence.");
    await page.getByRole("button", { name: "Review details", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("The academic record changed");
    await expect(page.getByRole("combobox", { name: "Academic posting term", exact: true })).toHaveValue(
        String(11).padStart(26, "0"),
    );
    expect(f.calls.some((c) => c.path.endsWith("/academic-details/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("awarded course correction searches identities, preserves the draft and confirms one protected decision", async ({
    page,
}) => {
    const f = await fixture(page, { courseCorrection: true, directCourse: true });
    const drawer = page.locator("#drawer-body");
    await expect(drawer).toContainText("does not move registration");
    await page.getByLabel(/Reason and course-publication evidence/).fill("Fictional <b>source</b> evidence.");
    await page.getByLabel(/Acceptance effective date/).fill("2026-08-10");
    await page.getByRole("searchbox", { name: "Find another course", exact: true }).fill("writing");
    await page.getByRole("button", { name: "Find course", exact: true }).click();
    await expect(drawer).toContainText("ENG.201");
    await page.getByRole("button", { name: "More courses", exact: true }).click();
    await expect(page.getByRole("button", { name: "Use course", exact: true })).toHaveCount(2);
    await page.getByRole("button", { name: "Use course", exact: true }).first().click();
    await expect(page.getByLabel(/Reason and course-publication evidence/)).toHaveValue(
        "Fictional <b>source</b> evidence.",
    );
    await expect(page.getByLabel(/Acceptance effective date/)).toHaveValue("2026-08-10");
    await page
        .getByRole("combobox", { name: "Accepted course publication", exact: true })
        .selectOption(String(14).padStart(26, "0"));
    await page.getByRole("button", { name: "Review course change", exact: true }).click();
    await expect(drawer).toContainText("ENG.201");
    await expect(drawer.locator("b")).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("/course-publication/commit/"))).toBe(false);
    await page.getByRole("button", { name: "Revise course change", exact: true }).click();
    await expect(
        page.getByRole("combobox", { name: "Accepted course publication", exact: true }),
    ).toHaveValue(String(14).padStart(26, "0"));
    await page.getByRole("button", { name: "Review course change", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const audit = await new AxeBuilder({ page })
        .include("#drawer-body")
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(audit.violations).toEqual([]);
    await page.getByRole("button", { name: "Confirm course change", exact: true }).click();
    expect(f.calls.find((c) => c.path.endsWith("/course-publication/review/"))).toMatchObject({
        data: {
            course_id: String(13).padStart(26, "0"),
            course_version_id: String(14).padStart(26, "0"),
            effective_on: "2026-08-10",
            enrollment_id: f.enrollment,
            version: 3,
        },
        csrf: "fictional-test-token",
    });
    expect(f.calls.find((c) => c.path.endsWith("/course-publication/commit/"))).toMatchObject({
        data: { review_token: "fictional-course-review" },
        csrf: "fictional-test-token",
    });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("stale awarded course correction preserves the entered values and never confirms", async ({ page }) => {
    const f = await fixture(page, { courseCorrection: true, directCourse: true, rejectCourse: true });
    await page
        .getByRole("combobox", { name: "Accepted course publication", exact: true })
        .selectOption(String(6).padStart(26, "0"));
    await page.getByLabel(/Reason and course-publication evidence/).fill("Fictional verification.");
    await page.getByRole("button", { name: "Review course change", exact: true }).click();
    await expect(page.getByLabel(/Reason and course-publication evidence/)).toHaveValue(
        "Fictional verification.",
    );
    await expect(page.locator("#drawer-body")).toContainText("The academic record changed");
    expect(f.calls.some((c) => c.path.endsWith("/course-publication/commit/"))).toBe(false);
    expect(f.errors).toEqual([]);
});
