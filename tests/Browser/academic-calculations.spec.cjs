const { test, expect } = require("@playwright/test");
const { fixture, accessible } = require("./support/student-degree-fixture.cjs");

test("credits and GPA are reviewed on demand, explain contributions and page locally without changing records", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        changed: true,
        calculationHistoryGaps: true,
    });
    expect(f.calls.some((c) => c.path.endsWith("/calculations/"))).toBe(false);
    await page.getByRole("button", { name: "Credits & GPA", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio.getByRole("heading", { name: "Credits & GPA", exact: true })).toBeVisible();
    await expect(studio).toContainText("do not apply courses to requirements");
    await expect(studio).toContainText("Earlier history is unknown");
    await expect(studio).toContainText("matriculation cohort changed");
    await expect(studio).toContainText("B.S. Computing <b>fictional</b>");
    await expect(studio).toContainText("Fictional algebra <b>unsafe</b>");
    await expect(studio.locator("b")).toHaveCount(0);
    const gpa = studio
        .getByRole("article")
        .filter({ has: page.getByRole("heading", { name: "GPA", exact: true }) });
    await expect(gpa).toContainText("3.5");
    await expect(gpa).toContainText("6 GPA credits · 21 quality points");
    await expect(studio).toContainText("0–3");
    await expect(studio).toContainText("Showing 1–20 of 21 records");
    await studio.getByText("Policies used for this review", { exact: true }).click();
    await expect(studio).toContainText("All eligible graded attempts");
    await expect(studio).toContainText("Repeat ordering: Not recorded");
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await studio.getByRole("button", { name: "Next contributions", exact: true }).click();
    await expect(studio).toContainText("Showing 21–21 of 21 records");
    await expect(studio).toContainText("LAST.101");
    await expect(studio).toContainText("Voided award");
    await accessible(page);
    expect(f.calls.filter((c) => c.path.endsWith("/calculations/"))).toHaveLength(1);
    await studio.getByRole("button", { name: "Previous contributions", exact: true }).click();
    await studio.getByRole("button", { name: "Refresh credits & GPA", exact: true }).click();
    await studio.getByRole("button", { name: "Back to previous screen", exact: true }).click();
    await expect(
        studio.getByRole("heading", { name: "Student degree requirements", exact: true }),
    ).toBeVisible();
    const calls = f.calls.filter((c) => c.path.endsWith("/calculations/"));
    expect(calls).toHaveLength(2);
    expect(calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    expect(calls.map((c) => c.body)).toEqual([{}, {}]);
    expect(f.calls.some((c) => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

for (const state of ["unknown", "none", "zero"]) {
    test(`GPA ${state} stays distinct from other outcomes`, async ({ page }) => {
        const f = await fixture(page, {
            assigned: true,
            unknownCalculationGpa: state === "unknown",
            noGpaCredits: state === "none",
            zeroCalculationGpa: state === "zero",
        });
        await page.getByRole("button", { name: "Credits & GPA", exact: true }).click();
        const studio = page.locator("dialog.degree-audit-studio");
        const gpa = studio
            .getByRole("article")
            .filter({ has: page.getByRole("heading", { name: "GPA", exact: true }) });
        if (state === "unknown") {
            await expect(gpa).toContainText("Undetermined");
            await expect(studio).toContainText("Compatibility must be established");
            await expect(gpa).not.toContainText("0 GPA credits");
        } else if (state === "none") {
            await expect(gpa).toContainText("Not applicable");
            await expect(gpa).toContainText("This is not a zero GPA");
        } else {
            await expect(gpa.locator(".h3")).toHaveText("0");
            await expect(gpa).toContainText("6 GPA credits · 0 quality points");
        }
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}

for (const state of ["needs_selection", "limited"]) {
    test(`calculation ${state} shows guidance without partial totals`, async ({ page }) => {
        const f = await fixture(page, {
            assigned: state === "limited",
            calculationLimit: state === "limited",
        });
        await page.getByRole("button", { name: "Credits & GPA", exact: true }).click();
        const studio = page.locator("dialog.degree-audit-studio");
        await expect(studio).toContainText(
            state === "limited"
                ? "No partial totals or records are shown"
                : "Select published degree requirements",
        );
        await expect(studio.getByRole("article")).toHaveCount(0);
        await expect(studio).not.toContainText("0 GPA credits");
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}

test("rejected calculations preserve academic work and announce a safe error", async ({ page }) => {
    const f = await fixture(page, { assigned: true, rejectCalculations: true });
    await page.getByRole("button", { name: "Academic work", exact: true }).click();
    await page.getByRole("button", { name: "Credits & GPA", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio.getByRole("heading", { name: "Academic work", exact: true })).toBeVisible();
    await expect(studio.getByRole("alert")).toContainText("Ask a registrar to review this record");
    await expect(studio).toContainText("MATH.101");
    await accessible(page);
    expect(f.errors).toEqual([]);
});

for (const ordering of ["academic_period", "completion_date"]) {
    test(`contributions explain the retained ${ordering} date`, async ({ page }) => {
        const f = await fixture(page, { assigned: true, retainedOrdering: ordering });
        await page.getByRole("button", { name: "Credits & GPA", exact: true }).click();
        const studio = page.locator("dialog.degree-audit-studio");
        await expect(studio).toContainText(
            `${ordering === "academic_period" ? "Retained instructional start" : "Retained completion date"}: 2026-08-24`,
        );
        await studio.getByText("Policies used for this review", { exact: true }).click();
        await expect(studio).toContainText(
            `Repeat ordering: ${ordering === "academic_period" ? "Academic term / instructional period" : "Actual completion date"}`,
        );
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}
