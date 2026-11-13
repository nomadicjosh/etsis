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

test("credit-list shortages explain their own minimum without inventing a partial placement", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        allocationOutcome: "infeasible",
        allocationCreditRule: true,
    });
    await page.getByRole("button", { name: "Review course placement", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await studio
        .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
        .click();
    await expect(studio).toContainText("Take 6 credits from this list");
    const requirement = studio
        .locator("details")
        .filter({ has: page.locator("summary").filter({ hasText: "Take 6 credits from this list" }) });
    await requirement.locator("summary").click();
    await expect(requirement).toContainText("At most 2 eligible credits toward this list's 6-credit minimum");
    await expect(requirement).toContainText("At least 4 more eligible credits from this list are needed");
    await expect(studio).toContainText("No speculative partial plan is shown");
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.calls.filter((c) => c.path.endsWith("/allocation/"))).toHaveLength(1);
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
        if (["needs_review", "unsupported"].includes(outcome)) {
            await expect(studio).toContainText("Shortfalls cannot be assessed");
            await expect(studio).not.toContainText("more eligible credits are needed");
            await expect(studio).not.toContainText("No eligible completed evidence");
        }
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

test("group shortages explain named and choose evidence without assigning missing courses or partial credit", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        allocationOutcome: "infeasible",
        allocationNamedRules: true,
    });
    await page.getByRole("button", { name: "Review course placement", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText("Current eligible work falls short");
    await studio
        .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
        .click();
    await expect(studio).toContainText("At most 3 credits toward the 63-credit minimum");
    await expect(studio).toContainText("At least 60 more eligible credits are needed");
    await expect(studio).toContainText("not applied credits or a partial placement");
    await studio.locator("summary").filter({ hasText: "All listed courses" }).click();
    const all = studio.locator("details").filter({ hasText: "All listed courses" });
    await expect(all).toContainText(
        "Required course contributions: 2 · Required courses with eligible evidence: 1",
    );
    await expect(all).toContainText("At least 1 more distinct eligible course contribution is needed");
    await expect(all).toContainText(
        "REQ.1 · Retained requirement <b>fictional</b> · Eligible completed evidence available",
    );
    await expect(all).toContainText(
        "REQ.2 · Retained requirement <b>fictional</b> · No eligible completed evidence",
    );
    await studio.locator("summary").filter({ hasText: "Choose 2 courses" }).click();
    const choose = studio.locator("details").filter({ hasText: "Choose 2 courses" });
    await expect(choose).toContainText("Required course contributions: 2");
    await expect(studio).not.toContainText("Supports requirement");
    await expect(studio.locator("b")).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await studio.getByRole("button", { name: "Back to course groups", exact: true }).click();
    expect(f.calls.filter((c) => c.path.endsWith("/allocation/"))).toHaveLength(1);
    expect(f.calls.some((c) => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("course-count shortages and unproved bounds remain distinct from a complete placement", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        allocationOutcome: "infeasible",
        allocationCourseCount: true,
    });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await studio.getByRole("button", { name: "Inspect course-group placement", exact: true }).click();
    await studio
        .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
        .click();
    await expect(studio).toContainText("At most 0 distinct course contributions toward the 4-course minimum");
    await expect(studio).toContainText("At least 4 more eligible course contributions are needed");
    await expect(studio).not.toContainText("more eligible credits are needed");
    await accessible(page);
    expect(f.calls.filter((c) => c.path.endsWith("/progress/"))).toHaveLength(1);
    expect(f.calls.filter((c) => c.path.endsWith("/allocation/"))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});

for (const courseCount of [false, true]) {
    test(`shared ${courseCount ? "course-count" : "credit"} shortages explain competing groups without partial placements`, async ({
        page,
    }) => {
        const f = await fixture(page, {
            assigned: true,
            allocationOutcome: "infeasible",
            allocationCompetition: true,
            allocationCourseCount: courseCount,
            competitionMore: true,
        });
        await page.getByRole("button", { name: "Review course placement", exact: true }).click();
        const studio = page.locator("dialog.degree-audit-studio");
        const panel = studio.getByRole("region", {
            name: "Groups competing for completed courses",
            exact: true,
        });
        await expect(panel).toContainText("General education <b>fictional</b>");
        await expect(panel).toContainText("Professional studies <script>fictional</script>");
        await expect(panel).toContainText(
            courseCount
                ? "combined shortfall is at least 1 course contribution"
                : "combined credit shortfall is at least 3 credits",
        );
        await expect(panel).toContainText("No partial placements have been assigned");
        await expect(panel).toContainText("Showing the first 10 explanations");
        await expect(panel.locator("script, b")).toHaveCount(0);
        await page.setViewportSize({ width: 375, height: 812 });
        await accessible(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await studio
            .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
            .click();
        await studio.getByRole("button", { name: "Back to course groups", exact: true }).click();
        await expect(panel).toBeVisible();
        expect(f.calls.filter((c) => c.path.endsWith("/allocation/"))).toHaveLength(1);
        expect(f.calls.some((c) => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
        expect(f.errors).toEqual([]);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });
}
for (const settings of [
    { allocationOutcome: "needs_review" },
    { allocationOutcome: "undetermined" },
    { allocationOutcome: "infeasible", shareCourses: true },
]) {
    test(`shared-shortage explanations are suppressed for ${settings.shareCourses ? "multiple-group sharing" : settings.allocationOutcome}`, async ({
        page,
    }) => {
        await fixture(page, { assigned: true, allocationCompetition: true, ...settings });
        await page.getByRole("button", { name: "Review course placement", exact: true }).click();
        await expect(
            page.getByRole("region", { name: "Groups competing for completed courses", exact: true }),
        ).toHaveCount(0);
    });
}

test("an approved substitute supports named and saved-rule cards with one course and its own labels", async ({page}) => {
    const f=await fixture(page,{assigned:true,readOnly:true,allocationRuleSubstitution:true});
    await page.getByRole("button",{name:"Review course placement",exact:true}).click();
    const studio=page.locator("dialog.degree-audit-studio");
    await studio.getByRole("button",{name:"Inspect group General education <b>fictional</b>",exact:true}).click();
    await expect(studio).toContainText("3 actual earned credits · One distinct course");
    await expect(studio).toContainText("Approved alternative for REQ.0");
    const named=studio.locator("details").filter({has:page.locator("summary").filter({hasText:"All listed courses"})});
    await named.locator("summary").click();
    await expect(named).toContainText("REQ.0 · Retained requirement <b>fictional</b> · Proposed placement");
    const rule=studio.locator("details").filter({has:page.locator("summary").filter({hasText:"Take 3 matching credits"})});
    await rule.locator("summary").click();
    await expect(rule).toContainText("SCI.201 · Accepted Science <b>fictional</b> · Proposed placement");
    await expect(rule).not.toContainText("REQ.0");
    await studio.getByText("Requirement contributions",{exact:true}).click();
    await expect(studio).toContainText("All listed courses · REQ.0: 3 eligible credits · One distinct course");
    await expect(studio).toContainText("DA.SCI · Revision 1: 3 eligible credits · One distinct course");
    await expect(studio.locator("b")).toHaveCount(0);
    await page.setViewportSize({width:375,height:812});
    await accessible(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    expect(f.calls.filter(c=>c.path.endsWith("/allocation/"))).toHaveLength(1);
    expect(f.calls.some(c=>/\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});


test("a complete plan explains each card without adding overlapping contributions to the group total", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        allocationRuleSubstitution: true,
        allocationCoverage: true,
    });
    await page.getByRole("button", { name: "Review course placement", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText("3 applied credits · 1 distinct course in this proposed plan");
    await page
        .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
        .click();
    await expect(studio).toContainText("Group quantity met in this proposed plan: 3 applied credits");
    await expect(studio).toContainText("Met in proposed plan · 1 of 1 required courses covered");
    await expect(studio).toContainText("Met in proposed plan · 3 of 3 required credits applied");
    await expect(studio).not.toContainText("6 applied credits");
    await studio.locator("summary").filter({ hasText: "Take 3 matching credits" }).click();
    await expect(studio).toContainText("Applied to this card: 3 credits · 1 distinct course");
    await expect(studio).not.toContainText("At most 3 eligible credits");
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.calls.filter((c) => c.path.endsWith("/allocation/"))).toHaveLength(1);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test("unestablished plans never display supplied coverage as applied work", async ({ page }) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        allocationOutcome: "undetermined",
        allocationRuleSubstitution: true,
        allocationCoverage: true,
    });
    await page.getByRole("button", { name: "Review course placement", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await page
        .getByRole("button", { name: "Inspect group General education <b>fictional</b>", exact: true })
        .click();
    await expect(studio).toContainText("No complete placement was established");
    await expect(studio).not.toContainText("Group quantity met");
    await expect(studio).not.toContainText("Met in proposed plan");
    expect(f.errors).toEqual([]);
});
