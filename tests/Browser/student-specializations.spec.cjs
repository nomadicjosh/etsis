const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");

async function fixture(
    page,
    {
        readOnly = false,
        cohortChanged = false,
        unknown = false,
        reject = false,
    } = {},
) {
    const calls = [],
        errors = [];
    const student = id(1),
        assignment = id(2),
        programId = id(3),
        person = id(4);
    const base = `/sis/students/${student}/programs/${assignment}/specializations/`;
    const offered = Array.from({ length: 23 }, (_, n) => ({
        id: id(100 + n),
        code: `CHOICE.${n + 1}`,
        title: n === 0 ? "Original <b>Art</b>" : `Fictional choice ${n + 1}`,
        description: "Plain <script>example</script> text.",
        revision: 1,
    }));
    const program = {
        definition: { code: "BA.ART", title: "Fictional arts" },
        academic_year_code: "2024-2025",
        catalog_revision: 1,
        specializations: { effective: { minimum: 0, maximum: 2 }, offered },
    };
    const summary = (ids) => ({
        program_version_id: id(10),
        program_code: "BA.ART",
        program_title: "Fictional arts",
        academic_year_code: "2024-2025",
        catalog_revision: 1,
        limits: { minimum: 0, maximum: 2 },
        specializations: offered.filter((item) => ids.includes(item.id)),
    });
    let selected = summary([offered[0].id]),
        prepared = null;
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw
            ? JSON.parse(new URLSearchParams(raw).get("_workspace_json"))
            : null;
        if (data)
            calls.push({
                path,
                data,
                csrf: route.request().headers()["x-csrf-token"],
            });
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/")
            result = {
                items: [
                    {
                        id: student,
                        person_id: person,
                        given_name: "Alex",
                        family_name: "Example",
                        institutional_id: "S-FICTIONAL",
                        active: true,
                    },
                ],
                next_after: null,
            };
        else if (path.endsWith("/record/"))
            result = {
                id: student,
                person_id: person,
                given_name: "Alex",
                family_name: "Example",
                institutional_id: "S-FICTIONAL",
                active: true,
                primary_program: {
                    id: programId,
                    code: "BA.ART",
                    title: "Fictional arts",
                },
                entry_term: { code: "24/FA" },
            };
        else if (path === `/sis/students/${student}/programs/`)
            result = {
                items: [
                    {
                        id: assignment,
                        program_id: programId,
                        program_code: "BA.ART",
                        program_title: "Fictional arts",
                        term_code: "24/FA",
                        catalog_year: "2024-2025",
                        catalog_revision: 1,
                        status: readOnly ? "closed" : "active",
                        is_primary: true,
                    },
                ],
                next_after: null,
            };
        else if (path === base)
            result = {
                assignment_id: assignment,
                scope: { status: readOnly ? "closed" : "active" },
                revision: "fictional-revision",
                program: unknown ? null : program,
                selection: selected,
                cohort_changed: cohortChanged,
                can_manage: !readOnly,
            };
        else if (path === base + "review/") {
            if (reject)
                return route.fulfill({
                    status: 422,
                    json: {
                        message:
                            "The review changed. Reload before confirming.",
                    },
                });
            prepared = summary(data.specialization_ids);
            result = {
                before: selected,
                after: prepared,
                reason: data.reason,
                review_token: "fictional-review-token",
            };
        } else if (path === base + "commit/") {
            selected = prepared;
            result = { operation_id: id(99) };
        } else if (path === base + "history/")
            result = {
                items: [
                    {
                        selection: selected,
                        version: 1,
                        effective_at: "2026-08-10T17:00:00Z",
                        recorded_at: "2026-08-10T17:00:00Z",
                    },
                ],
                next_cursor: null,
            };
        await route.fulfill({
            json: result,
            headers: { "Cache-Control": "private, no-store" },
        });
    });
    await page.goto(
        readOnly
            ? "/admin/student-read-fixture/"
            : "/admin/specialization-fixture/",
    );
    await page
        .locator('.workspace-nav-link[data-workspace="students"]')
        .click();
    await page.locator("#directory-search").fill("Alex");
    await page.locator("#directory-search").press("Enter");
    await page
        .getByRole("button", { name: "Alex Example", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Specializations", exact: true })
        .click();
    return { calls, errors, offered, base };
}

test("specialization selection preserves checked choices through search, pages and accessible review", async ({
    page,
}) => {
    const f = await fixture(page);
    await expect(page.getByRole("checkbox")).toHaveCount(20);
    await expect(
        page.getByLabel("CHOICE.1 — Original <b>Art</b>", { exact: true }),
    ).toBeChecked();
    await page
        .getByRole("button", { name: "More choices", exact: true })
        .focus();
    await page.keyboard.press("Enter");
    await expect(page.getByRole("checkbox")).toHaveCount(3);
    await expect(page.locator(":focus")).toContainText("Choices 21–23");
    await page
        .getByLabel("CHOICE.23 — Fictional choice 23", { exact: true })
        .check();
    await page.getByLabel("Find an offered specialization").fill("choice 23");
    await expect(page.getByRole("checkbox")).toHaveCount(1);
    await expect(page.getByRole("checkbox")).toBeChecked();
    await expect(
        page.getByRole("status").filter({ hasText: "2 selected." }),
    ).toBeVisible();
    await page
        .getByLabel("Reason for this selection change")
        .fill("Fictional registrar request.");
    await page.setViewportSize({ width: 390, height: 844 });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await page
        .getByRole("button", {
            name: "Review specialization selection",
            exact: true,
        })
        .click();
    await expect(
        page.getByRole("region", { name: "After", exact: true }),
    ).toContainText("Fictional choice 23");
    await page
        .getByRole("button", { name: "Revise selection", exact: true })
        .click();
    await expect(
        page.getByLabel("Reason for this selection change"),
    ).toHaveValue("Fictional registrar request.");
    await page
        .getByRole("button", {
            name: "Review specialization selection",
            exact: true,
        })
        .click();
    await page
        .getByRole("button", {
            name: "Confirm specialization selection",
            exact: true,
        })
        .click();
    await expect(
        page
            .getByRole("status")
            .filter({ hasText: "Specialization selection recorded." }),
    ).toBeVisible();
    await expect(
        page.getByRole("region", { name: "Recorded selection", exact: true }),
    ).toContainText("Fictional choice 23");
    await page
        .getByRole("button", { name: "Specialization history", exact: true })
        .click();
    await expect(
        page.getByRole("region", { name: "Decision 1", exact: true }),
    ).toContainText("Original <b>Art</b>");
    await expect(
        page.locator(".offcanvas-body b, .offcanvas-body script"),
    ).toHaveCount(0);
    expect(
        f.calls.filter((call) => call.path === f.base + "review/")[0].data
            .specialization_ids,
    ).toEqual([f.offered[0].id, f.offered[22].id]);
    expect(f.calls.every((call) => call.csrf === "fictional-test-token")).toBe(
        true,
    );
    expect(
        await page.evaluate(() => [localStorage.length, sessionStorage.length]),
    ).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("rejected selection keeps the draft and closed or unknown pursuits explain their boundary", async ({
    page,
}) => {
    const f = await fixture(page, { reject: true });
    await page
        .getByLabel("CHOICE.2 — Fictional choice 2", { exact: true })
        .check();
    await page
        .getByLabel("Reason for this selection change")
        .fill("Keep this draft.");
    await page
        .getByRole("button", {
            name: "Review specialization selection",
            exact: true,
        })
        .click();
    await expect(
        page.getByLabel("Reason for this selection change"),
    ).toHaveValue("Keep this draft.");
    await expect(
        page.getByLabel("CHOICE.2 — Fictional choice 2", { exact: true }),
    ).toBeChecked();
    expect(f.calls.filter((call) => call.path.endsWith("/commit/"))).toEqual(
        [],
    );
    expect(f.errors).toEqual([]);
    await page.unrouteAll({ behavior: "wait" });
    await fixture(page, { readOnly: true, cohortChanged: true });
    await expect(
        page
            .getByRole("alert")
            .filter({ hasText: "matriculation cohort changed" }),
    ).toBeVisible();
    await expect(
        page.getByText("This pursuit is closed.", { exact: false }),
    ).toBeVisible();
    await expect(page.getByRole("checkbox")).toHaveCount(0);
    await expect(
        page.getByRole("button", {
            name: "Review specialization selection",
            exact: true,
        }),
    ).toHaveCount(0);
    await page.unrouteAll({ behavior: "wait" });
    await fixture(page, { unknown: true });
    await expect(
        page.getByText("This pursuit has no retained program publication.", {
            exact: false,
        }),
    ).toBeVisible();
    await expect(page.getByRole("checkbox")).toHaveCount(0);
});
