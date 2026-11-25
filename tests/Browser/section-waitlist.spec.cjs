const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;
const id = (n) => `01K${String(n).padStart(23, "0")}`;
const section = id(1),
    student = id(2),
    person = id(3),
    program = id(4),
    term = id(5),
    entry = id(6),
    operation = id(7);
const defaults = () => ({
    enabled: false,
    maximum_active: 50,
    ordering: "accepted_fifo",
    allocation: "oldest_may_register",
});
async function accessible(page) {
    const result = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa", "wcag21aa", "wcag22aa"])
        .analyze();
    expect(
        result.violations.map((v) => ({ id: v.id, targets: v.nodes.map((n) => n.target) })),
    ).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
}
async function staffFixture(page, options = {}) {
    const calls = [],
        errors = [], waitlistPages = [], receipts = [];
    let policy = defaults(),
        version = 0;
    page.on("pageerror", (e) => errors.push(e.message));
    await page.route("**/sis/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()["x-csrf-token"] });
        let result = {};
        if (path.includes('/history-checks/waitlist/')) {
            if (path.endsWith('/run/')) {
                if (options.delay) await options.delay();
                const status = options.outcome || 'verified';
                const receipt = { reference: operation, status, events_checked: 2,
                    checked_at: '2026-08-10T17:00:00Z' };
                receipts.push(receipt);
                result = { result: { status, events_checked: 2, position: 2,
                    records_checked: status === 'verified' ? 1 : null,
                    issues: status === 'unverified' ? ['waitlist_entry'] : [] }, receipt, event_limit: 200 };
            } else result = { items: receipts, next_after: null };
        }
        else if (path === "/sis/ui/metrics/")
            result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith("/sis/ui/data/"))
            result = {
                items: path.includes("/sections/")
                    ? [
                          {
                              id: section,
                              code: "HIST 101.A",
                              course_id: id(8),
                              term_id: term,
                              course_title: "Fictional history",
                              term_code: "26/FA",
                              capacity: 1,
                              active: 1,
                          },
                      ]
                    : [],
                next_after: null,
            };
        else if (path.endsWith("/waitlist/policy/")) result = { policy, version, waiting_count: 2 };
        else if (path.endsWith("/waitlist/policy/review/"))
            result = { ...data, operation_id: operation, review_token: "fictional-policy-proof" };
        else if (path.endsWith("/waitlist/policy/confirm/")) {
            policy = data.policy;
            version++;
            result = { status: "recorded" };
        } else if (path.endsWith("/waitlist/")) {
            waitlistPages.push(new URL(route.request().url()).searchParams.get('after'));
            result = {
                section_code: "HIST 101.A",
                policy,
                waiting_count: 2,
                seats_remaining: 0,
                items: [
                    {
                        entry_id: entry,
                        student_id: student,
                        current_name: "Alex <script>Example</script>",
                        current_institutional_id: "S-FICTIONAL",
                        accepted_position: 2,
                        rank: 1,
                    },
                ],
                next_after: 2,
            };
        } else if (path.endsWith("/waitlist/review/"))
            result = {
                operation_id: operation,
                action: data.action,
                entry_id: data.entry_id,
                revision: "a".repeat(64),
                review_token: "fictional-removal-proof",
                reason: data.reason,
            };
        else if (path.endsWith("/waitlist/confirm/")) result = { status: "recorded" };
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    await page.goto(options.path || "/admin/");
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole("link", { name: "Sections", exact: true }).click();
    await page.getByRole("button", { name: "HIST 101.A", exact: true }).click();
    return { calls, errors, waitlistPages };
}
test("each section has an explicit disabled default and a reviewed policy with a preserved edit draft", async ({
    page,
}) => {
    const { calls, errors } = await staffFixture(page);
    await page.getByRole("button", { name: "Waitlist policy", exact: true }).click();
    await expect(
        page.getByLabel("Allow waitlisting for this section", { exact: true }),
    ).not.toBeChecked();
    await page.getByLabel("Allow waitlisting for this section", { exact: true }).check();
    await page.getByLabel("Maximum active requests").fill("25");
    await page.getByLabel("Reason for this section policy").fill("Fictional department approval.");
    await page.getByRole("button", { name: "Review waitlist policy", exact: true }).click();
    await expect(
        page.getByText(/Existing requests retain their priority/, { exact: false }),
    ).toBeVisible();
    expect(calls.filter((c) => c.path.endsWith("/confirm/"))).toHaveLength(0);
    await page.getByRole("button", { name: "Revise policy", exact: true }).click();
    await expect(page.getByLabel("Maximum active requests")).toHaveValue("25");
    await page.getByRole("button", { name: "Review waitlist policy", exact: true }).click();
    await page.getByRole("button", { name: "Confirm waitlist policy", exact: true }).click();
    await expect(
        page.getByText("Section waitlist policy recorded.", { exact: true }),
    ).toBeVisible();
    expect(calls.at(-1).data.policy).toEqual({ ...defaults(), enabled: true, maximum_active: 25 });
    expect(calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    expect(errors).toEqual([]);
});
test("staff queue removal reviews the selected accepted request and keeps current labels escaped", async ({
    page,
}) => {
    const { calls, errors } = await staffFixture(page);
    await page.getByRole("button", { name: "Section waitlist", exact: true }).click();
    await expect(
        page.getByRole("region", { name: "Accepted waitlist order", exact: true }),
    ).toContainText("Alex <script>Example</script>");
    expect(await page.locator("#drawer-body script").count()).toBe(0);
    await page.getByRole("button", { name: "Review removal", exact: true }).click();
    await page.getByLabel("Reason for removing this request").fill("Fictional registrar review.");
    await page.getByRole("button", { name: "Review waitlist removal", exact: true }).click();
    expect(calls.at(-1).data.entry_id).toBe(entry);
    expect(calls.filter((c) => c.path.endsWith("/confirm/"))).toHaveLength(0);
    await page.getByRole("button", { name: "Confirm waitlist change", exact: true }).click();
    await expect(page.getByText("Waitlist change recorded.", { exact: true })).toBeVisible();
    expect(calls.at(-1).data.reason).toBe("Fictional registrar review.");
    expect(errors).toEqual([]);
});
async function ownerFixture(page) {
    const calls = [],
        errors = [];
    let active = false,
        seats = 0,
        completed = false;
    page.on("pageerror", (e) => errors.push(e.message));
    const course = {
        id: section,
        course_code: "HIST 101",
        title: "Fictional history",
        section_code: "HIST 101.A",
        term_code: "26/FA",
        term_id: term,
        capacity: 1,
        enrolled: 1,
        web_registration: 1,
        credits_milli: 3000,
        fee_minor: 10000,
        currency: "USD",
    };
    await page.route("**/portal/api/**", async (route) => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get("_workspace_json")) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()["x-csrf-token"] });
        let result = {};
        if (path === "/portal/api/me/")
            result = {
                person: {
                    id: person,
                    given_name: "Alex",
                    family_name: "Example",
                    institutional_id: "S-FICTIONAL",
                },
                students: [
                    {
                        id: student,
                        primary_program_id: program,
                        entry_term_id: term,
                        active: 1,
                        enrollments: [],
                    },
                ],
                contact: { version: 0 },
                applications: [],
                restrictions: [],
                accounts: [],
                news: [],
                transcript_requests: [],
            };
        else if (path === "/portal/api/programs/")
            result = {
                items: [{ id: program, code: "BA.HIST", title: "History" }],
                next_after: null,
            };
        else if (path === "/portal/api/courses/") result = { items: [course], next_after: null };
        else if (path === "/portal/api/payments/status/") result = { enabled: false };
        else if (path.endsWith("/waitlists/" + student + "/"))
            result = {
                items: active
                    ? [
                          {
                              entry_id: entry,
                              section_id: section,
                              course_code: "HIST 101",
                              section_code: "HIST 101.A",
                              status: "active",
                              rank: 1,
                          },
                      ]
                    : [],
                next_after: null,
            };
        else if (path.includes("/waitlists/") && path.endsWith("/review/"))
            result = {
                operation_id: operation,
                entry_id: data.entry_id || entry,
                action: data.action,
                revision: "a".repeat(64),
                review_token: "fictional-owner-proof",
            };
        else if (path.includes("/waitlists/") && path.endsWith("/confirm/")) {
            active = data.action === "requested";
            result = { status: "recorded" };
        } else if (path.includes("/waitlists/"))
            result = {
                section_id: section,
                section_code: "HIST 101.A",
                course_code: "HIST 101",
                policy: { ...defaults(), enabled: true },
                waiting_count: active ? 1 : 0,
                entry_id: active ? entry : null,
                rank: active ? 1 : null,
                seats_remaining: seats,
            };
        else if (path === "/portal/api/registration/review/")
            result = {
                operation_id: id(9),
                review_token: "fictional-registration-proof",
                revision: "b".repeat(64),
                section: {
                    id: section,
                    code: "HIST 101.A",
                    course_code: "HIST 101",
                    course_title: "Fictional history",
                    credits_milli: 3000,
                    fee_minor: 10000,
                    currency: "USD",
                    seats_remaining: 1,
                    active: true,
                },
                can_commit: true,
                students: [
                    {
                        id: student,
                        given_name: "Alex",
                        family_name: "Example",
                        active: true,
                        enrollment_id: null,
                        enrollment_status: null,
                        issues: [],
                        prerequisites: { status: "met", course_statuses: [], missing_count: 0 },
                        readiness: [
                            {
                                code: "waitlist",
                                label: "Waitlist priority",
                                status: "met",
                                message: "Your request is the oldest accepted request.",
                                next_step: null,
                            },
                        ],
                        charges: { total_minor: 10000, currency: "USD", items: [], problems: [] },
                        schedule: { status: "clear", blocked: false, conflicts: [] },
                    },
                ],
            };
        else if (path === "/portal/api/register/") {
            completed = true;
            active = false;
            result = { created: 1 };
        }
        await route.fulfill({ json: result, headers: { "Cache-Control": "private, no-store" } });
    });
    return {
        calls,
        errors,
        seat: () => {
            seats = 1;
        },
        completed: () => completed,
    };
}
test("student joins and withdraws with review, then registers the oldest request through eligibility and fees", async ({
    page,
}) => {
    const f = await ownerFixture(page);
    await page.goto("/portal/student/registration/");
    await page.getByRole("button", { name: "Waitlist options", exact: true }).click();
    await page.getByRole("button", { name: "View waitlist options", exact: true }).click();
    await page.getByRole("button", { name: "Join section waitlist", exact: true }).click();
    expect(f.calls.at(-1).data.entry_id).toBeNull();
    expect(f.calls.filter((c) => c.path === "/portal/api/register/")).toHaveLength(0);
    await page.getByRole("button", { name: "Confirm waitlist change", exact: true }).click();
    await page.getByRole("button", { name: "View current waitlist", exact: true }).click();
    await expect(page.getByText(/Current place 1 of 1/)).toBeVisible();
    await page.getByRole("button", { name: "Close details", exact: true }).click();
    await page.getByRole("button", { name: "Your waitlist requests", exact: true }).click();
    await expect(page.locator("#campus-dialog-body")).toContainText("Waiting · current place 1");
    await page
        .locator("#campus-dialog-body")
        .getByRole("button", { name: "Waitlist options", exact: true })
        .click();
    await page.getByRole("button", { name: "Withdraw waitlist request", exact: true }).click();
    expect(f.calls.at(-1).data.entry_id).toBe(entry);
    await page.getByRole("button", { name: "Confirm waitlist change", exact: true }).click();
    await page.getByRole("button", { name: "View current waitlist", exact: true }).click();
    await page.getByRole("button", { name: "Join section waitlist", exact: true }).click();
    await page.getByRole("button", { name: "Confirm waitlist change", exact: true }).click();
    f.seat();
    await page.getByRole("button", { name: "View current waitlist", exact: true }).click();
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.getByRole("button", { name: "Review registration and fees", exact: true }).click();
    await page
        .locator("#campus-dialog-body summary")
        .filter({ hasText: "Registration checks" })
        .click();
    await expect(page.locator("#campus-dialog-body")).toContainText("Waitlist priority: Passed");
    await expect(page.locator("#campus-dialog-body")).toContainText("$100.00");
    await page.getByRole("button", { name: "Confirm registration", exact: true }).click();
    await expect.poll(f.completed).toBe(true);
    expect(f.calls.at(-1).data.review_token).toBe("fictional-registration-proof");
    expect(f.calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    expect(f.errors).toEqual([]);
});

async function allocationFixture(page, failure = 'stale') {
    const base = await staffFixture(page);
    const waiting = [student, id(22)];
    const requests = [];
    let reviews = 0, commits = 0, registered = false;
    let finishReview = null;
    const receipt = () => ({batch_id: id(50 + reviews), section_id: section, created: 2, existing: 0,
        registrations: waiting.map((value, index) => ({student_id: value, enrollment_id: id(70 + index), created: true, status: 'A'}))});
    await page.route('**/sis/**', async route => {
        const url = new URL(route.request().url());
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) requests.push({path: url.pathname, data, csrf: route.request().headers()['x-csrf-token']});
        let result;
        if (url.pathname.endsWith('/waitlist/')) result = {section_code: 'HIST 101.A', policy: defaults(),
            waiting_count: registered ? 0 : 2, seats_remaining: registered ? 0 : 2,
            items: registered ? [] : waiting.map((value, index) => ({entry_id: id(80 + index), student_id: value,
                current_name: index ? 'Jordan <img> Fictional' : 'Alex Fictional', current_institutional_id: `S-FICTIONAL-${index}`,
                accepted_position: index + 2, rank: index + 1})), next_after: null};
        else if (url.pathname.endsWith('/registration-batches/review/')) {
            reviews++;
            if (failure === 'delayed') await new Promise(resolve => { finishReview = resolve; });
            result = {operation_id: id(50 + reviews), revision: String(reviews).repeat(64), review_token: `fictional-group-${reviews}`,
                section: {id: section, code: 'HIST 101.A', course_code: 'HIST 101', course_title: 'Fictional history',
                    active: true, credits_milli: 3000, seats_remaining: 2, fee_minor: 1000, currency: 'USD', prerequisite_courses: []},
                students: data.student_ids.map((value, index) => ({id: value, given_name: index ? 'Jordan <img>' : 'Alex',
                    family_name: 'Fictional', institutional_id: `S-FICTIONAL-${index}`, active: true,
                    enrollment_id: null, enrollment_status: null, issues: [],
                    readiness: [{code: 'waitlist', label: 'Waitlist priority', status: 'met',
                        message: index ? 'Every earlier waiting request is selected in this group and will register first.' : 'Your request is the oldest accepted request.'}],
                    waitlist: {queue_version: 3, rank: index + 1, can_register: true, selected_predecessors: index > 0},
                    schedule: {status: 'clear', blocked: false, conflict_count: 0, unpublished_count: 0, conflicts: []},
                    charges: {total_minor: 1000, currency: 'USD', items: [], problems: []},
                    prerequisites: {status: 'not_required', missing_count: 0, course_statuses: []}, corequisites: {status: 'not_required'}}))};
        } else if (url.pathname.endsWith('/registration-batches/commit/')) {
            commits++;
            if (failure === 'denied') return route.fulfill({status: 403, json: {error: 'Access denied.'}});
            if (failure === 'stale' && commits === 1) return route.fulfill({status: 409, json: {error: 'The waitlist changed. Refresh the review.'}});
            registered = true;
            result = receipt();
        } else return route.fallback();
        return route.fulfill({json: result, headers: {'Cache-Control': 'private, no-store'}});
    });
    await page.getByRole('button', {name: 'Section waitlist', exact: true}).click();
    return {...base, waiting, requests, release: () => finishReview?.(), pending: () => !!finishReview};
}

async function chooseOldestGroup(page) {
    await page.getByLabel('Number of oldest requests', {exact: true}).fill('2');
    await page.getByRole('button', {name: 'Review oldest requests together', exact: true}).click();
    await expect(page.getByRole('region', {name: 'Selected students', exact: true})).toContainText('2 selected students');
    await expect(page.getByRole('combobox', {name: /^Course section/})).toHaveValue(section);
}

test('staff review consecutive oldest requests with stale drafts and returns through the shared group workflow', async ({page}) => {
    const f = await allocationFixture(page);
    await page.getByLabel('Number of oldest requests', {exact: true}).fill('3');
    await page.getByRole('button', {name: 'Review oldest requests together', exact: true}).click();
    await expect(page.getByLabel('Number of oldest requests', {exact: true})).toBeVisible();
    await chooseOldestGroup(page);
    await page.getByRole('button', {name: 'Review selected group', exact: true}).click();
    await expect(page.locator('#drawer-body')).toContainText('Waiting students are processed in accepted queue order.');
    await expect(page.locator('#drawer-body img')).toHaveCount(0);
    await page.getByRole('button', {name: 'Change selection', exact: true}).click();
    await expect(page.getByRole('region', {name: 'Selected students', exact: true})).toContainText('2 selected students');
    await page.getByRole('button', {name: 'Review selected group', exact: true}).click();
    await page.getByRole('button', {name: 'Confirm group registration', exact: true}).click();
    await expect(page.locator('#drawer-error')).toContainText('The waitlist changed');
    await expect(page.getByRole('region', {name: 'Reviewed registration group', exact: true})).toContainText('Jordan <img> Fictional');
    await page.getByRole('button', {name: 'Refresh review', exact: true}).click();
    await page.setViewportSize({width: 320, height: 812});
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    await accessible(page);
    await page.getByRole('button', {name: 'Confirm group registration', exact: true}).click();
    await expect(page.locator('#drawer-body')).toContainText('2 registrations created.');
    await page.getByRole('button', {name: 'Back to waitlist', exact: true}).click();
    await expect(page.locator('#drawer-body')).toContainText('No active waitlist requests.');
    const reviews = f.requests.filter(item => item.path.endsWith('/registration-batches/review/'));
    expect(reviews.every(item => JSON.stringify(item.data.student_ids) === JSON.stringify(f.waiting))).toBe(true);
    expect(f.requests.every(item => item.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('lost group authority clears private review details and further confirmation controls', async ({page}) => {
    const f = await allocationFixture(page, 'denied');
    await chooseOldestGroup(page);
    await page.getByRole('button', {name: 'Review selected group', exact: true}).click();
    await page.getByRole('button', {name: 'Confirm group registration', exact: true}).click();
    await expect(page.locator('#drawer-body')).toContainText('Access to this registration group changed.');
    await expect(page.locator('#drawer-body')).not.toContainText('Alex Fictional');
    await expect(page.getByRole('region', {name: 'Reviewed registration group', exact: true})).toHaveCount(0);
    await expect(page.getByRole('button', {name: 'Confirm group registration', exact: true})).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('a delayed group review cannot replace the queue after returning to the record', async ({page}) => {
    const f = await allocationFixture(page, 'delayed');
    await chooseOldestGroup(page);
    await page.getByRole('button', {name: 'Review selected group', exact: true}).click();
    await expect.poll(f.pending).toBe(true);
    await page.getByRole('button', {name: 'Back to waitlist', exact: true}).click();
    await expect(page.getByLabel('Number of oldest requests', {exact: true})).toBeVisible();
    f.release();
    await expect(page.getByRole('button', {name: 'Review selected group', exact: true})).toHaveCount(0);
    await expect(page.getByRole('region', {name: 'Reviewed registration group', exact: true})).toHaveCount(0);
    expect(f.errors).toEqual([]);
});


test('selected waitlist support check preserves the queue page and is private and accessible', async ({ page }) => {
    const { calls, errors, waitlistPages } = await staffFixture(page);
    await page.getByRole('button', { name: 'Section waitlist', exact: true }).click();
    await page.getByRole('button', { name: 'Next waitlist page', exact: true }).click();
    await page.getByRole('button', { name: 'Check waitlist request history', exact: true }).click();
    expect(calls.some(c => c.path.includes('/history-checks/') && c.path.endsWith('/run/'))).toBe(false);
    const run = page.getByRole('button', { name: 'Run history check', exact: true });
    await run.focus(); await page.keyboard.press('Enter');
    const result = page.getByRole('region', { name: 'Current check result', exact: true });
    await expect(result).toContainText('Retained requests checked: 1');
    await expect(result).toContainText('maximum 200');
    await expect(result).not.toContainText('Alex');
    await expect(page.getByRole('region', { name: 'Previous history checks', exact: true })).toContainText(operation);
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.locator('#record-drawer').evaluate(el => { el.style.fontSize = '200%'; });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-waitlist-support-phone.png', fullPage: true });
    expect(calls.filter(c => c.path.includes('/history-checks/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Section waitlist');
    expect(waitlistPages.at(-1)).toBe('2');
    expect(errors).toEqual([]);
});

test('waitlist support access loss clears results and queue-only staff have no support action', async ({ page }) => {
    const { errors } = await staffFixture(page, { outcome: 'unverified' });
    await page.getByRole('button', { name: 'Section waitlist', exact: true }).click();
    await page.getByRole('button', { name: 'Check waitlist request history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    const result = page.getByRole('region', { name: 'Current check result', exact: true });
    await expect(result).toContainText('Retained request, queue projection and protected history');
    await expect(result).not.toContainText('Retained requests checked:');
    await page.route('**/sis/history-checks/**', route => route.fulfill({ status: 403, json: { error: 'Current support authority is required.' } }));
    await page.getByRole('button', { name: 'Refresh previous checks', exact: true }).click();
    await expect(result).toBeEmpty();
    await expect(page.getByRole('region', { name: 'Previous history checks', exact: true })).toBeEmpty();
    await page.unroute('**/sis/history-checks/**');
    await page.goto('/admin/waitlist-office-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Sections', exact: true }).click();
    await page.getByRole('button', { name: 'HIST 101.A', exact: true }).click();
    await page.getByRole('button', { name: 'Section waitlist', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Review removal', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Check waitlist request history', exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('returning from a pending waitlist check discards its detached response', async ({ page }) => {
    let signal, release;
    const started = new Promise(resolve => { signal = resolve; });
    const pending = new Promise(resolve => { release = resolve; });
    const { errors } = await staffFixture(page, { delay: async () => { signal(); await pending; } });
    await page.getByRole('button', { name: 'Section waitlist', exact: true }).click();
    await page.getByRole('button', { name: 'Check waitlist request history', exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await started;
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    const reply = page.waitForResponse(response => response.url().includes('/history-checks/') && response.url().endsWith('/run/'));
    release(); await reply;
    await expect(page.locator('#drawer-title')).toHaveText('Section waitlist');
    await expect(page.getByRole('region', { name: 'Current check result', exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});
