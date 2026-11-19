const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => `01K000000000000000000000${String(n).padStart(2, "0")}`;
async function accessible(page) {
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        scan.violations.map((item) => ({ id: item.id, targets: item.nodes.map((node) => node.target) })),
    ).toEqual([]);
}

for (const academicCheck of ["verified", "history_gaps", "unavailable"])
    test(`transcript fulfillment retains documents with ${academicCheck} current history`, async ({
        page,
    }) => {
        const request = id(1),
            student = id(2),
            document = id(3),
            operation = id(4);
        const calls = [],
            errors = [];
        let status = "pending",
            saved = false,
            evidence = null;
        const snapshot = {
            student: { name: "Alex Example", institutional_id: "S-FICTIONAL" },
            program: { code: "BS.CS", title: "Computer Science" },
            issuer: { name: "Fictional University", address: "Fictional campus" },
            identity_verification: { earlier_history_unknown: academicCheck === "history_gaps" },
            program_verification: { status: "closed", labels_retained: true, definition_recorded: true },
            academic_verification: { status: academicCheck === "history_gaps" ? "history_gaps" : "verified" },
            credits: [
                {
                    history_recorded: true,
                    completed_on: "2026-05-12",
                    academic_on: "2026-01-12",
                    earlier_history_unknown: academicCheck === "history_gaps",
                    term_code: "26/FA",
                    course_code: "MATH.101",
                    course_title: "Algebra <script>example</script>",
                    attempted_milli: 3000,
                    completed_milli: 3000,
                    grade_code: "A",
                },
            ],
            totals: { attempted_milli: 3000, completed_milli: 3000, gpa_milli: 4000 },
        };
        const metadata = {
            id: document,
            created_at: "2026-11-07T12:00:00Z",
            prepared_version: 1,
            pdf_hash: "a".repeat(64),
        };
        page.on("pageerror", (error) => errors.push(error.message));
        const handler = async (route) => {
            const path = new URL(route.request().url()).pathname,
                raw = route.request().postData();
            const data = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
            if (data) calls.push({ path, data, csrf: route.request().headers()["x-csrf-token"] });
            if (path.endsWith("/pdf/")) {
                await route.fulfill({
                    body: "%PDF-1.4 Fictional PDF fixture",
                    contentType: "application/pdf",
                    headers: { "Cache-Control": "private, no-store" },
                });
                return;
            }
            let result = {};
            if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
            else if (path.startsWith("/sis/ui/data/"))
                result = {
                    items: path.includes("/transcripts/")
                        ? [
                              {
                                  id: request,
                                  student_id: student,
                                  delivery: "pickup",
                                  status,
                                  version: saved ? 1 : 0,
                              },
                          ]
                        : [],
                    next_after: null,
                };
            else if (path === `/portal/api/transcripts/${request}/`)
                result = {
                    id: request,
                    student_id: student,
                    delivery: "pickup",
                    recipient: "",
                    status,
                    version: saved ? 1 : 0,
                };
            else if (path.endsWith("/fulfillment/"))
                result = { status, version: saved ? 1 : 0, document: saved ? metadata : null };
            else if (path.endsWith("/review/"))
                result = {
                    snapshot,
                    operation_id: document,
                    revision: "f".repeat(64),
                    delivery: "pickup",
                    request_version: 0,
                    review_token: "fictional-preparation-proof",
                };
            else if (path.endsWith("/prepare/")) {
                saved = true;
                status = "ready";
                result = metadata;
            } else if (path === "/sis/ui/operation-id/") result = { id: operation };
            else if (path.endsWith("/delivery-review/"))
                result = {
                    operation_id: operation,
                    review_token: "fictional-delivery-proof",
                    delivery: data,
                };
            else if (path.endsWith("/deliver/")) {
                status = "fulfilled";
                evidence = { evidence: data.evidence, delivered_at: data.delivered_at };
                result = { status };
            } else if (path === `/sis/transcript-requests/${request}/documents/${document}/`)
                result = {
                    ...metadata,
                    snapshot,
                    is_latest: true,
                    academic_check: academicCheck,
                    academic_changed: academicCheck === "unavailable" ? null : false,
                    delivery: evidence,
                };
            await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
        };
        await page.route("**/sis/**", handler);
        await page.route("**/portal/api/transcripts/**", handler);
        await page.goto("/admin/");
        await page.locator('.workspace-nav-link[data-workspace="transcripts"]').click();
        await page.locator("#directory-results tbody button").first().click();
        await page.getByRole("button", { name: "Review preparation", exact: true }).click();
        await expect(page.getByRole("button", { name: "Prepare transcript", exact: true })).toBeDisabled();
        await expect(page.getByText("GPA", { exact: true }).locator("..").locator("dd")).toHaveText("4.000");
        await expect(page.locator("#drawer-body script")).toHaveCount(0);
        expect(calls.some((call) => call.path.endsWith("/prepare/"))).toBe(false);
        await page.setViewportSize({ width: 375, height: 812 });
        await accessible(page);
        await page
            .getByLabel("I reviewed the academic record and institutional release requirements")
            .check();
        await page.getByRole("button", { name: "Prepare transcript", exact: true }).click();
        await expect(page.locator("#drawer-body")).toContainText("Prepared at");
        const downloaded = page.waitForEvent("download");
        await page.getByRole("button", { name: "Download protected PDF", exact: true }).click();
        expect((await downloaded).suggestedFilename()).toBe(`transcript-${document}.pdf`);
        expect(status).toBe("ready");
        expect(calls.some((call) => call.path.endsWith("/deliver/"))).toBe(false);
        await page.locator("#drawer-body summary").filter({ hasText: "Dates & history" }).click();
        await expect(page.locator("#drawer-body")).toContainText("2026-05-12");
        await expect(page.locator("#drawer-body")).toContainText("Closed at preparation");
        await expect(page.locator("#drawer-body")).toContainText("Retained cohort publication");
        if (academicCheck === "history_gaps")
            await expect(page.locator("#drawer-body")).toContainText(
                "Current-state observations are identified",
            );
        if (academicCheck === "unavailable") {
            await expect(page.locator("#drawer-body")).toContainText(
                "Current identity, program or academic history could not be verified",
            );
            await expect(page.getByRole("button", { name: "Record delivery", exact: true })).toHaveCount(0);
            await accessible(page);
            expect(
                calls.some(
                    (call) => call.path.endsWith("/delivery-review/") || call.path.endsWith("/deliver/"),
                ),
            ).toBe(false);
            expect(errors).toEqual([]);
            return;
        }
        await page.getByRole("button", { name: "Record delivery", exact: true }).click();
        await page.getByLabel("Actual delivery time").fill("2026-11-07T12:00");
        await page
            .getByLabel("Delivery evidence / institutional reference")
            .fill("Fictional pickup evidence <script>example</script>");
        await accessible(page);
        await page.getByRole("button", { name: "Review delivery", exact: true }).click();
        expect(calls.some((call) => call.path.endsWith("/deliver/"))).toBe(false);
        await accessible(page);
        await page.getByRole("button", { name: "Confirm delivery", exact: true }).click();
        await expect(
            page.getByRole("region", { name: "Recorded delivery evidence", exact: true }),
        ).toContainText("Fictional pickup evidence");
        await expect(page.getByRole("button", { name: "Record delivery", exact: true })).toHaveCount(0);
        expect(calls.find((call) => call.path.endsWith("/prepare/"))).toMatchObject({
            data: {
                operation_id: document,
                release_reviewed: true,
                revision: "f".repeat(64),
                review_token: "fictional-preparation-proof",
            },
            csrf: "fictional-test-token",
        });
        expect(calls.find((call) => call.path.endsWith("/deliver/"))).toMatchObject({
            data: { operation_id: operation, version: 1, review_token: "fictional-delivery-proof" },
            csrf: "fictional-test-token",
        });
        expect(errors).toEqual([]);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });

test("the issued transcript HTML companion is accessible and reflows on narrow screens", async ({ page }) => {
    await page.goto("/fixture/transcript-document/");
    await expect(
        page.getByRole("heading", { name: "Official academic transcript", exact: true }),
    ).toBeVisible();
    await expect(page.getByRole("table")).toContainText("MATH.101");
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => document.scripts.length)).toBe(0);
});

test("closed student records keep their primary pursuit and cohort visible", async ({ page }) => {
    const student = id(11),
        person = id(12),
        errors = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        let result = { items: [], next_after: null };
        const record = {
            id: student,
            person_id: person,
            given_name: "Alex",
            family_name: "Example",
            institutional_id: "F-CLOSED",
            active: false,
            primary_program: { code: "BA", title: "Fictional retained program" },
            primary_program_status: "closed",
            entry_term: { code: "24/FA" },
            catalog: { year_code: "2024-2025" },
        };
        if (path === "/sis/ui/metrics/") result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/ui/data/students/") result = { items: [record], next_after: null };
        else if (path.endsWith("/record/")) result = record;
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/grade-amend-fixture/");
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.locator("#directory-search").fill("Alex");
    await page.locator("#directory-search").press("Enter");
    await page.getByRole("button", { name: "Alex Example", exact: true }).click();
    await expect(page.locator("#drawer-body")).toContainText("Fictional retained program");
    await expect(page.getByText("Primary pursuit", { exact: true }).locator("..")).toContainText("Closed");
    await expect(page.getByText("This primary pursuit is closed. Its history and transcript remain available.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Change program", exact: true })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Assign an additional program", exact: true })).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Register a student", exact: true })).toHaveCount(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(errors).toEqual([]);
});
