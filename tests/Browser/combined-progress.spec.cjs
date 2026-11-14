const { test, expect } = require("@playwright/test");
const { fixture, accessible } = require("./support/student-degree-fixture.cjs");

test("combined progress keeps one captured joint plan through component, group and contribution detail", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        combinedSharing: "multiple_components",
    });
    expect(f.calls.some((c) => c.path === `${f.base}combined/`)).toBe(false);
    await page
        .getByRole("button", {
            name: "Combined curriculum progress",
            exact: true,
        })
        .click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(
        studio.getByRole("heading", {
            name: "Combined curriculum progress",
            exact: true,
        }),
    ).toBeVisible();
    await expect(studio).toContainText(
        "a completed course may satisfy more than one component",
    );
    await expect(studio).toContainText(
        "Overall credit and GPA figures are not added across components",
    );
    await expect(studio).toContainText("Data <b>fictional</b> requirements");
    await expect(studio.locator("b, script")).toHaveCount(0);
    await page.setViewportSize({ width: 390, height: 844 });
    await accessible(page);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    await studio
        .getByRole("button", {
            name: "Inspect specialization data component",
            exact: true,
        })
        .focus();
    await page.keyboard.press("Enter");
    await expect(
        studio.getByRole("heading", {
            name: "Specialization component progress",
            exact: true,
        }),
    ).toBeVisible();
    await expect(studio).toContainText(
        "These placements come from the joint search",
    );
    await expect(
        studio.getByRole("button", {
            name: "Open degree progress guide",
            exact: true,
        }),
    ).toHaveCount(0);
    await studio
        .getByRole("button", {
            name: "Inspect course-group placement",
            exact: true,
        })
        .click();
    await expect(studio).toContainText(
        "this component's placements from the joint curriculum search",
    );
    await expect(
        studio.getByRole("button", {
            name: "Refresh course placement",
            exact: true,
        }),
    ).toHaveCount(0);
    await studio
        .getByRole("button", {
            name: "Back to component progress",
            exact: true,
        })
        .click();
    await studio
        .getByRole("button", {
            name: "Inspect credit and GPA contributions",
            exact: true,
        })
        .click();
    await studio
        .getByRole("button", {
            name: "Back to component progress",
            exact: true,
        })
        .click();
    await studio
        .getByRole("button", { name: "Back to combined progress", exact: true })
        .click();
    expect(f.calls.filter((c) => c.path === `${f.base}combined/`)).toHaveLength(
        1,
    );
    expect(
        f.calls.some((c) =>
            /progress\/$|academic-work\/allocation\/$/.test(c.path),
        ),
    ).toBe(false);
    await studio
        .getByRole("button", { name: "Refresh combined review", exact: true })
        .click();
    await expect
        .poll(
            () => f.calls.filter((c) => c.path === `${f.base}combined/`).length,
        )
        .toBe(2);
    expect(
        f.calls
            .filter((c) => c.path === `${f.base}combined/`)
            .every((c) => c.csrf === "fictional-test-token"),
    ).toBe(true);
    expect(
        await page.evaluate(() => [localStorage.length, sessionStorage.length]),
    ).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});

test("joint shortages never borrow an independently feasible partial component plan", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        combinedInfeasible: true,
    });
    await page
        .getByRole("button", {
            name: "Combined curriculum progress",
            exact: true,
        })
        .click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText(
        "No complete combined placement was found",
    );
    await expect(studio).toContainText(
        "No partial placements are presented as completed requirements",
    );
    await studio
        .getByRole("button", { name: "Inspect degree component", exact: true })
        .click();
    await expect(
        studio.getByRole("heading", {
            name: "Degree component progress",
            exact: true,
        }),
    ).toBeVisible();
    await studio
        .getByRole("button", {
            name: "Inspect course-group placement",
            exact: true,
        })
        .click();
    await expect(studio).toContainText("No complete group placement found");
    await expect(studio).not.toContainText("This course is placed");
    expect(f.calls.filter((c) => c.path === `${f.base}combined/`)).toHaveLength(
        1,
    );
    expect(f.errors).toEqual([]);
});

test("missing retained policies, assignments and read limits explain the next step without partial components", async ({
    page,
}) => {
    for (const options of [
        {
            combinedIssue: "sharing_policy_unknown",
            text: "Course sharing was not recorded",
        },
        {
            combinedIssue: "specialization_requirements_missing",
            text: "Each selected specialization needs",
        },
        { combinedIssue: "cohort_changed", text: "The cohort changed" },
        {
            progressLimit: true,
            text: "The combined review reached its work limit",
        },
    ]) {
        await page.unroute("**/sis/**");
        const f = await fixture(page, {
            assigned: true,
            readOnly: true,
            ...options,
        });
        await page
            .getByRole("button", {
                name: "Combined curriculum progress",
                exact: true,
            })
            .click();
        const studio = page.locator("dialog.degree-audit-studio");
        await expect(
            studio.getByRole("status").filter({ hasText: options.text }),
        ).toBeVisible();
        await expect(
            studio.getByRole("button", { name: /Inspect .* component/ }),
        ).toHaveCount(0);
        expect(f.errors).toEqual([]);
    }
});


test("staff shared-course shortages inspect the captured affected group and return to the joint review", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        combinedInfeasible: true,
        combinedCompetition: true,
    });
    await page.getByRole("button", { name: "Combined curriculum progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    const shortage = studio.getByRole("region", { name: "Shared-course shortages", exact: true });
    await expect(shortage).toContainText("At least 3 credit contributions more are needed");
    await expect(shortage).toContainText("shortfall is at least 1 course contribution");
    await page.setViewportSize({ width: 390, height: 844 });
    await accessible(page);
    await shortage
        .getByRole("button", { name: "Inspect Specialization DATA · General education <b>fictional</b>", exact: true })
        .focus();
    await page.keyboard.press("Enter");
    await expect(
        studio.getByRole("heading", { name: "General education <b>fictional</b>", exact: true }),
    ).toBeVisible();
    await expect(studio).toContainText("No speculative partial plan is shown");
    await studio.getByRole("button", { name: "Back to combined progress", exact: true }).click();
    await expect(shortage).toBeVisible();
    expect(f.calls.filter((c) => c.path === `${f.base}combined/`)).toHaveLength(1);
    expect(f.calls.some((c) => /progress\/$|academic-work\/allocation\/$/.test(c.path))).toBe(false);
    await studio.getByRole("button", { name: "Refresh combined review", exact: true }).click();
    await expect.poll(() => f.calls.filter((c) => c.path === `${f.base}combined/`).length).toBe(2);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    expect(f.errors).toEqual([]);
});
