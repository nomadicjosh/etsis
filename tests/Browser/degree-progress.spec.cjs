const { test, expect } = require("@playwright/test");
const { fixture, accessible } = require("./support/student-degree-fixture.cjs");

const minimumCard = (page, name) =>
    page
        .locator("dialog.degree-audit-studio")
        .getByRole("article")
        .filter({ has: page.getByRole("heading", { name, exact: true }) });

test("degree progress combines minimums and group placement in one on-demand read with accessible local detail views", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        changed: true,
        progressHistoryGaps: true,
    });
    expect(f.calls.some((c) => c.path === `${f.base}progress/`)).toBe(false);
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio.getByRole("heading", { name: "Degree progress review", exact: true })).toBeVisible();
    await expect(studio).toContainText("All checks use one verified read");
    await expect(studio).toContainText("not a completed degree audit or graduation decision");
    await expect(studio).toContainText("Earlier history is unknown");
    await expect(studio).toContainText("matriculation cohort changed");
    await expect(studio).toContainText("B.S. Computing <b>fictional</b>");
    await expect(studio.locator("b")).toHaveCount(0);
    const cumulative = minimumCard(page, "Cumulative GPA"),
        overall = minimumCard(page, "Overall GPA");
    await expect(cumulative).toContainText("Minimum met");
    await expect(cumulative.locator(".h3")).toHaveText("4");
    await expect(cumulative).toContainText("Approved minimum reduction · Published minimum: 3.75");
    await expect(cumulative).toContainText("Institutional work only");
    await expect(overall).toContainText("Below minimum");
    await expect(overall.locator(".h3")).toHaveText("3");
    await expect(studio).toContainText("A complete course-group placement was found");
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await studio.getByRole("button", { name: "Inspect credit and GPA contributions", exact: true }).click();
    await expect(studio).toContainText("Cumulative GPA: Transfer attempts are outside cumulative GPA");
    await expect(studio).toContainText("Retained work <b>fictional</b>");
    await expect(studio.locator("b")).toHaveCount(0);
    await expect(studio).toContainText("Showing 1–20 of 21 records");
    await studio.getByRole("button", { name: "Next contribution records", exact: true }).click();
    await expect(studio).toContainText("Showing 21–21 of 21 records");
    await expect(studio).toContainText("LAST.101");
    await studio.getByRole("button", { name: "Previous contribution records", exact: true }).click();
    await accessible(page);
    await studio.getByRole("button", { name: "Back to degree progress", exact: true }).click();
    await studio.getByRole("button", { name: "Inspect course-group placement", exact: true }).click();
    await expect(studio.getByRole("heading", { name: "Course-group placement", exact: true })).toBeVisible();
    await studio
        .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
        .click();
    await expect(studio).toContainText("Approved alternative for REQ.0");
    await studio.getByRole("button", { name: "Back to course groups", exact: true }).click();
    await studio.getByRole("button", { name: "Back to previous screen", exact: true }).click();
    await expect(studio.getByRole("heading", { name: "Degree progress review", exact: true })).toBeVisible();
    expect(f.calls.filter((c) => c.path === `${f.base}progress/`)).toHaveLength(1);
    expect(f.calls.some((c) => c.path.includes("/academic-work/"))).toBe(false);
    await studio.getByRole("button", { name: "Refresh degree progress", exact: true }).click();
    await studio.getByRole("button", { name: "Back to degree requirements", exact: true }).click();
    const calls = f.calls.filter((c) => c.path === `${f.base}progress/`);
    expect(calls).toHaveLength(2);
    expect(calls.map((c) => c.body)).toEqual([{}, {}]);
    expect(calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    expect(f.calls.some((c) => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("an unknown overall GPA does not become zero or erase known cumulative GPA", async ({ page }) => {
    const f = await fixture(page, { assigned: true, progressUnknownOverall: true });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    await expect(minimumCard(page, "Cumulative GPA")).toContainText("Minimum met");
    const overall = minimumCard(page, "Overall GPA");
    await expect(overall).toContainText("Needs review");
    await expect(overall.locator(".h3")).toHaveText("Unavailable");
    await expect(overall).toContainText("No partial GPA is shown");
    await expect(overall).not.toContainText("0 GPA credits");
    await accessible(page);
    expect(f.errors).toEqual([]);
});
for (const mode of ["none", "zero", "resident_range"]) {
    test(`progress preserves ${mode} semantics instead of assuming a minimum is met`, async ({ page }) => {
        const f = await fixture(page, {
            assigned: true,
            progressNoGpa: mode === "none",
            progressZeroGpa: mode === "zero",
            progressResidentRange: mode === "resident_range",
        });
        await page.getByRole("button", { name: "Degree progress", exact: true }).click();
        if (mode === "none") {
            const card = minimumCard(page, "Cumulative GPA");
            await expect(card).toContainText("GPA not yet established");
            await expect(card).toContainText("This is not a zero GPA");
            await expect(card.locator(".h3")).toHaveText("Unavailable");
        } else if (mode === "zero") {
            const card = minimumCard(page, "Cumulative GPA");
            await expect(card).toContainText("Below minimum");
            await expect(card.locator(".h3")).toHaveText("0");
            await expect(card).toContainText("6 GPA credits · 0 quality points");
        } else {
            const card = minimumCard(page, "Resident credits (institutional origin)");
            await expect(card).toContainText("Needs review");
            await expect(card.locator(".h3")).toHaveText("3–9");
            await expect(card).toContainText("not silently chosen");
        }
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}
for (const status of ["needs_selection", "limited"]) {
    test(`progress ${status} shows guidance without partial checks`, async ({ page }) => {
        const f = await fixture(page, {
            assigned: status === "limited",
            progressLimit: status === "limited",
        });
        await page.getByRole("button", { name: "Degree progress", exact: true }).click();
        const studio = page.locator("dialog.degree-audit-studio");
        await expect(studio).toContainText(
            status === "limited"
                ? "No partial minimum checks, records or placements"
                : "Select published degree requirements",
        );
        await expect(studio.getByRole("article")).toHaveCount(0);
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}

test("rejected progress preserves the retained degree view and announces the error", async ({ page }) => {
    const f = await fixture(page, { assigned: true, rejectProgress: true });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(
        studio.getByRole("heading", { name: "Student degree requirements", exact: true }),
    ).toBeVisible();
    await expect(studio.getByRole("alert")).toContainText("Ask a registrar to review this record");
    await accessible(page);
    expect(f.errors).toEqual([]);
});
