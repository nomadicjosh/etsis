const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;

test("registrar reviews a reasoned grade correction from the student record and reads bounded history", async ({
    page,
}) => {
    const id = (n) => String(n).padStart(26, "0");
    const student = id(1),
        person = id(2),
        section = id(3),
        grade = id(4);
    const calls = [],
        errors = [];
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
                        person_id: person,
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
        else if (path.endsWith("/grade-history/"))
            result = {
                items: [
                    {
                        version: data.cursor ? 11 : 1,
                        action: data.cursor ? "grade_decided" : "observed_existing",
                        grade_code: data.cursor ? "A" : "B",
                        effective_at: "2026-08-10",
                        recorded_at: "2026-08-11",
                    },
                ],
                next_cursor: data.cursor ? null : "fictional-frozen-cursor",
                earlier_history_unknown: true,
            };
        else if (path.endsWith("/grade-choices/"))
            result = {
                items: [{ id: grade, code: "B", points_milli: 3000 }],
                current_grade_code: "A",
                posted: true,
                can_amend: true,
                current_version: 3,
                effective_on_default: "2026-08-11",
                needs_assignment: false,
                pursuits: [],
            };
        else if (path.endsWith("/final-grades/review/"))
            result = {
                grade: { code: "B", points_milli: 3000 },
                reason: data.reason,
                review_token: "fictional-reviewed-correction",
                earlier_history_unknown: true,
            };
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/grade-amend-fixture/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await page.getByRole("button", { name: "View transcript", exact: true }).click();
    await page.getByRole("button", { name: "Grade history", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Earlier history is unknown");
    await page.getByRole("button", { name: "Next page", exact: true }).click();
    await expect
        .poll(() => calls.filter((c) => c.path.endsWith("/grade-history/")).at(-1)?.data)
        .toEqual({ cursor: "fictional-frozen-cursor" });
    await page.getByRole("button", { name: "Back to record", exact: true }).click();
    await page.getByRole("button", { name: "View transcript", exact: true }).click();
    await page.getByRole("button", { name: "Review final grade", exact: true }).click();
    await page.getByRole("combobox", { name:"Institutional grade", exact: true }).selectOption(grade);
    await expect(page.getByRole("textbox", { name:"Reason for correction", exact: true })).toHaveJSProperty(
        "required",
        true,
    );
    await page
        .getByRole("textbox", { name:"Reason for correction", exact: true })
        .fill("Approved <b>assessment</b> review.");
    await page.getByRole("textbox", { name:"Effective date", exact: true }).fill("2026-08-10");
    await page.getByRole("button", { name: "Review final grade", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("A → B · 3 grade points");
    await expect(page.locator("#drawer-body b")).toHaveCount(0);
    await page.getByRole("button", { name: "Change review", exact: true }).click();
    await expect(page.getByRole("textbox", { name:"Reason for correction", exact: true })).toHaveValue(
        "Approved <b>assessment</b> review.",
    );
    await expect(page.getByRole("textbox", { name:"Effective date", exact: true })).toHaveValue("2026-08-10");
    await page.getByRole("button", { name: "Review final grade", exact: true }).click();
    expect(calls.some((c) => c.path.endsWith("/final-grades/commit/"))).toBe(false);
    await page.setViewportSize({ width: 390, height: 844 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => v.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Confirm final grade", exact: true }).click();
    await expect
        .poll(() => calls.find((c) => c.path.endsWith("/final-grades/commit/"))?.data)
        .toEqual({ student_id: student, review_token: "fictional-reviewed-correction" });
    expect(calls.find((c) => c.path.endsWith("/final-grades/review/"))).toMatchObject({
        data: {
            student_id: student,
            version: 3,
            grade_id: grade,
            effective_on: "2026-08-10",
            reason: "Approved <b>assessment</b> review.",
        },
        csrf: "fictional-test-token",
    });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
