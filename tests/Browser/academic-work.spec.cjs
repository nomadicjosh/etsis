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

test("requirement matches explain candidates and unused work, retain mode while paging, and never allocate", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        changed: true,
        approvalConflicts: true,
        moreMatches: true,
    });
    await page.getByRole("button", { name: "Academic work", exact: true }).click();
    expect(f.calls.some((c) => c.path.endsWith("/matches/"))).toBe(false);
    await page.getByRole("button", { name: "Check requirement matches", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(
        studio.getByRole("heading", { name: "Requirement matches", exact: true, level: 1 }),
    ).toBeVisible();
    await expect(studio).toContainText("Candidate for allocation");
    await expect(studio).toContainText("No course is applied");
    await expect(studio).toContainText("B.S. Computing <b>fictional</b>");
    await expect(studio).toContainText("Algebra <b>fictional</b>");
    await expect(studio.locator("b")).toHaveCount(0);
    await expect(studio).toContainText("matriculation cohort changed");
    await expect(studio).toContainText("Some course approvals conflict");
    await expect(studio).toContainText("first 25 matches");
    await expect(studio).toContainText("Group minimum per-course grade points: 2.7");
    await expect(studio).toContainText(
        "does not decide whether the work counts toward overall credits or GPA",
    );
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    await studio.getByRole("button", { name: "Next page", exact: true }).click();
    await expect(studio).toContainText("A dropped course cannot satisfy");
    await expect(studio).toContainText("A voided award cannot satisfy");
    await expect(studio).toContainText("Approved alternative for");
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await studio.getByRole("button", { name: "Previous page", exact: true }).click();
    await studio.getByRole("button", { name: "Refresh requirement matches", exact: true }).click();
    await studio.getByRole("button", { name: "View academic work only", exact: true }).click();
    await expect(studio.getByRole("heading", { name: "Academic work", exact: true })).toBeVisible();
    await expect(studio).not.toContainText("Candidate for allocation");
    const calls = f.calls.filter((c) => c.path === `${f.base}academic-work/matches/`);
    expect(calls.map((c) => c.body)).toEqual([{}, { after: id(41) }, {}, {}]);
    expect(calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    expect(f.calls.some((c) => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("a missing degree selection explains setup instead of inventing matches", async ({ page }) => {
    const f = await fixture(page, { readOnly: true });
    await page.getByRole("button", { name: "Academic work", exact: true }).click();
    await page.getByRole("button", { name: "Check requirement matches", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText("Select published degree requirements for this pursuit");
    await expect(studio).not.toContainText("Candidate for allocation");
    await expect(studio).toContainText("MATH.101");
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test("denied matching retains the academic-work screen and announces the error", async ({ page }) => {
    const f = await fixture(page, { assigned: true, rejectAcademicMatches: true });
    await page.getByRole("button", { name: "Academic work", exact: true }).click();
    await page.getByRole("button", { name: "Check requirement matches", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio.getByRole("heading", { name: "Academic work", exact: true })).toBeVisible();
    await expect(studio.getByRole("alert")).toBeVisible();
    await expect(studio).not.toContainText("Candidate for allocation");
    await accessible(page);
    expect(f.errors).toEqual([]);
});
