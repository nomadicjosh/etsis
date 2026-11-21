const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => String(n).padStart(26, "0");
async function setup(page, { stale = false, ready = true } = {}) {
    const section = id(1),
        student = id(2),
        operation = id(3);
    let recorded = false,
        attempt = 0;
    const calls = [],
        errors = [];
    const row = {
        student_id: student,
        current_name: "Alex Example",
        current_institutional_id: "FIC-100",
        course_code: "HIST 101",
        course_title: "Fictional History",
        status: "A",
        grade_code: "A",
        attempted_milli: 3000,
        completed_milli: 3000,
    };
    const metadata = {
        operation_id: operation,
        version: 1,
        recorded_at: "2026-11-21T10:00:00+00:00",
        student_count: 1,
        history_gaps: true,
        matches_current: false,
    };
    const readiness = {
        section: { code: "HIST 101.A", term_code: "26/FA" },
        ready,
        history_gaps: true,
        rows: [row],
        issues: ready ? [] : [{ student_id: student, status: "pending_grade_change" }],
        latest: null,
    };
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()["x-csrf-token"] });
        let result = {};
        if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/teaching/")
            result = {
                items: [
                    {
                        id: section,
                        code: "HIST 101.A",
                        course_code: "HIST 101",
                        course_title: "Fictional History",
                        term_code: "26/FA",
                        capacity: 20,
                    },
                ],
                next_after: null,
            };
        else if (path === `/sis/ui/context/roster/${section}/`)
            result = {
                can_grade: true,
                items: [
                    {
                        id: id(4),
                        student_id: student,
                        given_name: "Alex",
                        family_name: "Example",
                        institutional_id: "FIC-100",
                        status: "A",
                        grade_code: "A",
                    },
                ],
                next_after: null,
            };
        else if (path.includes("/assignments/")) result = { items: [], next_after: null };
        else if (path === `/sis/sections/${section}/grade-certification/`)
            result = { ...readiness, latest: recorded ? metadata : null };
        else if (path.endsWith("/grade-certification/review/"))
            result = {
                ...readiness,
                operation_id: operation,
                revision: "a".repeat(64),
                expected_version: 0,
                reason: data.reason,
                acknowledged_history_gaps: data.acknowledged_history_gaps,
                review_token: "fictional-encrypted-review",
            };
        else if (path.endsWith("/grade-certification/confirm/")) {
            attempt++;
            if (stale && attempt === 1) {
                await route.fulfill({
                    status: 409,
                    contentType: "application/json",
                    body: JSON.stringify({
                        error: "Class grades or roster changed. Refresh and review again.",
                    }),
                });
                return;
            }
            recorded = true;
            result = { status: "recorded", operation_id: operation, version: 1 };
        } else if (path.endsWith("/grade-certification/history/"))
            result = { items: recorded ? [metadata] : [], next_before: null };
        else if (path.endsWith(`/grade-certification/history/${operation}/`))
            result = {
                ...metadata,
                section: readiness.section,
                reason: "Fictional final review.",
                rows: [row],
            };
        else if (path.startsWith("/sis/ui/")) result = { items: [], next_after: null };
        await route.fulfill({
            contentType: "application/json",
            body: JSON.stringify(result),
            headers: { "Cache-Control": "private, no-store" },
        });
    });
    await page.goto("/admin/");
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await page.getByRole("button", { name: "HIST 101.A", exact: true }).click();
    await page.getByRole("button", { name: "Class grade certification", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "HIST 101.A · 26/FA", exact: true }),
    ).toBeVisible();
    return { calls, errors };
}
async function review(page) {
    await page.getByLabel("Certification reason", { exact: true }).fill("Fictional final review.");
    await page.getByRole("checkbox").check();
    await page.getByRole("button", { name: "Review certification", exact: true }).click();
    await expect(
        page.getByRole("heading", { name: "Review class grade certification", exact: true }),
    ).toBeVisible();
}
test("class readiness, reviewed certification, history and changed-source notice remain accessible", async ({
    page,
}) => {
    const { calls, errors } = await setup(page);
    await page.setViewportSize({ width: 320, height: 900 });
    await expect(
        page.getByText("Every covered student has a retained final grade.", { exact: true }),
    ).toBeVisible();
    await review(page);
    await expect(
        page.getByRole("button", { name: "Confirm certification", exact: true }),
    ).toBeFocused();
    await page.getByRole("button", { name: "Revise certification", exact: true }).click();
    await expect(page.getByLabel("Certification reason", { exact: true })).toHaveValue(
        "Fictional final review.",
    );
    await page.getByRole("button", { name: "Review certification", exact: true }).click();
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) }))).toEqual(
        [],
    );
    await page.getByRole("button", { name: "Confirm certification", exact: true }).click();
    await expect(
        page.getByText("Class grade certification recorded.", { exact: true }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Refresh readiness", exact: true }).click();
    await expect(page.getByText(/previous certification remains retained/)).toBeVisible();
    await page.getByRole("button", { name: "Certification history", exact: true }).click();
    await page.getByRole("button", { name: "Open retained roster", exact: true }).click();
    await expect(page.getByText("Alex Example", { exact: true })).toBeVisible();
    expect(calls.filter((c) => c.path.endsWith("/confirm/"))).toHaveLength(1);
    expect(
        calls
            .filter((c) => c.path.includes("/grade-certification/"))
            .every((c) => c.csrf === "fictional-test-token"),
    ).toBe(true);
    expect(errors).toEqual([]);
});
test("stale certification keeps the reason and requires a fresh review", async ({ page }) => {
    const { calls, errors } = await setup(page, { stale: true });
    await review(page);
    await page.getByRole("button", { name: "Confirm certification", exact: true }).click();
    await expect(
        page.getByRole("button", { name: "Confirm certification", exact: true }),
    ).toBeDisabled();
    await page
        .getByRole("button", { name: "Refresh readiness and keep reason", exact: true })
        .click();
    await expect(page.getByLabel("Certification reason", { exact: true })).toHaveValue(
        "Fictional final review.",
    );
    await page.getByRole("checkbox").check();
    await page.getByRole("button", { name: "Review certification", exact: true }).click();
    await page.getByRole("button", { name: "Confirm certification", exact: true }).click();
    await expect(
        page.getByText("Class grade certification recorded.", { exact: true }),
    ).toBeVisible();
    expect(calls.filter((c) => c.path.endsWith("/review/"))).toHaveLength(2);
    expect(errors).toEqual([]);
});
test("pending grade corrections block the class-certification action", async ({ page }) => {
    const { calls, errors } = await setup(page, { ready: false });
    await expect(
        page.getByRole("button", { name: "Review certification", exact: true }),
    ).toBeDisabled();
    await expect(page.getByText("Grade correction pending", { exact: true })).toBeVisible();
    expect(calls.filter((c) => c.path.includes("/grade-certification/review/"))).toHaveLength(0);
    expect(errors).toEqual([]);
});
