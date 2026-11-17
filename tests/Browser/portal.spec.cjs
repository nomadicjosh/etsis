const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

const id = suffix => `01K${String(suffix).padStart(23, '0')}`;
const program = id(1), term = id(2), person = id(3), student = id(4), section = id(5), account = id(6), requestId = id(7), formId = id(8);
const course = { id: section, term_id: term, course_code: 'MATH.101', title: 'Algebra', section_code: 'MATH.101.01', term_code: '26/FA', credits_milli: 3000, capacity: 25, enrolled: 8, fee_minor: 15000, currency: 'USD', web_registration: 1 };
const transcript = [{ course_code: 'MATH.101', course_title: 'Algebra', completed_milli: 3000, attempted_milli: 3000, grade_points_milli: 12000, grade_code: 'A', counts_gpa: true }];

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => ({ target: node.target, summary: node.failureSummary })) }))).toEqual([]);
}

async function mockServices(page, options = {}) {
    let emergency = { contacts: [], version: 0 };
    let rejectEmergency = options.rejectEmergency === true;
    const mutations = [];
    const me = { person: { id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-EXAMPLE' },
        contact: { preferred_name: 'Alex', phone: '', address: '', version: 0 },
        students: [{ id: student, primary_program_id: program, entry_term_id: term, active: 1, enrollments: [{ section_id: section, term_id: term, section_code: course.section_code, course_code: course.course_code, title: 'Algebra', status: 'N', credits_milli: 3000 }] }],
        applications: [], restrictions: [], accounts: [{ id: account, student_id: student, term_id: term, currency: 'USD', balance_minor: 15000 }],
        news: [{ title: 'A fictional campus update', content: 'A welcoming place to connect.' }],
        transcript_requests: [], request_id: requestId, payment_id: id(14) };
    await page.route(/\/(?:portal\/api|sis\/forms)\//, async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const data = req.method() === 'POST' ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : null;
        if (data) { expect(req.headers()['x-csrf-token']).toBe('fictional-test-token'); mutations.push({ path, data }); }
        let result = {};
        if (path === '/portal/api/me/') result = me;
        else if (path === '/portal/api/applications/') result = { items: me.applications };
        else if (path === '/portal/api/programs/') result = { items: [{ id: program, code: 'BS.CS', title: 'Computer Science', form_id: formId }], next_after: null };
        else if (path === '/portal/api/courses/') result = { items: [course], next_after: null };
        else if (path.includes('/transcript/')) result = { items: transcript };
        else if (path.includes('/scores/')) result = { items: [{ title: 'Fictional problem set', earned_hundredths: 9500, possible_hundredths: 10000 }] };
        else if (path === '/portal/api/emergency-contacts/') {
            if (data && rejectEmergency) {
                rejectEmergency = false;
                return route.fulfill({ status: 409, json: { error: 'This record changed. Reload it before trying again.' } });
            }
            if (data) emergency = { contacts: data.contacts, version: emergency.version + 1 };
            result = data ? { status: 'saved' } : emergency;
        }
        else if (path === '/portal/api/contact/') { me.contact = { ...data, version: 1 }; result = { status: 'saved' }; }
        else if (path === '/portal/api/transcripts/') { me.transcript_requests = [{ id: requestId, student_id: student, delivery: data.delivery, status: 'pending', requested_at: '2026-11-06T12:00:00Z', version: 0 }]; result = { id: requestId }; }
        else if (path.endsWith('/status/') && path.includes('/transcripts/')) { me.transcript_requests[0].status = data.status; result = { status: 'changed' }; }
        else if (path === '/portal/api/payments/status/') result = { enabled: false };
        else if (path === `/portal/api/accounts/${account}/`) result = { id: account, currency: 'USD', balance_minor: 15000, entries: [{ type: 'charge', minor: 15000, currency: 'USD', reversed: false }] };
        else if (path === `/sis/forms/programs/${program}/active/`) result = { id: formId, name: 'Fictional computing application', submission_id: id(15), fields: [
            { key: 'transfer', label: 'Transfer applicant?', type: 'checkbox', required: false },
            { key: 'college', label: 'Previous college', type: 'text', required: true, show_when: { condition: 'AND', rules: [{ id: 'transfer', operator: 'equal', value: true }] } },
        ] };
        else if (path === `/sis/forms/${formId}/submissions/`) result = { status: 'submitted' };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return mutations;
}

test('three academic themes, public news and course discovery are responsive and accessible', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await mockServices(page);
    for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
        const response = await page.goto(`/portal/?theme=${theme}&guest=1`);
        expect(response.headers()['cache-control']).toContain('no-store');
        await expect(page.getByRole('heading', { level: 1 })).toContainText('curiosity');
        await expect(page.locator('.campus-hero-art img')).toBeVisible();
        await accessible(page);
        await page.screenshot({ path: `test-results/${theme}-desktop.png`, fullPage: true });
    }
    await page.goto('/portal/courses/?guest=1');
    await expect(page.locator('#campus-course-results')).toContainText('MATH.101');
    await page.getByLabel('Academic term').selectOption(term);
    await page.getByLabel('Course code, title, or section').fill('MATH.101');
    await page.getByRole('button', { name: 'Find courses' }).click();
    await expect(page.locator('#campus-course-count')).toContainText('1');
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/portal/?guest=1');
    await page.getByRole('button', { name: 'Open navigation' }).click();
    await expect(page.getByRole('link', { name: 'Sign in', exact: true })).toBeVisible();
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'test-results/campus-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
});

test('student applications, records, registration and accounts stay in one accessible portal', async ({ page }) => {
    const mutations = await mockServices(page);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.goto('/portal/student/');
    await expect(page.locator('#campus-service-content')).toContainText('Alex');
    await accessible(page);
    await page.screenshot({ path: 'test-results/campus-student-desktop.png', fullPage: true });
    await page.goto('/portal/student/profile/');
    await page.getByLabel('What would you like us to call you?').fill('Avery');
    await page.getByRole('button', { name: 'Save personal information' }).click();
    await expect(page.locator('#campus-notice')).toContainText('up to date');
    expect(mutations.at(-1).data.version).toBe(0);
    await accessible(page);
    await page.goto('/portal/apply/');
    await page.getByText('Search all academic programs', { exact: true }).click();
    await page.getByLabel('Program code or title').fill('BS.CS');
    await page.getByRole('button', { name: 'Find programs' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'matching programs loaded' })).toContainText('1');
    await page.getByLabel('Choose your academic program').selectOption(program);
    await page.getByLabel('When would you like to begin?').selectOption(term);
    await page.getByRole('button', { name: 'Continue to your application' }).click();
    await expect(page.getByLabel('Previous college')).toBeHidden();
    await page.getByLabel('Transfer applicant?').check();
    await page.getByLabel('Previous college').fill('Fictional College');
    await accessible(page);
    await page.getByRole('button', { name: 'Review answers' }).click();
    await expect(page.locator('.application-answer-review')).toContainText('Fictional College');
    await page.getByRole('button', { name: 'Submit application' }).click();
    await expect(page.locator('#campus-service-content')).toContainText('one step closer');
    expect(mutations.at(-1).data.answers).toEqual({ transfer: true, college: 'Fictional College' });
    await page.goto('/portal/student/grades/');
    await expect(page.getByRole('link', { name: 'Review degree progress', exact: true })).toHaveAttribute('href', '/portal/student/progress/');
    await expect(page.locator('#campus-service-content')).not.toContainText('4.000');
    await page.getByRole('button', { name: /assignment scores/ }).click();
    await expect(page.locator('#campus-dialog')).toContainText('95');
    await accessible(page);
    await page.getByRole('button', { name: 'Close details' }).click();
    await expect(page.getByRole('button', { name: /assignment scores/ })).toBeFocused();
    await page.goto('/portal/student/registration/');
    await page.getByRole('button', { name: 'Review & register' }).click();
    await accessible(page);
    await page.getByRole('button', { name: 'Confirm registration', exact: true }).click();
    await expect(page.locator('#campus-notice')).toContainText('registered');
    expect(mutations.at(-1).data).toEqual({ student_id: student, section_id: section });
    await page.goto('/portal/student/transcripts/');
    await page.getByRole('button', { name: 'Request official transcript' }).click();
    await expect(page.locator('#campus-service-content')).toContainText('pending');
    await page.getByRole('button', { name: 'Cancel request', exact: true }).click();
    await expect(page.locator('#campus-service-content')).toContainText('cancelled');
    await page.goto('/portal/student/accounts/');
    await page.getByRole('button', { name: 'View statement' }).click();
    await expect(page.locator('#campus-dialog')).toContainText('$150.00');
    await accessible(page);
    await page.getByRole('button', { name: 'Close details' }).click();
    await page.getByRole('button', { name: 'Pay bill', exact: true }).click();
    await expect(page.locator('#campus-dialog')).toContainText('not yet available');
    await page.getByRole('button', { name: 'Close details' }).click();
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/portal/student/');
    await expect(page.locator('#campus-service-content')).toContainText('Alex');
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'test-results/campus-student-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    await page.evaluate(() => dispatchEvent(new PageTransitionEvent('pagehide')));
    await expect(page.locator('#campus-service-content')).toContainText('Sign in');
    await expect(page.locator('#campus-main')).not.toContainText('Alex');
    await expect(page.locator('#campus-dialog-body')).toBeEmpty();
});

test('campus sign-in and account creation have accessible labels and keyboard focus', async ({ page }) => {
    await page.goto('/login/?guest=1');
    await expect(page.getByLabel('Email address')).toBeVisible();
    await page.keyboard.press('Tab');
    await expect(page.locator('.campus-skip')).toBeFocused();
    await accessible(page);
    await page.goto('/register/?guest=1');
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('autocomplete', 'new-password');
    await accessible(page);
});

test('published course descriptions remain keyboard accessible plain text on narrow screens', async ({ page }) => {
    const description = 'Fictional first paragraph.\n<script>window.fictionalPortalDescriptionExecuted = true</script>\nSecond paragraph.';
    course.description = description;
    try {
        await mockServices(page);
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/portal/courses/?guest=1');
        const disclosure = page.locator('#campus-course-results summary').filter({ hasText: 'Course description' });
        await disclosure.focus();
        await page.keyboard.press('Enter');
        await expect(page.locator('#campus-course-results .campus-course-description')).toHaveText(description);
        expect(await page.evaluate(() => window.fictionalPortalDescriptionExecuted)).toBeUndefined();
        await accessible(page);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    } finally {
        delete course.description;
    }
});


test('failed campus sign-in shows a generic accessible notice without retaining credentials', async ({ page }) => {
    await page.goto('/login/?guest=1&notice=credentials');
    await expect(page.getByRole('alert')).toContainText('Check your email address and password');
    await expect(page.getByLabel('Email address')).toBeFocused();
    await expect(page.getByLabel('Email address')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue('');
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});


test('owners order and clear emergency contacts using the shared accessible editor without losing rejected values', async ({ page }) => {
    const mutations = await mockServices(page, { rejectEmergency: true });
    await page.goto('/portal/student/profile/');
    await page.getByRole('button', { name: 'Manage emergency contacts', exact: true }).click();
    await page.getByRole('button', { name: 'Add emergency contact', exact: true }).click();
    let card = page.locator('[data-emergency-list] fieldset').first();
    await card.getByLabel('Name', { exact: true }).fill('Fictional Relative');
    await card.getByLabel('Phone number', { exact: true }).fill('001234');
    await page.getByRole('button', { name: 'Add emergency contact', exact: true }).click();
    card = page.locator('[data-emergency-list] fieldset').nth(1);
    await card.getByLabel('Name', { exact: true }).fill('Fictional Friend');
    await card.getByLabel('Phone number', { exact: true }).fill('005678');
    await page.getByRole('button', { name: 'Move earlier: contact 2', exact: true }).press('Enter');
    await expect(page.locator('[data-emergency-list] fieldset').first().getByLabel('Name', { exact: true })).toHaveValue('Fictional Friend');
    await page.getByRole('button', { name: 'Save emergency contacts', exact: true }).click();
    await expect(page.locator('#campus-error')).toContainText('This record changed');
    await expect(page.locator('[data-emergency-list] fieldset').first().getByLabel('Phone number', { exact: true })).toHaveValue('005678');
    await page.getByRole('button', { name: 'Save emergency contacts', exact: true }).click();
    await expect.poll(() => mutations.filter(item => item.path === '/portal/api/emergency-contacts/').length).toBe(2);
    await expect(page.getByRole('button', { name: 'Save emergency contacts', exact: true })).toBeEnabled();
    const sent = mutations.filter(item => item.path === '/portal/api/emergency-contacts/')[1];
    expect(sent.data.contacts.map(item => item.name)).toEqual(['Fictional Friend', 'Fictional Relative']);
    expect(sent.data.contacts[1].phone).toBe('001234');
    await accessible(page);
    await page.getByRole('button', { name: 'Remove contact 1', exact: true }).click();
    await page.getByRole('button', { name: 'Remove contact 1', exact: true }).click();
    await page.getByRole('button', { name: 'Save emergency contacts', exact: true }).click();
    await expect.poll(() => mutations.filter(item => item.path === '/portal/api/emergency-contacts/').length).toBe(3);
    expect(mutations.filter(item => item.path === '/portal/api/emergency-contacts/')[2].data).toEqual({ contacts: [], version: 1 });
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('students see linked charge credits as deductions in their own statement', async ({ page }) => {
    await mockServices(page);
    await page.route(`**/portal/api/accounts/${account}/`, route => route.fulfill({
        json: { id: account, currency: 'USD', balance_minor: 12500, entries: [
            { type: 'charge', minor: 15000, currency: 'USD', reversed: false },
            { type: 'charge_credit', minor: 2500, currency: 'USD', reversed: false },
        ] }, headers: { 'Cache-Control': 'private, no-store' },
    }));
    await page.goto('/portal/student/accounts/');
    await page.getByRole('button', { name: 'View statement' }).click();
    const dialog = page.locator('#campus-dialog');
    await expect(dialog).toContainText('charge credit');
    await expect(dialog).toContainText('-$25.00');
    await expect(dialog).toContainText('$125.00');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
});

test('student statements identify a refund payment link without receipt details or reasons', async ({ page }) => {
    await mockServices(page);
    await page.route(`**/portal/api/accounts/${account}/`, route => route.fulfill({
        json: { id: account, currency: 'USD', balance_minor: -13000, entries: [
            { operation_id: id(71), type: 'payment', minor: 10000, currency: 'USD', reversed: false },
            { operation_id: id(73), type: 'payment', minor: 5000, currency: 'USD', reversed: false },
            { operation_id: id(72), type: 'refund', minor: 2000, currency: 'USD', reversed: false,
                refund_allocations: [{ payment_operation_id: id(71), amount_minor: 1250 },
                    { payment_operation_id: id(73), amount_minor: 750 }] },
        ] }, headers: { 'Cache-Control': 'private, no-store' },
    }));
    await page.goto('/portal/student/accounts/');
    await page.getByRole('button', { name: 'View statement' }).click();
    await expect(page.locator('#campus-dialog')).toContainText(`For payment ${id(71).slice(-8)}`);
    await expect(page.locator('#campus-dialog')).toContainText('-$130.00');
    await expect(page.locator('#campus-dialog')).toContainText(`For payment ${id(73).slice(-8)} · $7.50`);
    await expect(page.locator('#campus-dialog').getByRole('row')).toHaveCount(4);
    await accessible(page);
});


test('student statements explain the retained registration credits and rate', async ({ page }) => {
    await mockServices(page);
    await page.route(`**/portal/api/accounts/${account}/`, route => route.fulfill({
        json: { id: account, currency: 'USD', balance_minor: 152, entries: [
            { operation_id: id(71), type: 'charge', minor: 152, currency: 'USD', reversed: false,
                registration_price: { enrollment_id: id(73), rule_id: id(74), name: 'Fictional tuition',
                    credits_milli: 1500, rate_minor: 101, currency: 'USD', rounding: 'half_up' } },
        ] }, headers: { 'Cache-Control': 'private, no-store' },
    }));
    await page.goto('/portal/student/accounts/');
    await page.getByRole('button', { name: 'View statement' }).click();
    await expect(page.locator('#campus-dialog')).toContainText('Fictional tuition');
    await expect(page.locator('#campus-dialog')).toContainText('1.5 credits × $1.01 per credit');
    await expect(page.locator('#campus-dialog')).toContainText('$1.52');
    await accessible(page);
});
