const { test, expect } = require("@playwright/test");
const { fixture, accessible, id } = require("./support/student-degree-fixture.cjs");
test("registrar chooses an exact replacement publication, keeps review fields and can revoke", async ({
    page,
}) => {
    const f = await fixture(page, { assigned: true });
    await page.getByRole("button", { name: "Student exceptions", exact: true }).click();
    await page.getByRole("button", { name: "Approve an exception", exact: true }).click();
    await page.getByRole("button", { name: "Course substitution", exact: true }).click();
    await page.getByRole("button", { name: "MATH.101 · Algebra", exact: true }).click();
    await page
        .getByLabel("Reason for student exception", { exact: true })
        .fill("Fictional equivalence approval");
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio").getByRole("alert")).toContainText(
        "explicit published replacement",
    );
    expect(f.calls.some((c) => c.path === f.adjustmentBase + "review/")).toBe(false);
    await page.getByLabel("Replacement course search", { exact: true }).fill("  SCI  ");
    await page
        .getByLabel("Replacement course search", { exact: true })
        .locator("xpath=ancestor::form")
        .getByRole("button", { name: "Search", exact: true })
        .click();
    await expect(page.getByLabel("Replacement course search", { exact: true })).toHaveValue("SCI");
    expect(f.calls.filter((c) => c.path === f.adjustmentBase + "replacement-courses/").at(-1).body).toEqual({
        search: "SCI",
    });
    await page.locator(".audit-source-card > summary").filter({ hasText: "SCI.202" }).click();
    await page.getByRole("button", { name: "Select replacement version 1", exact: true }).click();
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("SCI.201");
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("Fictional <b>Science</b>");
    await expect(page.locator("dialog.degree-audit-studio").locator("b")).toHaveCount(0);
    expect(f.calls.some((c) => c.path === f.adjustmentBase + "commit/")).toBe(false);
    await page.getByRole("button", { name: "Change review", exact: true }).click();
    await expect(page.getByLabel("Reason for student exception", { exact: true })).toHaveValue(
        "Fictional equivalence approval",
    );
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Approve reviewed exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "Course substitution · MATH.101 · Algebra → SCI.201",
    );
    expect(f.calls.find((c) => c.path === f.adjustmentBase + "review/").body.definition).toEqual({
        kind: "substitution",
        course_id: id(7),
        replacement_course_id: id(15),
        replacement_version_id: id(16),
    });
    await page.getByRole("button", { name: "Review revocation", exact: true }).click();
    await page
        .getByLabel("Reason for revocation", { exact: true })
        .fill("Fictional substitution restoration");
    await page.getByRole("button", { name: "Prepare revocation", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "restores the original course requirement",
    );
    await page.getByRole("button", { name: "Revoke reviewed exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("No active exceptions");
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
test("unpublished catalog entries cannot be selected as replacements", async ({ page }) => {
    const f = await fixture(page, { assigned: true, unpublishedReplacement: true });
    await page.getByRole("button", { name: "Student exceptions", exact: true }).click();
    await page.getByRole("button", { name: "Approve an exception", exact: true }).click();
    await page.getByRole("button", { name: "Course substitution", exact: true }).click();
    await page.getByRole("button", { name: "MATH.101 · Algebra", exact: true }).click();
    await page.locator(".audit-source-card > summary").filter({ hasText: "SCI.202" }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("No published versions");
    await expect(page.getByRole("button", { name: /Select replacement version/ })).toHaveCount(0);
    expect(f.calls.some((c) => c.path === f.adjustmentBase + "commit/")).toBe(false);
    expect(f.errors).toEqual([]);
    await accessible(page);
});

test("registrar accepts and revokes one publication without choosing another course", async ({page}) => {
    const f=await fixture(page,{assigned:true});
    await page.getByRole("button",{name:"Student exceptions",exact:true}).click();
    await page.getByRole("button",{name:"Approve an exception",exact:true}).click();
    await page.getByRole("button",{name:"Accept course publication",exact:true}).click();
    await page.getByRole("button",{name:"MATH.101 · Algebra",exact:true}).click();
    await page.getByRole("button",{name:"Accept publication version 2",exact:true}).click();
    await page.getByLabel("Reason for student exception",{exact:true}).fill("Fictional publication acceptance");
    await page.getByRole("button",{name:"Review exception",exact:true}).click();
    const dialog=page.locator("dialog.degree-audit-studio");
    await expect(dialog).toContainText("Other publication approvals remain separate");
    await expect(dialog).toContainText("Chosen course publication: version 2 · MATH.101 · Revised Algebra <b>fictional</b>");
    await expect(dialog.locator("b")).toHaveCount(0);
    await page.setViewportSize({width:375,height:812});await accessible(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.getByRole("button",{name:"Approve reviewed exception",exact:true}).click();
    await expect(dialog).toContainText("Course publication acceptance · MATH.101 · Algebra");
    expect(f.calls.find(c=>c.path===f.adjustmentBase+"review/").body.definition).toEqual({kind:"course_version",course_id:id(7),version_id:id(17)});
    await page.getByRole("button",{name:"Review revocation",exact:true}).click();
    await page.getByLabel("Reason for revocation",{exact:true}).fill("Fictional restore baseline");
    await page.getByRole("button",{name:"Prepare revocation",exact:true}).click();
    await expect(dialog).toContainText("removes only this student's additional publication allowance");
    await page.getByRole("button",{name:"Revoke reviewed exception",exact:true}).click();
    await expect(dialog).toContainText("No active exceptions");
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);
});
