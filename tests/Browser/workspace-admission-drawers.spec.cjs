const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const { workspaceTool } = require("./support/workspace-actions.cjs");
const id = (n) => String(n).padStart(26, "0");
const body = (page) => page.locator("#drawer-body");
const button = (page, name) => body(page).getByRole("button", { name, exact: true });
async function fixture(page) {
    const f = { calls: [], errors: [], fail: null, deny: null, delay: null };
    let release, start;
    f.pending = new Promise((resolve) => {
        start = resolve;
    });
    const held = new Promise((resolve) => {
        release = resolve;
    });
    f.release = release;
    f.policies = {
        offers: { require_acceptance: true, revision: "offer-1" },
        checklist: { required: false, revision: "checklist-1" },
        answers: { mode: "request", revision: "answer-1" },
        withdrawals: { mode: "request", revision: "withdrawal-1" },
    };
    page.on("pageerror", (error) => f.errors.push(error.message));
    await page.route("**/sis/**", async (route) => {
        const req = route.request(),
            url = new URL(req.url()),
            path = url.pathname;
        const data = req.postData()
            ? JSON.parse(new URLSearchParams(req.postData()).get("_workspace_json"))
            : null;
        f.calls.push({
            path,
            data,
            query: Object.fromEntries(url.searchParams),
            csrf: req.headers()["x-csrf-token"],
        });
        if (path === f.delay) {
            start();
            await held;
        }
        if (path === f.deny)
            return route.fulfill({ status: 403, json: { error: "Fictional access denied." } });
        if (path === f.fail) {
            f.fail = null;
            return route.fulfill({
                status: 503,
                json: { error: "Fictional request unavailable." },
            });
        }
        let result = { items: [], next_after: null };
        if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === "/sis/admissions/follow-ups/")
            result = {
                items: [
                    {
                        application_id: id(url.searchParams.has("after") ? 2 : 1),
                        given_name: "Fictional",
                        family_name: url.searchParams.has("after")
                            ? "Second Applicant"
                            : "First Applicant",
                        institutional_id: "S-FICTIONAL",
                        office: "Admissions",
                        assignee_name: "Fictional Reviewer",
                        work_status: "waiting",
                        next_due_on: "2026-11-22",
                    },
                ],
                next_after: url.searchParams.has("after") ? null : id(1),
                today: "2026-11-22",
            };
        else if (path === "/sis/admission-policy/") {
            if (data) {
                f.policies[data.area] = {
                    ...f.policies[data.area],
                    ...data,
                    revision: `${data.area}-2`,
                };
                result = f.policies[data.area];
            } else result = f.policies;
        } else if (path.endsWith("/review/"))
            result = {
                application: { id: { value: id(2) }, status: "submitted", version: 0 },
                person: {
                    id: { value: id(10) },
                    givenName: "Fictional",
                    familyName: "Second Applicant",
                    institutionalId: { value: "S-FICTIONAL" },
                },
                labels: {
                    program_code: "ARTS-UG",
                    program_title: "Fictional Arts",
                    term_code: "26/FA",
                },
            };
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto("/admin/");
    await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
    f.queue = () => workspaceTool(page, "Open admissions follow-up queue");
    f.policiesOpen = () => workspaceTool(page, "Configure admissions policies");
    return f;
}
async function reflow(page) {
    await expect(page.locator("#record-drawer")).not.toHaveClass(/showing|hiding/);
    const geometry = await page.evaluate(() => {
        const drawer = document.querySelector('#drawer-body');
        return { documentOverflow: Math.max(0, document.documentElement.scrollWidth - innerWidth),
            drawerOverflow: Math.max(0, drawer.scrollWidth - drawer.clientWidth - 1),
            outsideDocument: [...document.body.querySelectorAll('*')].filter(el => {
                const r = el.getBoundingClientRect(); const c = getComputedStyle(el);
                return r.width && r.left >= 0 && r.right > innerWidth + 1 && c.visibility !== 'hidden' && c.display !== 'none';
            }).slice(0, 8).map(el => ({ tag: el.tagName, class: el.className, width: el.getBoundingClientRect().width, right: el.getBoundingClientRect().right })),
            outside: [...drawer.querySelectorAll('*')].filter(el => {
                const rect = el.getBoundingClientRect(); return rect.width && rect.right > innerWidth + 1;
            }).slice(0, 8).map(el => ({ tag: el.tagName, class: el.className, width: el.getBoundingClientRect().width, right: el.getBoundingClientRect().right })) };
    });
    expect(geometry).toEqual({ documentOverflow: 0, drawerOverflow: 0, outsideDocument: [], outside: [] });
    const scan = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(scan.violations.map((v) => ({ id: v.id, nodes: v.nodes.map((n) => n.target) }))).toEqual(
        [],
    );
}

test("follow-up filters share a baseline and return from an application with their accepted page", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.queue();
    await expect(body(page)).toContainText("First Applicant");
    const baseline = await body(page).evaluate((el) => {
        const select = el.querySelector('[name="casework_scope"]').getBoundingClientRect();
        const check = el
            .querySelector('[name="casework_due"]')
            .parentElement.getBoundingClientRect();
        return Math.abs(select.bottom - check.bottom);
    });
    expect(baseline).toBeLessThanOrEqual(2);
    await page.getByLabel("Assignment", { exact: true }).selectOption("mine");
    await page.getByLabel("Due today or earlier", { exact: true }).check();
    await expect(button(page, "Next")).toBeDisabled();
    await button(page, "Refresh queue").click();
    await button(page, "Next").click();
    await expect(body(page)).toContainText("Second Applicant");
    await button(page, "Open application").click();
    await button(page, "Back to follow-up queue").click();
    await expect(page.getByLabel("Assignment", { exact: true })).toHaveValue("mine");
    await expect(page.getByLabel("Due today or earlier", { exact: true })).toBeChecked();
    await expect(body(page)).toContainText("Second Applicant");
    expect(f.calls.filter((c) => c.path === "/sis/admissions/follow-ups/").at(-1).query).toEqual({
        scope: "mine",
        due: "1",
        after: id(1),
    });
    await reflow(page);
    await page.screenshot({ path: "/tmp/etsis-admission-followup-desktop.png" });
    expect(f.errors).toEqual([]);
});
test("failed follow-up paging retains the accepted page and retry cursor", async ({ page }) => {
    const f = await fixture(page);
    await f.queue();
    await expect(body(page)).toContainText("First Applicant");
    f.fail = "/sis/admissions/follow-ups/";
    await button(page, "Next").click();
    await expect(page.locator("#drawer-error")).toContainText("unavailable");
    await expect(body(page)).toContainText("First Applicant");
    await expect(button(page, "Previous")).toBeDisabled();
    await button(page, "Next").click();
    await expect(body(page)).toContainText("Second Applicant");
    expect(f.errors).toEqual([]);
});
test("follow-up filters changed during loading stay unapplied and keep accepted results", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.queue();
    await expect(body(page)).toContainText("First Applicant");
    f.delay = "/sis/admissions/follow-ups/";
    await button(page, "Next").click();
    await f.pending;
    await page.getByLabel("Assignment", { exact: true }).selectOption("unassigned");
    await expect(button(page, "Refresh queue")).toBeDisabled();
    f.release();
    await expect(button(page, "Refresh queue")).toBeEnabled();
    await expect(body(page)).toContainText("Filters changed. Refresh queue");
    await expect(body(page)).toContainText("First Applicant");
    await expect(button(page, "Next")).toBeDisabled();
    expect(f.errors).toEqual([]);
});
test("queue and policy field groups reflow at narrow width and enlarged text", async ({ page }) => {
    const f = await fixture(page);
    await f.queue();
    await expect(body(page)).toContainText("First Applicant");
    await page.setViewportSize({ width: 320, height: 800 });
    await page.addStyleTag({ content: "html { font-size: 200% !important; }" });
    await reflow(page);
    await page.screenshot({ path: "/tmp/etsis-admission-followup-phone.png" });
    await button(page, "Back to Admissions").click();
    await f.policiesOpen();
    await expect(page.getByRole("group", { name: "Offer acceptance", exact: true })).toBeVisible();
    await reflow(page);
    await page
        .getByRole("group", { name: "Applicant withdrawal", exact: true })
        .scrollIntoViewIfNeeded();
    await page.screenshot({ path: "/tmp/etsis-admission-policies-phone.png" });
    expect(f.errors).toEqual([]);
});
test("saving one admissions policy preserves other drafts and sends its own revision", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.policiesOpen();
    await page
        .getByLabel("When applicants may revise answers", { exact: true })
        .selectOption("before_decision");
    await page
        .getByLabel("Require applicant acceptance before matriculation", { exact: true })
        .uncheck();
    await button(page, "Save offer policy").click();
    await expect(
        page.getByLabel("When applicants may revise answers", { exact: true }),
    ).toHaveValue("before_decision");
    await expect(
        page.getByRole("group", { name: "Application revisions", exact: true }),
    ).toContainText("Unsaved changes.");
    await button(page, "Save revision policy").click();
    expect(f.calls.filter((c) => c.data && c.path === "/sis/admission-policy/")).toMatchObject([
        {
            data: { area: "offers", require_acceptance: false, revision: "offer-1" },
            csrf: "fictional-test-token",
        },
        {
            data: { area: "answers", mode: "before_decision", revision: "answer-1" },
            csrf: "fictional-test-token",
        },
    ]);
    await reflow(page);
    await page.screenshot({ path: "/tmp/etsis-admission-policies-desktop.png" });
    expect(f.errors).toEqual([]);
});
test("policy save failures retain the draft and denied access removes policy controls", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.policiesOpen();
    await page.getByLabel("How applicants may withdraw", { exact: true }).selectOption("direct");
    f.fail = "/sis/admission-policy/";
    await button(page, "Save withdrawal policy").click();
    await expect(page.locator("#drawer-error")).toContainText("unavailable");
    await expect(page.getByLabel("How applicants may withdraw", { exact: true })).toHaveValue(
        "direct",
    );
    f.deny = "/sis/admission-policy/";
    await button(page, "Save withdrawal policy").click();
    await expect(body(page)).toContainText("no longer available with your current access");
    await expect(button(page, "Save offer policy")).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
test("policy saving disables all save actions while preserving newer input as an unsaved draft", async ({
    page,
}) => {
    const f = await fixture(page);
    await f.policiesOpen();
    f.delay = "/sis/admission-policy/";
    await button(page, "Save offer policy").click();
    await f.pending;
    await expect(button(page, "Save checklist policy")).toBeDisabled();
    await page
        .getByLabel("Require applicant acceptance before matriculation", { exact: true })
        .uncheck();
    f.release();
    await expect(page.getByRole("group", { name: "Offer acceptance", exact: true })).toContainText(
        "Your newer changes are still unsaved.",
    );
    await expect(
        page.getByLabel("Require applicant acceptance before matriculation", { exact: true }),
    ).not.toBeChecked();
    expect(f.errors).toEqual([]);
});
