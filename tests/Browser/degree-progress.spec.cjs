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

test("unused and pending work filters keep placement, totals and posting terms separate without rereading", async ({
    page,
}) => {
    const f = await fixture(page, { assigned: true, readOnly: true, progressWorkMix: true });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText("1 institutional records awaiting final grades · 3 attempted credits");
    await expect(studio).toContainText("1 completed records are unused in this proposed group placement");
    await expect(studio).toContainText(
        "not earned credits, completed requirements or a current-term enrollment-load calculation",
    );
    await studio.getByRole("button", { name: "Review unused and pending work", exact: true }).click();
    await studio.getByRole("button", { name: "Unused completed (1)", exact: true }).focus();
    await page.keyboard.press("Enter");
    await expect(studio.getByRole("button", { name: "Unused completed (1)", exact: true })).toBeFocused();
    await expect(studio).toContainText("LAST.101");
    await expect(studio).toContainText("Earned credit: Included once in this total");
    await expect(studio.getByRole("article")).toHaveCount(1);
    await expect(studio).toContainText("Retained posting term: 27/SP");
    await studio.getByRole("button", { name: "Awaiting grades (1)", exact: true }).click();
    await expect(studio.getByRole("article")).toHaveCount(1);
    await expect(studio.getByRole("article")).toContainText("Awaiting a final grade");
    await expect(studio.getByRole("article")).not.toContainText("Unused in this proposed");
    await studio.getByRole("button", { name: "Other outcomes (1)", exact: true }).click();
    await expect(studio.getByRole("article")).toContainText("Retained grade evidence needs review");
    await studio.getByRole("button", { name: "Placed or alternatives (18)", exact: true }).click();
    await expect(studio).toContainText("Equivalent evidence alternative; no single award chosen");
    await expect(studio.locator("b")).toHaveCount(0);
    await studio.getByRole("button", { name: "All work (21)", exact: true }).click();
    await studio.getByRole("button", { name: "Next contribution records", exact: true }).click();
    await expect(studio).toContainText("Showing 21–21 of 21 records");
    await studio.getByLabel("Posting term", { exact: true }).selectOption({ label: "27/SP" });
    await expect(studio.getByLabel("Posting term", { exact: true })).toBeFocused();
    await expect(studio).toContainText("Showing 1–1 of 1 records");
    await expect(studio.getByRole("button", { name: "Awaiting grades (0)", exact: true })).toBeVisible();
    await studio.getByRole("button", { name: "Awaiting grades (0)", exact: true }).click();
    await expect(studio).toContainText(
        "No records match this view. This does not establish degree completion",
    );
    await expect(studio.getByRole("article")).toHaveCount(0);
    await studio.getByLabel("Posting term", { exact: true }).selectOption("all");
    await expect(studio.getByRole("button", { name: "Awaiting grades (1)", exact: true })).toHaveAttribute(
        "aria-pressed",
        "true",
    );
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await studio.getByRole("button", { name: "Back to degree progress", exact: true }).click();
    expect(f.calls.filter((c) => c.path === `${f.base}progress/`)).toHaveLength(1);
    expect(f.calls.some((c) => c.path.includes("/academic-work/"))).toBe(false);
    expect(f.calls.some((c) => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

for (const outcome of ["infeasible", "undetermined", "needs_review", "unsupported"]) {
    test(`a ${outcome} placement cannot classify completed work as unused`, async ({ page }) => {
        const f = await fixture(page, { assigned: true, progressWorkMix: true, allocationOutcome: outcome });
        await page.getByRole("button", { name: "Degree progress", exact: true }).click();
        const studio = page.locator("dialog.degree-audit-studio");
        await expect(studio).toContainText(
            "Unused courses cannot be identified until a complete group placement is established",
        );
        await studio.getByRole("button", { name: "Review unused and pending work", exact: true }).click();
        await expect(
            studio.getByRole("button", { name: "Unused completed (0)", exact: true }),
        ).toBeDisabled();
        await expect(
            studio.getByRole("button", { name: "Placed or alternatives (0)", exact: true }),
        ).toBeDisabled();
        await studio.getByRole("button", { name: "Awaiting grades (1)", exact: true }).click();
        await expect(studio.getByRole("article")).toContainText("Course-group placement not established");
        await expect(studio.getByRole("article")).not.toContainText("Unused in this proposed");
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}

test("pending numeric limits and missing retained term labels never display invented totals or labels", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        progressWorkMix: true,
        progressPendingLimit: true,
        progressUnknownTerm: true,
    });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(studio).toContainText(
        "1 institutional records awaiting final grades · Credit total unavailable within the supported numeric limit",
    );
    await studio.getByRole("button", { name: "Review unused and pending work", exact: true }).click();
    await studio.getByLabel("Posting term", { exact: true }).selectOption({ label: "Label not retained" });
    await expect(studio.getByRole("article")).toContainText("Posting term label not retained");
    await expect(studio).not.toContainText("27/SP");
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test("combined degree progress reuses the same shared-course explanation when opening group placement", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        allocationCompetition: true,
        allocationOutcome: "infeasible",
    });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await expect(
        studio.getByRole("region", { name: "Groups competing for completed courses", exact: true }),
    ).toContainText("combined credit shortfall is at least 3 credits");
    await studio.getByRole("button", { name: "Inspect course-group placement", exact: true }).click();
    await expect(
        studio.getByRole("region", { name: "Groups competing for completed courses", exact: true }),
    ).toContainText("Professional studies <script>fictional</script>");
    expect(f.calls.filter((c) => c.path.endsWith("/progress/"))).toHaveLength(1);
    expect(f.calls.some((c) => c.path.endsWith("/allocation/"))).toBe(false);
    expect(f.errors).toEqual([]);
});
