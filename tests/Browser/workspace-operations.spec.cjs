const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

test('staff post and reverse payments, grade a roster and save catalog edits', async ({ page }) => {
    const id = suffix => `01K000000000000000000000${suffix}`;
    const account = id('01'); const section = id('02'); const student = id('03'); const grade = id('04'); const course = id('05'); const operation = id('06'); const assignment = id('07');
    const requests = []; const errors = [];
    let posted = false; let reversed = false;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const payload = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (payload) requests.push({ path, payload, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4];
            const rows = {
                accounts: [{ id: account, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', currency: 'USD' }],
                teaching: [{ id: section, code: 'MATH.101.A', capacity: 20 }],
                courses: [{ id: course, code: 'MATH.101', title: 'Algebra', credits_milli: 3000, active: 1 }],
                grades: [{ id: grade, code: 'A', points_milli: 4000 }],
            };
            result = { items: rows[dataset] || [], next_after: null };
        } else if (path === `/sis/accounts/${account}/`) result = {
            id: account, institutional_id: 'S-FICTIONAL', currency: 'USD', balance_minor: posted && !reversed ? 8750 : 10000,
            entries: posted ? [{ operation_id: operation, type: 'payment', minor: 1250, currency: 'USD', reversed }] : [],
        };
        else if (path === '/sis/ui/operation-id/') result = { id: operation };
        else if (path === `/sis/accounts/${account}/entries/`) { posted = true; result = { status: 'posted' }; }
        else if (path.endsWith('/reverse/')) { reversed = true; result = { status: 'reversed' }; }
        else if (path === `/sis/ui/context/roster/${section}/`) result = { can_grade: true, items: [{ id: id('08'), student_id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', status: 'A', grade_version: 2, grade_code: null }], next_after: null };
        else if (path === `/sis/ui/context/assignments/${section}/`) result = { items: [], next_after: null };
        else if (path === `/sis/sections/${section}/students/${student}/score-page/`) result = { next_after: null, items: [{ assignment_id: assignment, title: 'Quiz one', possible_hundredths: 1000, earned_hundredths: null, version: 0 }] };
        else if (path === `/sis/sections/${section}/students/${student}/attendance/`) result = { items: [], counts: {}, next_after: null };
        else if (path === `/sis/ui/context/prerequisites/${course}/`) result = { items: [], next_after: null };
        else if (path === `/sis/ui/catalog/courses/${course}/`) result = { id: course, code: 'MATH.101', title: 'Algebra', credits_milli: 3000, active: 1, revision: 'fictional-revision' };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    async function accessible() {
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
    }
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: $100.00');
    await page.getByRole('button', { name: 'Post a transaction', exact: true }).click();
    await page.getByLabel('Transaction type').selectOption('payment');
    await page.getByLabel('Amount (USD)').fill('12.50');
    await accessible();
    await page.getByRole('button', { name: 'Post transaction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: $87.50');
    expect(requests.find(item => item.path === `/sis/accounts/${account}/entries/`)).toMatchObject({ payload: { operation_id: operation, type: 'payment', amount_minor: '1250', currency: 'USD' }, csrf: 'fictional-test-token' });
    await page.getByRole('button', { name: 'Reverse entry', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm entry reversal', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Reversed');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await page.getByRole('button', { name: 'MATH.101.A', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Alex Example');
    await accessible();
    await page.getByRole('button', { name: 'Grade & attendance', exact: true }).click();
    await page.getByRole('button', { name: 'Enter score', exact: true }).click();
    await page.getByLabel('Points earned').fill('8.25');
    await page.getByRole('button', { name: 'Save score', exact: true }).click();
    await expect.poll(() => requests.find(item => item.path === `/sis/assignments/${assignment}/scores/`)?.payload).toEqual({ student_id: student, earned_hundredths: 825, version: 0 });
    await page.getByRole('button', { name: 'Record final grade', exact: true }).click();
    await page.getByLabel('Institutional grade').selectOption(grade);
    await page.getByRole('button', { name: 'Save final grade', exact: true }).click();
    await expect.poll(() => requests.find(item => item.path.endsWith('/final-grades/'))?.payload).toEqual({ student_id: student, grade_id: grade, version: 2 });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('button', { name: 'Algebra', exact: true }).click();
    await page.getByRole('button', { name: 'Edit catalog record', exact: true }).click();
    await page.getByLabel('Course title').fill('College Algebra');
    await page.getByLabel('Credits', { exact: false }).fill('3.125');
    await accessible();
    await page.getByRole('button', { name: 'Save catalog changes', exact: true }).click();
    await expect.poll(() => requests.find(item => item.path === `/sis/ui/catalog/courses/${course}/`)?.payload).toMatchObject({ title: 'College Algebra', credits_milli: 3125, active: true, revision: 'fictional-revision' });
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('balanced journals, billing batches, transfer awards and deliberate campaign dispatch', async ({ page }) => {
    const id = suffix => `01K000000000000000000000${suffix}`;
    const ledger = id('11'); const term = id('12'); const student = id('13'); const institution = id('14'); const equivalency = id('15'); const campaign = id('16'); const delivery = id('17'); const person = id('18'); const user = id('19'); const journal = id('20');
    const sent = []; const errors = []; let campaignStatus = 'draft';
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const payload = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (payload) sent.push({ path, payload });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4];
            const rows = {
                ledger: [{ id: ledger, code: '1000', title: 'Cash', currency: 'USD' }],
                terms: [{ id: term, code: '26/FA' }],
                students: [{ id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }],
                institutions: [{ id: institution, code: 'EXAMPLE', name: 'Fictional College' }],
                equivalencies: [{ id: equivalency, starts_on: '2026-01-01', credits_milli: 3000 }],
                campaigns: [{ id: campaign, status: campaignStatus }],
                people: [{ id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }],
            };
            result = { items: rows[dataset] || [], next_after: null };
        } else if (path === '/sis/journals/') result = { id: journal };
        else if (path === `/sis/journals/${journal}/`) result = { reference: 'FICT-J1', currency: 'USD', reversed: false, lines: [] };
        else if (path === `/sis/campaigns/${campaign}/`) result = { id: campaign, subject: 'Fictional announcement', body: 'Fictional body; never sent to a real mailbox.', status: campaignStatus, can_dispatch: true };
        else if (path === `/sis/campaigns/${campaign}/queue/`) { campaignStatus = 'queued'; result = { delivery_ids: [delivery] }; }
        else if (path === `/sis/ui/people/${person}/`) result = { person: { givenName: 'Alex', familyName: 'Example', ownerUserId: { value: user } } };
        else if (path === `/sis/ui/context/deliveries/${campaign}/`) result = { items: campaignStatus === 'draft' ? [] : [{ id: delivery, recipient_user_id: user, status: 'queued' }], next_after: null };
        await route.fulfill({ json: result });
    });
    async function close() { await page.getByRole('button', { name: 'Close record', exact: true }).click(); }
    async function nav(key) { await page.locator(`.workspace-nav-link[data-workspace="${key}"]`).click(); }
    await page.goto('/admin/');
    await nav('accounts');
    await page.locator('#directory-tabs [data-workspace="journals"]').click();
    await page.getByRole('button', { name: 'Post a journal', exact: true }).click();
    await page.getByLabel('Unique journal reference').fill('FICT-J1');
    await page.getByLabel('Posting date').fill('2026-11-07');
    const accounts = page.getByLabel('Ledger account', { exact: false });
    await accounts.nth(0).selectOption(ledger); await accounts.nth(1).selectOption(ledger);
    const amounts = page.getByLabel('Amount (positive debit, negative credit)');
    await amounts.nth(0).fill('1.25'); await amounts.nth(1).fill('-1.25');
    await expect(page.locator('#drawer-body')).toContainText('Balanced.');
    await page.getByRole('button', { name: 'Post balanced journal', exact: true }).click();
    await expect.poll(() => sent.find(item => item.path === '/sis/journals/')?.payload.lines).toEqual([{ account_id: ledger, amount_minor: 125 }, { account_id: ledger, amount_minor: -125 }]);
    await close();
    await page.locator('#directory-tabs [data-workspace="billing"]').click();
    await page.getByRole('button', { name: 'Generate student bills', exact: true }).click();
    await page.getByLabel('Billing term').selectOption(term);
    await page.getByLabel('Find a student', { exact: false }).selectOption(student);
    await page.getByRole('button', { name: 'Add student to batch', exact: true }).click();
    await page.getByRole('button', { name: 'Generate selected student bills', exact: true }).click();
    await expect.poll(() => sent.find(item => item.path === '/sis/billing-batches/')?.payload).toEqual({ term_id: term, students: [{ student_id: student }] });
    await expect(page.locator('#record-drawer')).not.toBeVisible();
    await nav('institutions');
    await page.locator('#directory-tabs [data-workspace="equivalencies"]').click();
    await page.getByRole('button', { name: `…${equivalency.slice(-8)}`, exact: true }).click();
    await page.getByRole('button', { name: 'Award transfer credit', exact: true }).click();
    await page.locator('#drawer-body').getByRole('combobox', { name: /^Student/ }).selectOption(student);
    await page.getByLabel('Sending institution').selectOption(institution);
    await page.getByLabel('Award term').selectOption(term);
    await page.getByLabel('External course completion date').fill('2026-05-10');
    await page.getByLabel('Grade rank earned').fill('3');
    await page.getByRole('button', { name: 'Award transfer credit', exact: true }).click();
    await expect.poll(() => sent.find(item => item.path === '/sis/transfer-awards/')?.payload).toMatchObject({ student_id: student, equivalency_id: equivalency, institution_id: institution, term_id: term, grade_rank: 3 });
    await close(); await nav('campaigns');
    await page.getByRole('button', { name: `…${campaign.slice(-8)}`, exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional announcement');
    await page.getByRole('button', { name: 'Choose recipients', exact: true }).click();
    await page.getByLabel('Person with sign-in access').selectOption(person);
    await page.getByRole('button', { name: 'Add recipient', exact: true }).click();
    await page.getByRole('button', { name: 'Queue reviewed recipients', exact: true }).click();
    await expect.poll(() => sent.find(item => item.path.endsWith('/queue/'))?.payload).toEqual({ recipients: [{ user_id: user }] });
    expect(sent.some(item => item.path.endsWith('/dispatch/'))).toBe(false);
    await page.getByRole('button', { name: 'Review and send', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm send to this recipient', exact: true }).click();
    await expect.poll(() => sent.filter(item => item.path === `/sis/deliveries/${delivery}/dispatch/`).length).toBe(1);
    expect(errors).toEqual([]);
});
