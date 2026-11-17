/* Campus workspace. Private records stay in memory; nothing is persisted in browser storage. */
(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('workspace-config').textContent);
    const $one = (selector, root = document) => root.querySelector(selector);
    const $all = (selector, root = document) => [...root.querySelectorAll(selector)];
    const can = permission => config.permissions.includes(permission);
    const csrf = () => $one('#csrf-store input').value;
    const number = value => new Intl.NumberFormat().format(Number(value || 0));
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const clear = element => { disposeActionMenus(element); element.replaceChildren(); };
    let actionMenuSequence = 0;
    const currencyCodes = new Set(Intl.supportedValuesOf('currency'));
    const currencyOptions = [...new Set(['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', ...currencyCodes])];
    const currencyDigits = currency => currencyCodes.has(currency)
        ? new Intl.NumberFormat(undefined, { style: 'currency', currency }).resolvedOptions().maximumFractionDigits : null;
    const money = (minor, currency = 'USD') => {
        const digits = currencyDigits(currency);
        return digits === null ? `${minor} minor units ${currency}`
            : new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(minor / 10 ** digits);
    };
    function moneyInput(value, currency) {
        if (value.length > 40) throw new Error('The amount exceeds the supported limit.');
        const digits = currencyDigits(currency);
        if (digits === null) throw new Error('This currency needs a verified minor-unit definition before entering an amount.');
        const parts = /^(-?)([0-9]+)(?:\.([0-9]+))?$/.exec(value);
        if (!parts) throw new Error('Enter an amount using digits and a decimal point.');
        const decimal = (parts[3] || '').replace(/0+$/, '');
        if (decimal.length > digits) throw new Error(`${currency} amounts allow ${digits} decimal places.`);
        const magnitude = BigInt(parts[2]) * 10n ** BigInt(digits) + BigInt(decimal.padEnd(digits, '0') || '0');
        if (magnitude > 2000000000n) throw new Error('The amount exceeds the supported limit.');
        return Number(parts[1] ? -magnitude : magnitude);
    }
    const text = value => value == null ? '—' : String(value);
    const escapeLabel = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    let relationalStudio = null;
    let current = config.section;
    let listRows = [];
    let nextAfter = null;
    let intakeChart = null;
    let generation = 0;
    let directoryRequest = 0;
    let directoryQuery = '';
    let dirty = false;
    let formSerial = 0;
    let drawerOpener = null;
    let pendingNavigation = null;
    let signInPending = false;
    let formDraft = { id: null, version: null, status: 'draft', fields: [] };
    const drawer = bootstrap.Offcanvas.getOrCreateInstance($one('#record-drawer'));
    const searchModal = bootstrap.Modal.getOrCreateInstance($one('#search-modal'));
    const leaveModal = bootstrap.Modal.getOrCreateInstance($one('#leave-modal'));

    function node(tag, attributes = {}, ...children) {
        const element = document.createElement(tag);
        for (const [key, value] of Object.entries(attributes)) {
            if (value === null || value === undefined || value === false) continue;
            if (key.startsWith('on') && typeof value === 'function') element.addEventListener(key.slice(2), value);
            else if (key === 'class') element.className = value;
            else if (key === 'text') element.textContent = value;
            else if (key === 'value') element.value = value;
            else if (key === 'checked') element.checked = value;
            else if (key === 'disabled') element.disabled = value;
            else element.setAttribute(key, value === true ? '' : String(value));
        }
        for (const child of children.flat()) {
            if (child !== null && child !== undefined) element.append(child instanceof Node ? child : document.createTextNode(String(child)));
        }
        return element;
    }

    async function send(path, method = 'GET', data, accept = 'application/json') {
        const response = await fetch(path, {
            method, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
            // The router's legacy input parser accepts form strings, but crashes on JSON numbers/booleans.
            // Private middleware restores the typed object before CSRF, firewall and controller handling.
            headers: { Accept: accept, ...(method === 'GET' ? {} : { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'X-CSRF-Token': csrf() }) },
            body: method === 'GET' ? undefined : new URLSearchParams({ _workspace_json: JSON.stringify(data || {}) }).toString(),
        });
        if (response.status === 401) {
            if (!signInPending) {
                signInPending = true;
                window.location.assign(config.login_url || '/login/');
            }
            const error = new Error('Sign in again to continue.');
            error.status = 401;
            throw error;
        }
        return response;
    }
    async function request(path, method = 'GET', data) {
        return jsonResponse(await send(path, method, data));
    }
    async function requestFile(path, data, filename = 'section-roster.csv', { type = 'text/csv', maximum = 262144 } = {}) {
        const response = await send(path, 'POST', data, type);
        if (!response.ok) return jsonResponse(response);
        if (!response.headers.get('Content-Type')?.startsWith(type)) throw new Error('The download could not be prepared.');
        const file = await response.blob();
        if (file.size > maximum) throw new Error('The download exceeded the permitted page size.');
        const url = URL.createObjectURL(file);
        const link = node('a', { href: url, download: filename, hidden: true });
        document.body.append(link); link.click(); link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    async function jsonResponse(response) {
        if (response.status === 204) return {};
        let result;
        try { result = await response.json(); } catch { throw new Error('We could not complete that request. Please reload and try again.'); }
        if (!response.ok) {
            const error = new Error(result.error || 'The request could not be completed.');
            error.fields = Array.isArray(result.errors) ? result.errors : [];
            error.status = response.status;
            throw error;
        }
        return result;
    }

    function showError(error) {
        if (error.status === 401 && signInPending) return;
        const main = $one('#workspace-alert');
        const inDrawer = $one('#record-drawer').matches('.show, .showing');
        let alert = main;
        const scope = inDrawer ? $one('#drawer-body') : $one('#workspace-main');
        if (inDrawer) {
            main.hidden = true;
            clear(main);
            alert = $one('#drawer-error');
            if (!alert) {
                alert = node('div', { id: 'drawer-error', class: 'alert alert-danger', role: 'alert', tabindex: '-1' });
                scope.prepend(alert);
            }
        }
        clear(alert);
        const entries = (error.fields || []).map(item => ({
            ...item,
            controls: $all(`[name="${CSS.escape(item.field)}"]`, scope).filter(field => field.getClientRects().length),
        }));
        const hasFields = entries.some(entry => entry.controls.length);
        const heading = error.status === 403 ? 'This task needs additional access.'
            : entries.length && !hasFields && error.message === 'Please correct the highlighted fields.'
                ? 'Please review the following details.'
                : (error.message || 'Something interrupted the request. Try again.');
        alert.append(node('strong', {}, heading));
        if (entries.length) {
            const list = node('ul', { class: 'mb-0 mt-2' });
            for (const item of entries) {
                const label = item.controls[0]?.labels?.[0]?.textContent.replace(/\s*\*\s*$/, '').trim();
                list.append(node('li', {}, label ? `${label}: ${item.message}` : item.message));
                item.controls.forEach(field => { field.setAttribute('aria-invalid', 'true'); field.classList.add('is-invalid'); });
            }
            alert.append(list);
        }
        alert.hidden = false;
        alert.focus();
    }

    function notify(message) {
        const status = $one('#workspace-status');
        status.textContent = message;
        status.hidden = false;
        window.setTimeout(() => { status.hidden = true; }, 6500);
    }

    async function task(callback, button) {
        $one('#workspace-alert').hidden = true;
        $one('#drawer-error')?.remove();
        if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
        try { return await callback(); } catch (error) { showError(error); }
        finally { if (button) { button.disabled = false; button.removeAttribute('aria-busy'); } }
    }

    function listen(selector, type, callback) {
        const element = $one(selector);
        if (element) element.addEventListener(type, event => { if (type === 'submit') event.preventDefault(); task(() => callback(event), type === 'click' ? element : $one('[type="submit"]', element)); });
    }

    function disposeActionMenus(root) {
        root?.querySelectorAll('[data-workspace-menu]').forEach(toggle => bootstrap.Dropdown.getInstance(toggle)?.dispose());
    }

    /** Native buttons/links keep their handlers and permission checks inside a Bootstrap disclosure. */
    function actionMenu(label, entries, { context = label, icon: iconName = 'three-dots', compact = false } = {}) {
        const identity = `workspace-actions-${++actionMenuSequence}`;
        const toggle = node('button', { type: 'button', id: identity,
            class: `btn btn-outline-secondary dropdown-toggle${compact ? ' btn-sm' : ''}`,
            'data-bs-toggle': 'dropdown', 'data-workspace-menu': '', 'aria-expanded': 'false',
            'aria-controls': `${identity}-items`, 'aria-label': context }, icon(iconName), node('span', {}, label));
        const items = node('ul', { id: `${identity}-items`, class: 'dropdown-menu dropdown-menu-end', 'aria-labelledby': identity });
        for (const entry of entries) {
            if (entry.heading) {
                items.append(node('li', {}, node('h3', { class: 'dropdown-header' }, entry.heading)));
                continue;
            }
            if (entry.divider) { items.append(node('li', {}, node('hr', { class: 'dropdown-divider' }))); continue; }
            const control = entry instanceof HTMLElement ? entry
                : node('button', { type: 'button', onclick: event => task(entry.run, event.currentTarget) },
                    icon(entry.icon || 'arrow-right'), node('span', {}, entry.label));
            if (!control.hasAttribute('aria-label')) control.setAttribute('aria-label', control.textContent.trim());
            const danger = control.matches('.btn-danger, .btn-outline-danger') || entry.danger;
            control.classList.remove(...[...control.classList].filter(name => name === 'btn' || name.startsWith('btn-')));
            control.classList.add('dropdown-item');
            if (danger) control.classList.add('workspace-danger');
            control.addEventListener('click', () => {
                bootstrap.Dropdown.getInstance(toggle)?.hide();
                toggle.focus({ preventScroll: true });
            }, { capture: true });
            items.append(node('li', {}, control));
        }
        const wrapper = node('div', { class: 'dropdown workspace-action-menu' }, toggle, items);
        // Fixed positioning keeps row actions inside the viewport when a table scrolls horizontally.
        new bootstrap.Dropdown(toggle, { popperConfig: config => ({ ...config, strategy: 'fixed' }) });
        return wrapper;
    }

    function emptyState(title, description, name = 'flower1') {
        return node('div', { class: 'empty-state' }, icon(name), node('h3', {}, title), node('p', {}, description));
    }

    function table(headers, rows, caption = '') {
        const element = node('table', { class: 'data-table' });
        if (caption) element.append(node('caption', { class: 'visually-hidden' }, caption));
        element.append(node('thead', {}, node('tr', {}, headers.map(header => node('th', { scope: 'col' }, header)))));
        element.append(node('tbody', {}, rows.map(row => node('tr', {}, row.map(value => node('td', {}, value instanceof Node ? value : text(value)))))));
        return element;
    }

    function recordSummary(entries) {
        return node(
            'dl',
            { class: `record-summary${entries.length === 4 ? ' record-summary-paired' : ''}` },
            entries.map(([label, value]) =>
                node('div', {}, node('dt', {}, label), node('dd', {}, value ?? 'Not recorded')),
            ),
        );
    }
    const deliveryModes = [{ value: 'in_person', label: 'In person' }, { value: 'online', label: 'Online' }, { value: 'hybrid', label: 'Hybrid' }, { value: 'unspecified', label: 'Delivery to be confirmed' }];
    const datasets = {
        people: { title: 'People', description: 'One person. One connected story. Keep admissions, academics, work and access together.', icon: 'people', action: 'Add a person', endpoint: '/sis/persons/', permission: 'sis:person:manage', fields: [ ['given_name', 'Given name'], ['family_name', 'Family name'], ['email', 'Email address', 'email'], ['institutional_id', 'Assigned institutional ID', 'text', '', false] ], columns: [['Person', 'name'], ['Institutional ID', 'institutional_id'], ['Sign-in account', 'account']] },
        applications: { title: 'Admissions', description: 'From a first hello to a new beginning. Review and decide without losing your place.', icon: 'inboxes', action: 'Start an application', permission: 'sis:admissions:manage', columns: [['Applicant', 'name'], ['Institutional ID', 'institutional_id'], ['Status', 'status']] },
        students: { title: 'Students', description: 'A clearer picture of every student’s academic journey.', icon: 'mortarboard', permission: 'sis:student:read', columns: [['Student', 'name'], ['Institutional ID', 'institutional_id'], ['Status', 'active']] },
        courses: { title: 'Academic catalog', description: 'Keep programs, courses, terms and sections in one connected workspace.', icon: 'journal-bookmark', action: 'Add a course', endpoint: '/sis/courses/', permission: 'sis:catalog:manage', fields: [['code', 'Course code'], ['title', 'Course title'], ['credits_milli', 'Credits', 'credits']], columns: [['Course', 'title'], ['Code', 'code'], ['Credits', 'credits_milli'], ['Status', 'active']] },
        programs: { title: 'Academic programs', description: 'Give every academic path a clear home.', icon: 'signpost-split', action: 'Add a program', endpoint: '/sis/programs/', permission: 'sis:catalog:manage', fields: [['code', 'Program code'], ['title', 'Program title']], columns: [['Program', 'title'], ['Code', 'code']] },
        terms: { title: 'Academic terms', description: 'Set the dates once. Keep everyone on the same page.', icon: 'calendar-range', action: 'Add a term', endpoint: '/sis/terms/', permission: 'sis:catalog:manage', fields: [['code', 'Term code'], ['starts_on', 'First day', 'date'], ['ends_on', 'Last day', 'date'], ['add_drop_ends_on', 'Last day to add or drop', 'date']], columns: [['Term', 'code'], ['Name', 'name'], ['Academic year', 'academic_year_code'], ['Category', 'kind'], ['Starts', 'starts_on'], ['Ends', 'ends_on'], ['Add/drop deadline', 'add_drop_ends_on']] },
        sections: { title: 'Course sections', description: 'Turn your course catalog into a schedule that works.', icon: 'collection', action: 'Offer a section', endpoint: '/sis/sections/', permission: 'sis:catalog:manage', fields: [['course_id', 'Course', 'lookup', 'courses'], ['term_id', 'Term', 'lookup', 'terms'], ['code', 'Section code'], ['capacity', 'Seats', 'number'], ['delivery_mode', 'Delivery mode', 'choice', deliveryModes], ['faculty_user_id', 'Faculty account (optional)', 'lookup', 'faculty', false], ['web_registration', 'Allow student self-registration', 'checkbox'], ['fee_minor', 'Section fee', 'money'], ['currency', 'Currency', 'currency']], columns: [['Section', 'code'], ['Seats', 'capacity'], ['Status', 'active']] },
        rooms: { title: 'Rooms & spaces', description: 'Make room for classes, meetings, and everything in between.', icon: 'door-open', action: 'Add a room', endpoint: '/sis/rooms/', permission: 'sis:scheduling:manage', fields: [], columns: [['Code', 'code'], ['Room name', 'name'], ['Building', 'building_name'], ['Capacity', 'capacity']] },
        calendar: { title: 'Campus booking calendar', description: 'Find meetings across campus and keep room changes connected.', icon: 'calendar-week', permission: 'sis:scheduling:manage', columns: [] },
        buildings: { title: 'Buildings', description: 'Give every room a clear home on campus.', icon: 'buildings', action: 'Add a building', permission: 'sis:scheduling:manage', columns: [['Code', 'code'], ['Building name', 'name']] },
        bookingcategories: { title: 'Booking categories', description: 'Give meetings, course sections and campus events clear labels.', icon: 'tags', action: 'Add a category', permission: 'sis:scheduling:manage', columns: [['Code', 'code'], ['Category name', 'name'], ['Color', 'tone'], ['Available', 'active']] },
        bookings: { title: 'Spaces & scheduling', description: 'See what’s requested, approve what fits, and keep the day flowing.', icon: 'calendar3', action: 'Plan a meeting', endpoint: '/sis/bookings/', permission: 'sis:scheduling:manage', fields: [['room_id', 'Room', 'lookup', 'rooms'], ['title', 'Meeting purpose'], ['starts_at', 'Starts', 'datetime-local'], ['ends_at', 'Ends', 'datetime-local']], columns: [['Request', 'title'], ['Category', 'category_name'], ['Course section', 'section_code'], ['Status', 'status']] },
        employees: { title: 'Team & timesheets', description: 'The people who make your campus work. All connected to their person record.', icon: 'person-workspace', action: 'Add employment', endpoint: '/sis/employees/', permission: 'sis:hr:manage', fields: [['person_id', 'Person', 'lookup', 'people'], ['job_title', 'Job title']], columns: [['Team member', 'name'], ['Job title', 'job_title'], ['Status', 'active']] },
        timesheets: { title: 'Timesheets', description: 'Review time entries with the context you need, right here.', icon: 'clock-history', action: 'Submit time', endpoint: '/sis/timesheets/', permission: 'sis:hr:manage', fields: [['employee_id', 'Team member', 'lookup', 'employees'], ['starts_at', 'Started', 'datetime-local'], ['ends_at', 'Finished', 'datetime-local']], columns: [['Started', 'starts_at'], ['Finished', 'ends_at'], ['Status', 'status']] },
        accounts: { title: 'Student accounts', description: 'Clear statements, careful corrections, and a history you can trust.', icon: 'wallet2', action: 'Open an account', endpoint: '/sis/accounts/', permission: 'sis:finance:post', fields: [['student_id', 'Student', 'lookup', 'students'], ['term_id', 'Term', 'lookup', 'terms'], ['currency', 'Currency', 'currency']], columns: [['Student', 'name'], ['Currency', 'currency']] },
        journals: { title: 'General ledger', description: 'Balanced journals with an immutable financial history.', icon: 'journal-text', permission: 'sis:finance:ledger', columns: [['Reference', 'reference'], ['Posted', 'posted_on'], ['Currency', 'currency']] },
        reports: { title: 'Reports', description: 'Useful answers, securely scoped to reports you own or can administer.', icon: 'bar-chart-line', action: 'Create a report', endpoint: '/sis/reports/', permission: 'sis:reporting:manage', fields: [['name', 'Report name'], ['program_id', 'Program (optional)', 'lookup', 'programs', false], ['term_id', 'Entry term (optional)', 'lookup', 'terms', false]], columns: [['Report', 'name']] },
        campaigns: { title: 'Communications', description: 'Thoughtful messages. Encrypted content. A deliberate send.', icon: 'chat-square-text', action: 'Draft a message', endpoint: '/sis/campaigns/', permission: 'sis:communications:manage', fields: [['subject', 'Subject'], ['body', 'Message', 'textarea']], columns: [['Campaign', 'short_id'], ['Status', 'status']] },
        portal: { title: 'Campus content', description: 'A useful, welcoming home for campus news and information.', icon: 'window', action: 'Create content', endpoint: '/sis/portal/editor/', permission: 'sis:portal:manage', fields: [['kind', 'Content type', 'choice', ['news', 'page', 'link']], ['code', 'Unique code'], ['title', 'Title'], ['content', 'Content or HTTPS link', 'textarea'], ['audience', 'Audience', 'choice', ['campus', 'public']]], columns: [['Title', 'title'], ['Type', 'kind'], ['Status', 'status']] },
    };
    datasets.transcripts = { title: 'Transcript requests', description: 'Review a request, prepare the official record, and record delivery after it is complete.', icon: 'file-earmark-text', permission: 'sis:transcript:manage', columns: [['Request', 'short_id'], ['Delivery', 'delivery'], ['Status', 'status'], ['Requested', 'requested_at']] };
    const operations = window.createWorkspaceOperations({ node, request, task, can, table, badge, money, moneyInput, actionMenu, disposeActionMenus, buildForm, formData, openDrawer, notify, openRecord, createRecord, navigate, datasets, courseDrops: () => courseDropStudio, catalog: () => catalogStudio, classifications: () => classificationStudio, programs: () => programStudio, degreeAudit: () => degreeAuditStudio, calendar: () => academicCalendarStudio, batchRegistration: () => batchRegistration, teaching: () => teachingStudio, restrictions: () => restrictionStudio, closeDrawer: () => drawer.hide(), refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const courseDropStudio = window.createWorkspaceCourseDrops({ node, request, task, openDrawer, notify, buildForm, formData });
    const academicCalendarStudio = window.createWorkspaceAcademicCalendar({ node, request, task, buildForm, formData, openDrawer, table, badge, notify, refreshTerms: async () => { drawer.hide(); if (datasets[current]) await loadDirectory(); } });
    const degreeAuditStudio = window.createWorkspaceDegreeAudit({ node, request, task, can, closeDrawer: () => drawer.hide() });
    const courseRuleStudio = window.createWorkspaceCourseRules({ node, request, task, can, openDrawer, closeDrawer: () => drawer.hide(), table });
    const catalogStudio = window.createWorkspaceCatalog({ templates: () => offeringTemplates, deliveryModes, node, request, task, buildForm, formData, openDrawer, table, money, openRecord, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const classificationStudio = window.createWorkspaceClassifications({ node, request, task, buildForm, formData, openDrawer, publications: course => catalogStudio.publications(course) });
    const programStudio = window.createWorkspacePrograms({ node, request, task, buildForm, formData, openDrawer, degreeAudit: () => degreeAuditStudio, references: () => classificationStudio, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const offeringTemplates = window.createWorkspaceOfferingTemplates({ node, request, task, table, badge, money, buildForm, formData, openDrawer, notify, catalog: () => catalogStudio });
    const batchRegistration = window.createWorkspaceRegistration({ node, request, task, can, table, money, buildForm, openDrawer, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const moodleStudio = window.createWorkspaceMoodle({ node, request, task, can });
    const moodlePeople = window.createWorkspaceMoodlePeople({ node, request, task, can, openDrawer, openPerson });
    const moodleRequests = window.createWorkspaceMoodleRequests({ node, request, task, can, openDrawer, openPerson });
    const accessStudio = window.createWorkspaceAccess({ node, request, task, openDrawer, badge, notify, definitionActions });
    const peopleStudio = window.createWorkspacePeople({ node, request, task, openDrawer, buildForm, formData, notify, can, openPerson, badge, recordSummary, actionMenu, historyPanel, flow: operations.flow, loginUrl: config.login_url || '/login/' });
    const teachingStudio = window.createWorkspaceTeaching({ node, request, requestFile, task, buildForm, formData, openDrawer, openRecord, table, badge, flow: operations.flow });
    const schedulingStudio = window.createWorkspaceScheduling({ openCalendar: () => { drawer.hide(); return navigate('calendar', current !== 'calendar'); }, node, request, task, can, recordSummary, actionMenu, showAudit: (dataset, id) => operations.audit(dataset, { id }), openDrawer, buildForm, formData, notify, table, badge, financeReviews: () => financeReviewStudio, flow: operations.flow, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve(),
        composeAnnouncement: (preset, back) => createRecord('campaigns', preset, { back,
            explanation: 'Review this copy of the published invitation and dates before saving. Later event changes are not copied into this message. Saving creates an encrypted draft; choose recipients and confirm delivery in Communications.' }) });
    const employmentStudio = window.createWorkspaceEmployment({ node, request, task, can, recordSummary, actionMenu, showAudit: (dataset, id) => operations.audit(dataset, { id }), openDrawer, openPerson, buildForm, formData, notify, table, badge, historyPanel, positions: () => positionStudio, financeReviews: () => financeReviewStudio, flow: operations.flow, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const hrSetup = window.createWorkspaceHrSetup({ node, request, task, openDrawer, table, badge, notify });
    const positionStudio = window.createWorkspacePositions({ node, request, task, openDrawer, table, badge,
        flow: operations.flow, amounts: hrSetup.amounts, openEmployee: id => employmentStudio.employee(id), notify });
    const restrictionStudio = window.createWorkspaceRestrictions({ node, request, task, can, buildForm, formData, notify, openDrawer, openRecord, table, badge, flow: operations.flow, showAudit: (studentId, holdId) => operations.audit('students', { id: studentId }, holdId), closeDrawer: () => drawer.hide() });
    const importStudio = window.createWorkspaceImports({ node, table, request, notify, number, task, can, subjects: () => catalogStudio.subjects(), classifications: () => classificationStudio.library() });
    const transcriptStudio = window.createWorkspaceTranscripts({ node, request, requestFile, task, can, table, badge, buildForm, formData, openDrawer, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const financeReviewStudio = window.createWorkspaceFinanceReviews({ node, request, task, table, badge, money,
        buildForm, formData, openDrawer, account: (id, back) => operations.openAccount(id, back) });
    const hiatusStudio = window.createWorkspaceHiatus({ node, request, task, table, badge, buildForm, formData, openDrawer, financeReviews: () => financeReviewStudio, flow: operations.flow, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const transferAwardStudio = window.createWorkspaceTransferAwards({ node, request, task, table, buildForm, formData, openDrawer, back: id => transferStudio.record('equivalencies', id) });
    const studentStudio = window.createWorkspaceStudents({ node, request, task, can, actionMenu, recordSummary, disposeActionMenus, buildForm, formData, degreeAudit: () => degreeAuditStudio, openDrawer, openPerson, table, badge, flow: operations.flow, registration: operations.registration, restrictions: () => restrictionStudio, registrationHistory: () => batchRegistration, calendar: () => academicCalendarStudio, teaching: () => teachingStudio, transferAwards: () => transferAwardStudio, hiatus: () => hiatusStudio, showAudit: (dataset, id) => operations.audit(dataset, { id }) });
    relationalStudio = window.createWorkspaceReportStudio({
        node, request, requestFile, task, can, table, openDrawer,
        closeDrawer: () => drawer.hide(), simpleReport: () => reportStudio.create(),
        registerPopulation: (students, back) => {
            relationalStudio.cleanup(false);
            return batchRegistration.start("", students, back);
        },
        billPopulation: (students, back) => {
            relationalStudio.cleanup(false);
            return operations.billingBatch(students, back);
        },
        messagePopulation: async (students, back) => {
            const recipients = await request('/sis/campaigns/recipients/students/', 'POST', {
                students: students.map(student => ({ student_id: student.id })),
            });
            relationalStudio.cleanup(false);
            return operations.composeMessage(recipients.items, back);
        },
        sharing: async id => {
            const body = openDrawer('Report sharing', 'REPORT ACCESS');
            await operations.enhance('reports', { id }, body);
        },
    });
    const reportRequests = window.createWorkspaceReportRequests({ node, request, task, can, table, badge, buildForm, formData, openDrawer, notify, reports: () => reportStudio, builder: () => relationalStudio });
    const reportStudio = window.createWorkspaceReports({ builder: () => relationalStudio, node, request, requestFile, task, can, table, openDrawer, openRecord, flow: operations.flow, enhance: operations.enhance, closeDrawer: () => drawer.hide() });
    const transferStudio = window.createWorkspaceTransfer({ awards: () => transferAwardStudio, node, request, task, can, openDrawer, openRecord, table, badge, flow: operations.flow, showAudit: (kind, id) => operations.audit(kind, { id }) });
    datasets.timesheets.columns = [['Team member', 'name'], ['Institutional ID', 'institutional_id'], ['Started (UTC)', 'starts_at'], ['Status', 'status']];
    Object.assign(datasets, operations.datasets);
    datasets.registration = { title: 'Registration', description: 'Register a reviewed group into one section, with eligibility, seats and charges handled together.', icon: 'person-plus', action: 'Register a group', permission: 'sis:registration:manage', columns: [['Section', 'code'], ['Course', 'course_title'], ['Term', 'term_code'], ['Capacity', 'capacity'], ['Status', 'active']] };
    datasets.external.columns = [['Code', 'code'], ['Course', 'title'], ['Institution', 'institution_name']];
    datasets.equivalencies.columns = [['External course', 'external_code'], ['Institutional course', 'course_code'], ['Credits', 'credits_milli'], ['Effective', 'starts_on']];
    datasets.sections.columns = datasets.teaching.columns = [['Section', 'code'], ['Course', 'course_code'], ['Term', 'term_code'], ['Seats', 'capacity']];
    datasets.journals.action = 'Post a journal';
    const pageNames = { holds: 'Holds & restrictions', overview: 'Overview', forms: 'Application forms', imports: 'Import data', rules: 'Rules & audiences', access: 'Roles & access', moodle: 'Moodle integration', apply: 'Application', ...Object.fromEntries(Object.entries(datasets).map(([key, value]) => [key, value.title])) };
    const catalogTabs = [['courses', 'Courses'], ['sections', 'Sections'], ['programs', 'Programs'], ['terms', 'Terms'], ['grades', 'Grade definitions']];

    function navigationParent(section) {
        if (catalogTabs.some(([key]) => key === section)) return 'courses';
        if (['rooms', 'buildings', 'bookings', 'bookingcategories', 'calendar'].includes(section)) return 'bookings';
        if (['employees', 'timesheets'].includes(section)) return 'employees';
        if (['accounts', 'journals', 'billing', 'ledger'].includes(section)) return 'accounts';
        if (['institutions', 'external', 'equivalencies'].includes(section)) return 'institutions';
        return section;
    }

    async function navigate(section, push = true) {
        if (dirty && current === 'forms' && section !== 'forms') { pendingNavigation = () => navigate(section, push); leaveModal.show(); return; }
        if (section !== 'moodle') moodleStudio.leave();
        if (current !== section) $one('#directory-search').value = '';
        drawer.hide();
        current = section;
        generation++;
        $one('#workspace-alert').hidden = true;
        $all('[data-panel]').forEach(panel => { panel.hidden = panel.dataset.panel !== (datasets[section] ? 'directory' : section); });
        $all('[data-workspace]').forEach(link => {
            const key = link.dataset.workspace;
            const active = key === section || (link.classList.contains('workspace-nav-link') && key === navigationParent(section));
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        $all('[data-requires]').forEach(element => { element.hidden = !can(element.dataset.requires); });
        $one('#topbar-page').textContent = pageNames[section] || 'Workspace';
        const parent = navigationParent(section), parentLink = $one('#topbar-parent');
        parentLink.hidden = parent === section;
        $one('#topbar-parent-divider').hidden = parent === section;
        parentLink.textContent = pageNames[parent] || 'Workspace';
        parentLink.href = `/admin/workspace/${parent}/`;
        parentLink.dataset.workspace = parent;
        document.title = `${pageNames[section] || 'Workspace'} · etsis`;
        if (push) history.pushState({ section }, '', section === 'overview' ? '/admin/' : `/admin/workspace/${section}/`);
        const navigation = $one('#workspace-navigation');
        const navigationWasOpen = navigation.matches('.show, .showing');
        const focusMain = () => $one('#workspace-main').focus({ preventScroll: true });
        if (navigationWasOpen) {
            const version = generation;
            navigation.addEventListener('hidden.bs.offcanvas', () => {
                const focused = document.activeElement;
                if (version === generation && (focused === document.body || navigation.contains(focused)
                    || focused?.matches('[aria-label="Open navigation"]'))) focusMain();
            }, { once: true });
        }
        bootstrap.Offcanvas.getInstance(navigation)?.hide();
        let loading;
        if (section === 'overview') loading = renderDashboard();
        else if (datasets[section]) loading = loadDirectory();
        else if (section === 'forms') loading = loadFormStudio();
        else if (section === 'rules') loading = loadRuleStudio();
        else if (section === 'holds') loading = restrictionStudio.load();
        else if (section === 'access') loading = loadAccess();
        else if (section === 'moodle') loading = moodleStudio.load();
        else if (section === 'apply') loading = loadApplicantSelect();
        // Announce navigation once; a late response must not steal focus from a menu or editor.
        if (!navigationWasOpen) focusMain();
        await loading;
    }

    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-workspace]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey) return;
        event.preventDefault();
        task(() => navigate(link.dataset.workspace));
    });
    window.addEventListener('popstate', () => task(() => navigate(location.pathname.match(/workspace\/([^/]+)/)?.[1] || 'overview', false)));
    window.addEventListener('pagehide', () => { disposeActionMenus(document); $one('#drawer-body').replaceChildren(); $one('#directory-results').replaceChildren(); listRows = []; importStudio.clear(); formDraft = { id: null, version: null, status: 'draft', fields: [] }; $one('#workspace-main').replaceChildren(); $one('#person-search-results').replaceChildren(); });
    window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    listen('#discard-draft', 'click', () => { dirty = false; leaveModal.hide(); pendingNavigation?.(); pendingNavigation = null; });

    async function renderDashboard() {
        const token = generation;
        const data = await request('/sis/ui/metrics/');
        if (token !== generation) return;
        const cards = $one('#metric-grid'); clear(cards);
        if (!data.cards.length) cards.append(emptyState('Your workspace is ready.', 'Use the navigation to start an application or open a task your role allows.', 'sun'));
        for (const card of data.cards) {
            cards.append(node('a', { class: 'metric-card', href: `/admin/workspace/${card.destination}/`, 'data-workspace': card.destination },
                node('div', { class: 'metric-top' }, node('span', {}, card.label), node('span', { class: 'metric-icon' }, icon(card.icon))),
                node('strong', { class: 'metric-value' }, number(card.value)), node('span', { class: 'metric-caption' }, card.caption)));
        }
        const intake = data.intake || [];
        $one('#intake-empty').hidden = intake.length > 0;
        $one('#intake-empty').textContent = can('sis:student:read') ? 'Student intake will appear here when students are enrolled.' : 'Student metrics require student-record access.';
        intakeChart?.destroy();
        const context = $one('#intake-chart').getContext('2d');
        const gradient = context.createLinearGradient(0, 0, 0, 260);
        gradient.addColorStop(0, '#4a8bc244'); gradient.addColorStop(1, '#4a8bc202');
        intakeChart = new Chart(context, { type: 'line', data: { labels: intake.map(item => item.label), datasets: [{ label: 'Students', data: intake.map(item => Number(item.value)), borderColor: '#346d9d', backgroundColor: gradient, fill: true, tension: .35, pointRadius: 4, pointBackgroundColor: '#fff', pointBorderWidth: 2, borderWidth: 2.5 }] }, options: { responsive: true, maintainAspectRatio: false, animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 450 }, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, border: { display: false }, ticks: { color: '#586d82', font: { size: 11 } } }, y: { beginAtZero: true, grid: { color: '#edf1f6' }, border: { display: false }, ticks: { precision: 0, color: '#586d82', font: { size: 11 } } } } } });
        clear($one('#intake-table')); $one('#intake-table').append(table(['Entry term', 'Students'], intake.map(item => [item.label, number(item.value)]), 'Student intake values'));
        const pipeline = $one('#pipeline-bars'); clear(pipeline);
        const max = Math.max(1, ...(data.pipeline || []).map(item => Number(item.value)));
        if (!data.pipeline?.length) pipeline.append(node('p', { class: 'muted-text' }, 'Admissions metrics require admissions access.'));
        for (const item of data.pipeline || []) {
            const fill = node('div'); fill.style.width = `${Number(item.value) / max * 100}%`;
            pipeline.append(node('div', { class: 'pipeline-row' }, node('div', { class: 'pipeline-row-header' }, node('span', {}, item.label), node('strong', {}, number(item.value))), node('div', { class: 'pipeline-track', 'aria-hidden': 'true' }, fill)));
        }
        const queue = $one('#work-queue'); clear(queue);
        if (!data.queue?.length) queue.append(node('p', { class: 'queue-empty' }, 'No approval queues are assigned to your role. Your next steps are in the navigation.'));
        for (const item of data.queue || []) queue.append(node('a', { class: 'queue-row', href: `/admin/workspace/${item.destination}/`, 'data-workspace': item.destination }, node('div', {}, node('span', { class: 'queue-count' }, number(item.value)), node('span', {}, item.label, node('small', { class: 'queue-caption' }, Number(item.value) ? 'Waiting for review' : 'All caught up. A little breathing room.'))), icon('arrow-right')));
    }

    async function records(dataset, search = '', after = '') { return dataset === 'academic_years' ? request('/sis/academic-years/', 'POST', { ...(search ? { search } : {}), ...(after ? { after } : {}) }) : request(`/sis/ui/data/${dataset}/`, 'POST', { search, after }); }
    function recordOption(item, dataset = '') {
        return { value: dataset === 'faculty' ? item.owner_user_id : item.id, label: dataset === 'rooms' ? [item.code, item.name, item.building_name].filter(Boolean).join(' · ') : item.term_code ? [item.code, item.course_code, item.term_code].filter(Boolean).join(' · ') : item.institutional_id ? `${item.given_name} ${item.family_name} · ${item.institutional_id}` : [item.code, item.title || item.name || item.job_title].filter(Boolean).join(' · ') || item.id };
    }
    async function options(dataset, search = '') {
        const result = await records(dataset, search);
        return result.items.map(item => recordOption(item, dataset));
    }
    function searchableLookup(select, dataset) {
        const search = node('input', { class: 'form-control', type: 'search', maxlength: 80, 'aria-label': `Search ${dataset} options`, placeholder: 'Search by name, code or ID' });
        const searchButton = node('button', { class: 'btn btn-outline-secondary', type: 'button' }, 'Find');
        const more = node('button', { class: 'btn btn-link btn-sm px-0', type: 'button', hidden: true }, 'Load more choices');
        const status = node('span', { class: 'form-text d-block', role: 'status' }, 'Showing up to 50 choices. Search to find another record.');
        let after = '';
        let query = '';
        const run = async (append = false) => {
            const result = await records(dataset, query, append ? after : '');
            const selected = select.selectedOptions[0]?.cloneNode(true);
            if (!append) populate(select, [], 'Choose…');
            for (const item of result.items.map(item => recordOption(item, dataset))) {
                if (![...select.options].some(option => option.value === item.value)) select.append(node('option', { value: item.value }, item.label));
            }
            if (selected?.value) {
                if (![...select.options].some(option => option.value === selected.value)) select.append(selected);
                select.value = selected.value;
            }
            after = result.next_after || ''; more.hidden = !after;
            status.textContent = `${result.items.length} ${append ? 'additional ' : ''}choices found.${after ? ' More choices are available.' : ''}`;
        };
        searchButton.addEventListener('click', () => { query = search.value.trim(); task(() => run(), searchButton); });
        search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); searchButton.click(); } });
        more.addEventListener('click', () => task(() => run(true), more));
        return node('div', { class: 'mt-2' }, node('div', { class: 'input-group input-group-sm' }, search, searchButton), status, more);
    }
    function installLookup(selector, dataset) {
        const select = $one(selector);
        if (!select.dataset.searchable) { select.after(searchableLookup(select, dataset)); select.dataset.searchable = 'true'; }
    }
    function populate(select, items, placeholder = 'Choose…') {
        clear(select); select.append(node('option', { value: '' }, placeholder));
        items.forEach(item => select.append(node('option', { value: item.value }, item.label)));
    }
    function badge(value) {
        const positive = ['active', 'approved', 'accepted', 'published', 'matriculated', 'enabled'].includes(String(value).toLowerCase());
        return node('span', { class: `status-badge${positive ? ' success' : ' neutral'}` }, String(value).replaceAll('_', ' '));
    }

    async function loadDirectory(append = false) {
        const section = current; const definition = datasets[section]; const navigation = generation;
        const query = $one('#directory-search').value.trim();
        if (append && query !== directoryQuery) return loadDirectory(false);
        const token = ++directoryRequest;
        if (!append) directoryQuery = query;
        $one('#directory-heading').textContent = definition.title;
        $one('#directory-description').textContent = definition.description;
        $one('#directory-list-title').textContent = definition.title;
        const create = $one('#directory-create'); create.hidden = !definition.action || !can(definition.permission);
        $one('span', create).textContent = definition.action || 'Add record';
        const tabs = $one('#directory-tabs'), tools = $one('#directory-tools');
        clear(tabs); clear(tools);
        const family = navigationParent(section);
        const related = family === 'courses' ? catalogTabs
            : family === 'bookings' ? [['bookings', 'Space requests'], ['calendar', 'Calendar'], ['rooms', 'Rooms & spaces'], ['buildings', 'Buildings'], ['bookingcategories', 'Categories']]
            : family === 'employees' ? [['employees', 'Team'], ['timesheets', 'Timesheets']]
            : family === 'accounts' ? [['accounts', 'Student accounts'], ['billing', 'Term billing'], ['journals', 'Journals'], ['ledger', 'Chart of accounts']]
            : family === 'institutions' ? [['institutions', 'Institutions'], ['external', 'External courses'], ['equivalencies', 'Equivalencies']] : [];
        const visibleRelated = related.filter(([key]) => !['journals', 'ledger'].includes(key) || can('sis:finance:ledger'));
        visibleRelated.forEach(([key, label]) => tabs.append(node('a', { href: `/admin/workspace/${key}/`,
            'data-workspace': key, class: section === key ? 'active' : '', ...(section === key ? { 'aria-current': 'page' } : {}) }, label)));
        const tool = (label, run, name = label, iconName = 'arrow-right') => node('button', {
            type: 'button', class: 'btn btn-outline-primary', 'aria-label': name,
            onclick: event => task(run, event.currentTarget),
        }, icon(iconName), node('span', {}, label));
        if (family === 'courses' && can('sis:catalog:manage')) {
            tools.append(
                actionMenu('Curriculum', [
                    { label: 'Course group library', icon: 'collection', run: () => degreeAuditStudio.library() },
                    { label: 'Grading scales', icon: 'clipboard-data', run: () => degreeAuditStudio.gradingScale() },
                    { label: 'Subjects', icon: 'journal-bookmark', run: () => catalogStudio.subjects() },
                    { label: 'Departments & levels', icon: 'diagram-3', run: () => classificationStudio.library() },
                    { label: 'Degrees & awards', icon: 'award', run: () => classificationStudio.library('award') },
                ], { icon: 'collection' }),
                actionMenu('Academic calendar', [
                    { label: 'Academic years', icon: 'calendar-range', run: () => academicCalendarStudio.years() },
                    { label: 'Term types', icon: 'calendar3', run: () => academicCalendarStudio.types() },
                ], { icon: 'calendar3' }),
                actionMenu('Course offerings', [
                    { label: 'Build course offerings', icon: 'plus-circle', run: () => catalogStudio.start() },
                    { label: 'Saved offering plans', icon: 'journals', run: () => catalogStudio.plans() },
                    { label: 'Offering templates', icon: 'layout-text-window', run: () => offeringTemplates.page() },
                ], { icon: 'journal-richtext' }),
            );
        }
        if (family === 'employees' && can('sis:hr:manage')) tools.append(tool('HR setup', () => hrSetup.library(), 'HR setup', 'gear'));
        if (section === 'campaigns' && can('sis:communications:manage')) tools.append(tool('Message templates', () => operations.campaignTemplates(), 'My message templates', 'chat-square-text'));
        if (['reports', 'sharedreports'].includes(section)) {
            const requests = [
                { label: 'Request a report', icon: 'plus-circle', run: () => reportRequests.create() },
                { label: 'My report requests', icon: 'person-lines-fill', run: () => reportRequests.queue() },
            ];
            if (can('sis:reporting:manage')) requests.push({ label: 'Report request queue', icon: 'inboxes', run: () => reportRequests.queue(true) });
            tools.append(actionMenu('Report requests', requests, { icon: 'inboxes' }));
        }
        if (section === 'reports' && can('sis:reporting:manage')) {
            $one('span', create).textContent = 'Report Studio';
            create.setAttribute('aria-label', 'Open Report Studio');
        } else create.removeAttribute('aria-label');
        if (section === 'accounts' && can('sis:finance:post')) tools.append(tool('Finance reviews', () => financeReviewStudio.queue(), 'Finance review queue', 'inboxes'));
        if (section === 'billing' && can('sis:finance:post') && can('sis:finance:read') && can('sis:student:read')) tools.append(tool('Review bills', () => operations.billingBatch(), 'Review student bills', 'receipt'));
        if (section === 'registration' && can('sis:registration:manage')) tools.append(tool('Hiatus history', () => hiatusStudio.choose(), 'Student hiatus history', 'clock-history'));
        tabs.hidden = visibleRelated.length === 0;
        tools.hidden = tools.childElementCount === 0;
        $one('#directory-navigation').hidden = tabs.hidden && tools.hidden;
        if (!append) { listRows = []; nextAfter = null; $one('#directory-results').replaceChildren(node('div', { class: 'loading-card' }, 'Loading records…')); }
        $one('#directory-search-form').hidden = section === 'calendar';
        if (section === 'calendar') {
            listRows = []; nextAfter = null;
            $one('#directory-more').hidden = true;
            $one('#record-count').textContent = 'Weekly calendar';
            const target = node('div');
            $one('#directory-results').replaceChildren(target);
            await schedulingStudio.calendar(target, () => token === directoryRequest && navigation === generation && current === section);
            return;
        }
        if (section === 'grades' && can('sis:catalog:manage')) {
            const data = await request('/sis/grading-scales/global/');
            if (token !== generation || section !== current) return;
            const rows = data.scale.definition.grades.filter(g => g.code.toLowerCase().includes($one('#directory-search').value.toLowerCase()));
            $one('#directory-results').replaceChildren(node('div', { class:'table-responsive', tabindex:'0', role:'region', 'aria-label':'Global grading scale' }, table(['Grade','Minimum score (%)','Maximum score (%)','Grade points','Count in GPA','Earns credit'], rows.map(g => [g.code,g.min_score_milli == null ? '—' : g.min_score_milli / 1000,g.max_score_milli == null ? '—' : g.max_score_milli / 1000,g.points_milli / 1000,g.counts_gpa ? 'Yes' : 'No',g.passes ? 'Yes' : 'No']), 'Current global grade scale')));
            $one('#record-count').textContent = `${rows.length} grade definitions`;
            $one('#directory-more').hidden = true;
            return;
        }
        if (['people', 'students'].includes(section) && query === '') {
            listRows = []; nextAfter = null;
            $one('#directory-results').replaceChildren(emptyState(
                section === 'people' ? 'Find a person.' : 'Find a student.',
                'Search by name or institutional ID. Records appear only after you search.', definition.icon));
            $one('#record-count').textContent = 'Search to view records';
            $one('#directory-more').hidden = true;
            return;
        }
        if (!append) {
            listRows = []; nextAfter = null;
            clear($one('#directory-results'));
            $one('#record-count').textContent = 'Loading records…';
            $one('#directory-more').hidden = true;
        }
        let result;
        try { result = await records(section, query, append ? nextAfter : ''); }
        catch (error) {
            if (token !== directoryRequest || navigation !== generation || section !== current) return;
            throw error;
        }
        if (token !== directoryRequest || navigation !== generation || section !== current) return;
        listRows = append ? [...listRows, ...result.items] : result.items; nextAfter = result.next_after;
        const results = $one('#directory-results'); clear(results);
        if (!listRows.length) results.append(emptyState(directoryQuery ? 'No matching records' : 'No records yet', directoryQuery ? 'Try a different name, code or search term.' : definition.action && can(definition.permission) ? 'Add your first record using the button above.' : 'Records available to your role will appear here.', definition.icon));
        else results.append(node('div', { class: 'table-responsive', tabindex: '0', role: 'region', 'aria-label': 'Scrollable records' }, table([...definition.columns.map(([label]) => label), ''], listRows.map(row => [
            ...definition.columns.map(([, field], index) => renderCell(section, row, field, index)),
            node('button', { class: 'icon-button', type: 'button', 'aria-label': `Open ${recordLabel(row)}`, onclick: () => task(() => openRecord(section, row)) }, icon('arrow-up-right')),
        ]), `${definition.title} records`)));
        $one('#record-count').textContent = `${number(listRows.length)} ${listRows.length === 1 ? 'record' : 'records'}${nextAfter ? ' loaded' : ''}`;
        $one('#directory-more').hidden = !nextAfter;
    }

    function recordLabel(row) { if (row.external_code && row.course_code) return `${row.external_code} → ${row.course_code}`; return [row.given_name, row.family_name].filter(Boolean).join(' ') || row.title || row.name || row.code || row.reference || `Record ${row.id.slice(-6)}`; }
    function renderCell(section, row, field, index) {
        if (section === 'bookingcategories' && field === 'tone') return schedulingStudio.categoryBadge({ name: row.tone, tone: row.tone });
        if (field === 'category_name') return schedulingStudio.categoryBadge(row);
        if (['rooms', 'buildings', 'bookingcategories', 'calendar'].includes(section) && field === 'name') return row.name || 'Name not set';
        if (section === 'rooms' && field === 'building_name') return row.building_name || 'Building not assigned';
        if (section === 'terms' && field === 'kind') return row.kind === 'standard' ? 'Standard' : row.kind === 'non_standard' ? 'Non-standard' : 'Classification needed';
        let value = field === 'name' ? recordLabel(row) : field === 'short_id' ? `…${row.id.slice(-8)}` : row[field];
        if (field === 'billing_amount') return `${row.amount_decimal === null ? `${row.amount_minor} minor units` : row.amount_decimal} ${row.currency}${row.pricing_basis === 'registered_credit' ? ' per credit' : ''}`;
        if (field === 'active') return badge(Number(row.active) ? 'Active' : 'Inactive');
        if (field === 'status') return badge(value);
        if (field === 'account') return badge(row.owner_user_id ? 'Linked' : 'Not linked');
        if (['credits_milli', 'points_milli'].includes(field)) value = value === null ? '—' : Number(value) / 1000;
        if (index === 0) {
            const link = node('button', { class: 'record-link', type: 'button', onclick: () => task(() => openRecord(section, row)) }, text(value));
            if (row.given_name) return node('div', { class: 'person-cell' }, node('span', { class: 'avatar', 'aria-hidden': 'true' }, `${row.given_name[0] || ''}${row.family_name[0] || ''}`), link);
            return link;
        }
        return value;
    }
    $one('#directory-search-form').addEventListener('submit', event => {
        event.preventDefault();
        task(() => loadDirectory());
    });
    listen('#directory-more', 'click', () => loadDirectory(true));
    listen('#directory-create', 'click', () => current === 'applications' ? startApplication() : createRecord(current));
    document.addEventListener('click', event => { if (event.target.closest('[data-action="new-person"]')) task(() => createRecord('people')); });

    function openDrawer(title, eyebrow = 'CONNECTED RECORD') {
        relationalStudio?.cleanup();
        $one('#workspace-alert').hidden = true;
        $one('#drawer-title').textContent = title; $one('#drawer-eyebrow').textContent = eyebrow;
        const alreadyOpen = $one('#record-drawer').matches('.show, .showing');
        if (!alreadyOpen) drawerOpener = document.activeElement;
        const content = node('div');
        disposeActionMenus($one('#drawer-body'));
        $one('#drawer-body').replaceChildren(content); drawer.show();
        if (alreadyOpen) {
            $one('#drawer-title').tabIndex = -1;
            $one('#drawer-title').focus({ preventScroll: true });
        }
        return content;
    }
    $one('#record-drawer').addEventListener('hidden.bs.offcanvas', () => {
        if (!relationalStudio?.isOpen()) relationalStudio?.cleanup();
        disposeActionMenus($one('#drawer-body'));
        $one('#drawer-body').replaceChildren();
        if (relationalStudio?.isOpen()) return;
        if (drawerOpener?.isConnected && !drawerOpener.disabled) drawerOpener.focus();
        else $one('#workspace-main').focus({ preventScroll: true });
        drawerOpener = null;
    });
    async function createRecord(section, preset = {}, {
        back = null, backLabel = 'Back to public event', explanation = '', afterSave = null,
    } = {}) {
        if (section === 'grades') return degreeAuditStudio.gradingScale();
        if (section === 'terms') return academicCalendarStudio.newTerm();
        if (section === 'registration') return batchRegistration.start();
        if (section === 'reports') return relationalStudio.start();
        if (section === 'timesheets') return employmentStudio.submit(preset);
        if (section === 'bookings') return schedulingStudio.requestBooking(preset);
        if (section === 'rooms') return schedulingStudio.roomForm(null, preset);
        if (section === 'buildings') return schedulingStudio.buildingForm();
        if (section === 'bookingcategories') return schedulingStudio.categoryForm();
        if (section === 'journals') return operations.createJournal();
        const definition = datasets[section];
        const operation = ['people', 'billing'].includes(section) ? (await request('/sis/ui/operation-id/')).id : null;
        const body = openDrawer(definition.action, definition.title.toUpperCase());
        if (back) body.append(node('button', { type: 'button', class: 'btn btn-link mb-3',
            onclick: () => task(back) }, backLabel));
        if (explanation) body.append(node('p', { class: 'form-text' }, explanation));
        const form = await buildForm(definition.fields, section === 'billing' ? { pricing_basis: 'term', ...preset } : preset, { layout: section });
        if (!body.isConnected) return;
        if (section === 'billing') {
            const basis = form.elements.namedItem('pricing_basis');
            const label = form.querySelector('label[for="' + form.elements.namedItem('amount_minor').id + '"]');
            const help = node('p', { class: 'form-text', id: 'billing-pricing-help' });
            basis.setAttribute('aria-describedby', help.id);
            basis.after(help);
            const update = () => {
                label.childNodes[0].textContent = basis.value === 'registered_credit' ? 'Rate per credit ' : 'Amount per term ';
                help.textContent = basis.value === 'registered_credit'
                    ? 'Applies to future accepted registrations in this term and program scope. Credits and rate stay with each charge. Early drop/add reverses it; late drops retain it. Existing registrations are unchanged. Applicable rates and flat fees add together.'
                    : 'Charged once per student and billing term. Retiring a rule preserves its earlier charges.';
            };
            basis.addEventListener('change', update); update();
        }
        if (section === 'campaigns') {
            form.elements.subject.maxLength = 191;
            form.elements.body.maxLength = 100000;
        }
        if (section === 'portal') {
            const code = form.elements.namedItem('code');
            code.maxLength = 80; code.pattern = '[a-z0-9][a-z0-9-]{0,79}';
            code.setAttribute('aria-describedby', 'portal-code-help');
            code.after(node('p', { class: 'form-text', id: 'portal-code-help' }, 'Use lowercase letters, numbers and hyphens, for example fall-welcome.'));
            form.append(node('p', { class: 'form-text' }, 'Choose News for portal updates. Campus content requires sign-in; public news appears on the public website. Use Page builder to design public pages.'));
        }
        if (section === 'terms') {
            const code = form.elements.namedItem('code');
            code.maxLength = 22; code.pattern = String.raw`[A-Za-z0-9][A-Za-z0-9._\/\-]{0,21}`;
            code.setAttribute('aria-describedby', 'term-code-help');
            code.after(node('div', { class: 'form-text', id: 'term-code-help' }, 'Up to 22 letters or numbers, with dots, underscores, slashes or hyphens (for example, 26/FA). Codes are saved in uppercase.'));
            const deadline = form.elements.namedItem('add_drop_ends_on');
            deadline.setAttribute('aria-describedby', 'term-deadline-help');
            deadline.after(node('div', { class: 'form-text', id: 'term-deadline-help' }, 'Choose a date between the first and last day of the term.'));
        }
        form.append(node('p', { class: 'form-text mb-4' }, section === 'people' ? 'Saving creates a linked sign-in account and queues a private password-setup email. Student, employment and faculty records share this person.' : 'Save here and keep your place in the workspace.'));
        form.append(node('button', { class: 'btn btn-primary', type: 'submit' }, 'Save and continue ', icon('arrow-right')));
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!form.reportValidity()) return;
            const data = { ...formData(form, definition.fields), ...(operation ? { operation_id: operation } : {}) };
            if (section === 'people' && !data.institutional_id) delete data.institutional_id;
            const saved = await request(definition.endpoint, 'POST', data);
            notify('Saved. One less thing on your list.');
            if (afterSave) await afterSave(saved);
            else if (section === 'people') await openPerson(saved.id);
            else if (['campaigns', 'billing'].includes(section)) await openRecord(section, { id: saved.id });
            else { drawer.hide(); if (current === section) await loadDirectory(); }
        }, $one('[type="submit"]', form)); });
        body.append(form);
        return form;
    }

    // Shared private change-review paging. Each domain supplies only its approved card content.
    async function historyPanel({ body, endpoint, description, unknownText, emptyText, label, render }) {
        const panel = node('div');
        body.append(panel);
        const notice = node('p', { class: 'alert alert-info', hidden: true }, unknownText);
        const status = node('p', { role: 'status', class: 'form-text' }, 'Loading recorded changes…');
        const list = node('ul', { class: 'list-unstyled', 'aria-label': label });
        let after = 0, through = null, shown = 0, epoch = 0;
        const time = (heading, value) => node('p', { class: 'small mb-1' }, `${heading}: `,
            node('time', { datetime: value }, new Date(value).toLocaleString(undefined,
                { dateStyle: 'medium', timeStyle: 'long' })));
        async function load(older = false) {
            const expectedEpoch = ++epoch;
            const data = await request(endpoint, 'POST', older ? { after, through_version: through } : { after: 0 });
            if (!panel.isConnected || epoch !== expectedEpoch) return;
            if (!Array.isArray(data.items)) throw new Error('The history could not be loaded. Try refreshing it.');
            const cards = data.items.map(item => {
                const card = node('article', { class: 'record-section' },
                    node('h3', {}, item.category ? `${item.category} · ${item.action}` : item.action),
                    time('Recorded', item.recorded_at),
                    node('p', { class: 'small mb-2 private-text' }, `Recorded by: ${item.recorded_by}`), render(item));
                if (item.effective_at && !item.earlier_details_unknown) card.append(time('Effective', item.effective_at));
                return node('li', {}, card);
            });
            if (!older) { list.replaceChildren(); shown = 0; }
            list.append(...cards);
            shown += cards.length;
            through = data.through_version;
            after = data.next_after;
            notice.hidden = !data.earlier_history_unknown;
            more.hidden = after === null;
            status.textContent = through === 0 ? emptyText : `Showing ${shown} of ${through} recorded changes.`;
        }
        const control = (text, callback) => node('button', { type: 'button', class: 'btn btn-outline-secondary',
            onclick: event => task(callback, event.currentTarget) }, text);
        const refresh = control('Refresh history', () => load());
        const more = control('Load older changes', () => load(true));
        more.hidden = true;
        panel.append(node('p', { class: 'form-text' }, description), refresh, notice, status, list, more);
        await load();
    }

    const formLayouts = {
        decision_reason: [{ title: 'Decision reason', fields: ['reason'], wide: ['reason'] }],
        student_activity: [
            { title: 'Student activity', fields: ['active', 'effective_on'], wide: ['active'] },
            { title: 'Decision reason', fields: ['reason'], wide: ['reason'] },
        ],
        student_program: [
            {
                title: 'New program',
                fields: ['program_id', 'entry_term_id'],
                wide: ['program_id', 'entry_term_id'],
            },
            { title: 'Decision reason', fields: ['reason'], wide: ['reason'] },
        ],
        rooms: [
            {
                title: 'Room details',
                fields: ['code', 'capacity', 'name', 'building_id'],
                wide: ['name', 'building_id'],
            },
        ],
        agenda_window: [{ title: 'Agenda window', fields: ['starts_at', 'ends_at'] }],
        meeting_request: [
            {
                title: 'Meeting details',
                fields: ['title', 'category_id', 'section_id'],
                wide: ['title', 'category_id', 'section_id'],
            },
            { title: 'Location', fields: ['online', 'room_id'], wide: ['online', 'room_id'] },
            { title: 'Dates & times', fields: ['starts_at', 'ends_at'] },
        ],
        recurring_meeting: [
            {
                title: 'Meeting details',
                fields: ['title', 'category_id', 'section_id'],
                wide: ['title', 'category_id', 'section_id'],
            },
            { title: 'Location', fields: ['online', 'room_id'], wide: ['online', 'room_id'] },
            {
                title: 'Meeting pattern',
                fields: [
                    'from',
                    'through',
                    'start_time',
                    'end_time',
                    'overnight',
                    'interval_weeks',
                    'excluded_dates',
                ],
                wide: ['overnight', 'interval_weeks', 'excluded_dates'],
            },
        ],
        employment_dates: [
            { title: 'Employment period', fields: ['from', 'through', 'voided'], wide: ['voided'] },
            { title: 'Decision reason', fields: ['reason'], wide: ['reason'] },
        ],
        employment_profile: [
            { title: 'Employment details', fields: ['job_title', 'active'], wide: ['job_title', 'active'] },
            { title: 'Decision reason', fields: ['reason'], wide: ['reason'] },
        ],
        work_time: [
            {
                title: 'Work interval',
                fields: ['employee_id', 'starts_at', 'ends_at'],
                wide: ['employee_id'],
            },
        ],
        people: [
            { title: 'Person details', fields: ['given_name', 'family_name', 'email'], wide: ['email'] },
            { title: 'Directory privacy', fields: ['directory_restricted'], wide: ['directory_restricted'] },
            {
                title: 'Institutional ID',
                description: 'Leave blank to let etsis assign an ID.',
                fields: ['institutional_id'],
                wide: ['institutional_id'],
            },
        ],
        courses: [
            { title: 'Course essentials', fields: ['code', 'credits_milli', 'title'], wide: ['title'] },
        ],
        programs: [
            { title: 'Program essentials', fields: ['code', 'title', 'description'], wide: ['description'] },
        ],
        sections: [
            {
                title: 'Course & section',
                fields: ['course_id', 'term_id', 'code', 'capacity'],
                wide: ['course_id', 'term_id'],
            },
            {
                title: 'Teaching & registration',
                fields: ['delivery_mode', 'faculty_user_id', 'web_registration'],
                wide: ['faculty_user_id', 'web_registration'],
            },
            { title: 'Section fee', fields: ['fee_minor', 'currency'] },
        ],
    };
    async function buildForm(fields, preset = {}, { layout = null } = {}) {
        const form = node('form', { class: 'record-form' });
        const serial = ++formSerial;
        for (const [key, label, type = 'text', source = '', required = true] of fields) {
            const id = `input-${serial}-${key}`;
            const container = node('div', { class: 'mb-4' });
            const fieldLabel = node('label', { class: 'form-label', for: id }, label, required && type !== 'checkbox' ? node('span', { class: 'required-indicator', 'aria-hidden': 'true' }, ' *') : null);
            let control;
            if (['lookup', 'currency', 'choice'].includes(type)) {
                control = node('select', { class: 'form-select', id, name: key, required });
                const values = type === 'lookup' ? await options(source) : type === 'currency' ? currencyOptions.map(value => ({ value, label: value })) : source.map(value => typeof value === 'object' ? value : ({ value, label: value }));
                populate(control, values, required ? 'Choose…' : 'All / not specified');
                if (type === 'currency') control.value = 'USD';
            } else if (type === 'textarea') control = node('textarea', { class: 'form-control', id, name: key, rows: 5, maxlength: 10000, required });
            else if (type === 'checkbox') control = node('input', { class: 'form-check-input', id, name: key, type: 'checkbox' });
            else control = node('input', { class: 'form-control', id, name: key, type: ['credits', 'money', 'hundredths', 'signedmoney'].includes(type) ? 'number' : type === 'signed' ? 'number' : type, required, maxlength: 191, ...(type === 'signed' ? { min: -2000000000, max: 2000000000, step: 1 } : {}), ...(type === 'number' ? { min: 0, max: 100000, step: 1 } : {}), ...(['credits', 'money', 'hundredths', 'signedmoney'].includes(type) ? { min: type === 'signedmoney' ? -1000000 : 0, max: type === 'credits' ? 999 : 1000000, step: type === 'credits' ? '.001' : '.01', value: 0 } : {}) });
            const limits = { points_milli: [0, 10], severity: [0, 1000], course_limit: [1, 1000], minimum_grade_rank: [0, 10000], grade_rank: [0, 10000], possible_hundredths: [0.01, 10000], capacity: [1, 100000], amount_minor: [0.01, 1000000] };
            if (limits[key] && control.type === 'number') [control.min, control.max] = limits[key];
            if (['money', 'signedmoney'].includes(type)) control.dataset.money = key === 'amount_minor' ? 'positive' : type;
            if (preset[key] !== undefined) {
                if (type === 'checkbox') control.checked = preset[key] === true || Number(preset[key]) === 1;
                else {
                    if (type === 'lookup' && ![...control.options].some(option => option.value === preset[key])) control.append(node('option', { value: preset[key] }, 'Selected record'));
                    control.value = preset[key];
                }
            }
            if (type === 'checkbox') container.append(node('div', { class: 'form-check' }, control, fieldLabel)); else container.append(fieldLabel, control);
            if (type === 'lookup') container.append(searchableLookup(control, source));
            control.addEventListener('input', () => { control.removeAttribute('aria-invalid'); control.classList.remove('is-invalid'); });
            form.append(container);
        }
        function updateMoneyControls() {
            const digits = currencyDigits(form.elements.namedItem('currency')?.value || form.dataset.currency || 'USD');
            for (const input of form.querySelectorAll('[data-money]')) {
                input.step = digits === null ? 'any' : String(10 ** -digits);
                if (input.dataset.money === 'positive') input.min = digits === null ? '0' : String(10 ** -digits);
            }
        }
        form.addEventListener('change', updateMoneyControls);
        updateMoneyControls();
        const groups = [];
        for (const [index, definition] of (formLayouts[layout] || []).entries()) {
            const grid = node('div', { class: 'record-form-grid' });
            for (const key of definition.fields) {
                const field = form.elements.namedItem(key)?.closest('.mb-4');
                if (!field) continue;
                field.classList.add('record-form-field');
                field.classList.toggle('record-form-wide', definition.wide?.includes(key) || false);
                grid.append(field);
            }
            if (!grid.childElementCount) continue;
            const helpId = `form-${serial}-section-${index}-help`;
            groups.push(
                node(
                    'fieldset',
                    {
                        class: 'record-form-section',
                        ...(definition.description ? { 'aria-describedby': helpId } : {}),
                    },
                    node('legend', {}, definition.title),
                    definition.description
                        ? node('p', { id: helpId, class: 'form-text' }, definition.description)
                        : null,
                    grid,
                ),
            );
        }
        if (groups.length) {
            form.classList.add('record-form-structured');
            const required = fields.some(
                ([, , type = 'text', , required = true]) => required && type !== 'checkbox',
            );
            form.prepend(
                ...(required
                    ? [node('p', { class: 'record-form-guide' }, 'Fields marked * are required.')]
                    : []),
                ...groups,
            );
        }
        return form;
    }

    function formData(form, fields) {
        const result = {};
        for (const [key, , type = 'text', , required = true] of fields) {
            const element = form.elements.namedItem(key);
            let value = element.value;
            if (!required && !value && type !== 'checkbox') continue;
            if (type === 'checkbox') value = element.checked;
            else if (type === 'number' || type === 'signed') value = Number(value);
            else if (['money', 'signedmoney'].includes(type)) value = moneyInput(value, form.elements.namedItem('currency')?.value || form.dataset.currency || 'USD');
            else if (['credits', 'hundredths'].includes(type)) { const [whole, decimal = ''] = value.split('.'); const places = type === 'credits' ? 3 : 2; value = (Math.abs(Number(whole)) * 10 ** places + Number(decimal.padEnd(places, '0'))) * (whole.startsWith('-') ? -1 : 1); }
            else if (type === 'datetime-local') value = new Date(value).toISOString().replace('.000Z', '+00:00');
            result[key] = value;
        }
        return result;
    }

    async function openPerson(id) {
        const body = openDrawer('Person workspace', 'PERSON RECORD');
        body.append(node('p', { class: 'muted-text' }, 'Loading person record…'));
        const data = await request(`/sis/ui/people/${id}/`); const person = data.person; clear(body);
        body.append(node('div', { class: 'record-profile' }, node('span', { class: 'avatar large', 'aria-hidden': 'true' }, `${person.givenName[0]}${person.familyName[0]}`), node('div', {}, node('h3', {}, `${person.givenName} ${person.familyName}`), badge(person.institutionalId.value))));
        const tabs = node('div', { class: 'workspace-tabs', role: 'tablist', 'aria-label': 'Person record sections' });
        const panes = node('div');
        const available = [['overview', 'Overview'], ...(data.students ? [['students', 'Academics']] : []), ...(data.applications ? [['applications', 'Admissions']] : []), ...(data.accounts ? [['accounts', 'Accounts']] : []), ...(data.employees ? [['employees', 'Employment']] : []), ...(can('admin:access:manage') ? [['access', 'Access']] : [])];
        available.forEach(([key, label], index) => {
            const panel = node('div', { id: `person-panel-${key}`, role: 'tabpanel', 'aria-labelledby': `person-tab-${key}`, hidden: index !== 0 });
            const button = node('button', { type: 'button', id: `person-tab-${key}`, role: 'tab', 'aria-controls': panel.id, 'aria-selected': index === 0 ? 'true' : 'false', tabindex: index === 0 ? '0' : '-1', class: index === 0 ? 'active' : '' }, label);
            button.addEventListener('click', () => { [...tabs.children].forEach(tab => { tab.classList.toggle('active', tab === button); tab.setAttribute('aria-selected', tab === button ? 'true' : 'false'); tab.tabIndex = tab === button ? 0 : -1; }); [...panes.children].forEach(item => { item.hidden = item !== panel; }); });
            button.addEventListener('keydown', event => { if (['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) { event.preventDefault(); const all = [...tabs.children]; const next = all[event.key === 'Home' ? 0 : event.key === 'End' ? all.length - 1 : (all.indexOf(button) + (event.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length]; next.click(); next.focus(); } });
            if (key === 'overview') {
                panel.append(node('dl', { class: 'detail-list' }, node('div', {}, node('dt', {}, 'Institutional ID'), node('dd', {}, person.institutionalId.value)), node('div', {}, node('dt', {}, 'Sign-in account'), node('dd', {}, person.ownerUserId ? 'Linked' : 'Not linked'))));
                const actions = node('div', { class: 'inline-actions' });
                const extra = [];
                if (can('sis:person:manage') && (!person.ownerUserId || can('admin:edit:user'))) actions.append(node('button', { class: 'btn btn-outline-primary', type: 'button', onclick: () => task(() => peopleStudio.editProfile(person)) }, 'Edit person profile'));
                if (can('sis:person:manage')) actions.append(node('button', { class: 'btn btn-outline-primary', type: 'button', onclick: () => task(() => peopleStudio.contact(person)) }, 'Contact information'));
                if (can('sis:admissions:manage')) actions.append(node('button', { class: 'btn btn-primary', type: 'button', onclick: () => task(() => startApplication(person.id.value)) }, 'Start application'));
                if (person.ownerUserId && can('admin:edit:user')) extra.push({ label: 'Manage sign-in account', icon: 'key', run: () => peopleStudio.account(person) });
                if (can('sis:person:manage')) extra.push({ label: 'Account setup', icon: 'envelope', run: () => accountSetup(person) });
                if (can('sis:person:assign-id')) extra.push({ label: 'Change institutional ID', icon: 'person-vcard', run: () => editInstitutionalId(person) });
                if (can('sis:person:manage') && can('sis:audit:read')) extra.push({ label: 'Profile history', icon: 'clock-history', run: () => peopleStudio.history(person) });
                if (can('sis:hr:manage') && !data.employees?.length) extra.push({ label: 'Add employment', icon: 'person-workspace', run: () => createRecord('employees', { person_id: person.id.value }) });
                if (can('sis:moodle:link-person') || (can('sis:moodle:create-person') && can('sis:person:manage'))) {
                    if (extra.length) extra.push({ divider: true });
                    extra.push({ heading: 'Moodle' });
                    if (can('sis:moodle:link-person')) extra.push({ label: 'Moodle account link', icon: 'link-45deg', run: () => moodlePeople.open(person.id.value) });
                    if (can('sis:moodle:create-person') && can('sis:person:manage')) {
                        const createMoodle = node('button', { type: 'button', 'aria-label': 'Create a Moodle account', onclick: event => task(() => moodleRequests.open(person.id.value), event.currentTarget) }, icon('person-plus'), node('span', {}, 'Create Moodle account'));
                        extra.push(createMoodle);
                    }
                }
                if (extra.length) actions.append(actionMenu('More', extra, { context: 'More person actions' }));

                panel.append(actions, node('p', { class: 'muted-text' }, 'This institutional ID follows the person across student, employment and financial records. Sign-in access is attached to the same person.'));
            } else if (key === 'access') {
                if (person.ownerUserId) {
                    let loaded = false; let loading = false;
                    button.addEventListener('click', () => {
                        if (loaded || loading) return;
                        loading = true;
                        task(async () => { try { panel.replaceChildren(); await personAccess(panel, person.ownerUserId.value); loaded = true; } finally { loading = false; } });
                    });
                } else panel.append(node('p', { class: 'muted-text' }, 'Enable sign-in access on this person before assigning roles.'));
            } else {
                const rows = data[key] || [];
                if (!rows.length) panel.append(emptyState('Nothing here yet.', 'As this person’s journey grows, the connected records will appear here.'));
                else rows.forEach(row => {
                    const wrapper = node('div', { class: 'record-section' }, node('h3', {}, key === 'employees' ? row.job_title : key === 'applications' ? 'Application' : key === 'accounts' ? 'Student account' : 'Student record'));
                    if (row.status) wrapper.append(badge(row.status));
                    const action = node('button', { class: 'btn btn-sm btn-outline-primary mt-3', type: 'button', onclick: () => task(() => openRecord(key, { ...row, given_name: person.givenName, family_name: person.familyName })) }, 'Open workspace ', icon('arrow-right'));
                    wrapper.append(action); panel.append(wrapper);
                });
            }
            tabs.append(button); panes.append(panel);
        });
        body.append(tabs, panes);
    }

    async function accountSetup(person) {
        const body = openDrawer('Account password setup', 'ONE PERSON. ONE CAMPUS ACCOUNT.');
        const state = await request(`/sis/ui/people/${person.id.value}/password-setup/`);
        body.append(node('p', {}, state.pending ? `Password setup: ${state.state}. A setup link is delivered privately to the account’s email address.` : 'This account does not need an initial password setup.'));
        if (state.pending) body.append(node('button', { type: 'button', class: 'btn btn-primary', onclick: event => task(async () => {
            await request(`/sis/ui/people/${person.id.value}/password-setup/`, 'POST', {});
            notify('A new setup email is queued. Earlier links are no longer valid.'); await accountSetup(person);
        }, event.currentTarget) }, 'Send a new setup link'));
    }
    async function editInstitutionalId(person) {
        const body = openDrawer('Change institutional ID', 'PERSON IDENTITY');
        const form = await buildForm([['institutional_id', 'Institutional ID', 'text', '', false]], { institutional_id: person.institutionalId.value });
        form.append(node('p', { class: 'form-text' }, 'Leave blank to generate a new ID. Connected records resolve the current ID automatically.'), node('button', { type: 'submit', class: 'btn btn-primary mt-3' }, 'Save ID'));
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => { await request(`/sis/persons/${person.id.value}/institutional-id/`, 'POST', { institutional_id: form.elements.institutional_id.value, identity_version: person.identityVersion }); notify('Institutional ID updated across connected records.'); await openPerson(person.id.value); }, $one('[type="submit"]', form)); });
        body.append(form);
    }

    async function openRecord(section, row) {
        if (section === 'registration') return batchRegistration.start(row.id);
        if (['institutions', 'external', 'equivalencies'].includes(section)) return transferStudio.record(section, row.id);
        if (section === 'students') return studentStudio.student(row.id);
        if (section === 'employees') return employmentStudio.employee(row.id);
        if (section === 'timesheets') return employmentStudio.entry(row.id);
        if (section === 'rooms') return schedulingStudio.room(row.id);
        if (section === 'buildings') return schedulingStudio.building(row.id);
        if (section === 'bookingcategories') return schedulingStudio.categoryForm(row);
        if (section === 'bookings') return schedulingStudio.booking(row.id);
        if (['reports', 'sharedreports'].includes(section)) return reportStudio.record(row.id);
        if (section === 'people') return openPerson(row.id);
        if (section === 'applications') return peopleStudio.application(row);
        if (section === 'portal') return editCampusContent(row.id);
        if (section === 'transcripts') return reviewTranscript(row.id);
        const body = openDrawer(recordLabel(row), datasets[section]?.title.toUpperCase() || 'RECORD');
        const details = node('dl', { class: 'detail-list' });
        for (const [key, value] of Object.entries(row)) {
            if (key === 'id' || key.endsWith('_id') || ['given_name', 'family_name'].includes(key)) continue;
            const quantity = ['credits_milli', 'points_milli'].includes(key);
            const label = key === 'credits_milli' ? 'Credits' : key === 'points_milli' ? 'Grade points'
                : key === 'active' ? 'Status' : key === 'web_registration' ? 'Student self-registration'
                : key.replaceAll('_', ' ').replace(/^./u, value => value.toUpperCase());
            const formatted = value === null ? text(value)
                : quantity ? Number(value) / 1000
                : key === 'active' ? badge(Number(value) ? 'Active' : 'Inactive')
                : key === 'web_registration' ? (Number(value) ? 'Allowed' : 'Not enabled')
                : key === 'fee_minor' && row.currency ? money(value, row.currency)
                : text(value);
            details.append(node('div', {}, node('dt', {}, label), node('dd', {}, formatted)));
        }
        body.append(details);
        const actions = node('div', { class: 'inline-actions' });
        const action = (label, path, data = {}, success = 'Updated.', style = 'btn-outline-primary') => actions.append(node('button', { type: 'button', class: `btn ${style}`, onclick: event => task(async () => { await request(path, 'POST', data); notify(success); drawer.hide(); if (datasets[current]) await loadDirectory(); }, event.currentTarget) }, label));
        if (row.person_id && can('sis:person:manage')) actions.append(node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: () => task(() => openPerson(row.person_id)) }, 'Open person record'));
        body.append(actions);
        if (section === 'sections' && can('sis:scheduling:manage'))
            actions.append(node('button', { type: 'button', class: 'btn btn-outline-primary',
                onclick: () => task(() => schedulingStudio.requestSeries({ section_id: row.id, title: row.code })) },
                'Plan class meetings'));
        if (section === 'sections' && can('sis:scheduling:manage') && can('sis:catalog:manage'))
            actions.append(node('button', { type: 'button', class: 'btn btn-outline-primary',
                onclick: () => task(() => schedulingStudio.meetingPlan(row.id)) }, 'Meeting plan'));
        await operations.enhance(section, row, body);
    }

    async function editCampusContent(id) {
        const post = await request(`/sis/portal/editor/${id}/`);
        const body = openDrawer(post.title, 'CAMPUS PUBLISHING');
        const fields = datasets.portal.fields;
        const form = await buildForm(fields, post);
        form.elements.kind.disabled = true; form.elements.code.readOnly = true;
        form.append(node('p', { class: 'form-text' }, 'Campus content is visible after sign-in. Public news appears on the public portal. Saving an edit returns content to draft for review.'), node('button', { class: 'btn btn-primary', type: 'submit' }, 'Save draft'));
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!form.reportValidity()) return;
            await request('/sis/portal/editor/', 'POST', { ...formData(form, fields), id, version: Number(post.version) });
            notify('Draft saved. Review it before publishing.'); await editCampusContent(id); await loadDirectory();
        }, $one('[type="submit"]', form)); });
        body.append(badge(post.status), node('p', { class: 'mt-3' }, `Audience: ${post.audience === 'public' ? 'Public website' : 'Signed-in campus'}`), form);
        if (post.status !== 'archived') body.append(node('button', { class: 'btn btn-outline-primary mt-4', type: 'button', onclick: event => task(async () => {
            await request(`/sis/portal/posts/${id}/publication/`, 'POST', { publish: post.status !== 'published', version: Number(post.version) });
            notify(post.status === 'published' ? 'Content archived.' : 'Content published to its selected audience.'); drawer.hide(); await loadDirectory();
        }, event.currentTarget) }, post.status === 'published' ? 'Archive content' : 'Publish saved draft'));
        if (can('vihzhuo:manage')) body.append(node('a', { class: 'btn btn-link mt-4', href: '/admin/manager' }, 'Design public pages in Vihzhuo'));
    }

    async function reviewTranscript(id) {
        return transcriptStudio.record(id);
    }

    function fieldInput(field, value, prefix = 'answer') {
        const id = `${prefix}-${field.key}`;
        let control;
        const attributes = { id, name: field.key, required: field.required };
        if (field.type === 'select') { control = node('select', { ...attributes, class: 'form-select' }); populate(control, field.options.map(option => ({ value: option, label: option }))); }
        else if (field.type === 'textarea') control = node('textarea', { ...attributes, class: 'form-control', rows: 4, maxlength: 4000 });
        else control = node('input', { ...attributes, class: field.type === 'checkbox' ? 'form-check-input' : 'form-control', type: field.type, ...(field.type === 'number' ? { step: 1, min: -999999999, max: 999999999 } : {}), ...(field.type === 'text' || field.type === 'email' ? { maxlength: 191 } : {}) });
        if (value !== undefined) { if (field.type === 'checkbox') control.checked = Boolean(value); else control.value = value; }
        const label = node('label', { class: field.type === 'checkbox' ? 'form-check-label' : 'form-label', for: id }, field.label, field.required ? node('span', { class: 'required-indicator', 'aria-hidden': 'true' }, ' *') : null);
        const wrapper = node('div', { class: 'mb-4', 'data-answer-key': field.key });
        if (field.type === 'checkbox') wrapper.append(node('div', { class: 'form-check' }, control, label)); else wrapper.append(label, control);
        if (field.help) { const help = node('div', { class: 'form-text', id: `${id}-help` }, field.help); wrapper.append(help); control.setAttribute('aria-describedby', help.id); }
        return wrapper;
    }

    function evaluateGroup(group, facts) {
        const results = group.rules.map(rule => {
            if (rule.condition) return evaluateGroup(rule, facts);
            const actual = facts[rule.id];
            if (actual === undefined || actual === null || actual === '') return false;
            switch (rule.operator) {
                case 'equal': return actual === rule.value;
                case 'not_equal': return actual !== rule.value;
                case 'in': return rule.value.includes(actual);
                case 'not_in': return !rule.value.includes(actual);
                case 'less': return Number.isInteger(actual) && actual < rule.value;
                case 'greater': return Number.isInteger(actual) && actual > rule.value;
                default: return false;
            }
        });
        return group.condition === 'AND' ? results.every(Boolean) : results.some(Boolean);
    }

    function applicationAnswers(form, fields) {
        const answers = {};
        for (const field of fields) {
            const control = form.elements.namedItem(field.key);
            if (!control || control.disabled) continue;
            if (field.type === 'checkbox') answers[field.key] = control.checked;
            else if (field.type === 'number') { if (control.value !== '') answers[field.key] = Number(control.value); }
            else if (control.value !== '') answers[field.key] = control.value;
        }
        return answers;
    }

    function applicationControls(fields, preview = false) {
        const form = node('form', { class: 'application-fields', 'aria-label': preview ? 'Application form preview' : 'Application answers' });
        for (const field of fields) form.append(fieldInput(field, undefined, preview ? 'preview' : 'answer'));
        function updateVisibility() {
            // Conditions may only refer to preceding fields; evaluate in that same order as the server.
            const facts = {};
            for (const field of fields) {
                const wrapper = $one(`[data-answer-key="${field.key}"]`, form);
                const control = form.elements.namedItem(field.key);
                const visible = !field.show_when || evaluateGroup(field.show_when, facts);
                wrapper.hidden = !visible; control.disabled = !visible;
                if (visible) {
                    if (field.type === 'checkbox') facts[field.key] = control.checked;
                    else if (field.type === 'number' && control.value !== '') facts[field.key] = Number(control.value);
                    else if (control.value !== '') facts[field.key] = control.value;
                }
            }
        }
        form.addEventListener('input', updateVisibility); form.addEventListener('change', updateVisibility);
        updateVisibility();
        return form;
    }

    async function loadFormStudio() {
        if (!can('sis:forms:manage')) throw new Error('Form administration needs additional access.');
        const [programs, result] = await Promise.all([options('programs'), request('/sis/forms/')]);
        const selected = $one('#form-program').value;
        populate($one('#form-program'), programs); $one('#form-program').value = selected;
        installLookup('#form-program', 'programs');
        const library = $one('#form-library-list'); clear(library);
        if (!result.items.length) library.append(emptyState('Your first welcome starts here.', 'Create a form and enable it for an academic program.', 'ui-checks'));
        for (const form of result.items) {
            const button = node('button', { class: 'form-library-item', type: 'button', onclick: () => task(async () => {
                if (dirty) { pendingNavigation = async () => loadDraft(form.id); leaveModal.show(); return; }
                await loadDraft(form.id);
            }) }, node('strong', {}, form.name), badge(form.enabled_id ? 'Enabled' : form.status), node('small', {}, `${form.program} · ${form.status === 'published' ? 'Open to create a new revision' : 'Continue editing draft'}`));
            const item = node('div', {}, button);
            if (form.enabled_id && can('sis:forms:publish')) item.append(node('button', { class: 'btn btn-sm btn-outline-secondary ms-3 mb-3', type: 'button', 'aria-label': `Disable ${form.name}`, onclick: event => task(async () => {
                if (!window.confirm(`Stop accepting new applications through “${form.name}”? Existing submissions will be preserved.`)) return;
                await request(`/sis/forms/programs/${form.program_id}/disable/`, 'POST', {});
                notify('Application form disabled. Existing submissions are preserved.'); await loadFormStudio();
            }, event.currentTarget) }, 'Disable application form'));
            library.append(item);
        }
        if (!formDraft.fields.length) renderDesigner();
    }

    async function loadDraft(id) {
        const record = await request(`/sis/forms/${id}/`);
        formDraft = { ...record, id: record.status === 'published' ? null : record.id, version: record.status === 'published' ? null : record.version, status: 'draft', fields: structuredClone(record.fields) };
        $one('#form-name').value = record.name;
        const programSelect = $one('#form-program');
        if (![...programSelect.options].some(option => option.value === record.program_id)) programSelect.append(node('option', { value: record.program_id }, 'Selected program'));
        programSelect.value = record.program_id;
        $one('#designer-state').textContent = record.status === 'published' ? 'New revision' : 'Draft';
        $one('#designer-save-status').textContent = record.status === 'published' ? 'The published form stays unchanged. Save this as a new revision.' : 'You are editing an unpublished draft.';
        dirty = false; renderDesigner();
    }

    function renderDesigner() {
        const container = $one('#designer-fields'); clear(container);
        if (!formDraft.fields.length) container.append(emptyState('A blank canvas, full of possibility.', 'Add the fields your program needs. Applicants will see a clean, accessible form.', 'layout-text-window'));
        formDraft.fields.forEach((field, index) => {
            const heading = node('div', { class: 'designer-field-header' }, node('span', { class: 'field-order' }, String(index + 1).padStart(2, '0')), node('strong', {}, field.type === 'select' ? 'Dropdown choice' : field.type === 'textarea' ? 'Long answer' : field.type));
            const controls = node('div', { class: 'field-controls' });
            for (const [label, name, delta] of [['Move field up', 'arrow-up', -1], ['Move field down', 'arrow-down', 1]]) {
                controls.append(node('button', { class: 'icon-button', type: 'button', 'aria-label': `${label}: ${field.label || `field ${index + 1}`}`, disabled: index + delta < 0 || index + delta >= formDraft.fields.length, onclick: () => {
                    [formDraft.fields[index], formDraft.fields[index + delta]] = [formDraft.fields[index + delta], formDraft.fields[index]];
                    // The server rejects forward references. A moved field's visibility can be adjusted here before save.
                    dirty = true; renderDesigner();
                } }, icon(name)));
            }
            controls.append(node('button', { class: 'icon-button', type: 'button', 'aria-label': `Remove field ${index + 1}`, onclick: () => { formDraft.fields.splice(index, 1); dirty = true; renderDesigner(); } }, icon('trash3')));
            heading.append(controls);
            const content = node('div', { class: 'designer-field-body row g-3' });
            const input = (key, label, width, type = 'text') => {
                const id = `designer-${index}-${key}`;
                const control = node(type === 'textarea' ? 'textarea' : 'input', { class: 'form-control', id, value: type === 'textarea' ? undefined : field[key] || '', maxlength: key === 'help' ? 300 : key === 'key' ? 40 : 120, ...(type === 'textarea' ? { rows: 3 } : { type: 'text' }) });
                if (type === 'textarea') control.value = field.options.join('\n');
                control.addEventListener('input', () => { if (type === 'textarea') field.options = control.value.split('\n').filter(value => value.trim()); else field[key] = control.value; dirty = true; });
                return node('div', { class: `col-md-${width}` }, node('label', { class: 'form-label', for: id }, label), control);
            };
            content.append(input('label', 'Question or field label', 7), input('key', 'Stable field key', 5), input('help', 'A helpful hint (optional)', 12));
            if (field.type === 'select') content.append(input('options', 'Choices (one per line)', 12, 'textarea'));
            const required = node('input', { class: 'form-check-input', type: 'checkbox', id: `field-required-${index}`, checked: field.required, onchange: event => { field.required = event.target.checked; dirty = true; } });
            content.append(node('div', { class: 'col-12' }, node('div', { class: 'form-check' }, required, node('label', { class: 'form-check-label', for: required.id }, 'An answer is required'))));
            if (index > 0) {
                const conditionBox = node('input', { class: 'form-check-input', type: 'checkbox', id: `field-condition-${index}`, checked: Boolean(field.show_when) });
                const conditionRow = node('div', { class: 'row g-2 mt-2', hidden: !field.show_when });
                const prior = node('select', { class: 'form-select', id: `field-condition-source-${index}`, 'aria-label': 'Show when this earlier field' });
                populate(prior, formDraft.fields.slice(0, index).map(item => ({ value: item.key, label: item.label || item.key })), 'Choose an earlier field');
                const expected = node('input', { class: 'form-control', type: 'text', id: `field-condition-value-${index}`, 'aria-label': 'Equals this value', placeholder: 'Equals this value', maxlength: 191 });
                const rule = field.show_when?.rules?.[0]; if (rule && !rule.condition) { prior.value = rule.id; expected.value = text(rule.value); }
                function updateCondition() {
                    if (!conditionBox.checked) { field.show_when = null; conditionRow.hidden = true; }
                    else {
                        conditionRow.hidden = false;
                        const source = formDraft.fields.find(item => item.key === prior.value);
                        let value = expected.value;
                        if (source?.type === 'checkbox') value = value === 'true' || value === '1';
                        else if (source?.type === 'number') value = Number(value);
                        field.show_when = { condition: 'AND', rules: [{ id: prior.value, operator: 'equal', value }] };
                    }
                    dirty = true;
                }
                conditionBox.addEventListener('change', updateCondition); prior.addEventListener('change', updateCondition); expected.addEventListener('input', updateCondition);
                conditionRow.append(node('div', { class: 'col-md-6' }, prior), node('div', { class: 'col-md-6' }, expected));
                content.append(node('div', { class: 'col-12' }, node('div', { class: 'form-check' }, conditionBox, node('label', { class: 'form-check-label', for: conditionBox.id }, 'Show only when an earlier answer matches')), conditionRow));
            }
            container.append(node('div', { class: 'designer-field' }, heading, content));
        });
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-add-field]');
        if (!button) return;
        if (formDraft.fields.length >= 40) return showError(new Error('An application form supports up to 40 fields.'));
        const type = button.dataset.addField;
        const next = formDraft.fields.length + 1;
        formDraft.fields.push({ key: `field_${next}_${Date.now().toString(36).slice(-4)}`, label: '', help: '', type, required: false, options: type === 'select' ? ['Option one', 'Option two'] : [], show_when: null });
        dirty = true; renderDesigner();
        $one('.designer-field:last-child input', $one('#designer-fields'))?.focus();
    });
    listen('#form-designer-meta', 'input', () => { dirty = true; });
    listen('#form-designer-meta', 'change', () => { dirty = true; });
    listen('#form-new', 'click', () => {
        const reset = () => { formDraft = { id: null, version: null, status: 'draft', fields: [] }; $one('#form-name').value = ''; $one('#form-program').value = ''; dirty = false; $one('#designer-state').textContent = 'New draft'; renderDesigner(); $one('#form-name').focus(); };
        if (dirty) { pendingNavigation = reset; leaveModal.show(); } else reset();
    });
    async function saveDraft() {
        if (!$one('#form-designer-meta').reportValidity()) return false;
        if (!formDraft.fields.length) throw new Error('Add at least one field before saving your form.');
        const record = await request('/sis/forms/', 'POST', { id: formDraft.id || undefined, version: formDraft.version ?? undefined, program_id: $one('#form-program').value, name: $one('#form-name').value, fields: formDraft.fields });
        formDraft = record; dirty = false;
        $one('#designer-state').textContent = 'Draft saved'; $one('#designer-save-status').textContent = 'Saved. Your draft is ready to preview or publish.';
        await loadFormStudio(); notify('Draft saved. A good start.'); return true;
    }
    listen('#form-save', 'click', saveDraft);
    listen('#form-publish', 'click', async () => {
        if (dirty || !formDraft.id) { if (!await saveDraft()) return; }
        await request(`/sis/forms/${formDraft.id}/publish/`, 'POST', { version: formDraft.version });
        notify('Published. This program now uses your new application form.');
        $one('#designer-state').textContent = 'Published'; $one('#designer-save-status').textContent = 'Published forms stay unchanged. Open one from the library to create a new revision.';
        formDraft = { ...formDraft, id: null, version: null, status: 'draft' }; dirty = false; await loadFormStudio();
    });
    listen('#form-preview', 'click', () => {
        const body = openDrawer($one('#form-name').value || 'Application preview', 'AS YOUR APPLICANT WILL SEE IT');
        body.append(node('p', { class: 'muted-text' }, 'Preview only. Nothing you enter here is submitted.'), applicationControls(formDraft.fields, true));
    });

    async function startApplication(personId) {
        const body = openDrawer('Start an application', 'A NEW BEGINNING');
        const fields = [...(personId ? [] : [['person_id', 'Person', 'lookup', 'people']]), ['program_id', 'Academic program', 'lookup', 'programs'], ['entry_term_id', 'Entry term', 'lookup', 'terms']];
        const selector = await buildForm(fields);
        selector.append(node('button', { class: 'btn btn-primary', type: 'submit' }, 'Open program application ', icon('arrow-right')));
        selector.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!selector.reportValidity()) return;
            const data = formData(selector, fields); const form = await request(`/sis/forms/programs/${data.program_id}/active/`);
            clear(body); await renderSubmission(body, form, personId || data.person_id, data.entry_term_id);
        }, $one('[type="submit"]', selector)); });
        body.append(selector);
    }
    async function loadApplicantSelect() {
        const [programs, terms] = await Promise.all([options('programs'), options('terms')]);
        populate($one('#apply-program'), programs); populate($one('#apply-term'), terms);
        installLookup('#apply-program', 'programs'); installLookup('#apply-term', 'terms');
    }
    listen('#application-select-form', 'submit', async () => {
        const person = await request('/sis/ui/me/');
        const form = await request(`/sis/forms/programs/${$one('#apply-program').value}/active/`);
        const container = $one('#applicant-form'); clear(container); await renderSubmission(container, form, person.id, $one('#apply-term').value);
    });
    async function renderSubmission(container, form, personId, termId) {
        container.append(node('h3', { class: 'mb-4' }, form.name));
        const answers = applicationControls(form.fields);
        answers.append(node('p', { class: 'form-text' }, 'Your application is private. Required fields are marked with an asterisk.'), node('button', { type: 'submit', class: 'btn btn-primary mt-3' }, 'Submit application ', icon('send')));
        answers.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!answers.reportValidity()) return;
            await request(`/sis/forms/${form.id}/submissions/`, 'POST', { submission_id: form.submission_id, person_id: personId, entry_term_id: termId, answers: applicationAnswers(answers, form.fields) });
            clear(container); container.append(emptyState('You’re one step closer.', 'Your application has been submitted. The admissions team can now review your program-specific form.', 'check2-circle'));
            notify('Application submitted. Here’s to your next chapter.');
        }, $one('[type="submit"]', answers)); });
        container.append(answers);
    }



    let ruleInitialized = false;
    let ruleMode = 'students';
    listen('#course-rule-mode', 'click', async () => {
        ruleMode = 'courses';
        $one('#student-rules-panel').hidden = true;
        $one('#course-rule-studio').hidden = false;
        $one('#student-rule-mode').setAttribute('aria-pressed', 'false');
        $one('#course-rule-mode').setAttribute('aria-pressed', 'true');
        await courseRuleStudio.open();
    });
    listen('#student-rule-mode', 'click', async () => {
        ruleMode = 'students';
        $one('#student-rules-panel').hidden = false;
        $one('#course-rule-studio').hidden = true;
        $one('#student-rule-mode').setAttribute('aria-pressed', 'true');
        $one('#course-rule-mode').setAttribute('aria-pressed', 'false');
        await loadRuleStudio();
    });
    async function loadRuleStudio() {
        if (!can('sis:rules:manage')) throw new Error('Rule administration needs additional access.');
        if (ruleMode === 'courses') return courseRuleStudio.open();
        if (!ruleInitialized) {
            const [programs, terms] = await Promise.all([options('programs'), options('terms')]);
            const values = items => Object.fromEntries(items.map(item => [item.value, escapeLabel(item.label)]));
            $('#student-rule-builder').queryBuilder({
                allow_groups: 4, allow_empty: false, plugins: [],
                filters: [
                    { id: 'program_id', label: 'Academic program', type: 'string', input: 'select', values: values(programs), operators: ['equal', 'not_equal', 'in', 'not_in'] },
                    { id: 'term_id', label: 'Entry term', type: 'string', input: 'select', values: values(terms), operators: ['equal', 'not_equal', 'in', 'not_in'] },
                    { id: 'active', label: 'Active student', type: 'boolean', input: 'select', values: { 1: 'Yes', 0: 'No' }, operators: ['equal', 'not_equal'] },
                ],
                lang: { add_rule: 'Add condition', add_group: 'Add group', conditions: { AND: 'All (AND)', OR: 'Any (OR)' } },
            });
            $('#student-rule-builder').on('afterAddRule.queryBuilder afterUpdateRuleFilter.queryBuilder afterUpdateRuleOperator.queryBuilder afterSetRules.queryBuilder', accessibleRules);
            accessibleRules(); ruleInitialized = true;
        }
        const rules = await records('rules');
        populate($one('#rule-saved'), rules.items.map(item => ({ value: item.id, label: item.name })), 'Start a new rule');
    }
    function accessibleRules() {
        $all('.rule-filter-container select', $one('#student-rule-builder')).forEach(select => select.setAttribute('aria-label', 'Condition field'));
        $all('.rule-operator-container select', $one('#student-rule-builder')).forEach(select => select.setAttribute('aria-label', 'Comparison'));
        $all('.rule-value-container input, .rule-value-container select', $one('#student-rule-builder')).forEach(input => input.setAttribute('aria-label', 'Condition value'));
        $all('.rules-group-header input', $one('#student-rule-builder')).forEach(input => input.setAttribute('aria-label', input.value === 'AND' ? 'Match all conditions' : 'Match any condition'));
    }
    function getRules() { const rules = $('#student-rule-builder').queryBuilder('getRules'); if (!rules) throw new Error('Complete each rule before continuing.'); return rules; }
    listen('#rule-preview', 'click', async () => {
        const result = await request('/sis/rules/preview/', 'POST', { rules: getRules() });
        $one('#rule-results-surface').hidden = false;
        $one('#rule-result-count').textContent = `${number(result.items.length)} matching students${result.items.length === 500 ? ' (first 500 shown)' : ''}.`;
        $one('#rule-results').replaceChildren(result.items.length ? table(['Institutional ID', 'Program', 'Entry term', 'Active'], result.items.map(item => [item.institutional_id, item.program, item.term, Number(item.active) ? 'Yes' : 'No']), 'Rule preview results') : emptyState('No matches just yet.', 'Try adjusting a condition to find the audience you need.', 'search'));
    });
    listen('#rule-save', 'click', async () => {
        if (!$one('#rule-name').value.trim()) throw new Error('Give your rule a name before saving.');
        await request('/sis/rules/', 'POST', { name: $one('#rule-name').value, rules: getRules() }); notify('Rule saved. Ready to use again.'); await loadRuleStudio();
    });
    listen('#rule-saved', 'change', async () => {
        const id = $one('#rule-saved').value;
        if (!id) { $('#student-rule-builder').queryBuilder('reset'); $one('#rule-name').value = ''; return; }
        const record = await request(`/sis/rules/${id}/`); $('#student-rule-builder').queryBuilder('setRules', record.rules); $one('#rule-name').value = record.name; accessibleRules();
    });

    async function loadAccess() { await accessStudio.load(); $one('#access-roles').prepend(node('button', { type: 'button', class: 'btn btn-outline-primary mb-3', onclick: event => task(() => restrictionStudio.officeSetup(), event.currentTarget) }, 'Offices & delegation')); }
    async function createAccessDefinition(kind) {
        const body = openDrawer(kind === 'roles' ? 'Create a role' : 'Create a permission', 'A LITTLE MORE FLEXIBILITY');
        const fields = [['name', kind === 'roles' ? 'Role identifier' : 'Permission identifier'], ['description', 'Description', 'text', '', false]];
        const form = await buildForm(fields); form.append(node('p', { class: 'form-text' }, 'Use lowercase letters, digits, colons, underscores or hyphens. Identifiers stay stable; descriptions can explain the purpose.'), node('button', { class: 'btn btn-primary mt-3', type: 'submit' }, 'Create'));
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => { if (!form.reportValidity()) return; await request(`/sis/access/${kind}/`, 'POST', formData(form, fields)); notify('Created. Ready to assign.'); drawer.hide(); await loadAccess(); }, $one('[type="submit"]', form)); });
        body.append(form);
    }
    listen('#finance-reviews-open', 'click', () => financeReviewStudio.queue());
    listen('#access-new-role', 'click', () => createAccessDefinition('roles'));
    listen('#access-new-permission', 'click', () => createAccessDefinition('permissions'));

    function definitionActions(body, kind, definition) {
        const edit = node('button', { type: 'button', class: 'btn btn-outline-primary mt-3', onclick: () => task(async () => {
            const surface = openDrawer(`Edit ${definition.name}`, 'CUSTOM ACCESS DEFINITION');
            const fields = [['description', 'Description', 'text', '', false]];
            const form = await buildForm(fields, definition);
            const save = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Save description'); form.append(save);
            form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
                await request(`/sis/access/${kind}/${definition.id}/`, 'PATCH', { description: form.elements.description.value });
                notify('Description updated.'); drawer.hide(); await loadAccess();
            }, save); });
            surface.append(form);
        }) }, 'Edit description');
        const remove = node('button', { type: 'button', class: 'btn btn-outline-danger mt-3', onclick: () => {
            const surface = openDrawer(`Delete ${definition.name}?`, 'CUSTOM ACCESS DEFINITION');
            surface.append(node('p', {}, 'Deleting this definition also removes its assignments. Review affected access before confirming.'));
            surface.append(node('button', { type: 'button', class: 'btn btn-danger', onclick: event => task(async () => {
                await request(`/sis/access/${kind}/${definition.id}/`, 'DELETE'); notify('Custom definition deleted.'); drawer.hide(); await loadAccess();
            }, event.currentTarget) }, 'Confirm deletion'));
        } }, 'Delete definition');
        body.append(node('div', { class: 'inline-actions' }, edit, remove));
    }
    async function personAccess(container, userId) { return accessStudio.person(container, userId); }

    listen('#global-search-open', 'click', () => searchModal.show());
    $one('#search-modal').addEventListener('shown.bs.modal', () => $one('#person-search').focus());
    document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && can('sis:person:manage')) { event.preventDefault(); searchModal.show(); } });
    listen('#person-search-form', 'submit', async () => {
        const result = await records('people', $one('#person-search').value);
        const results = $one('#person-search-results'); clear(results);
        if (!result.items.length) results.append(emptyState('No match yet.', 'Try a given name, family name, or institutional ID.', 'search'));
        result.items.forEach(person => results.append(node('button', { class: 'form-library-item', type: 'button', onclick: () => { searchModal.hide(); task(() => openPerson(person.id)); } }, node('strong', {}, `${person.given_name} ${person.family_name}`), node('small', {}, person.institutional_id))));
    });
    task(() => navigate(current, false));
})();
