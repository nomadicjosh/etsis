const { test, expect } = require("@playwright/test");
const { fixture, accessible } = require("./support/student-degree-fixture.cjs");
const id = (n) => String(n).padStart(26, "0");

test("course routing uses the shared approval flow with explicit course publication and receiving component", async ({
    page,
}) => {
    const f = await fixture(page, { assigned: true });
    await page.getByRole("button", { name: "Student exceptions", exact: true }).click();
    await page.getByRole("button", { name: "Course routing", exact: true }).click();
    await page.getByRole("button", { name: "Route a course", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await page
        .getByRole("button", {
            name: "Specialization DATA · Data <b>fictional</b> requirements",
            exact: true,
        })
        .click();
    await page
        .getByLabel("Reason for student exception", { exact: true })
        .fill("Fictional registrar course routing.");
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(studio.getByRole("alert")).toContainText("Choose an explicit published");
    await studio.locator("summary").filter({ hasText: "SCI.202" }).click();
    await page.getByRole("button", { name: "Select course version 1", exact: true }).click();
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(studio).toContainText("Only the chosen curriculum component");
    await expect(studio.locator("b, script")).toHaveCount(0);
    await page.getByRole("button", { name: "Change review", exact: true }).click();
    await expect(page.getByLabel("Reason for student exception", { exact: true })).toHaveValue(
        "Fictional registrar course routing.",
    );
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
    await page.getByRole("button", { name: "Approve reviewed exception", exact: true }).click();
    await expect(studio).toContainText("Course routing · SCI.201");
    await page.getByRole("button", { name: "Review revocation", exact: true }).click();
    await page
        .getByLabel("Reason for revocation", { exact: true })
        .fill("Fictional removal of routing restriction.");
    await page.getByRole("button", { name: "Prepare revocation", exact: true }).click();
    await expect(studio).toContainText("restores normal component choices");
    await page.getByRole("button", { name: "Revoke reviewed exception", exact: true }).click();
    await expect(studio).toContainText("No active exceptions");
    const review = f.calls.filter((c) => c.path === `${f.adjustmentBase}review/`)[0];
    expect(review.body.definition).toEqual({
        kind: "component_route",
        course_id: id(15),
        course_version_id: id(16),
        target_version_id: id(71),
    });
    expect(review.csrf).toBe("fictional-test-token");
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
});
