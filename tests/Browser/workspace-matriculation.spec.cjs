const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (number) => String(number).padStart(26, "0");
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((item) => item.id)).toEqual([]);
}

test("matriculation reviews the derived cohort, preserves revisions and shows curated history", async ({
    page,
}) => {
    const app = id(1),
        person = id(2),
        student = id(3),
        assignment = id(4),
        program = id(5),
        term = id(6);
    let status = "accepted";
    const posted = [],
        errors = [];
    const source = {
        program_code: "BS.CS",
        program_title: "Fictional computing",
        entry_term_code: "26/FA",
        cohort: { year_code: "2026-2027", revision: 1, catalog_id: null },
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
                              family_name: "Prospect",
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
                    version: status === "accepted" ? 1 : 2,
                },
                person: {
                    id: { value: person },
                    givenName: "Fictional",
                    familyName: "Prospect",
                    institutionalId: { value: "S-FICTIONAL" },
                },
                labels: {
                    program_code: source.program_code,
                    program_title: source.program_title,
                    term_code: source.entry_term_code,
                },
            };
        else if (path.endsWith("/matriculate/review/"))
            result = {
                source,
                person: {
                    given_name: "Fictional",
                    family_name: "Prospect",
                    institutional_id: "S-FICTIONAL",
                },
                effective_on: "2026-08-10",
                review_token: "fictional-protected-review-token",
            };
        else if (path.endsWith("/matriculate/commit/")) {
            status = "matriculated";
            result = { student_id: student };
        } else if (path.endsWith("/matriculate/history/"))
            result = {
                history_recorded: true,
                earlier_history_unknown: false,
                acceptance_history_unknown: true,
                source: {
                    ...source,
                    cohort: { ...source.cohort, catalog_id: id(7) },
                },
                matriculation: {
                    application_id: app,
                    student_id: student,
                    assignment_id: assignment,
                    program_id: program,
                    entry_term_id: term,
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
        .getByRole("button", { name: "Fictional Prospect", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Matriculate student", exact: true })
        .click();
    await page
        .getByLabel("Reason for matriculation")
        .fill("Fictional completed admissions review");
    await accessible(page);
    await page
        .getByRole("button", { name: "Review matriculation", exact: true })
        .click();
    await expect(
        page.getByRole("heading", { name: "Fictional Prospect", exact: true }),
    ).toBeFocused();
    await expect(page.locator("#drawer-body")).toContainText("2026-2027");
    await expect(page.locator("#drawer-body")).toContainText(
        "will be created on confirmation",
    );
    expect(
        posted.filter((call) => call.path.endsWith("/commit/")),
    ).toHaveLength(0);
    await page
        .getByRole("button", { name: "Revise matriculation", exact: true })
        .click();
    await expect(page.getByLabel("Reason for matriculation")).toHaveValue(
        "Fictional completed admissions review",
    );
    await page
        .getByRole("button", { name: "Review matriculation", exact: true })
        .click();
    await page.setViewportSize({ width: 390, height: 844 });
    await accessible(page);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
    ).toBe(true);
    await page
        .getByRole("button", { name: "Confirm matriculation", exact: true })
        .click();
    await page
        .getByRole("button", { name: "Matriculation history", exact: true })
        .click();
    await expect(page.locator("#drawer-body")).toContainText(
        "Original cohort: 2026-2027",
    );
    await expect(page.locator("#drawer-body")).toContainText(
        "preceding acceptance decision is not yet tracked",
    );
    await expect(page.locator("#drawer-body")).not.toContainText(
        "Fictional completed admissions review",
    );
    expect(posted.find((call) => call.path.endsWith("/commit/")).data).toEqual({
        review_token: "fictional-protected-review-token",
    });
    await accessible(page);
    expect(
        await page.evaluate(() => localStorage.length + sessionStorage.length),
    ).toBe(0);
    expect(errors).toEqual([]);
});
