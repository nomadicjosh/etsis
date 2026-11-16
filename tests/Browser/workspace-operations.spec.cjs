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
            id: account, institutional_id: 'S-FICTIONAL', currency: 'USD', position: reversed ? 3 : posted ? 2 : 1, balance_minor: posted && !reversed ? 8750 : 10000,
            entries: posted ? [{ operation_id: operation, type: 'payment', minor: 1250, currency: 'USD', reversed }] : [],
        };
        else if (path === '/sis/ui/operation-id/') result = { id: operation };
        else if (path === `/sis/accounts/${account}/entries/review/`) result = {
            review_token: 'fictional-posting-review', type: payload.type, amount_minor: Number(payload.amount_minor),
            currency: payload.currency, balance_minor: 10000, resulting_balance_minor: 8750, reason: payload.reason,
        };
        else if (path === `/sis/accounts/${account}/entries/commit/`) { posted = true; result = { status: 'posted' }; }
        else if (path.endsWith('/reverse/review/')) result = { review_token: 'fictional-reversal-review', type: 'payment',
            amount_minor: 1250, currency: 'USD', balance_minor: 8750, resulting_balance_minor: 10000, reason: payload.reason };
        else if (path.endsWith('/reverse/commit/')) { reversed = true; result = { status: 'reversed' }; }
        else if (path === `/sis/ui/context/roster/${section}/`) result = { can_grade: true, items: [{ id: id('08'), student_id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', status: 'A', grade_version: 2, grade_code: null }], next_after: null };
        else if (path === `/sis/ui/context/assignments/${section}/`) result = { items: [], next_after: null };
        else if (path === `/sis/sections/${section}/students/${student}/grade-choices/`) result = { items:[{id:grade,code:'A',points_milli:4000}], pursuits:[], needs_assignment:false, current_version:2, effective_on_default:'2026-08-10' };
        else if(path.endsWith('/final-grades/review/')) result={grade:{code:'A',points_milli:4000},reason:'Initial final grade recorded.',review_token:'fictional-grade-review'};
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
    await page.getByRole('textbox', { name: /^Reason for transaction/ }).fill('Fictional payment received.');
    await page.getByRole('button', { name: 'Refresh account balance', exact: true }).click();
    await expect(page.getByLabel('Amount (USD)')).toHaveValue('12.50');
    await expect(page.getByRole('textbox', { name: /^Reason for transaction/ })).toHaveValue('Fictional payment received.');
    await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm transaction', exact: true })).toBeVisible();
    expect(requests.some(item => item.path.endsWith('/entries/commit/'))).toBe(false);
    await page.getByRole('button', { name: 'Confirm transaction', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Balance: $87.50');
    expect(requests.find(item => item.path === `/sis/accounts/${account}/entries/review/`)).toMatchObject({ payload: { type: 'payment', amount_minor: 1250, currency: 'USD', position: 1, reason: 'Fictional payment received.' }, csrf: 'fictional-test-token' });
    expect(requests.find(item => item.path === `/sis/accounts/${account}/entries/commit/`).payload).toEqual({ review_token: 'fictional-posting-review' });
    await page.getByRole('button', { name: 'Reverse entry', exact: true }).click();
    await page.getByRole('textbox', { name: /^Reason for reversal/ }).fill('Fictional posting correction.');
    await page.getByRole('button', { name: 'Review entry reversal', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed entry reversal' })).toContainText('$87.50 → $100.00');
    await page.getByRole('button', { name: 'Confirm entry reversal', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Reversed');
    expect(requests.find(item => item.path === `/sis/accounts/${account}/entries/${operation}/reverse/review/`).payload)
        .toEqual({ currency: 'USD', position: 2, reason: 'Fictional posting correction.' });
    expect(requests.find(item => item.path === `/sis/accounts/${account}/entries/${operation}/reverse/commit/`).payload)
        .toEqual({ review_token: 'fictional-reversal-review' });
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
    await page.getByRole('button', { name: 'Review final grade', exact: true }).click();
    await accessible();
    expect(requests.some(item=>item.path.endsWith('/commit/') && item.path.includes('final-grades'))).toBe(false);
    await page.getByRole('button', { name: 'Confirm final grade', exact: true }).click();
    await expect.poll(() => requests.find(item => item.path.endsWith('/final-grades/commit/'))?.payload).toEqual({ student_id: student, review_token:'fictional-grade-review' });
    expect(requests.find(item=>item.path.endsWith('/final-grades/review/'))?.payload).toMatchObject({student_id:student,grade_id:grade,version:2,effective_on:'2026-08-10'});
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
    const sent = []; const errors = []; let campaignStatus = 'draft'; let authorized = false; let confirmedSent = 0;
    const billingRule = id('22'); let ruleCreated = false; let ruleRetired = false; let journalReversed = false; let operationCount = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const payload = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (payload) sent.push({ path, payload });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: operationCount++ ? billingRule : journal };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4];
            const rows = {
                ledger: [{ id: ledger, code: '1000', title: 'Cash', currency: 'USD' }],
                billing: ruleCreated ? [{ id: billingRule, name: 'Fictional fee', term_code: '26/FA', program_label: 'All programs', kind: 'fee', amount_decimal: '2.50', currency: 'USD', status: ruleRetired ? 'Retired' : 'Active' }] : [],
                terms: [{ id: term, code: '26/FA' }],
                students: [{ id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }],
                transferstudents: [{ id: student, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }],
                institutions: [{ id: institution, code: 'EXAMPLE', name: 'Fictional College' }],
                equivalencies: [{ id: equivalency, external_code: 'EXT.101', course_code: 'CS.101', starts_on: '2026-01-01', credits_milli: 3000 }],
                campaigns: [{ id: campaign, status: campaignStatus }],
                people: [{ id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }],
            };
            result = { items: rows[dataset] || [], next_after: null };
        } else if (path === `/sis/ui/transfer/equivalencies/${equivalency}/`) result = {
            id: equivalency, institution_id: institution, institution_code: 'EXAMPLE', institution_name: 'Fictional College',
            external_code: 'EXT.101', external_title: 'External computing', course_code: 'CS.101', course_title: 'Computing',
            starts_on: '2026-01-01', ends_on: null, credits_milli: 3000, awarded_grade: 'TR', points_milli: 0,
            counts_gpa: 0, passes: 1, minimum_grade_rank: 2, revision: 'fictional-transfer-revision',
        };
        else if (path === `/sis/transfer-equivalencies/${equivalency}/course-versions/`) result = {
            items: [{ version_id: id('21'), version: 1, code: 'CS.101', transcript_title: 'Computing', credits_milli: 3000 }], next_cursor: null };
        else if (path.endsWith('/awards/')) result = { items: [], next_after: null };
        else if (path === '/sis/journals/') result = { id: journal };
        else if (path === `/sis/journals/${journal}/reverse/`) { journalReversed = true; result = { status: 'reversed' }; }
        else if (path === `/sis/journals/${journal}/`) result = { reference: 'FICT-J1', currency: 'USD', position: journalReversed ? 2 : 1, reversed: journalReversed, lines: [] };
        else if (path === '/sis/billing-rules/') { ruleCreated = true; result = { id: billingRule }; }
        else if (path === `/sis/billing-rules/${billingRule}/retire/`) { ruleRetired = true; result = { status: 'retired' }; }
        else if (path === `/sis/billing-rules/${billingRule}/`) result = { name: 'Fictional fee', kind: 'fee', amount_minor: 250, amount_decimal: '2.50', currency: 'USD', position: ruleRetired ? 2 : 1, retired: ruleRetired };
        else if (path === '/sis/billing-batches/review/') result = {
            term: { id: term, code: '26/FA' },
            rules: [{ id: id('23'), name: 'Fictional new fee', minor: 250, currency: 'USD' }],
            students: [{ student_id: student, name: 'Alex Example', institutional_id: 'S-FICTIONAL',
                program_label: 'BSCS', account_id: null, account_position: null, currency: 'USD',
                balance_before_minor: 0, balance_after_minor: 250, charge_minor: 250,
                new_charges: 1, already_posted: 0, previously_reversed: 0,
                items: [{ rule_id: id('23'), status: 'new' }], problems: [] }],
            blocked: 0, new_charges: 1, totals: [{ currency: 'USD', minor: 250 }],
            review_token: 'fictional-billing-review', expires_at: '2026-11-16T09:20:00Z',
        };
        else if (path === '/sis/billing-batches/') result = {
            posted: 1, accounts_created: 1, totals: [{ currency: 'USD', minor: 250 }],
            students: [{ student_id: student, account_id: id('24') }],
        };
        else if (path === `/sis/campaigns/${campaign}/`) result = { id: campaign, subject: 'Fictional announcement', body: 'Fictional body; never sent to a real mailbox.', status: campaignStatus, version: 0, can_dispatch: true };
        else if (path === '/sis/campaigns/recipients/search/') result = { items: [{ id: person, user_id: user, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }], next_after: null };
        else if (path === `/sis/campaigns/${campaign}/recipient-review/`) result = { subject: 'Fictional announcement', body: 'Fictional body; never sent to a real mailbox.', version: 0, recipients: [{ id: person, user_id: user, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }] };
        else if (path === `/sis/campaigns/${campaign}/queue/`) { campaignStatus = 'queued'; result = { delivery_ids: [delivery] }; }
        else if (path === `/sis/campaigns/${campaign}/delivery-setup/`) result = {
            timezone: 'America/Los_Angeles', suggested_local: '2026-11-16T09:00',
        };
        else if (path === `/sis/campaigns/${campaign}/delivery-review/`) result = {
            review_token: 'fictional-background-review', subject: 'Fictional announcement', body: 'Fictional body; never sent to a real mailbox.',
            recipients: [{ name: 'Alex Example', institutional_id: 'S-FICTIONAL' }],
        };
        else if (path === `/sis/campaigns/${campaign}/delivery-confirm/`) { authorized = true; result = { status: 'active' }; }
        else if (path === `/sis/campaigns/${campaign}/delivery-progress/`) result = {
            status: confirmedSent ? 'completed' : authorized ? 'active' : 'unconfirmed', version: authorized ? 1 : 0,
            campaign_status: campaignStatus, total: 1, counts: { sent: confirmedSent, queued: 1 - confirmedSent, sending: 0, needs_review: 0 },
        };
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
    await expect(page.locator('#drawer-body').getByRole('combobox', { name: /^Currency/ }).locator('option[value="KWD"]')).toHaveCount(1);
    await page.getByRole('textbox', { name: /^Reason for journal/ }).fill('Fictional approved posting.');
    const accounts = page.getByLabel('Ledger account', { exact: false });
    await accounts.nth(0).selectOption(ledger); await accounts.nth(1).selectOption(ledger);
    const amounts = page.getByLabel('Amount (positive debit, negative credit)');
    await amounts.nth(0).fill('1.25'); await amounts.nth(1).fill('-1.25');
    await expect(page.locator('#drawer-body')).toContainText('Balanced.');
    expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations).toEqual([]);
    await page.getByRole('button', { name: 'Post balanced journal', exact: true }).click();
    await expect.poll(() => sent.find(item => item.path === '/sis/journals/')?.payload.lines).toEqual([{ account_id: ledger, amount_minor: 125 }, { account_id: ledger, amount_minor: -125 }]);
    expect(sent.find(item => item.path === '/sis/journals/').payload).toMatchObject({ operation_id: journal, reason: 'Fictional approved posting.' });
    await page.getByRole('button', { name: 'Reverse journal', exact: true }).click();
    await page.getByRole('textbox', { name: /^Reason for journal reversal/ }).fill('Fictional journal correction.');
    await page.getByRole('button', { name: 'Confirm journal reversal', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Reversed');
    expect(sent.find(item => item.path === `/sis/journals/${journal}/reverse/`).payload).toEqual({ position: 1, reason: 'Fictional journal correction.' });
    await close();
    await page.locator('#directory-tabs [data-workspace="billing"]').click();
    await page.getByRole('button', { name: 'Define a billing rule', exact: true }).click();
    await page.locator('#drawer-body').getByRole('combobox', { name: /^Term/ }).selectOption(term);
    await page.locator('#drawer-body').getByLabel('Rule name').fill('Fictional fee');
    await page.locator('#drawer-body').getByRole('combobox', { name: /^Type/ }).selectOption('fee');
    await page.locator('#drawer-body').getByLabel('Amount', { exact: false }).fill('2.50');
    await page.getByRole('textbox', { name: /^Reason for billing rule/ }).fill('Fictional approved fee.');
    expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations).toEqual([]);
    await page.getByRole('button', { name: /Save and continue/ }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional fee');
    expect(sent.find(item => item.path === '/sis/billing-rules/').payload).toMatchObject({ operation_id: billingRule, reason: 'Fictional approved fee.', amount_minor: 250 });
    await page.getByRole('button', { name: 'Retire billing rule', exact: true }).click();
    await page.getByRole('textbox', { name: /^Reason for rule retirement/ }).fill('Fictional retired fee.');
    await page.getByRole('button', { name: 'Confirm rule retirement', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Retired');
    expect(sent.find(item => item.path === `/sis/billing-rules/${billingRule}/retire/`).payload).toEqual({ position: 1, reason: 'Fictional retired fee.' });
    await close();
    const billingDirectory = page.locator('#directory-results');
    for (const label of ['Fictional fee', '26/FA', 'All programs', '2.50 USD', 'Retired']) {
        await expect(billingDirectory).toContainText(label);
    }
    await page.getByRole('button', { name: 'Review student bills', exact: true }).click();
    await page.getByLabel('Billing term').selectOption(term);
    await page.getByLabel('Find a student', { exact: false }).selectOption(student);
    await page.getByRole('button', { name: 'Add student to batch', exact: true }).click();
    await page.getByLabel('Reason for billing').fill('Fictional reviewed batch.');
    await page.getByRole('button', { name: 'Review selected bills', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('1 new charge is ready for review.');
    await expect.poll(() => sent.find(item => item.path === '/sis/billing-batches/review/')?.payload).toEqual({
        term_id: term, students: [{ student_id: student }], reason: 'Fictional reviewed batch.',
    });
    expect(sent.some(item => item.path === '/sis/billing-batches/')).toBe(false);
    await page.getByLabel('Reason for billing').fill('Fictional corrected reason.');
    await expect(page.getByRole('button', { name: 'Confirm reviewed charges', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Review selected bills', exact: true }).click();
    await expect(page.getByRole('table', { name: 'Reviewed student charges' })).toContainText('Alex Example');
    expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze()).violations).toEqual([]);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Confirm reviewed charges', exact: true }).click();
    await expect.poll(() => sent.find(item => item.path === '/sis/billing-batches/')?.payload).toEqual({ review_token: 'fictional-billing-review' });
    await expect(page.locator('#drawer-body')).toContainText('1 charge posted. 1 account opened.');
    await close();
    await page.setViewportSize({ width: 1440, height: 1000 });
    await nav('institutions');
    await page.locator('#directory-tabs [data-workspace="equivalencies"]').click();
    await page.getByRole('button', { name: 'Open EXT.101 → CS.101', exact: true }).click();
    await page.getByRole('button', { name: 'Award transfer credit', exact: true }).click();
    await page.locator('#drawer-body').getByRole('combobox', { name: /^Student/ }).selectOption(student);
    await page.getByLabel('Posting term').selectOption(term);
    await page.getByLabel('External course completion date').fill('2026-05-10');
    await page.getByLabel('External grade rank').fill('3');
    await page.getByLabel('Accepted institutional course publication').selectOption(id('21'));
    await page.getByRole('button', { name: 'Confirm transfer award', exact: true }).click();
    await expect.poll(() => sent.find(item => item.path === '/sis/transfer-awards/')?.payload).toMatchObject({ student_id: student, equivalency_id: equivalency, institution_id: institution, term_id: term, grade_rank: 3, revision: 'fictional-transfer-revision' });
    await close(); await nav('campaigns');
    await page.getByRole('button', { name: `…${campaign.slice(-8)}`, exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional announcement');
    await page.getByRole('button', { name: 'Choose recipients', exact: true }).click();
    await page.getByLabel('Find people', { exact: true }).fill('Alex');
    await page.getByRole('button', { name: 'Find recipients', exact: true }).click();
    await page.getByRole('checkbox', { name: 'Alex Example · S-FICTIONAL', exact: true }).check();
    await page.getByRole('button', { name: 'Review message and recipients', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm recipient queue', exact: true }).click();
    await expect.poll(() => sent.find(item => item.path.endsWith('/queue/'))?.payload).toEqual({ version: 0, recipients: [{ user_id: user }] });
    expect(sent.some(item => item.path.endsWith('/dispatch/'))).toBe(false);
    await expect(page.getByRole('progressbar', { name: 'Confirmed sends', exact: true })).toHaveAttribute('aria-valuenow', '0');
    await page.getByRole('button', { name: 'Review background delivery', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed delivery recipients' })).toContainText('Alex Example');
    expect(sent.some(item => item.path.endsWith('/delivery-confirm/'))).toBe(false);
    await page.getByRole('button', { name: 'Confirm background delivery', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('You can leave this screen');
    expect(sent.filter(item => item.path.endsWith('/delivery-confirm/'))).toEqual([
        expect.objectContaining({ payload: { review_token: 'fictional-background-review' } }),
    ]);
    await close();
    confirmedSent = 1; // Fictional server worker finishes while the drawer is closed.
    await page.getByRole('button', { name: `…${campaign.slice(-8)}`, exact: true }).click();
    await expect(page.getByRole('progressbar', { name: 'Confirmed sends', exact: true })).toHaveAttribute('aria-valuenow', '100');
    await expect(page.locator('#drawer-body')).toContainText('1 of 1 messages sent');
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(sent.some(item => item.path.endsWith('/dispatch/'))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});

for (const outcome of ['confirmed_sent', 'closed']) {
    test(`delivery decisions retain evidence and review wording without retrying mail: ${outcome}`, async ({ page }) => {
        const campaign = '01K00000000000000000000016', delivery = '01K00000000000000000000017';
        const base = `/sis/campaigns/${campaign}/deliveries/${delivery}`;
        const calls = []; const errors = [];
        let decision = null;
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/sis/**', async route => {
            const path = new URL(route.request().url()).pathname;
            const raw = route.request().postData();
            const payload = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
            if (payload) calls.push({ path, payload, csrf: route.request().headers()['x-csrf-token'] });
            let result = {};
            if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
            else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/campaigns/') ? [{ id: campaign, status: decision ? 'completed' : 'queued' }] : [], next_after: null };
            else if (path === `/sis/campaigns/${campaign}/`) result = {
                id: campaign, subject: 'Fictional notice', body: 'Fictional message.', status: decision ? 'completed' : 'queued', version: 1, can_dispatch: false,
            };
            else if (path.endsWith('/delivery-progress/')) result = {
                status: decision ? 'completed' : 'held', campaign_status: decision ? 'completed' : 'queued', version: 2, total: 1,
                counts: { sent: decision?.decision === 'confirmed_sent' ? 1 : 0, queued: 0, sending: 0,
                    needs_review: decision ? 0 : 1, closed: decision?.decision === 'closed' ? 1 : 0 },
            };
            else if (path === `/sis/ui/context/deliveries/${campaign}/`) result = { items: [{
                id: delivery, given_name: 'Alex', family_name: 'Example', recipient_user_id: '01K00000000000000000000019',
                status: 'needs_review', version: 2, disposition: decision?.decision || null, resolution_id: decision ? delivery : null,
            }], next_after: null };
            else if (path === `${base}/review/` && route.request().method() === 'GET') result = {
                delivery_id: delivery, transport_status: 'needs_review', disposition: decision?.decision || null,
                worker_active: false, decision,
            };
            else if (path === `${base}/review/`) result = {
                ...payload, delivery_id: delivery, transport_status: 'needs_review', review_token: 'fictional-delivery-decision',
            };
            else if (path === `${base}/confirm/`) {
                const reviewed = calls.find(item => item.path === `${base}/review/`).payload;
                decision = { ...reviewed, actor_id: '01K00000000000000000000020', decided_at: '2026-11-15T10:00:00-08:00' };
                result = { disposition: decision.decision };
            }
            await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
        });
        await page.goto('/admin/');
        await page.locator('.workspace-nav-link[data-workspace="campaigns"]').click();
        await page.getByRole('button', { name: `…${campaign.slice(-8)}`, exact: true }).click();
        await page.getByRole('button', { name: 'Review delivery', exact: true }).click();
        await expect(page.locator('#drawer-body')).toContainText(`Delivery reference: ${delivery}`);
        await expect(page.getByRole('option', { name: 'Close without resending', exact: true })).toHaveAttribute('value', 'closed');
        await expect(page.getByRole('option', { name: 'Confirm sent using evidence', exact: true })).toHaveAttribute('value', 'confirmed_sent');
        await page.getByRole('combobox', { name: /^Decision/ }).selectOption(outcome);
        const evidence = page.getByLabel('Mail-service receipt or institutional case reference', { exact: true });
        if (outcome === 'confirmed_sent') await expect(evidence).toHaveAttribute('required', '');
        else await expect(evidence).not.toHaveAttribute('required', '');
        await page.getByRole('textbox', { name: /^Reason for this decision/ }).fill('Fictional evidence reviewed by staff.');
        await evidence.fill('CASE-FICTIONAL');
        await page.getByRole('button', { name: 'Review decision', exact: true }).click();
        await expect(page.getByRole('region', { name: 'Delivery decision review', exact: true })).toContainText('CASE-FICTIONAL');
        expect(calls.some(item => item.path === `${base}/confirm/`)).toBe(false);
        await page.setViewportSize({ width: 375, height: 812 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
        await page.getByRole('button', { name: 'Confirm delivery decision', exact: true }).click();
        await expect(page.getByRole('progressbar', { name: 'Confirmed sends', exact: true })).toHaveAttribute('aria-valuenow', outcome === 'confirmed_sent' ? '100' : '0');
        if (outcome === 'closed') await expect(page.locator('#drawer-body')).toContainText('1 closed without resending');
        await page.getByRole('button', { name: 'View delivery decision', exact: true }).click();
        await expect(page.locator('#drawer-body')).toContainText('Mail-service result: Unconfirmed');
        await expect(page.locator('#drawer-body')).toContainText(outcome === 'confirmed_sent' ? 'Staff confirmed sending' : 'Closed without resending');
        await expect(page.locator('#drawer-body')).toContainText('CASE-FICTIONAL');
        await expect(page.getByRole('button', { name: 'Confirm delivery decision', exact: true })).toHaveCount(0);
        expect(calls.find(item => item.path === `${base}/confirm/`)).toMatchObject({
            payload: { review_token: 'fictional-delivery-decision' }, csrf: 'fictional-test-token',
        });
        expect(calls.some(item => item.path.endsWith('/dispatch/') || item.path.endsWith('/delivery-confirm/'))).toBe(false);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(errors).toEqual([]);
    });
}

test('scheduled campaign review binds the local time and keeps browser delivery independent', async ({ page }) => {
    const campaign = '01K00000000000000000000016';
    const calls = []; const errors = [];
    let authorized = false, sent = false;
    let timing = null;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const payload = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, payload, method: route.request().method(), csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/campaigns/') ? [{ id: campaign, status: 'queued' }] : [], next_after: null };
        else if (path === `/sis/campaigns/${campaign}/`) result = { id: campaign, subject: 'Fictional notice', body: 'Fictional message.', status: 'queued', version: 1, can_dispatch: true };
        else if (path.endsWith('/delivery-setup/')) result = { timezone: 'America/Los_Angeles', suggested_local: '2026-11-16T09:00' };
        else if (path.endsWith('/delivery-review/')) {
            const day = payload.send_at?.slice(8, 10);
            timing = payload.send_at ? { utc: `2026-11-${day}T18:00:00.000000Z`, timezone: 'America/Los_Angeles',
                display: `Nov ${day}, 2026 at 10:00 am PST · America/Los_Angeles` } : null;
            result = { review_token: `fictional-review-${payload.send_at || 'now'}`, subject: 'Fictional notice',
                body: 'Fictional message.', send_after: timing, recipients: [{ name: 'Alex Example', institutional_id: 'F-001' }] };
        } else if (path.endsWith('/delivery-confirm/')) { authorized = true; result = { status: 'active' }; }
        else if (path.endsWith('/delivery-progress/')) result = {
            status: sent ? 'completed' : authorized ? 'active' : 'unconfirmed', waiting: authorized && !sent,
            campaign_status: 'queued', version: authorized ? 1 : 0, total: 1, send_after: authorized ? timing : null,
            counts: { sent: sent ? 1 : 0, queued: sent ? 0 : 1, sending: 0, needs_review: 0 },
        };
        else if (path.includes('/context/')) result = { items: [], next_after: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="campaigns"]').click();
    const open = () => page.getByRole('button', { name: `…${campaign.slice(-8)}`, exact: true }).click();
    await open();
    await page.getByRole('button', { name: 'Review background delivery', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm background delivery', exact: true })).toBeEnabled();
    await page.getByRole('radio', { name: 'Schedule for later', exact: true }).check();
    const date = page.getByLabel('Send after · America/Los_Angeles', { exact: true });
    await date.fill('2026-11-16T10:00');
    await expect(page.getByRole('button', { name: 'Confirm background delivery', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Update delivery review', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm scheduled delivery', exact: true })).toBeEnabled();
    await date.fill('2026-11-17T10:00');
    await expect(page.getByRole('button', { name: 'Confirm scheduled delivery', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Update delivery review', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Message delivery review', exact: true })).toContainText('Nov 17, 2026 at 10:00 am PST');
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(calls.some(item => item.path.endsWith('/delivery-confirm/'))).toBe(false);
    await page.getByRole('button', { name: 'Confirm scheduled delivery', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Scheduled and waiting.');
    await expect(page.locator('#drawer-body')).toContainText('Scheduled start: Nov 17, 2026 at 10:00 am PST');
    expect(calls.find(item => item.path.endsWith('/delivery-confirm/'))).toMatchObject({
        payload: { review_token: 'fictional-review-2026-11-17T10:00' }, csrf: 'fictional-test-token',
    });
    expect(calls.filter(item => item.path.endsWith('/delivery-review/')).at(-1)?.payload).toEqual({
        send_at: '2026-11-17T10:00', timezone: 'America/Los_Angeles',
    });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    sent = true; // Fictional worker runs later without an open browser drawer.
    await open();
    await expect(page.getByRole('progressbar', { name: 'Confirmed sends', exact: true })).toHaveAttribute('aria-valuenow', '100');
    expect(calls.some(item => item.path.endsWith('/dispatch/'))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});

test('background campaign progress updates independently and preserves held counts', async ({ page }) => {
    const campaign = '01K00000000000000000000016';
    const calls = []; const errors = [];
    let checked = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        calls.push({ path, method: route.request().method() });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/campaigns/') ? [{ id: campaign, status: 'queued' }] : [], next_after: null };
        else if (path === `/sis/campaigns/${campaign}/`) result = { id: campaign, subject: 'Fictional notice', body: 'Fictional message.', status: 'queued', version: 1, can_dispatch: true };
        else if (path === `/sis/campaigns/${campaign}/delivery-progress/`) result = {
            status: checked++ ? 'held' : 'active', campaign_status: 'queued', version: checked, total: 4,
            counts: { sent: 2, sending: 0, queued: checked === 1 ? 2 : 1, needs_review: checked === 1 ? 0 : 1 },
        };
        else if (path.includes('/context/')) result = { items: [], next_after: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.clock.install();
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="campaigns"]').click();
    await page.getByRole('button', { name: `…${campaign.slice(-8)}`, exact: true }).click();
    const progress = page.getByRole('progressbar', { name: 'Confirmed sends', exact: true });
    await expect(progress).toHaveAttribute('aria-valuenow', '50');
    await expect(progress).toHaveAttribute('aria-valuetext', '2 of 4 messages confirmed sent');
    await page.clock.fastForward(6500);
    await expect(page.locator('#drawer-body')).toContainText('1 queued · 0 sending · 1 need review');
    await expect(page.getByRole('button', { name: 'Cancel background delivery', exact: true })).not.toBeVisible();
    await expect(progress).toHaveAttribute('aria-valuenow', '50');
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(calls.filter(item => item.method !== 'GET' && !item.path.startsWith('/sis/ui/'))).toEqual([]);
    expect(errors).toEqual([]);
});

test('recipient review keeps selections across search pages and ignores superseded responses', async ({ page }) => {
    const id = n => String(n).padStart(26, '0'), campaign = id(900);
    const alex = { id: id(1), user_id: id(101), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-001' };
    const sam = { id: id(2), user_id: id(102), given_name: 'Sam', family_name: 'Example', institutional_id: 'S-002' };
    const calls = [], errors = [];
    let releaseSearch, releaseReview, reviewCount = 0, queued = false;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/campaigns/') result.items = [{ id: campaign, status: queued ? 'queued' : 'draft' }];
        else if (path === `/sis/campaigns/${campaign}/`) result = { id: campaign, version: 2, status: queued ? 'queued' : 'draft',
            subject: 'Fictional <img> invitation', body: 'Fictional reviewed message.', can_dispatch: false };
        else if (path === '/sis/campaigns/recipients/search/') {
            if (data.search === 'Delayed') {
                await new Promise(resolve => { releaseSearch = resolve; });
                result = { items: [{ ...sam, given_name: 'Stale' }], next_after: null };
            } else result = data.after ? { items: [sam], next_after: null } : { items: [alex], next_after: alex.id };
        } else if (path === `/sis/campaigns/${campaign}/recipient-review/`) {
            if (++reviewCount === 1) await new Promise(resolve => { releaseReview = resolve; });
            result = { subject: 'Fictional <img> invitation', body: 'Fictional reviewed message.', version: 2,
                recipients: [alex, sam].filter(person => data.recipients.some(selected => selected.user_id === person.user_id)) };
        } else if (path === `/sis/campaigns/${campaign}/queue/`) { queued = true; result = { delivery_ids: [id(901)] }; }
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="campaigns"]').click();
    await page.getByRole('button', { name: `…${campaign.slice(-8)}`, exact: true }).click();
    await page.getByRole('button', { name: 'Choose recipients', exact: true }).click();
    expect(calls.some(call => call.path === '/sis/campaigns/recipients/search/')).toBe(false);
    const search = page.getByLabel('Find people', { exact: true });
    await search.fill('Delayed');
    await page.getByRole('button', { name: 'Find recipients', exact: true }).click();
    await expect.poll(() => !!releaseSearch).toBe(true);
    await search.fill('Alex');
    await page.getByRole('checkbox', { name: 'Alex Example · S-001', exact: true }).check();
    releaseSearch();
    await expect(page.getByRole('checkbox', { name: /Stale/ })).toHaveCount(0);
    await page.getByRole('button', { name: 'More matching people', exact: true }).click();
    await page.getByRole('checkbox', { name: 'Sam Example · S-002', exact: true }).check();
    await expect(page.getByRole('region', { name: 'Selected recipients' })).toContainText('2 selected recipients');
    await page.getByRole('button', { name: 'Review message and recipients', exact: true }).click();
    await expect.poll(() => !!releaseReview).toBe(true);
    await page.getByRole('checkbox', { name: 'Alex Example · S-001', exact: true }).uncheck();
    releaseReview();
    const reviewButton = page.getByRole('button', { name: 'Review message and recipients', exact: true });
    await expect(reviewButton).toBeEnabled();
    await expect(page.getByRole('region', { name: 'Message and recipient review' })).not.toBeVisible();
    await reviewButton.click();
    const review = page.getByRole('region', { name: 'Message and recipient review' });
    await expect(review).toContainText('Recipients 1–1 of 1');
    await expect(review).toContainText('Sam Example · S-002');
    await expect(review.locator('img')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Review before queueing', exact: true })).toBeFocused();
    const AxeBuilder = require('@axe-core/playwright').default;
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
    await page.setViewportSize({ width: 320, height: 900 });
    // Fractional offcanvas sizing can round its scroll extent up by one CSS pixel.
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.getByRole('button', { name: 'Back to recipient selection', exact: true }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await reviewButton.click();
    await page.getByRole('button', { name: 'Confirm recipient queue', exact: true }).click();
    await expect.poll(() => queued).toBe(true);
    expect(calls.find(call => call.path.endsWith('/queue/')).data).toEqual({ version: 2, recipients: [{ user_id: sam.user_id }] });
    expect(calls.some(call => call.path.includes('/sis/ui/people/') || call.path.endsWith('/dispatch/'))).toBe(false);
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
});

test('final grade selection asks for the student pursuit when grading scales differ', async ({ page }) => {
    const id = n => `01K000000000000000000000${String(n).padStart(2,'0')}`;
    const section=id(1), student=id(2), pursuit=id(3), other=id(4), grade=id(5);
    const calls=[], errors=[];
    page.on('pageerror', e=>errors.push(e.message));
    await page.route('**/sis/**', async route=>{
        const req=route.request(), path=new URL(req.url()).pathname, raw=req.postData();
        const data=raw?JSON.parse(new URLSearchParams(raw).get('_workspace_json')):{};
        calls.push({path,data,csrf:req.headers()['x-csrf-token']});
        let result={items:[],next_after:null};
        if(path==='/sis/ui/metrics/')result={cards:[],intake:[],pipeline:[],queue:[]};
        else if(path==='/sis/ui/data/teaching/')result={items:[{id:section,code:'MATH.101.A',capacity:20}],next_after:null};
        else if(path===`/sis/ui/context/roster/${section}/`)result={can_grade:true,items:[{id:id(6),student_id:student,given_name:'Alex',family_name:'Example',institutional_id:'FICTIONAL',status:'A',grade_code:null,grade_version:0}],next_after:null};
        else if(path.endsWith('/attendance/'))result={items:[],counts:{},next_after:null};
        else if(path.endsWith('/grade-choices/'))result=data.assignment_id?{items:[{id:grade,code:'A+',points_milli:5000}],pursuits:[],needs_assignment:false,current_version:0,effective_on_default:'2026-08-10'}:{items:[],pursuits:[{id:pursuit,code:'GRAD',title:'Graduate degree'},{id:other,code:'UG',title:'Undergraduate degree'}],needs_assignment:true};
        else if(path.endsWith('/final-grades/review/'))result={grade:{code:'A+',points_milli:5000},reason:'Initial final grade recorded.',review_token:'fictional-program-grade'};
        await route.fulfill({json:result,headers:{'Cache-Control':'private, no-store'}});
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await page.getByRole('button',{name:'MATH.101.A',exact:true}).click();
    await page.getByRole('button',{name:'Grade & attendance',exact:true}).click();
    await page.getByRole('button',{name:'Record final grade',exact:true}).click();
    await expect(page.getByLabel('Program pursuit',{exact:true})).toHaveValue('');
    await page.setViewportSize({width:390,height:844});
    let scan=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
    expect(scan.violations.map(v=>v.id)).toEqual([]);
    await page.getByLabel('Program pursuit',{exact:true}).selectOption(pursuit);
    await page.getByRole('button',{name:'Use selected program',exact:true}).click();
    await expect(page.getByRole('combobox',{name:'Institutional grade',exact:true})).toContainText('A+ · 5 grade points');
    await page.getByRole('combobox',{name:'Institutional grade',exact:true}).selectOption(grade);
    scan=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
    expect(scan.violations.map(v=>v.id)).toEqual([]);
    await page.getByRole('button',{name:'Review final grade',exact:true}).click();
    await expect(page.locator('#drawer-body')).toContainText('5 grade points');
    await page.getByRole('button',{name:'Confirm final grade',exact:true}).click();
    await expect.poll(()=>calls.find(c=>c.path.endsWith('/final-grades/commit/'))?.data).toEqual({student_id:student,review_token:'fictional-program-grade'});
    expect(calls.find(c=>c.path.endsWith('/final-grades/review/'))?.data).toMatchObject({student_id:student,grade_id:grade,version:0,assignment_id:pursuit});
    expect(calls.filter(c=>c.path.endsWith('/grade-choices/')).at(-1)).toMatchObject({data:{assignment_id:pursuit},csrf:'fictional-test-token'});
    expect(calls.some(c=>c.path==='/sis/ui/data/grades/')).toBe(false);
    expect(errors).toEqual([]);
});

for (const scenario of [
    { currency: 'USD', amount: '12.34', invalid: '1e3', step: '0.01' },
    { currency: 'JPY', amount: '1234', invalid: '1.50', step: '1' },
    { currency: 'KWD', amount: '1.234', invalid: '1.2341', step: '0.001' },
]) {
    test(`account entry preserves ${scenario.currency} minor units and rejects excess precision`, async ({ page }) => {
        const account = '01K00000000000000000000081';
        const operation = '01K00000000000000000000082';
        const posted = []; let saved = false, reviewed;
        await page.route('**/sis/**', async route => {
            const path = new URL(route.request().url()).pathname;
            const raw = route.request().postData();
            const payload = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
            let result = { items: [], next_after: null };
            if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
            else if (path === '/sis/ui/data/accounts/') result.items = [{ id: account, given_name: 'Alex', family_name: 'Example', currency: scenario.currency }];
            else if (path === '/sis/ui/operation-id/') result = { id: operation };
            else if (path === `/sis/accounts/${account}/`) result = {
                id: account, institutional_id: 'S-FICTIONAL', currency: scenario.currency,
                balance_minor: saved ? -1234 : 0, position: saved ? 2 : 1, entries: [],
            };
            else if (path === `/sis/accounts/${account}/entries/review/`) {
                reviewed = payload;
                result = { review_token: 'fictional-currency-review', type: payload.type, amount_minor: payload.amount_minor,
                    currency: payload.currency, balance_minor: 0, resulting_balance_minor: -1234, reason: payload.reason };
            }
            else if (path === `/sis/accounts/${account}/entries/commit/`) { posted.push(reviewed); saved = true; result = { status: 'posted' }; }
            await route.fulfill({ json: result });
        });
        await page.goto('/admin/');
        await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
        await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
        await page.getByRole('button', { name: 'Post a transaction', exact: true }).click();
        await page.getByLabel('Transaction type').selectOption('payment');
        const amount = page.getByLabel(`Amount (${scenario.currency})`);
        await expect(amount).toHaveAttribute('step', scenario.step);
        await amount.fill(scenario.invalid);
        await page.getByRole('textbox', { name: /^Reason for transaction/ }).fill('Fictional currency receipt.');
        await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
        if (scenario.currency === 'USD') {
            await expect(page.getByText('Enter an amount using digits and a decimal point.', { exact: true })).toBeVisible();
        } else {
            expect(await amount.evaluate(input => input.validity.stepMismatch)).toBe(true);
        }
        expect(posted).toEqual([]);
        await amount.fill(scenario.amount);
        await page.getByRole('button', { name: 'Review transaction', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Confirm transaction', exact: true })).toBeVisible();
        expect(posted).toEqual([]);
        await page.getByRole('button', { name: 'Confirm transaction', exact: true }).click();
        await expect.poll(() => posted.length).toBe(1);
        expect(posted[0]).toMatchObject({ amount_minor: 1234, currency: scenario.currency, position: 1 });
    });
}
