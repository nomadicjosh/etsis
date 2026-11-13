const { test, expect } = require("@playwright/test");
const { fixture, accessible, id } = require("./support/student-degree-fixture.cjs");

test("registrar explicitly chooses and reviews a retained group, revises without losing fields, then revokes the preference", async ({
    page,
}) => {
    const f = await fixture(page, { assigned: true });
    await page.getByRole("button", { name: "Student exceptions", exact: true }).click();
    await page.getByRole("button", { name: "Approve an exception", exact: true }).click();
    await page.getByRole("button", { name: "Course placement", exact: true }).click();
    await page.getByRole("button", { name: "MATH.101 · Algebra", exact: true }).click();
    await expect(page.getByRole("button", { name: "Use in General education", exact: true })).toHaveAttribute(
        "aria-pressed",
        "false",
    );
    await page
        .getByLabel("Reason for student exception", { exact: true })
        .fill("Fictional advisor recommendation <script>private</script>");
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio").getByRole("alert")).toContainText(
        "Choose the group",
    );
    expect(f.calls.some((c) => c.path === f.adjustmentBase + "review/")).toBe(false);
    await page.getByRole("button", { name: "Use in General education", exact: true }).click();
    await expect(page.getByRole("button", { name: "Use in General education", exact: true })).toHaveAttribute(
        "aria-pressed",
        "true",
    );
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText("Retained <b>Algebra</b>");
    await expect(studio).toContainText("does not guarantee a complete placement");
    await expect(studio.locator("script, b")).toHaveCount(0);
    await page.getByRole("button", { name: "Change review", exact: true }).click();
    await expect(page.getByLabel("Reason for student exception", { exact: true })).toHaveValue(
        "Fictional advisor recommendation <script>private</script>",
    );
    await expect(page.getByRole("button", { name: "Use in General education", exact: true })).toHaveAttribute(
        "aria-pressed",
        "true",
    );
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Approve reviewed exception", exact: true }).click();
    await expect(studio).toContainText(
        "Course placement · MATH.101 · Retained <b>Algebra</b> → General education",
    );
    const review = f.calls.filter((c) => c.path === f.adjustmentBase + "review/").at(-1);
    expect(review.body.definition).toEqual({ kind: "placement", course_id: id(7), group_id: id(6) });
    expect(review.csrf).toBe("fictional-test-token");
    await page.getByRole("button", { name: "Review revocation", exact: true }).click();
    await page
        .getByLabel("Reason for revocation", { exact: true })
        .fill("Fictional return to normal sharing.");
    await page.getByRole("button", { name: "Prepare revocation", exact: true }).click();
    await expect(studio).toContainText("restores normal eligible group choices");
    await page.getByRole("button", { name: "Revoke reviewed exception", exact: true }).click();
    await expect(studio).toContainText("No active exceptions");
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test("placement review displays the approved preference using retained group labels", async ({ page }) => {
    const f = await fixture(page, { assigned: true, readOnly: true, allocationPreference: true });
    await page.getByRole("button", { name: "Review course placement", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText("Approved placement preference: General education <b>fictional</b>");
    await expect(studio.locator("b")).toHaveCount(0);
    expect(f.calls.some((c) => /\/(commit|review)\/$/.test(c.path))).toBe(false);
    expect(f.errors).toEqual([]);
});

test("registrar reserves an approved substitute without offering it as a curriculum waiver target", async ({
    page,
}) => {
    const f = await fixture(page, { assigned: true, approvedReplacement: true });
    await page.getByRole("button", { name: "Student exceptions", exact: true }).click();
    await page.getByRole("button", { name: "Approve an exception", exact: true }).click();
    const choice = "SCI.201 · Fictional <b>Science</b> · Approved substitute version 1 for MATH.101";
    await expect(page.getByRole("button", { name: choice, exact: true })).toHaveCount(0);
    await page.getByRole("button", { name: "Course placement", exact: true }).click();
    await page.getByRole("button", { name: choice, exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText(
        "Approved substitute version 1 for MATH.101 · Retained <b>Algebra</b>",
    );
    await page.getByRole("button", { name: "Use in General education", exact: true }).click();
    await page
        .getByLabel("Reason for student exception", { exact: true })
        .fill("Fictional reviewed alternative placement.");
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(studio).toContainText(
        "Course placement · SCI.201 · Fictional <b>Science</b> → General education",
    );
    await expect(studio).toContainText("separate group restriction remains");
    await page.getByRole("button", { name: "Change review", exact: true }).click();
    await expect(page.getByRole("button", { name: "Use in General education", exact: true })).toHaveAttribute(
        "aria-pressed",
        "true",
    );
    await expect(page.getByLabel("Reason for student exception", { exact: true })).toHaveValue(
        "Fictional reviewed alternative placement.",
    );
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    const review = f.calls.filter((c) => c.path === f.adjustmentBase + "review/").at(-1);
    expect(review.body.definition).toEqual({
        kind: "placement",
        course_id: id(15),
        group_id: id(6),
        substitution_adjustment_id: id(20),
    });
    expect(review.csrf).toBe("fictional-test-token");
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await expect(studio.locator("b,script")).toHaveCount(0);
    await page.getByRole("button", { name: "Approve reviewed exception", exact: true }).click();
    const source = studio.locator(".audit-library-card").filter({
        has: page.getByRole("heading", { name: /^Course substitution/ }),
    });
    await source.getByRole("button", { name: "Review revocation", exact: true }).click();
    await page.getByLabel("Reason for revocation", { exact: true }).fill("Fictional substitution removed.");
    await page.getByRole("button", { name: "Prepare revocation", exact: true }).click();
    await page.getByRole("button", { name: "Revoke reviewed exception", exact: true }).click();
    await expect(studio).toContainText(
        "Course placement · SCI.201 · Fictional <b>Science</b> → General education",
    );
    await expect(studio).toContainText("substitution and repeat requirements still apply");
    await page.getByRole("button", { name: "Approve an exception", exact: true }).click();
    await page.getByRole("button", { name: "Course placement", exact: true }).click();
    await expect(page.getByRole("button", { name: choice, exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
