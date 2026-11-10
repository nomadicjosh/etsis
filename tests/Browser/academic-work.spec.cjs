const { test, expect } = require("@playwright/test");
const { fixture, accessible, id } = require("./support/student-degree-fixture.cjs");

test("academic work is fetched on demand, pages retained facts, and is accessible without changing requirements", async ({
    page,
}) => {
    const f = await fixture(page, { readOnly: true });
    expect(f.calls.some((c) => c.path.endsWith("academic-work/"))).toBe(false);
    await page.getByRole("button", { name: "Academic work", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio.getByRole("heading", { name: "Academic work", exact: true })).toBeVisible();
    await expect(studio).toContainText("not been allocated to this program");
    const retained = studio.getByRole("article").filter({ hasText: "MATH.101" });
    await expect(retained).toContainText("Grade points per course: 4");
    await expect(retained).toContainText("quality points: 12");
    await expect(retained).toContainText("Recorded history verified");
    await expect(studio.getByRole("article").filter({ hasText: "OLD.101" })).toContainText(
        "retained grade definition is unavailable",
    );
    await expect(studio).toContainText("Earlier history is unknown");
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await studio.getByRole("button", { name: "Next page", exact: true }).click();
    await expect(studio).toContainText("Late drop");
    await expect(studio).toContainText("Voided award");
    await expect(studio).toContainText("Fictional late drop <b>unsafe</b>");
    await expect(studio.locator("b")).toHaveCount(0);
    await expect(studio.getByRole("button", { name: "Next page", exact: true })).toHaveCount(0);
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await studio.getByRole("button", { name: "Previous page", exact: true }).click();
    await expect(studio).toContainText("MATH.101");
    await studio.getByRole("button", { name: "Refresh academic work", exact: true }).click();
    await studio.getByRole("button", { name: "Back to degree requirements", exact: true }).click();
    await expect(studio).toContainText("No published requirements have been assigned");
    const calls = f.calls.filter((c) => c.path === `${f.base}academic-work/`);
    expect(calls.map((c) => c.body)).toEqual([{}, { after: id(41) }, {}, {}]);
    expect(calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    expect(f.calls.some((c) => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("a rejected academic-work page preserves the visible records and exposes an accessible error", async ({
    page,
}) => {
    const f = await fixture(page, { rejectAcademicPage: true });
    await page.getByRole("button", { name: "Academic work", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await studio.getByRole("button", { name: "Next page", exact: true }).click();
    await expect(studio.getByRole("alert")).toBeVisible();
    await expect(studio).toContainText("MATH.101");
    await expect(studio).not.toContainText("Voided award");
    await accessible(page);
    expect(f.errors).toEqual([]);
});
