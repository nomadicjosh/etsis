const { test, expect } = require("@playwright/test");
const { fixture, accessible } = require("./support/student-degree-fixture.cjs");

test("group placement is on demand, explains retained rules and alternatives, and pages without changing work", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        allocationHistoryGaps: true,
        changed: true,
        shareCourses: true,
    });
    expect(f.calls.some((c) => c.path.endsWith("/allocation/"))).toBe(false);
    await page.getByRole("button", { name: "Review course placement", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio.getByRole("heading", { name: "Course-group placement", exact: true })).toBeVisible();
    await expect(studio).toContainText("A complete group placement was found");
    await expect(studio).toContainText("This is not a completed degree audit");
    await expect(studio).toContainText("Earlier history is unknown");
    await expect(studio).toContainText("matriculation cohort changed");
    await expect(studio).toContainText("A completed course may satisfy multiple groups");
    await expect(studio).toContainText("General education <b>fictional</b>");
    await expect(studio.locator("b")).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await studio
        .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
        .click();
    await expect(studio).toContainText("63 required credits");
    await expect(studio).toContainText("Minimum course grade points: 2");
    await expect(studio).toContainText("1 approved course waivers");
    await expect(studio).toContainText("Approved alternative for REQ.0");
    await expect(studio).toContainText("2 equivalent earned-credit evidence alternatives");
    await expect(studio).toContainText("Showing 1–20 of 21 placements");
    expect(await studio.getByText("Course requirement waived", { exact: false }).count()).toBe(0);
    await studio.locator("summary").filter({ hasText: "Eligible course pool" }).click();
    await expect(studio).toContainText(
        "REQ.0 · Retained requirement <b>fictional</b> · Course requirement waived",
    );
    await expect(studio.locator("b")).toHaveCount(0);
    await accessible(page);
    await studio.getByRole("button", { name: "Next placements", exact: true }).click();
    await expect(studio).toContainText("Showing 21–21 of 21 placements");
    await expect(studio).toContainText("LAST.101");
    await studio.getByRole("button", { name: "Previous placements", exact: true }).click();
    await studio.getByRole("button", { name: "Back to course groups", exact: true }).click();
    await studio.getByRole("button", { name: "Next work records", exact: true }).click();
    await expect(studio).toContainText("Showing 21–21 of 21 records");
    await studio.getByRole("button", { name: "Previous work records", exact: true }).click();
    expect(f.calls.filter((c) => c.path.endsWith("/allocation/"))).toHaveLength(1);
    await studio.getByRole("button", { name: "Refresh course placement", exact: true }).click();
    await studio.getByRole("button", { name: "Back to previous screen", exact: true }).click();
    await expect(
        studio.getByRole("heading", { name: "Student degree requirements", exact: true }),
    ).toBeVisible();
    const calls = f.calls.filter((c) => c.path.endsWith("/allocation/"));
    expect(calls).toHaveLength(2);
    expect(calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    expect(calls.map((c) => c.body)).toEqual([{}, {}]);
    expect(f.calls.some((c) => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

for (const outcome of ["infeasible", "needs_review", "unsupported", "undetermined"]) {
    test(`placement ${outcome} stays distinct and never shows a partial plan`, async ({ page }) => {
        const f = await fixture(page, { assigned: true, allocationOutcome: outcome });
        await page.getByRole("button", { name: "Review course placement", exact: true }).click();
        const studio = page.locator("dialog.degree-audit-studio");
        const labels = {
            infeasible: "No complete group placement found",
            needs_review: "Some academic inputs need review",
            unsupported: "These retained rules need additional support",
            undetermined: "The placement search reached its limit",
        };
        await expect(studio).toContainText(labels[outcome]);
        await expect(studio).toContainText("No placements have been assigned");
        await expect(studio).not.toContainText("21 proposed course placements");
        await expect(studio).toContainText("A completed course can be placed in one group");
        await studio
            .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
            .click();
        await expect(studio).toContainText("No speculative partial plan is shown");
        await expect(studio).not.toContainText("actual earned credits");
        await studio.locator("summary").filter({ hasText: "Eligible course pool" }).click();
        await expect(studio).toContainText("REQ.0");
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}
for (const status of ["needs_selection", "limited"]) {
    test(`placement ${status} provides guidance without fake empty results`, async ({ page }) => {
        const f = await fixture(page, {
            assigned: status === "limited",
            allocationLimit: status === "limited",
        });
        await page.getByRole("button", { name: "Review course placement", exact: true }).click();
        const studio = page.locator("dialog.degree-audit-studio");
        await expect(studio).toContainText(
            status === "limited"
                ? "No partial placements or records are shown"
                : "Select published degree requirements",
        );
        await expect(studio.getByRole("article")).toHaveCount(0);
        await expect(studio).not.toContainText("0 current academic records");
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}

test("inconsistent placement preserves current work and announces a safe error", async ({ page }) => {
    const f = await fixture(page, { assigned: true, rejectAllocation: true });
    await page.getByRole("button", { name: "Academic work", exact: true }).click();
    await page.getByRole("button", { name: "Review course placement", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio.getByRole("heading", { name: "Academic work", exact: true })).toBeVisible();
    await expect(studio.getByRole("alert")).toContainText("Ask a registrar to review this record");
    await expect(studio).toContainText("MATH.101");
    await accessible(page);
    expect(f.errors).toEqual([]);
});
