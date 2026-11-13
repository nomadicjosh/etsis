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
    await studio.getByRole("button", { name: "Back to degree progress", exact: true }).click();
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


test('local next steps explain gaps, shortfalls and pending work without new requests or decisions', async ({ page }) => {
    const f = await fixture(page, { assigned: true, changed: true, progressHistoryGaps: true, progressWorkMix: true, progressUnknownOverall: true });
    await page.getByRole('button', { name: 'Degree progress', exact: true }).click();
    const before = f.calls.length;
    await page.getByText('Next steps for this review', { exact: true }).click();
    const guide = page.locator('[data-progress-next-steps]');
    await expect(guide).toContainText('No AI provider is used');
    await expect(guide).toContainText('Verify earlier history');
    await expect(guide).toContainText('Confirm the selected requirements');
    await expect(guide).toContainText('Resolve unavailable minimum checks');
    await expect(guide).toContainText('Review pending grades');
    await expect(guide).toContainText('Inspect unused completed work');
    await expect(guide).toContainText('not a graduation decision');
    expect(f.calls.length).toBe(before);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.calls.filter(c => c.path === `${f.base}progress/`)).toHaveLength(1);
    expect(f.calls.some(c => /\/(assign|commit|review)\/$/.test(c.path))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('local next steps keep a work-limit result separate from academic shortfalls', async ({ page }) => {
    const f = await fixture(page, { assigned: true, progressLimit: true });
    await page.getByRole('button', { name: 'Degree progress', exact: true }).click();
    await page.getByText('Next steps for this review', { exact: true }).click();
    const guide = page.locator('[data-progress-next-steps]');
    await expect(guide).toContainText('Arrange a controlled record review');
    await expect(guide).not.toContainText('Review minimum shortfalls');
    await expect(guide).not.toContainText('Inspect unused completed work');
    expect(f.calls.filter(c => c.path === `${f.base}progress/`)).toHaveLength(1);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test("local progress guide docks on either side with keyboard focus and one shared next-step list", async ({ page }) => {
    const f = await fixture(page, { assigned: true, progressHistoryGaps: true, changed: true });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    const before = f.calls.length;
    const launch = studio.getByRole("button", { name: "Open degree progress guide", exact: true });
    await launch.focus(); await page.keyboard.press("Enter");
    const guide = studio.getByRole("complementary", { name: "Degree progress guide", exact: true });
    await expect(guide.getByRole("heading", { name: "Degree progress guide", exact: true })).toBeFocused();
    await expect(launch).toHaveAttribute("aria-expanded", "true");
    await expect(guide).toContainText("Nothing is sent to an AI provider");
    await expect(guide).toContainText("Verify earlier history");
    await expect(studio.locator("[data-progress-next-steps]")).toHaveCount(1);
    await expect(guide.getByRole("button", { name: "What should I review next?", exact: true })).toHaveAttribute("aria-pressed", "true");
    await guide.getByRole("button", { name: "Move guide left", exact: true }).click();
    await expect(studio.locator(".progress-guide-layout > :first-child")).toHaveAttribute("data-progress-guide", "");
    await expect(guide.getByRole("button", { name: "Move guide right", exact: true })).toBeFocused();
    await guide.getByRole("button", { name: "Move guide right", exact: true }).click();
    await expect(studio.locator(".progress-guide-layout > :last-child")).toHaveAttribute("data-progress-guide", "");
    await accessible(page);
    await page.keyboard.press("Escape");
    await expect(guide).toHaveCount(0);
    await expect(studio).toBeVisible();
    await expect(launch).toBeFocused();
    await expect(launch).toHaveAttribute("aria-expanded", "false");
    await expect(studio.locator(".progress-guide-inline [data-progress-next-steps]")).toHaveCount(1);
    expect(f.calls.length).toBe(before);
    expect(f.errors).toEqual([]);
});

test("guide topics and drilldowns reuse the captured review and preserve their place", async ({ page }) => {
    const f = await fixture(page, { assigned: true, progressUnknownOverall: true });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    const before = f.calls.length;
    await studio.getByRole("button", { name: "Open degree progress guide", exact: true }).click();
    let guide = studio.getByRole("complementary", { name: "Degree progress guide", exact: true });
    await guide.getByRole("button", { name: "Move guide left", exact: true }).click();
    await guide.getByRole("button", { name: "Understand credits and GPA", exact: true }).click();
    await expect(guide).toContainText("must not be treated as zero");
    await guide.getByRole("button", { name: "Show minimum cards", exact: true }).click();
    await expect(studio.getByRole("heading", { name: "General degree minimums", exact: true })).toBeFocused();
    await guide.getByRole("button", { name: "Open credit and GPA detail", exact: true }).click();
    await expect(studio).toContainText("Showing 1–20 of 21 records");
    await studio.getByRole("button", { name: "Back to degree progress", exact: true }).click();
    guide = studio.getByRole("complementary", { name: "Degree progress guide", exact: true });
    await expect(guide.getByRole("heading", { name: "Degree progress guide", exact: true })).toBeFocused();
    await expect(guide.getByRole("button", { name: "Understand credits and GPA", exact: true })).toHaveAttribute("aria-pressed", "true");
    await expect(studio.locator(".progress-guide-layout")).toHaveAttribute("data-guide-side", "left");
    await expect(studio.locator(".progress-guide-inline")).toBeEmpty();
    await guide.getByRole("button", { name: "Understand course placement", exact: true }).click();
    await guide.getByRole("button", { name: "Open course-group detail", exact: true }).click();
    await expect(studio.getByRole("heading", { name: "Course-group placement", exact: true })).toBeVisible();
    await studio.getByRole("button", { name: "Back to degree progress", exact: true }).click();
    await guide.getByRole("button", { name: "Understand unused and pending work", exact: true }).click();
    await guide.getByRole("button", { name: "Open unused and pending work", exact: true }).click();
    await expect(studio).toContainText("Showing 1–20 of 21 records");
    await studio.getByRole("button", { name: "Back to degree progress", exact: true }).click();
    await guide.getByRole("button", { name: "What can this review confirm?", exact: true }).click();
    await expect(guide).toContainText("not a graduation decision");
    expect(f.calls.length).toBe(before);
    expect(f.calls.filter(c => c.path === `${f.base}progress/`)).toHaveLength(1);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test("limited progress guide offers no partial academic comparisons or extra reads", async ({ page }) => {
    const f = await fixture(page, { assigned: true, progressLimit: true });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const before = f.calls.length;
    await page.getByRole("button", { name: "Open degree progress guide", exact: true }).click();
    const guide = page.getByRole("complementary", { name: "Degree progress guide", exact: true });
    await expect(guide).toContainText("Arrange a controlled record review");
    await expect(guide.getByRole("button", { name: "Understand credits and GPA", exact: true })).toHaveCount(0);
    await expect(guide.getByRole("button", { name: "Understand course placement", exact: true })).toHaveCount(0);
    await guide.getByRole("button", { name: "What can this review confirm?", exact: true }).click();
    await expect(guide).toContainText("not ready to provide completion comparisons");
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.calls.length).toBe(before);
    expect(f.errors).toEqual([]);
});

test("guide reflows on narrow screens, preserves inline guidance and clears on refresh or close", async ({ page }) => {
    const f = await fixture(page, { assigned: true });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    const studio = page.locator("dialog.degree-audit-studio");
    await page.getByText("Next steps for this review", { exact: true }).click();
    await studio.getByRole("button", { name: "Open degree progress guide", exact: true }).click();
    const guide = studio.getByRole("complementary", { name: "Degree progress guide", exact: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await guide.getByRole("button", { name: "Move guide left", exact: true }).click();
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await guide.getByRole("button", { name: "Close progress guide", exact: true }).click();
    await expect(studio.locator(".progress-guide-inline details")).toHaveAttribute("open", "");
    await studio.getByRole("button", { name: "Open degree progress guide", exact: true }).click();
    await studio.getByRole("button", { name: "Refresh degree progress", exact: true }).click();
    await expect(guide).toHaveCount(0);
    await expect(studio.getByRole("button", { name: "Open degree progress guide", exact: true })).toHaveAttribute("aria-expanded", "false");
    expect(f.calls.filter(c => c.path === `${f.base}progress/`)).toHaveLength(2);
    await studio.getByRole("button", { name: "Open degree progress guide", exact: true }).click();
    await studio.getByRole("button", { name: "Close builder", exact: true }).click();
    await expect(page.locator("[data-progress-guide]")).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});


test("contribution records distinguish captured grades, pending work and unavailable definitions", async ({
    page,
}) => {
    const f = await fixture(page, {
        assigned: true,
        readOnly: true,
        allocationEvidence: "ties",
        progressWorkMix: true,
    });
    await page.getByRole("button", { name: "Degree progress", exact: true }).click();
    await page.getByRole("button", { name: "Inspect credit and GPA contributions", exact: true }).click();
    const unknown = minimumCard(page, "MATH.18 · Retained work <b>fictional</b>");
    const pending = minimumCard(page, "MATH.19 · Retained work <b>fictional</b>");
    await expect(unknown).toContainText("Grade evidence unavailable");
    await expect(unknown).not.toContainText("Awaiting a final grade");
    await expect(pending).toContainText("Awaiting a final grade");
    await expect(pending).not.toContainText("Grade evidence unavailable");
    await expect(minimumCard(page, "MATH.0 · Retained work <b>fictional</b>")).toContainText(
        "Recorded grade: A · 4 grade points",
    );
    expect(f.calls.filter((c) => c.path === `${f.base}progress/`)).toHaveLength(1);
    expect(f.errors).toEqual([]);
});
