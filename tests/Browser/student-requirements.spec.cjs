const { test, expect } = require("@playwright/test");
const { fixture, accessible, id } = require("./support/student-degree-fixture.cjs");

test("registrar explicitly assigns a cohort publication with preserved review fields and no automatic assignment", async ({
    page,
}) => {
    const f = await fixture(page, { paging: true, requirementSchema: 4 });
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "No published requirements have been assigned",
    );
    expect(f.calls.some((c) => c.path.endsWith("/assign/"))).toBe(false);
    await page.getByRole("button", { name: "Assign published requirements", exact: true }).click();
    await page.getByRole("radio", { name: /Version 1 · B.S. Computing/ }).check();
    await page.getByLabel("Program pursuit start date", { exact: true }).fill("2026-08-01");
    await page
        .getByLabel("Reason for requirement assignment", { exact: true })
        .fill("Fictional registrar approval");
    await page
        .locator("dialog.degree-audit-studio")
        .getByRole("button", { name: "Next page", exact: true })
        .click();
    await expect(page.getByLabel("Program pursuit start date", { exact: true })).toHaveValue("2026-08-01");
    await page
        .locator("dialog.degree-audit-studio")
        .getByRole("button", { name: "Previous page", exact: true })
        .click();
    await expect(page.getByRole("radio", { name: /Version 1 · B.S. Computing/ })).toBeChecked();
    await page.getByRole("button", { name: "Review requirement assignment", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "Anticipated completion: 2030-08-01",
    );
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("MATH.101 · Algebra");
    expect(f.calls.some((c) => c.path.endsWith("/assign/"))).toBe(false);
    await page.getByRole("button", { name: "Change selection", exact: true }).click();
    await expect(page.getByLabel("Reason for requirement assignment", { exact: true })).toHaveValue(
        "Fictional registrar approval",
    );
    await page.getByRole("button", { name: "Review requirement assignment", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Assign reviewed requirements", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("Pursuit began 2026-08-01");
    await expect(page.locator("dialog.degree-audit-studio").locator("b")).toHaveCount(0);
    expect(f.calls.find((c) => c.path === `${f.base}review/`)).toMatchObject({
        body: {
            template_id: f.template,
            requirements_version_id: f.version,
            pursuit_started_on: "2026-08-01",
            reason: "Fictional registrar approval",
        },
        csrf: "fictional-test-token",
    });
    expect(f.calls.find((c) => c.path === `${f.base}assign/`)).toMatchObject({
        body: { review_token: "fictional-assignment-review" },
        csrf: "fictional-test-token",
    });
    await page.getByRole("button", { name: "Assignment history", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("Decision 1");
    await accessible(page);
    await page.keyboard.press("Escape");
    await expect(page.locator("dialog.degree-audit-studio")).toHaveCount(0);
    expect(
        await page.evaluate(
            () =>
                document.activeElement !== document.body &&
                document.activeElement.getClientRects().length > 0,
        ),
    ).toBe(true);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
test("missing publications and rejected reviews remain clear and preserve entered fields", async ({
    page,
}) => {
    const f = await fixture(page, { reject: true });
    await page.getByRole("button", { name: "Assign published requirements", exact: true }).click();
    await page.getByRole("button", { name: "Review requirement assignment", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio").getByRole("alert")).toContainText(
        "Choose a published version",
    );
    await page.getByRole("radio", { name: /Version 1 · B.S. Computing/ }).check();
    await page.getByLabel("Program pursuit start date", { exact: true }).fill("2026-08-01");
    await page.getByLabel("Reason for requirement assignment", { exact: true }).fill("Fictional reason");
    await page.getByRole("button", { name: "Review requirement assignment", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio").getByRole("alert")).toContainText(
        "cohort changed",
    );
    await expect(page.getByLabel("Reason for requirement assignment", { exact: true })).toHaveValue(
        "Fictional reason",
    );
    expect(f.calls.some((c) => c.path.endsWith("/assign/"))).toBe(false);
    expect(f.errors).toEqual([]);
});
test("cohort corrections keep the selected requirements visible with a review notice", async ({ page }) => {
    const f = await fixture(page, { assigned: true, changed: true });
    await expect(page.locator("dialog.degree-audit-studio").getByRole("status")).toContainText(
        "matriculation cohort changed",
    );
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("MATH.101 · Algebra");
    await accessible(page);
    expect(f.calls.some((c) => c.path.endsWith("/assign/"))).toBe(false);
    await page.getByRole("button", { name: "Back to student", exact: true }).click();
    await expect(page.locator("#record-drawer")).toContainText("Alex Example");
    expect(f.errors).toEqual([]);
});

test("student readers inspect assigned requirements without catalog or assignment controls", async ({
    page,
}) => {
    const f = await fixture(page, { assigned: true, readOnly: true });
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("MATH.101 · Algebra");
    await expect(
        page.getByRole("button", { name: "Assign published requirements", exact: true }),
    ).toHaveCount(0);
    await page.getByRole("button", { name: "Assignment history", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("Decision 1");
    expect(f.calls.some((c) => c.path.endsWith("/choices/") || c.path.endsWith("/assign/"))).toBe(false);
    await accessible(page);
    expect(f.errors).toEqual([]);
});
test("unpublished cohort requirements show a useful empty state", async ({ page }) => {
    const f = await fixture(page, { noPublications: true });
    await page.getByRole("button", { name: "Assign published requirements", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "No published requirements for this matriculation cohort",
    );
    await expect(page.getByRole("radio")).toHaveCount(0);
    expect(f.calls.some((c) => c.path.endsWith("/review/") || c.path.endsWith("/assign/"))).toBe(false);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test("registrar approves and revokes a program-wide course waiver without a group selector", async ({
    page,
}) => {
    const f = await fixture(page, { assigned: true });
    await page.getByRole("button", { name: "Student exceptions", exact: true }).click();
    await page.getByRole("button", { name: "Approve an exception", exact: true }).click();
    await page.getByRole("button", { name: "MATH.101 · Algebra", exact: true }).click();
    await page
        .getByLabel("Reason for student exception", { exact: true })
        .fill("Fictional <b>course waiver</b>");
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("throughout this program");
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("General education");
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("Required credits: 120");
    expect(f.calls.some((c) => c.path === f.adjustmentBase + "commit/")).toBe(false);
    await page.getByRole("button", { name: "Change review", exact: true }).click();
    await expect(page.getByLabel("Reason for student exception", { exact: true })).toHaveValue(
        "Fictional <b>course waiver</b>",
    );
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole("button", { name: "Approve reviewed exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "Course waiver · MATH.101 · Algebra",
    );
    await expect(page.locator("dialog.degree-audit-studio").locator("b")).toHaveCount(0);
    const review = f.calls.find((c) => c.path === f.adjustmentBase + "review/");
    expect(review.body.definition).toEqual({ kind: "waiver", course_id: id(7) });
    expect(review.csrf).toBe("fictional-test-token");
    await page.getByRole("button", { name: "Review revocation", exact: true }).click();
    await page.getByLabel("Reason for revocation", { exact: true }).fill("Fictional restored requirement");
    await page.getByRole("button", { name: "Prepare revocation", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("restores the course requirement");
    await page.getByRole("button", { name: "Revoke reviewed exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("No active exceptions");
    await page.getByRole("button", { name: "Exception history", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("Decision 1 · Approved");
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("Decision 2 · Revoked");
    await accessible(page);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});
test("minimum exceptions show explicit new values and preserve the published baseline", async ({ page }) => {
    const f = await fixture(page, { assigned: true });
    await page.getByRole("button", { name: "Student exceptions", exact: true }).click();
    await page.getByRole("button", { name: "Approve an exception", exact: true }).click();
    await page.getByRole("button", { name: "Credit or GPA minimum", exact: true }).click();
    await page.getByRole("button", { name: "Degree · Required credits · 120", exact: true }).click();
    await page.getByLabel("New required minimum", { exact: true }).fill("108");
    await page.getByLabel("Reason for student exception", { exact: true }).fill("Fictional minimum approval");
    await page.getByRole("button", { name: "Review exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("120 → 108");
    await page.getByRole("button", { name: "Approve reviewed exception", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("Required credits: 108");
    expect(f.calls.find((c) => c.path === f.adjustmentBase + "review/").body.definition).toEqual({
        kind: "minimum",
        group_id: null,
        minimum: "min_credits_milli",
        required_milli: 108000,
    });
    await page.getByRole("button", { name: "Back to degree requirements", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText("120 total credits");
    expect(f.errors).toEqual([]);
});
test("student readers see their course waiver without approval or revocation controls", async ({ page }) => {
    const f = await fixture(page, { assigned: true, readOnly: true, activeWaiver: true });
    await page.getByRole("button", { name: "Student exceptions", exact: true }).click();
    await expect(page.locator("dialog.degree-audit-studio")).toContainText(
        "Course waiver · MATH.101 · Algebra",
    );
    await expect(page.getByRole("button", { name: "Approve an exception", exact: true })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Review revocation", exact: true })).toHaveCount(0);
    expect(f.calls.some((c) => c.path === f.adjustmentBase + "commit/")).toBe(false);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('a mismatched program revision is explained before selection', async ({ page }) => {
    const f = await fixture(page, { catalogMismatch: true, requirementSchema: 4 });
    await page.getByRole('button', { name: 'Assign published requirements', exact: true }).click();
    await expect(page.getByRole('radio', { name: /Version 1 · B.S. Computing/ })).toBeDisabled();
    await expect(page.locator('dialog.degree-audit-studio')).toContainText('different program catalog revision');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.calls.some(c => c.path.endsWith('/assign/'))).toBe(false);
});

test('retained program details display their original labels as literal text', async ({ page }) => {
    await fixture(page, { assigned: true, programPublication: {
        version_id: id(20), catalog_revision: 1, academic_year_code: '2026-2027',
        definition: { code: 'BSCS', title: 'Original Computing', description: 'Original program description',
            classifications: { department: null, academic_level: null,
                award: { code: 'BS', title: 'Bachelor <script>fictional</script>' } } },
    } });
    await page.getByText('Retained program · BSCS — Original Computing', { exact: true }).click();
    await expect(page.locator('dialog.degree-audit-studio')).toContainText('Bachelor <script>fictional</script>');
    await expect(page.locator('dialog.degree-audit-studio script')).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
});
