/* Campus workspace. Private records stay in memory; browser storage holds disclosure preferences only. */
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
    let surfaces = null;
    let currentRecordReturn = null;
    let drawerTaskNavigation = null;
    let personTabsObserver = null;
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
    let formDraft = { id: null, version: null, status: 'draft', fields: [], layout: [] };
    let savingFormDraft = null;
    const collapsedFormFields = new Set();
    const expandedFormSettings = new Set();
    const collapsedContainerSettings = new Set();
    const formPreferences = window.createApplicationFormPreferences();
    let formPresentation = formPreferences.transient(), formDisplayTouched = false;
    function rememberFormPresentation() {
        if (!formDisplayTouched) return;
        formPresentation.write({
            fields: formDraft.fields.map(field => collapsedFormFields.has(field.key)),
            settings: formDraft.fields.map(field => expandedFormSettings.has(field.key)),
            containers: (formDraft.layout || []).map(container => !collapsedContainerSettings.has(container.key)),
        });
    }
    const formEdits = { past: [], future: [], current: null, group: null };
    let formConditions = null;
    let formLoadSerial = 0;
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
        if (tag === 'button' && children.length === 1 && typeof children[0] === 'string')
            surfaces?.decorateButton(element, children[0]);
        return element;
    }

    surfaces = window.createWorkspaceSurfaces({node, task, bootstrap});

    async function send(path, method = 'GET', data, accept = 'application/json') {
        const multipart = typeof FormData !== 'undefined' && data instanceof FormData;
        const response = await fetch(path, {
            method, credentials: 'same-origin', cache: 'no-store', redirect: 'error',
            // The router's legacy input parser accepts form strings, but crashes on JSON numbers/booleans.
            // Private middleware restores the typed object before CSRF, firewall and controller handling.
            headers: { Accept: accept, ...(method === 'GET' ? {} : { ...(!multipart ? { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' } : {}), 'X-CSRF-Token': csrf() }) },
            body: method === 'GET' ? undefined : multipart ? data : new URLSearchParams({ _workspace_json: JSON.stringify(data || {}) }).toString(),
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
    async function requestHtml(path, data) {
        const response = await send(path, 'POST', data, 'text/html');
        if (!response.ok) return jsonResponse(response);
        if (!response.headers.get('Content-Type')?.startsWith('text/html'))
            throw new Error('The document preview could not be prepared.');
        const file = await response.blob();
        if (file.size > 1024 * 1024) throw new Error('The document preview exceeded its permitted size.');
        return file.text();
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
        surfaces?.disposeMenus(root);
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
            if (!control.querySelector('.bi')) control.prepend(icon('arrow-right'));
            if (danger) control.classList.add('workspace-danger');
            control.addEventListener('click', () => {
                bootstrap.Dropdown.getInstance(toggle)?.hide();
                toggle.focus({ preventScroll: true });
            }, { capture: true });
            items.append(node('li', {}, control));
        }
        const wrapper = node('div', { class: 'dropdown workspace-action-menu' }, toggle, items);
        // Fixed positioning keeps row actions inside the viewport when a table scrolls horizontally.
        surfaces.dropdown(toggle);
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
        sections: { title: 'Course sections', description: 'Turn your course catalog into a schedule that works.', icon: 'collection', action: 'Offer a section', endpoint: '/sis/sections/', permission: 'sis:catalog:manage', fields: [['course_id', 'Course', 'lookup', 'courses'], ['term_id', 'Term', 'lookup', 'terms'], ['code', 'Section code'], ['capacity', 'Seats', 'number'], ['minimum_enrollment', 'Minimum enrollment target', 'number', '', false], ['delivery_mode', 'Delivery mode', 'choice', deliveryModes], ['faculty_user_id', 'Faculty account (optional)', 'lookup', 'faculty', false], ['web_registration', 'Allow student self-registration', 'checkbox'], ['fee_minor', 'Section fee', 'money'], ['currency', 'Currency', 'currency']], columns: [['Section', 'code'], ['Seats', 'capacity'], ['Status', 'active']] },
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
        portal: { title: 'Campus content', description: 'A useful, welcoming home for campus news and information.', icon: 'window', action: 'Create content', endpoint: '/sis/portal/editor/', permission: 'sis:portal:manage', fields: [['kind', 'Content type', 'choice', [{ value: 'news', label: 'News update' }, { value: 'page', label: 'Text page' }, { value: 'link', label: 'Website link' }]], ['code', 'Unique code'], ['title', 'Title'], ['content', 'Content', 'textarea'], ['audience', 'Audience', 'choice', [{ value: 'campus', label: 'Signed-in campus' }, { value: 'public', label: 'Public website' }]]], columns: [['Title', 'title'], ['Type', 'kind'], ['Status', 'status']] },
    };
    datasets.transcripts = { title: 'Transcript requests', description: 'Review a request, prepare the official record, and record delivery after it is complete.', icon: 'file-earmark-text', permission: 'sis:transcript:manage', columns: [['Request', 'short_id'], ['Delivery', 'delivery'], ['Status', 'status'], ['Requested', 'requested_at']] };
    const historyChecks = window.createWorkspaceHistoryChecks({ node, request, task, openDrawer });
    const operations = window.createWorkspaceOperations({ waitlists: () => waitlistStudio, historyChecks: () => historyChecks, node, request, task, can, table, badge, money, moneyInput, recordSummary, actionMenu, disposeActionMenus, buildForm, formData, openDrawer, notify, openRecord, createRecord, navigate, datasets, courseDrops: () => courseDropStudio, catalog: () => catalogStudio, classifications: () => classificationStudio, prerequisites: () => prerequisiteStudio, programs: () => programStudio, degreeAudit: () => degreeAuditStudio, calendar: () => academicCalendarStudio, batchRegistration: () => batchRegistration, teaching: () => teachingStudio, restrictions: () => restrictionStudio, closeDrawer: () => drawer.hide(), refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const courseDropStudio = window.createWorkspaceCourseDrops({ node, request, task, openDrawer, notify, buildForm, formData });
    const academicCalendarStudio = window.createWorkspaceAcademicCalendar({ node, request, task, buildForm, formData, openDrawer, table, badge, notify, recordSummary, refreshTerms: async () => { drawer.hide(); if (datasets[current]) await loadDirectory(); } });
    const degreeAuditStudio = window.createWorkspaceDegreeAudit({ taskNavigation: surfaces.taskNavigation, node, request, task, can, closeDrawer: () => drawer.hide() });
    const courseRuleStudio = window.createWorkspaceCourseRules({ node, request, task, can, openDrawer, closeDrawer: () => drawer.hide(), table });
    const catalogStudio = window.createWorkspaceCatalog({ prerequisites: () => prerequisiteStudio, templates: () => offeringTemplates, deliveryModes, node, request, task, buildForm, formData, openDrawer, table, money, openRecord, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const prerequisiteStudio = window.createWorkspacePrerequisites({ node, request, task, openDrawer, catalog: () => catalogStudio });
    const classificationStudio = window.createWorkspaceClassifications({ node, request, task, buildForm, formData, openDrawer, actionMenu, badge, publications: course => catalogStudio.publications(course), sectionPublications: section => catalogStudio.sectionPublications(section) });
    const programStudio = window.createWorkspacePrograms({ node, request, task, buildForm, formData, openDrawer, degreeAudit: () => degreeAuditStudio, references: () => classificationStudio, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const offeringTemplates = window.createWorkspaceOfferingTemplates({ node, request, task, table, badge, money, actionMenu, buildForm, formData, openDrawer, notify, catalog: () => catalogStudio });
    const waitlistStudio = window.createSectionWaitlist({ node, request, task, table, can, openDrawer,
        register: (student, section, back) => batchRegistration.single(student, back, { section_id: section }) });
    const batchRegistration = window.createWorkspaceRegistration({ node, request, task, can, table, money, recordSummary, buildForm, openDrawer, closeDrawer: () => drawer.hide(), refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const moodleStudio = window.createWorkspaceMoodle({ node, request, task, can });
    const moodlePeople = window.createWorkspaceMoodlePeople({ node, request, task, can, openDrawer, openPerson });
    const moodleRequests = window.createWorkspaceMoodleRequests({ node, request, task, can, openDrawer, openPerson });
    const accessStudio = window.createWorkspaceAccess({ node, request, task, openDrawer, badge, notify, definitionActions, offices: () => restrictionStudio.officeSetup({ label: 'Back to roles & access', run: async () => { drawer.hide(); await loadAccess(); } }) });
    const peopleStudio = window.createWorkspacePeople({ documentHoldLabels: config.document_hold_labels, documentRetentionLabels: config.document_retention_labels, closeDrawer: () => drawer.hide(), node, request, task, openDrawer, buildForm, formData, notify, can, openPerson, badge, recordSummary, actionMenu, historyPanel, messageRecord: operations.messageRecord, flow: operations.flow, loginUrl: config.login_url || '/login/' });
    const teachingStudio = window.createWorkspaceTeaching({ actionMenu, node, request, requestFile, task, can, recordSummary, buildForm, formData, openDrawer, openRecord, table, badge, flow: operations.flow });
    const schedulingStudio = window.createWorkspaceScheduling({ openCalendar: () => { drawer.hide(); return navigate('calendar', current !== 'calendar'); }, node, request, task, can, recordSummary, actionMenu, showAudit: (dataset, id) => operations.audit(dataset, { id }), openDrawer, buildForm, formData, notify, table, badge, financeReviews: () => financeReviewStudio, flow: operations.flow, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve(),
        composeAnnouncement: (preset, back) => createRecord('campaigns', preset, { back,
            explanation: 'Review this copy of the published invitation and dates before saving. Later event changes are not copied into this message. Saving creates an encrypted draft; choose recipients and confirm delivery in Communications.' }) });
    const employmentStudio = window.createWorkspaceEmployment({ node, request, task, can, recordSummary, actionMenu, showAudit: (dataset, id) => operations.audit(dataset, { id }), openDrawer, openPerson, buildForm, formData, notify, table, badge, historyPanel, positions: () => positionStudio, financeReviews: () => financeReviewStudio, flow: operations.flow, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const timeReviewPolicy = window.createTimeReviewPolicy({ node, request, task, openDrawer, table, recordSummary, backToWorkspace: () => drawer.hide() });
    const hrSetup = window.createWorkspaceHrSetup({ node, request, task, openDrawer, badge, notify, actionMenu });
    const positionStudio = window.createWorkspacePositions({ node, request, task, can, openDrawer, recordSummary, actionMenu, badge,
        flow: operations.flow, amounts: hrSetup.amounts, openEmployee: id => employmentStudio.employee(id), notify });
    const restrictionStudio = window.createWorkspaceRestrictions({ node, request, task, can, recordSummary, actionMenu, buildForm, formData, notify, openDrawer, openRecord, table, badge, flow: operations.flow, showAudit: (studentId, holdId) => operations.audit('students', { id: studentId }, holdId), closeDrawer: () => drawer.hide() });
    const importStudio = window.createWorkspaceImports({ node, table, request, notify, number, task, can, recordSummary, subjects: () => catalogStudio.subjects(), classifications: kind => classificationStudio.library(kind) });
    const transcriptStudio = window.createWorkspaceTranscripts({ node, request, requestHtml, requestFile, task, can, recordSummary, table, badge, buildForm, formData, openDrawer, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const financeReviewStudio = window.createWorkspaceFinanceReviews({ node, request, task, table, badge, money, recordSummary, actionMenu,
        buildForm, formData, openDrawer, account: (id, back, label) => operations.openAccount(id, back, label) });
    const hiatusStudio = window.createWorkspaceHiatus({ node, request, task, table, badge, buildForm, formData, openDrawer, financeReviews: () => financeReviewStudio, flow: operations.flow, refreshDirectory: () => datasets[current] ? loadDirectory() : Promise.resolve() });
    const transferAwardStudio = window.createWorkspaceTransferAwards({ node, request, task, table, recordSummary, actionMenu, buildForm, formData, openDrawer, back: id => transferStudio.record('equivalencies', id) });
    const prerequisitePermissions = window.createWorkspacePrerequisitePermissions({ node, request, task, can, openDrawer });
    const contentPublication = window.createCampusContentPublication({ node, request, task, openDrawer, recordSummary, badge,
        edit: editCampusContent, saved: async action => {
            notify({ approve: 'Publication approved.', submit: 'Draft submitted for review.', return: 'Draft returned to its author.', archive: 'Content archived.' }[action]);
            drawer.hide(); await loadDirectory();
        } });
    const enrollmentLoad = window.createWorkspaceEnrollmentLoad({ node, request, task, can, openDrawer });
    const standingStudio = window.createWorkspaceStanding({ node, request, task, can, openDrawer, degreeAudit: () => degreeAuditStudio });
    const advisingStudio = window.createWorkspaceAdvising({ node, request, task, can, openDrawer });
    const studentStudio = window.createWorkspaceStudents({ historyChecks: () => historyChecks, prerequisitePermissions: () => prerequisitePermissions, enrollmentLoad: () => enrollmentLoad, standing: () => standingStudio, advising: () => advisingStudio, node, request, task, can, actionMenu, recordSummary, messageRecord: operations.messageRecord, disposeActionMenus, buildForm, formData, degreeAudit: () => degreeAuditStudio, openDrawer, openPerson, table, badge, flow: operations.flow, registration: operations.registration, restrictions: () => restrictionStudio, registrationHistory: () => batchRegistration, calendar: () => academicCalendarStudio, teaching: () => teachingStudio, transferAwards: () => transferAwardStudio, hiatus: () => hiatusStudio, showAudit: (dataset, id) => operations.audit(dataset, { id }) });
    relationalStudio = window.createWorkspaceReportStudio({
        node, request, requestFile, task, can, table, openDrawer, actionMenu, disposeActionMenus,
        closeDrawer: () => drawer.hide(), simpleReport: () => reportStudio.create(),
        openAccount: (id, back) => {
            relationalStudio.cleanup(false);
            return operations.openAccount(id, back, "Back to report").catch(async error => {
                await back();
                throw error;
            });
        },
        openCurriculum: (student, pursuit, back) => {
            relationalStudio.cleanup(false);
            return studentStudio.specializations(student, pursuit, back, null, "", "Back to report").catch(async error => {
                await back();
                throw error;
            });
        },
        openApplication: (id, back) => {
            relationalStudio.cleanup(false);
            return peopleStudio.application({ id, reportBack: back }).catch(async error => {
                await back();
                throw error;
            });
        },
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
    const transferStudio = window.createWorkspaceTransfer({ awards: () => transferAwardStudio, node, request, task, can, recordSummary, openDrawer, openRecord, table, badge, flow: operations.flow, showAudit: (kind, id) => operations.audit(kind, { id }) });
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
        if (!teachingStudio.canLeave()) return;
        if (dirty && current === 'forms' && section !== 'forms') { pendingNavigation = () => navigate(section, push); leaveModal.show(); return; }
        if (section !== 'moodle') moodleStudio.leave();
        if (current !== section) $one('#directory-search').value = '';
        drawer.hide();
        current = section;
        generation++;
        $one('#workspace-loading').hidden = true;
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
    window.addEventListener('pagehide', () => { cleanupFormConditions(); formLayoutEditor.cleanup(); disposeActionMenus(document); $one('#drawer-body').replaceChildren(); $one('#directory-results').replaceChildren(); listRows = []; importStudio.clear(); formDraft = { id: null, version: null, status: 'draft', fields: [], layout: [] }; collapsedFormFields.clear(); expandedFormSettings.clear(); collapsedContainerSettings.clear(); formPresentation = formPreferences.transient(); formDisplayTouched = false; formEdits.past = []; formEdits.future = []; formEdits.current = null; formEdits.group = null; $one('#workspace-main').replaceChildren(); $one('#person-search-results').replaceChildren(); });
    window.addEventListener('pageshow', event => { if (event.persisted) location.reload(); });
    window.addEventListener('beforeunload', event => { if (dirty || !teachingStudio.canLeave()) { event.preventDefault(); event.returnValue = ''; } });
    listen('#discard-draft', 'click', async () => {
        const destination = pendingNavigation; pendingNavigation = null;
        resetFormStudio(false); leaveModal.hide(); await destination?.();
    });

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
        if (section === 'students' && can('sis:gradebook:amend') && can('sis:student:read')) tools.append(
            tool('Grade corrections', () => teachingStudio.gradeChanges(), 'Review requested grade corrections', 'clipboard-check'));
        if (section === 'students' && can('sis:load:policy')) tools.append(
            tool('Load policy', () => enrollmentLoad.policy(), 'Configure enrollment load policies', 'sliders'));
        if (section === 'students' && can('sis:standing:configure')) tools.append(
            tool('Standing definitions', () => standingStudio.definitions(), 'Configure academic standing definitions', 'list-check'));
        if (section === 'students' && can('sis:gradebook:manage') && can('sis:gradebook:calculation-policy:publish')) tools.append(
            tool('Comparison policy', () => window.createGradeComparisonPolicy({node,request,task,openDrawer,closeDrawer: () => drawer.hide()}).open(), 'Publish institutional grade comparison policy', 'sliders'));
        if (section === 'students' && can('sis:gradebook:policy')) tools.append(
            tool('Grade policy', () => teachingStudio.gradeChangePolicy(), 'Configure grade correction policy', 'sliders'));
        if (section === 'applications') tools.append(tool('Follow-up queue', () => peopleStudio.caseQueue(),
            'Open admissions follow-up queue', 'inboxes'));
        if (section === 'applications') tools.append(tool('Withdrawal requests', () => peopleStudio.withdrawalQueue(),
            'Review applicant withdrawal requests', 'inbox'));
        if (section === 'applications' && can('sis:admissions:manage') && can('sis:admissions:documents:retain')) tools.append(
            tool(config.document_retention_labels?.['Document retention'] || 'Document retention', () => peopleStudio.documentRetention(), config.document_retention_labels?.['Configure document retention'] || 'Configure document retention', 'archive'));
        if (section === 'applications' && can('sis:admissions:policy')) tools.append(
            tool('Policies', () => peopleStudio.admissionPolicy(), 'Configure admissions policies', 'sliders'));
        if (section === 'registration' && can('sis:registration:manage')) tools.append(
            tool('Enrollment targets', () => catalogStudio.enrollmentReview(), 'Review section enrollment targets', 'people'));
        if (family === 'courses' && can('sis:catalog:manage')) {
            tools.append(
                actionMenu('Curriculum', [
                    { label: 'Course group library', icon: 'collection', run: () => degreeAuditStudio.library() },
                    { label: 'Grading scales', icon: 'clipboard-data', run: () => degreeAuditStudio.gradingScale() },
                    { label: 'Subjects', icon: 'journal-bookmark', run: () => catalogStudio.subjects() },
                    { label: 'Departments & levels', icon: 'diagram-3', run: () => classificationStudio.library() },
                    { label: 'Schools & fields', icon: 'buildings', run: () => classificationStudio.library('school') },
                    { label: 'Degrees & awards', icon: 'award', run: () => classificationStudio.library('award') },
                    { label: 'Instructional methods', icon: 'easel', run: () => classificationStudio.library('instructional_method') },
                    { label: 'Campuses & sites', icon: 'buildings', run: () => classificationStudio.library('campus') },
                ], { icon: 'collection' }),
                actionMenu('Academic calendar', [
                    { label: 'Academic years', icon: 'calendar-range', run: () => academicCalendarStudio.years() },
                    { label: 'Term types', icon: 'calendar3', run: () => academicCalendarStudio.types() },
                ], { icon: 'calendar3' }),
                actionMenu('Course offerings', [
                    { label: 'Build course offerings', icon: 'plus-circle', run: () => catalogStudio.start() },
                    { label: 'Enrollment targets', icon: 'people', run: () => catalogStudio.enrollmentReview() },
                    { label: 'Saved offering plans', icon: 'journals', run: () => catalogStudio.plans() },
                    { label: 'Offering templates', icon: 'layout-text-window', run: () => offeringTemplates.page() },
                ], { icon: 'journal-richtext' }),
            );
        }
        if (family === 'employees' && can('sis:hr:manage') && can('sis:hr:time-policy')) tools.append(tool('Time review policy', () => timeReviewPolicy.open(), 'Institutional time review policy', 'sliders'));
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
        if (section === 'transcripts' && can('sis:transcript:policy') && can('sis:student:read')) tools.append(tool('Transcript setup', () => transcriptStudio.policy(), 'Transcript setup', 'list-check'));
        if (section === 'registration' && can('sis:registration:policy')) tools.append(tool('Timetable policy', () => batchRegistration.policy(), 'Registration timetable policy', 'calendar-check'));
        tabs.hidden = visibleRelated.length === 0;
        tools.hidden = tools.childElementCount === 0;
        $one('#directory-navigation').hidden = tabs.hidden && tools.hidden;
        if (!append) { listRows = []; nextAfter = null; $one('#directory-results').replaceChildren(node('div', { class: 'loading-card' }, 'Loading records…')); }
        $one('#directory-search-form').hidden = section === 'calendar';
        if (section === 'calendar') {
            listRows = []; nextAfter = null;
            $one('#directory-more').hidden = true;
            $one('#record-count').textContent = 'Week and month views';
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
        if (section === 'portal' && field === 'status') return badge(contentPublication.visibility(row));
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

    function recordReturn(incoming, alreadyOpen) {
        if (!incoming) return null;
        const previous = alreadyOpen ? currentRecordReturn : null;
        let ancestor = previous;
        while (ancestor && ancestor.key !== incoming.key) ancestor = ancestor.parent;
        const trail = [{...incoming, parent: ancestor ? ancestor.parent : previous}];
        let parent = trail[0].parent;
        while (parent && trail.length < 8) {trail.push(parent);parent = parent.parent;}
        let result = null;
        for (let index = trail.length - 1; index >= 0; index--) result = {...trail[index], parent:result};
        return result;
    }

    function openDrawer(title, eyebrow = 'CONNECTED RECORD', options = {}) {
        if (!teachingStudio.canLeave()) throw new Error('Save or discard your worksheet changes before opening another task.');
        cleanupFormConditions();
        relationalStudio?.cleanup();
        $one('#workspace-alert').hidden = true;
        $one('#drawer-title').textContent = title; $one('#drawer-eyebrow').textContent = eyebrow;
        const alreadyOpen = $one('#record-drawer').matches('.show, .showing');
        if (!alreadyOpen) drawerOpener = document.activeElement;
        surfaces.closeMenus();
        drawerTaskNavigation?.dispose();
        personTabsObserver?.disconnect(); personTabsObserver = null;
        if (Object.hasOwn(options, 'record')) currentRecordReturn = recordReturn(options.record, alreadyOpen);
        else if (!alreadyOpen) currentRecordReturn = null;
        const content = node('div', {class:'record-task-content'});
        const parent = Object.hasOwn(options, 'record') ? currentRecordReturn?.parent : currentRecordReturn;
        drawerTaskNavigation = surfaces.taskNavigation(content, title, parent);
        disposeActionMenus($one('#drawer-body'));
        $one('#drawer-body').replaceChildren(drawerTaskNavigation.element, content); drawer.show();
        if (alreadyOpen) {
            $one('#drawer-title').tabIndex = -1;
            $one('#drawer-title').focus({ preventScroll: true });
        }
        return content;
    }
    $one('#record-drawer').addEventListener('hide.bs.offcanvas', event => {
        if (!teachingStudio.canLeave()) event.preventDefault();
    });
    $one('#record-drawer').addEventListener('hidden.bs.offcanvas', () => {
        if ($one('#record-drawer').matches('.show, .showing')) return;
        drawerTaskNavigation?.dispose(); drawerTaskNavigation = null; currentRecordReturn = null;
        personTabsObserver?.disconnect(); personTabsObserver = null;
        cleanupFormConditions();
        if (!relationalStudio?.isOpen()) relationalStudio?.cleanup();
        disposeActionMenus($one('#drawer-body'));
        $one('#drawer-body').replaceChildren();
        teachingStudio.canLeave();
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
        const body = openDrawer(definition.action, definition.title.toUpperCase());
        const operation = ['people', 'billing'].includes(section) ? (await request('/sis/ui/operation-id/')).id : null;
        if (!body.isConnected) return;
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
        if (section === 'portal') { body.append(form); await campusContentForm(form); if (!body.isConnected) return; }
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
            if (section === 'portal') data.content_format = form.elements.content_format.value;
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
        application_start: [
            { title: 'Applicant', fields: ['person_id'], wide: ['person_id'] },
            { title: 'Program & intake', fields: ['program_id', 'entry_term_id'], wide: ['program_id', 'entry_term_id'] },
        ],
        student_hiatus: [
            { title: 'Hiatus type & dates', fields: ['code', 'starts_on', 'ends_on'], wide: ['code'] },
            { title: 'Private registrar comment', fields: ['note'], wide: ['note'] },
        ],
        student_hiatus_return: [{ title: 'Return decision', fields: ['return_on', 'note'], wide: ['note'] }],
        curriculum_component: [
            { title: 'Component identity', fields: ['kind', 'code', 'title'], wide: ['kind', 'title'] },
            { title: 'Description', fields: ['description'], wide: ['description'] },
        ],
        journal: [
            { title: 'Journal details', fields: ['reference', 'posted_on', 'currency'], wide: ['reference'] },
            { title: 'Posting reason', fields: ['reason'], wide: ['reason'] },
        ],
        ledger: [{ title: 'Ledger account', fields: ['code', 'name', 'type'], wide: ['name'] }],
        billing: [
            { title: 'Fee identity', fields: ['code', 'name', 'kind'], wide: ['name'] },
            { title: 'Amount & application', fields: ['amount_minor', 'currency', 'pricing_basis', 'program_id'], wide: ['pricing_basis', 'program_id'] },
        ],
        booking_calendar: [{
            title: 'Find meetings',
            description: 'Choose a date and narrow the meetings shown in the calendar.',
            fields: ['date', 'location', 'building_id', 'category_id', 'search'],
            wide: ['search'],
        }],
        transfer_catalog: [{ title: 'Transfer catalog details', fields: ['code', 'name', 'title'], wide: ['name', 'title'] }],
        transfer_equivalency: [
            { title: 'Receiving course', fields: ['course_id'], wide: ['course_id'] },
            { title: 'Effective completion dates', fields: ['starts_on', 'ends_on'] },
            { title: 'Award rules', fields: ['minimum_grade_rank', 'credits_milli', 'awarded_grade'], wide: ['minimum_grade_rank'] },
        ],
        transfer_award: [
            { title: 'Recipient & posting', fields: ['student_id', 'term_id'], wide: ['student_id', 'term_id'] },
            { title: 'External course result', fields: ['completed_on', 'grade_rank'] },
            { title: 'Accepted course', fields: ['course_version_id'], wide: ['course_version_id'] },
        ],
        transfer_correction: [
            { title: 'Award values', fields: ['credits_milli', 'awarded_grade'] },
            { title: 'Registrar decision', fields: ['effective_on', 'reason'], wide: ['reason'] },
        ],
        transfer_course: [{ title: 'Course acceptance', fields: ['course_version_id', 'effective_on', 'reason'], wide: ['course_version_id', 'reason'] }],
        transfer_details: [{ title: 'Transfer academic details', fields: ['term_id', 'completed_on', 'academic_on', 'effective_on', 'reason'], wide: ['reason'] }],
        registration_group: [
            { title: 'Course section', fields: ['section_id'], wide: ['section_id'] },
            { title: 'Build the student group', fields: ['student_id'], wide: ['student_id'] },
        ],
        registration_section: [{ title: 'Course section', fields: ['section_id'], wide: ['section_id'] }],
        registration_receipt: [{ title: 'Completed registration', fields: ['operation_id'], wide: ['operation_id'] }],
        registration_window: [
            { title: 'Registration window', fields: ['opens_at', 'closes_at'] },
            { title: 'Course limit', fields: ['course_limit'], wide: ['course_limit'] },
            { title: 'Publication reason', fields: ['reason'], wide: ['reason'] },
        ],
        registration_policy: [
            { title: 'Published meeting overlaps', fields: ['mode', 'faculty_mode'], wide: ['mode', 'faculty_mode'] },
            { title: 'Co-requisite decisions', fields: ['prior_completion', 'drop_mode'], wide: ['prior_completion', 'drop_mode'] },
        ],
        restriction: [
            { title: 'Hold responsibility', fields: ['office_id', 'code'], wide: ['office_id', 'code'] },
            { title: 'Dates & registration', fields: ['starts_on', 'ends_on', 'severity', 'blocks_staff'], wide: ['severity', 'blocks_staff'] },
            { title: 'Internal explanation', fields: ['note'], wide: ['note'] },
        ],
        restriction_end: [{ title: 'End this hold', fields: ['ends_on', 'reason'], wide: ['reason'] }],
        staff_note: [{ title: 'Internal staff note', fields: ['note'], wide: ['note'] }],
        portal: [
            { title: 'Content details', fields: ['kind', 'code', 'title'], wide: ['title'] },
            { title: 'Wording & audience', fields: ['content', 'audience'], wide: ['content', 'audience'] },
        ],
        campaigns: [{ title: 'Message content', fields: ['subject', 'body'], wide: ['subject', 'body'] }],
        access_definition: [{ title: 'Access definition', fields: ['name', 'description'], wide: ['name', 'description'] }],
        teaching_assignment: [
            {
                title: 'Assignment details',
                fields: ['title', 'assignment_kind', 'possible_hundredths', 'bonus_hundredths', 'due_on'],
                wide: ['title'],
            },
        ],
        attendance_mark: [{ title: 'Attendance mark', fields: ['held_on', 'status'] }],
        final_grade: [
            { title: 'Grade decision', fields: ['grade_id', 'effective_on', 'reason'], wide: ['reason'] },
        ],
        academic_date: [
            { title: 'Academic details', fields: ['posting_term_id', 'effective_on', 'academic_on', 'completed_on', 'reason'], wide: ['posting_term_id', 'reason'] },
        ],
        transcript_delivery: [
            { title: 'Delivery evidence', fields: ['delivered_at', 'evidence'], wide: ['evidence'] },
        ],
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
                fields: ['course_id', 'term_id', 'code', 'capacity', 'minimum_enrollment'],
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
            const limits = { points_milli: [0, 10], severity: [0, 1000], course_limit: [1, 1000], minimum_grade_rank: [0, 10000], grade_rank: [0, 10000], possible_hundredths: [0.01, 10000], bonus_hundredths: [0, 10000], capacity: [1, 100000], minimum_enrollment: [0, 100000], amount_minor: [0.01, 1000000] };
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
            if (key === 'minimum_enrollment') {
                const help = node('p', { id: `${id}-help`, class: 'form-text' },
                    'Advisory target. Leave blank or use zero for no target. It cannot exceed section capacity.');
                control.setAttribute('aria-describedby', help.id); container.append(help);
            }
            if (type === 'lookup') container.append(searchableLookup(control, source));
            control.addEventListener('input', () => { control.removeAttribute('aria-invalid'); control.classList.remove('is-invalid'); });
            form.append(container);
        }
        const enrollmentTarget = form.elements.namedItem('minimum_enrollment');
        const capacity = form.elements.namedItem('capacity');
        if (enrollmentTarget && capacity) {
            const limitTarget = () => { enrollmentTarget.max = capacity.value || '100000'; };
            capacity.addEventListener('input', limitTarget); limitTarget();
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

    function personConnection(section, row, person, index) {
        const labels = {
            students: ['Student record', 'Open student record', 'mortarboard', 'Academic activity and program history.'],
            applications: ['Application', 'Open application', 'inbox', 'Admissions review and recorded decisions.'],
            accounts: ['Student account', 'Open account', 'wallet2', 'Account statement and recorded transactions.'],
            employees: ['Employment record', 'Open employment record', 'briefcase', 'Position assignments and time review.'],
        };
        const [kind, action, glyph, description] = labels[section];
        const title = section === 'employees' && row.job_title ? row.job_title : `${kind} ${index + 1}`;
        const heading = node('h3', {}, title);
        const status = row.status ? String(row.status).replaceAll('_', ' ').replace(/^./u, value => value.toUpperCase())
            : section === 'employees' && row.active != null ? (Number(row.active) ? 'Time submissions enabled' : 'Time submissions paused')
            : section === 'students' && row.active != null ? (Number(row.active) ? 'Active' : 'Inactive') : null;
        return node('article', { class: 'person-connection-card', 'aria-label': `${kind} ${index + 1}` },
            node('div', { class: 'person-connection-heading' },
                node('span', { class: 'icon-soft', 'aria-hidden': 'true' }, icon(glyph)),
                node('div', { class: 'person-connection-identity' }, node('p', { class: 'eyebrow' }, kind), heading),
                status ? badge(status) : row.currency ? badge(row.currency) : null),
            node('p', { class: 'form-text' }, description),
            node('div', { class: 'person-connection-footer' },
                node('button', {
                    class: 'btn btn-sm btn-outline-primary', type: 'button',
                    'aria-label': `${action}: ${title}`,
                    onclick: event => task(() => openRecord(section, { ...row, given_name: person.givenName, family_name: person.familyName }), event.currentTarget),
                }, action, icon('arrow-right'))));
    }

    async function openPerson(id, selectedTab = 'overview') {
        let activeTab = selectedTab;
        const body = openDrawer('Person workspace', 'PERSON RECORD', {record:{key:`person:${id}`,label:'Person record',run:()=>openPerson(id,activeTab)}});
        body.append(node('p', { class: 'muted-text' }, 'Loading person record…'));
        const data = await request(`/sis/ui/people/${id}/`);
        if (!body.isConnected) return;
        const person = data.person; clear(body);
        body.append(node('div', { class: 'record-profile' }, node('span', { class: 'avatar large', 'aria-hidden': 'true' }, `${person.givenName[0]}${person.familyName[0]}`), node('div', {}, node('h3', {}, `${person.givenName} ${person.familyName}`), badge(person.institutionalId.value))));
        const tabs = node('div', { class: 'workspace-tabs person-record-tabs', role: 'tablist', 'aria-label': 'Person record sections' });
        const panes = node('div', { class: 'person-record-panels' });
        const revealActiveTab = () => {
            const selected = tabs.querySelector('[aria-selected="true"]');
            if (!selected || !tabs.isConnected) return;
            const bounds = tabs.getBoundingClientRect(), active = selected.getBoundingClientRect();
            if (active.left < bounds.left) tabs.scrollLeft -= bounds.left - active.left;
            else if (active.right > bounds.right) tabs.scrollLeft += active.right - bounds.right;
        };
        const available = [['overview', 'Overview'], ...(data.students ? [['students', 'Academics']] : []), ...(data.applications ? [['applications', 'Admissions']] : []), ...(data.accounts ? [['accounts', 'Accounts']] : []), ...(data.employees ? [['employees', 'Employment']] : []), ...(can('admin:access:manage') ? [['access', 'Access']] : [])];
        available.forEach(([key, label], index) => {
            const panel = node('div', { id: `person-panel-${key}`, role: 'tabpanel', tabindex: '0', 'aria-labelledby': `person-tab-${key}`, hidden: index !== 0 });
            const button = node('button', { type: 'button', id: `person-tab-${key}`, role: 'tab', 'aria-controls': panel.id, 'aria-selected': index === 0 ? 'true' : 'false', tabindex: index === 0 ? '0' : '-1', class: index === 0 ? 'active' : '' }, label);
            button.addEventListener('click', () => {
                activeTab = key;
                [...tabs.children].forEach(tab => {
                    tab.classList.toggle('active', tab === button);
                    tab.setAttribute('aria-selected', tab === button ? 'true' : 'false');
                    tab.tabIndex = tab === button ? 0 : -1;
                });
                [...panes.children].forEach(item => { item.hidden = item !== panel; });
                revealActiveTab();
            });
            button.addEventListener('keydown', event => { if (['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) { event.preventDefault(); const all = [...tabs.children]; const next = all[event.key === 'Home' ? 0 : event.key === 'End' ? all.length - 1 : (all.indexOf(button) + (event.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length]; next.click(); next.focus(); } });
            if (key === 'overview') {
                panel.append(node('dl', { class: 'detail-list' }, node('div', {}, node('dt', {}, 'Institutional ID'), node('dd', {}, person.institutionalId.value)), node('div', {}, node('dt', {}, 'Sign-in account'), node('dd', {}, person.ownerUserId ? 'Linked' : 'Not linked'))));
                const actions = node('div', { class: 'inline-actions', role: 'group', 'aria-label': 'Person tasks' });
                const extra = [];
                if (can('sis:person:manage') && (!person.ownerUserId || can('admin:edit:user'))) actions.append(node('button', { class: 'btn btn-outline-primary', type: 'button', onclick: () => task(() => peopleStudio.editProfile(person)) }, 'Edit person profile'));
                if (can('sis:person:manage')) actions.append(node('button', { class: 'btn btn-outline-primary', type: 'button', onclick: () => task(() => peopleStudio.contact(person)) }, 'Contact information'));
                if (can('sis:person:manage')) actions.append(node('button', { class: 'btn btn-outline-primary', type: 'button', onclick: () => peopleStudio.education(person) }, 'Educational background'));
                if (can('sis:admissions:manage')) actions.append(node('button', { class: 'btn btn-primary', type: 'button', onclick: () => task(() => startApplication(person.id.value, `${person.givenName} ${person.familyName}`)) }, 'Start application'));
                if (can('sis:person:manage') && can('sis:communications:manage')) extra.push({ label: 'Message person', icon: 'envelope',
                    run: () => operations.messageRecord('person', id, () => openPerson(id), body) });
                if (person.ownerUserId && can('admin:edit:user')) extra.push({ label: 'Manage sign-in account', icon: 'key', run: () => peopleStudio.account(person) });
                if (can('sis:person:manage')) extra.push({ label: 'Account setup', icon: 'envelope', run: () => accountSetup(person) });
                if (can('sis:person:assign-id')) extra.push({ label: 'Change institutional ID', icon: 'person-vcard', run: () => editInstitutionalId(person) });
                if (can('sis:person:manage') && can('sis:audit:read') && can('sis:history:verify')) extra.push({ label: 'Check profile history', icon: 'shield-check', run: () => historyChecks.open('person', person.id.value, () => openPerson(person.id.value)) });
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
                panel.append(node('p', { class: 'person-connection-count' }, `${rows.length} connected ${rows.length === 1 ? 'record' : 'records'} on this page`));
                if (!rows.length) panel.append(emptyState('No connected records.', 'Records appear here when this person gains this capability.'));
                else panel.append(node('div', { class: 'person-connection-list' }, rows.map((row, index) => personConnection(key, row, person, index))));
            }
            tabs.append(button); panes.append(panel);
        });
        body.append(tabs, panes);
        const selected = available.findIndex(([key]) => key === activeTab);
        tabs.children[selected < 0 ? 0 : selected].click();
        personTabsObserver = new ResizeObserver(revealActiveTab);
        personTabsObserver.observe(tabs);
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

    async function openRecord(section, row, origin = {}) {
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
        const body = openDrawer(section === 'campaigns' ? 'Message details' : recordLabel(row), datasets[section]?.title.toUpperCase() || 'RECORD',
            {record:{key:`${section === 'programs' ? 'program' : section}:${row.id}`,label:recordLabel(row),run:()=>openRecord(section,row,origin)}});
        if (section === 'campaigns' && origin.back) body.append(node('button', { type: 'button',
            class: 'btn btn-link mb-3', onclick: () => task(origin.back) }, origin.backLabel));
        if (section === 'sections' && origin.back) body.append(node('button', { type: 'button',
            class: 'btn btn-link mb-3', onclick: () => task(origin.back) }, origin.backLabel || 'Back'));
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
        if (!['teaching', 'campaigns'].includes(section)) body.append(details);
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
        await operations.enhance(section, row, body, origin);
    }

    async function campusContentForm(form, format = 'plain') {
        const code = form.elements.namedItem('code');
        const kind = form.elements.namedItem('kind');
        const content = form.elements.namedItem('content');
        content.maxLength = 60000;
        const helpId = `${code.id}-help`;
        code.maxLength = 80;
        code.pattern = '[a-z0-9][a-z0-9-]{0,79}';
        code.setAttribute('aria-describedby', helpId);
        code.after(
            node(
                'p',
                { class: 'form-text', id: helpId },
                code.readOnly ? 'The content type and code stay fixed after creation.'
                    : 'Use lowercase letters, numbers and hyphens, for example fall-welcome.',
            ),
        );
        form.elements.title.maxLength = 191;
        const update = () => {
            form.querySelector(`label[for="${content.id}"]`).firstChild.textContent =
                kind.value === 'link' ? 'HTTPS link ' : 'Content ';
            content.rows = kind.value === 'link' ? 3 : 12;
            content.placeholder =
                kind.value === 'link' ? 'https://example.edu/' : 'Write your campus update…';
        };
        kind.addEventListener('change', update);
        update();
        form.append(
            node(
                'p',
                { class: 'form-text' },
                'Campus content requires sign-in. Public news appears on the public website. Use Page builder to design public pages.',
            ),
        );
        await window.CampusRichText.mount(form, node, format);
    }

    async function reviewCampusPublication(post, action = post.status === 'published' ? 'archive' : 'approve') {
        return contentPublication.open(post, action);
    }

    async function editCampusContent(id) {
        const body = openDrawer('Content editor', 'CAMPUS PUBLISHING');
        body.append(node('p', { role: 'status' }, 'Loading saved content…'));
        const post = { ...(await request(`/sis/portal/editor/${id}/`)), id };
        if (!body.isConnected) return;
        $one('#drawer-title').textContent = post.title;
        const fields = datasets.portal.fields;
        const form = await buildForm(fields, post, { layout: 'portal' });
        if (!body.isConnected) return;
        form.elements.kind.disabled = true;
        form.elements.code.readOnly = true;
        if (post.can_edit) { body.append(form); await campusContentForm(form, post.content_format); }
        if (!body.isConnected) return;
        form.append(
            node('p', { class: 'form-text' }, 'Saving an edit returns content to draft for review.'),
            node('button', { class: 'btn btn-primary', type: 'submit' }, 'Save draft'),
        );
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            task(
                async () => {
                    if (!form.reportValidity()) return;
                    await request('/sis/portal/editor/', 'POST', {
                        ...formData(form, fields),
                        id,
                        version: Number(post.version),
                        content_format: form.elements.content_format.value,
                    });
                    if (!body.isConnected) return;
                    notify('Draft saved. Review it before publishing.');
                    await editCampusContent(id);
                    await loadDirectory();
                },
                $one('[type="submit"]', form),
            );
        });
        const actions = node('div', {
            class: 'inline-actions',
            role: 'group',
            'aria-label': 'Content tasks',
        });
        const baseline = JSON.stringify(formData(form, fields));
        const changed = () => JSON.stringify(formData(form, fields)) !== baseline;
        const hint = node('p', { class: 'form-text', role: 'status', hidden: true });
        if (post.can_publish && can('sis:portal:publish') && post.status !== 'archived') {
            const review = node(
                'button',
                {
                    class: 'btn btn-outline-primary',
                    type: 'button',
                    onclick: (event) =>
                        task(async () => {
                            if (changed()) return;
                            await reviewCampusPublication(post);
                        }, event.currentTarget),
                },
                post.status === 'published' ? 'Review archive' : 'Review publication',
            );
            const update = () => {
                review.disabled = changed();
                hint.hidden = !review.disabled;
                hint.textContent = review.disabled
                    ? 'Save your changes before reviewing publication or archiving.'
                    : '';
            };
            form.addEventListener('input', update);
            form.addEventListener('change', update);
            actions.append(review);
        }
        if (post.can_edit && post.status === 'draft' && !post.can_publish) {
            const submit = node('button', { type: 'button', class: 'btn btn-outline-primary',
                onclick: event => task(() => reviewCampusPublication(post, 'submit'), event.currentTarget) }, 'Request review');
            const update = () => { submit.disabled = changed(); hint.hidden = !submit.disabled;
                hint.textContent = submit.disabled ? 'Save your changes before requesting review.' : ''; };
            form.addEventListener('input', update); form.addEventListener('change', update);
            actions.append(submit);
        }
        if (post.can_publish && can('sis:portal:publish') && post.status === 'pending') {
            const sendBack = node('button', { type: 'button', class: 'btn btn-outline-secondary',
                onclick: event => task(() => reviewCampusPublication(post, 'return'), event.currentTarget) }, 'Return to author');
            form.addEventListener('input', () => { sendBack.disabled = changed(); });
            form.addEventListener('change', () => { sendBack.disabled = changed(); });
            actions.append(sendBack);
        }
        if (can('vihzhuo:manage'))
            actions.append(
                node('a', { class: 'btn btn-outline-secondary', href: '/admin/manager' }, 'Page builder'),
            );
        body.replaceChildren(
            recordSummary([
                ['Status', badge(post.visibility === 'pending' ? 'Awaiting review' : post.visibility)],
                ['Audience', post.audience === 'public' ? 'Public website' : 'Signed-in campus'],
            ]),
            actions,
            hint,
            post.review_note ? node('section', { class: 'alert alert-info', 'aria-label': 'Author feedback' }, node('h3', { class: 'h6' }, 'Note from reviewer'), node('p', {}, post.review_note)) : null,
            post.can_edit ? form : node('section', { class: 'record-section', 'aria-label': 'Content awaiting review' }, node('h3', {}, post.title), window.CampusRichText.article(post, node)),
        );
    }
    async function reviewTranscript(id) {
        return transcriptStudio.record(id);
    }

    const applicationForms = new WeakMap();
    resetFormEdits();
    const formLibrary = window.createApplicationFormLibrary({ node, request, task, badge, can, emptyState,
        openForm: async (id, targetLabel) => {
            if (dirty) { pendingNavigation = async () => loadDraft(id, targetLabel); leaveModal.show(); return false; }
            return loadDraft(id, targetLabel);
        },
        removeDefault: reviewFormDisable,
        active: () => current === 'forms' ? generation : null,
    });
    function showFormLibrary(visible, remember = false) {
        $one('#form-library-panel').hidden = !visible;
        $one('#form-studio-layout').classList.toggle('library-hidden', !visible);
        $one('#form-library-toggle').setAttribute('aria-expanded', String(visible));
        $one('[data-form-library-label]').textContent = visible ? 'Hide library' : 'Show library';
        if (remember) formPreferences.showLibrary(visible);
    }
    showFormLibrary(formPreferences.library(!matchMedia('(max-width: 767px)').matches));
    listen('#form-library-toggle', 'click', () => showFormLibrary($one('#form-library-panel').hidden, true));

    const formLayoutEditor = window.createApplicationLayoutEditor({ node, icon, openDrawer, task,
        draft: () => formDraft, changed: formChanged, render: renderDesigner,
        settingsOpen: container => !collapsedContainerSettings.has(container.key),
        settingsChanged: (container, open) => {
            if (open) collapsedContainerSettings.delete(container.key); else collapsedContainerSettings.add(container.key);
            formDisplayTouched = true; rememberFormPresentation();
        },
        announce: message => { $one('#form-sort-status').textContent = message; },
        closeDrawer: after => { $one('#record-drawer').addEventListener('hidden.bs.offcanvas', after, { once: true }); drawer.hide(); },
    });
    function applicationControls(fields, preview = false, layout = []) {
        const instance = window.createApplicationForm({ fields, preview, layout });
        applicationForms.set(instance.form, instance);
        return instance.form;
    }

    async function loadFormStudio() {
        if (!can('sis:forms:manage')) throw new Error('Form administration needs additional access.');
        const version = generation;
        const [programs, levels] = await Promise.all([options('programs'), request('/sis/forms/academic-levels/'), formLibrary.load()]);
        if (version !== generation || current !== 'forms') return;
        const selected = $one('#form-program').value;
        const selectedLabel = $one('#form-program').selectedOptions[0]?.textContent;
        populate($one('#form-program'), programs);
        if (selected && !programs.some(program => program.value === selected))
            $one('#form-program').append(node('option', { value: selected }, selectedLabel || 'Selected program'));
        $one('#form-program').value = selected;
        installLookup('#form-program', 'programs');
        const levelSelect = $one('#form-level'), selectedLevel = levelSelect.value;
        const levelLabel = levelSelect.selectedOptions[0]?.textContent;
        populate(levelSelect, levels.items.map(level => ({ value: level.id, label: level.title })), 'Choose an academic level');
        if (selectedLevel && !levels.items.some(level => level.id === selectedLevel)) levelSelect.append(node('option', { value: selectedLevel }, levelLabel || 'Selected academic level'));
        levelSelect.value = selectedLevel;
        $one('#form-level-help').textContent = levels.items.length
            ? 'Uses the academic levels configured by your institution. A program-specific form takes precedence.'
            : 'No academic levels are configured. Set them up in Academic catalog, or choose One academic program.';
        syncFormScope();
        if (!formDraft.fields.length) renderDesigner();
        updateFormPublication();
    }

    async function loadDraft(id, targetLabel = '') {
        const serial = ++formLoadSerial, version = generation;
        const record = await request(`/sis/forms/${id}/`);
        const presentation = await formPreferences.record(record.id);
        if (serial !== formLoadSerial || version !== generation || current !== 'forms' || dirty) return;
        formDraft = { ...record, id: record.status === 'published' ? null : record.id, version: record.status === 'published' ? null : record.version, status: 'draft', fields: structuredClone(record.fields), layout: structuredClone(record.layout || []) };
        $one('#form-name').value = record.name;
        $one('#form-scope').value = record.program_id ? 'program' : 'academic_level';
        for (const [selector, value, fallback] of [['#form-program', record.program_id, 'Selected program'], ['#form-level', record.academic_level_id, 'Selected academic level']]) {
            const select = $one(selector);
            if (value && ![...select.options].some(option => option.value === value)) select.append(node('option', { value }, record.target_label || targetLabel || fallback));
            select.value = value || '';
        }
        syncFormScope();
        collapsedFormFields.clear(); expandedFormSettings.clear(); collapsedContainerSettings.clear();
        formPresentation = presentation; formDisplayTouched = presentation.stored;
        const displayed = presentation.snapshot();
        formDraft.fields.forEach((field, index) => {
            if (displayed.fields[index]) collapsedFormFields.add(field.key);
            if (displayed.settings[index]) expandedFormSettings.add(field.key);
        });
        formDraft.layout.forEach((container, index) => {
            if (displayed.containers[index] === false) collapsedContainerSettings.add(container.key);
        });
        resetFormEdits();
        $one('#designer-state').textContent = record.status === 'published' ? 'New revision' : 'Draft';
        $one('#designer-save-status').textContent = record.status === 'published' ? 'The published form stays unchanged. Save this as a new revision.' : 'You are editing an unpublished draft.';
        dirty = false; renderDesigner(); updateFormPublication();
        formLibrary.select(id);
        if (matchMedia('(max-width: 767px)').matches) { showFormLibrary(false); $one('#form-name').focus(); }
        return true;
    }

    function updateFormPublication() {
        $one('#form-publish').disabled = dirty || !formDraft.id || savingFormDraft === formDraft;
    }

    function formTarget() {
        const program = $one('#form-scope').value === 'program';
        return { program_id: program ? $one('#form-program').value : null, academic_level_id: program ? null : $one('#form-level').value };
    }

    function syncFormScope() {
        const program = $one('#form-scope').value === 'program';
        const locked = Boolean(formDraft.id) || savingFormDraft === formDraft;
        $one('#form-scope').disabled = locked;
        for (const [name, active] of [['program', program], ['level', !program]]) {
            $one(`#form-${name}-group`).hidden = !active;
            $one(`#form-${name}`).disabled = !active || locked;
            $one(`#form-${name}`).required = active;
        }
        if ($one('#form-program').nextElementSibling) $one('#form-program').nextElementSibling.hidden = locked;
    }

    function cleanupFormConditions() {
        if (formConditions) { formConditions.queryBuilder('destroy'); formConditions = null; }
    }

    function conditionProblem(field, index) {
        if (!field.show_when) return '';
        const preceding = new Set(formDraft.fields.slice(0, index).map(item => item.key));
        const valid = group => Array.isArray(group.rules) && group.rules.every(rule => rule.rules ? valid(rule) : preceding.has(rule.id));
        return valid(field.show_when) ? '' : 'A visibility rule refers to a missing or later question. Move that question earlier, or edit these conditions before saving.';
    }

    function editFormConditions(field) {
        const index = formDraft.fields.indexOf(field);
        const body = openDrawer('Field visibility', field.label || 'APPLICATION QUESTION');
        const prior = formDraft.fields.slice(0, index);
        body.append(node('p', { class: 'muted-text' }, 'Show this question when earlier answers match. Combine conditions using All or Any. Hidden questions do not require an answer.'));
        const builder = node('div', { class: 'form-condition-builder' }); body.append(builder);
        if (prior.length) {
            const values = items => Object.fromEntries(items.map(item => [item, escapeLabel(item)]));
            const filters = prior.map(item => ({ id: item.key, label: escapeLabel(item.label || item.key),
                type: item.type === 'number' ? 'integer' : item.type === 'checkbox' ? 'boolean' : 'string',
                ...(item.type === 'checkbox' ? { input: 'select', values: { 1: 'Checked', 0: 'Not checked' } }
                    : ['select', 'radio'].includes(item.type) ? { input: 'select', values: values(item.options) } : {}),
                operators: item.type === 'number' ? ['equal', 'not_equal', 'less', 'greater', 'in', 'not_in'] : ['equal', 'not_equal', 'in', 'not_in'],
            }));
            const accessible = () => {
                $all('i', builder).forEach(icon => icon.setAttribute('aria-hidden', 'true'));
                $all('.rule-filter-container select', builder).forEach(control => control.setAttribute('aria-label', 'Earlier question'));
                $all('.rule-operator-container select', builder).forEach(control => control.setAttribute('aria-label', 'Comparison'));
                $all('.rule-value-container input, .rule-value-container select', builder).forEach(control => control.setAttribute('aria-label', 'Condition value'));
                $all('.rules-group-header input', builder).forEach(control => control.setAttribute('aria-label', control.value === 'AND' ? 'Match all conditions' : 'Match any condition'));
            };
            formConditions = $(builder);
            formConditions.on('afterAddGroup.queryBuilder afterCreateRuleFilters.queryBuilder afterCreateRuleOperators.queryBuilder afterCreateRuleInput.queryBuilder afterUpdateRuleFilter.queryBuilder afterUpdateRuleOperator.queryBuilder afterSetRules.queryBuilder', accessible);
            formConditions.queryBuilder({ allow_groups: 4, allow_empty: false, plugins: [], filters,
                lang: { add_rule: 'Add condition', add_group: 'Add group', conditions: { AND: 'All', OR: 'Any' } },
                ...(field.show_when && !conditionProblem(field, index) ? { rules: field.show_when } : {}),
            });
            accessible();
        } else body.append(node('p', { class: 'alert alert-info' }, 'The first question is always visible. Add or move another question before it to use conditions.'));
        body.append(node('div', { class: 'inline-actions' },
            node('button', { class: 'btn btn-outline-secondary', type: 'button', onclick: () => drawer.hide() }, 'Cancel'),
            node('button', { class: 'btn btn-outline-primary', type: 'button', onclick: () => { delete field.show_when; formChanged(); renderDesigner(index, 'conditions'); drawer.hide(); } }, 'Always show'),
            ...(prior.length ? [node('button', { class: 'btn btn-primary', type: 'button', onclick: () => task(async () => {
                const rules = formConditions.queryBuilder('getRules');
                if (!rules) throw new Error('Complete each condition before applying it.');
                const problem = formLayoutEditor.conditionLimits(rules);
                if (problem) throw new Error(problem);
                // Persist only the domain rule language, never plugin metadata or labels.
                const clean = group => ({ condition: group.condition, rules: group.rules.map(rule => rule.rules ? clean(rule) : { id: rule.id, operator: rule.operator, value: rule.value }) });
                field.show_when = clean(rules); formChanged(); renderDesigner(index, 'conditions'); drawer.hide();
            }) }, 'Apply conditions')] : []),
        ));
    }

    // Question/layout edits only: names, targets, server versions and publication decisions stay separate.
    function formEditSnapshot() {
        return JSON.stringify({ fields: formDraft.fields, layout: formDraft.layout || [] });
    }

    function updateFormEdits() {
        $one('#form-undo').disabled = !formEdits.past.length;
        $one('#form-redo').disabled = !formEdits.future.length;
    }

    function resetFormEdits() {
        formEdits.past = []; formEdits.future = []; formEdits.current = formEditSnapshot(); formEdits.group = null;
        updateFormEdits();
    }

    function trimFormEdits() {
        let bytes = 2 * [formEdits.current, ...formEdits.past, ...formEdits.future].reduce((sum, value) => sum + value.length, 0);
        for (const entries of [formEdits.past, formEdits.future]) {
            while (entries.length && (formEdits.past.length + formEdits.future.length > 30 || bytes > 2 * 1024 * 1024))
                bytes -= 2 * entries.shift().length;
        }
    }

    function rememberFormEdit(event) {
        const next = formEditSnapshot();
        if (next === formEdits.current) return;
        const control = event?.type === 'input' ? event.target : null;
        const group = control?.matches('textarea, input:not([type="checkbox"]):not([type="radio"])') ? control : null;
        if (!group || group !== formEdits.group || formEdits.future.length) formEdits.past.push(formEdits.current);
        formEdits.current = next; formEdits.future = []; formEdits.group = group;
        if (formEdits.current === formEdits.past.at(-1)) { formEdits.past.pop(); formEdits.group = null; }
        trimFormEdits();
        updateFormEdits();
    }

    function restoreFormEdit(redo) {
        const source = redo ? formEdits.future : formEdits.past;
        if (!source.length) return;
        const trigger = document.activeElement;
        (redo ? formEdits.past : formEdits.future).push(formEdits.current);
        formEdits.current = source.pop(); formEdits.group = null;
        const state = JSON.parse(formEdits.current);
        cleanupFormConditions();
        // Mutate the current draft so an in-flight save can still bind its returned identity/version.
        Object.assign(formDraft, { fields: state.fields, layout: state.layout });
        renderDesigner(); formChanged(null, false); updateFormEdits();
        $one('#form-sort-status').textContent = redo ? 'Edit reapplied.' : 'Previous edit restored.';
        if (trigger?.disabled) $one(redo ? '#form-undo' : '#form-redo').focus();
    }

    $one('#form-undo').addEventListener('click', () => restoreFormEdit(false));
    $one('#form-redo').addEventListener('click', () => restoreFormEdit(true));
    $one('#form-studio-layout').addEventListener('focusout', () => { formEdits.group = null; });

    function formChanged(event = null, remember = true) {
        if (remember) rememberFormEdit(event);
        dirty = true;
        formLoadSerial++;
        $one('#designer-state').textContent = 'Unsaved changes';
        $one('#designer-save-status').textContent = 'Save your draft before reviewing publication. Existing applications keep their published form.';
        updateFormPublication();
    }

    function formSettingControls(field, index) {
        const definitions = ['text', 'textarea', 'email'].includes(field.type)
            ? [['placeholder', 'Placeholder', 'text'], ['min_length', 'Minimum characters', 'number'], ['max_length', 'Maximum characters', 'number']]
            : field.type === 'number' ? [['min_value', 'Minimum value', 'number'], ['max_value', 'Maximum value', 'number']]
                : field.type === 'date' ? [['earliest_date', 'Earliest date', 'date'], ['latest_date', 'Latest date', 'date']] : [];
        const controls = new Map();
        const validate = () => {
            for (const [min, max] of [['min_length', 'max_length'], ['min_value', 'max_value'], ['earliest_date', 'latest_date']]) {
                const maximum = controls.get(max);
                const reversed = field.settings?.[min] !== undefined && field.settings?.[max] !== undefined && field.settings[min] > field.settings[max];
                maximum?.setCustomValidity(reversed ? (field.type === 'date'
                    ? 'The latest date must be on or after the earliest date.' : 'The maximum must be at least the minimum.') : '');
            }
        };
        const result = definitions.map(([key, label, type]) => {
            const characters = key.endsWith('_length');
            const control = node('input', { id: `designer-${index}-${key}`, class: 'form-control', type,
                ...(type === 'text' ? { maxlength: 160 } : type === 'number' ? { step: 1, min: characters ? 0 : -999999999, max: characters ? field.type === 'textarea' ? 4000 : 191 : 999999999 } : {}) });
            control.value = field.settings?.[key] ?? '';
            controls.set(key, control);
            control.addEventListener('input', event => {
                field.settings ||= {};
                if (control.value === '') delete field.settings[key];
                else field.settings[key] = type === 'number' ? Number(control.value) : control.value;
                if (!Object.keys(field.settings).length) delete field.settings;
                validate();
                formChanged(event);
            });
            return node('div', { class: type === 'text' ? 'col-12' : 'col-md-6' }, node('label', { class: 'form-label', for: control.id }, label), control);
        });
        validate();
        return result;
    }

    function renderDesigner(focusIndex = null, focusControl = null) {
        formEdits.group = null;
        formLayoutEditor.cleanup();
        const container = $one('#designer-fields'); clear(container);
        const cards = new Map();
        const refreshConditionProblems = () => {
            formDraft.fields.forEach((field, index) => {
                const visibility = cards.get(field.key)?.querySelector('.field-visibility');
                if (!visibility) return;
                const notice = visibility.querySelector('[data-field-condition-problem]');
                const problem = conditionProblem(field, index);
                if (!problem) notice?.remove();
                else if (!notice) visibility.append(node('p', { class: 'text-danger mb-0', role: 'status', 'data-field-condition-problem': '' }, problem));
                else if (notice.textContent !== problem) notice.textContent = problem;
            });
        };
        if (!formDraft.fields.length && !formDraft.layout?.length) container.append(emptyState('A blank canvas, full of possibility.', 'Add the questions your applicants need.', 'layout-text-window'));
        formDraft.fields.forEach((field, index) => {
            const types = window.applicationQuestionTypes;
            const title = node(formDraft.layout?.length ? 'h4' : 'h3', { id: `field-title-${index}` }, field.label || types[field.type]);
            const drag = node('button', { class: 'icon-button form-field-drag', type: 'button', 'aria-label': `Reorder field ${index + 1}`, title: 'Drag to reorder; use the arrow buttons with a keyboard' }, icon('grip-vertical'));
            const heading = node('div', { class: 'designer-field-header' }, drag,
                node('span', { class: 'field-order', 'aria-hidden': 'true' }, String(index + 1).padStart(2, '0')),
                node('div', { class: 'field-heading-text' }, title, node('span', { class: 'field-type' }, types[field.type])));
            const controls = node('div', { class: 'field-controls' });
            for (const [label, name, delta] of [['Move field up', 'arrow-up', -1], ['Move field down', 'arrow-down', 1]]) {
                controls.append(node('button', { class: 'icon-button', type: 'button', 'data-field-move': name, 'aria-label': `${label}: ${field.label || `field ${index + 1}`}`, disabled: !formLayoutEditor.canMove(field.key, delta), onclick: () => {
                    const position = formLayoutEditor.move(field.key, delta);
                    formChanged(); renderDesigner(position, name);
                    $one('#form-sort-status').textContent = `${field.label || 'Field'} moved to position ${position + 1}.`;
                } }, icon(name)));
            }
            const duplicate = node('button', { class: 'icon-button', type: 'button', title: 'Duplicate field',
                'aria-label': `Duplicate field ${index + 1}`,
                disabled: formDraft.fields.length >= 40, onclick: () => {
                    const copy = structuredClone(field); copy.key = newFormFieldKey(); copy.label = `${copy.label || types[field.type]} copy`.slice(0, 120);
                    formDraft.fields.splice(index + 1, 0, copy); formLayoutEditor.insertAfter(field.key, copy.key);
                    formChanged(); renderDesigner(formDraft.fields.indexOf(copy), 'label');
                } }, icon('copy'));
            const remove = node('button', { class: 'icon-button field-remove', type: 'button', title: 'Remove field', 'data-field-remove': '',
                'aria-label': `Remove field ${index + 1}`, onclick: () => {
                    formDraft.fields.splice(index, 1); formLayoutEditor.remove(field.key); collapsedFormFields.delete(field.key); formChanged();
                    renderDesigner(Math.min(index, formDraft.fields.length - 1), 'drag');
                } }, icon('trash3'));
            if (formDraft.layout?.length) controls.append(node('button', { class: 'icon-button', type: 'button',
                title: 'Move to another column',
                'aria-label': `Move question ${index + 1} to another column`,
                onclick: () => formLayoutEditor.showMove(field) }, icon('box-arrow-right')));
            controls.append(duplicate, remove);
            const content = node('div', { class: 'designer-field-body row g-3', id: `field-body-${index}`, hidden: collapsedFormFields.has(field.key) });
            const toggle = node('button', { class: 'icon-button', type: 'button', 'aria-label': `${content.hidden ? 'Expand' : 'Collapse'} field ${index + 1}`, 'aria-expanded': String(!content.hidden), 'aria-controls': content.id, onclick: () => {
                content.hidden = !content.hidden;
                if (content.hidden) collapsedFormFields.add(field.key); else collapsedFormFields.delete(field.key);
                toggle.setAttribute('aria-expanded', String(!content.hidden)); toggle.setAttribute('aria-label', `${content.hidden ? 'Expand' : 'Collapse'} field ${index + 1}`);
                formDisplayTouched = true; rememberFormPresentation();
                toggle.replaceChildren(icon(content.hidden ? 'chevron-down' : 'chevron-up'));
            } }, icon(content.hidden ? 'chevron-down' : 'chevron-up'));
            controls.append(toggle); heading.append(controls);
            const input = (key, label, type = 'text') => {
                const id = `designer-${index}-${key}`;
                const control = node(type === 'textarea' ? 'textarea' : 'input', { class: 'form-control', id,
                    required: ['label', 'key', 'options'].includes(key),
                    maxlength: key === 'help' ? 600 : key === 'key' ? 40 : type === 'textarea' ? 7260 : 240,
                    ...(type === 'textarea' ? { rows: 3 } : { type: 'text' }) });
                control.value = type === 'textarea' ? field.options.join('\n') : field[key] || '';
                if (key !== 'key') control.setCustomValidity(formLayoutEditor.inputProblem(key, control.value));
                control.addEventListener('input', event => {
                    if (key === 'key') {
                        const previous = field.key;
                        const valid = formLayoutEditor.rename(previous, control.value);
                        control.setCustomValidity(valid ? '' : 'Use a unique key with lowercase letters, numbers and underscores.');
                        if (valid && previous !== field.key) {
                            if (collapsedFormFields.delete(previous)) collapsedFormFields.add(field.key);
                            if (expandedFormSettings.delete(previous)) expandedFormSettings.add(field.key);
                            cards.delete(previous); cards.set(field.key, card); card.dataset.fieldKey = field.key;
                            refreshConditionProblems();
                        }
                    } else {
                        if (type === 'textarea') field.options = control.value.split('\n').filter(value => value.trim());
                        else field[key] = control.value;
                        control.setCustomValidity(formLayoutEditor.inputProblem(key, control.value));
                    }
                    if (key === 'label') {
                        title.textContent = field.label || types[field.type];
                        for (const [name, label] of [['arrow-up', 'Move field up'], ['arrow-down', 'Move field down']])
                            controls.querySelector(`[data-field-move="${name}"]`).setAttribute('aria-label', `${label}: ${field.label || `field ${index + 1}`}`);
                    }
                    formChanged(event);
                });
                return node('div', { class: 'col-12' }, node('label', { class: 'form-label', for: id }, label), control);
            };
            content.append(input('label', 'Question or field label'), input('help', 'A helpful hint (optional)'));
            if (['select', 'radio'].includes(field.type)) content.append(input('options', 'Choices (one per line)', 'textarea'));
            const required = node('input', { class: 'form-check-input', type: 'checkbox', id: `field-required-${index}`, checked: field.required, onchange: event => { field.required = event.target.checked; formChanged(); } });
            content.append(node('div', { class: 'col-12' }, node('div', { class: 'form-check' }, required, node('label', { class: 'form-check-label', for: required.id }, 'An answer is required'))));
            const problem = conditionProblem(field, index);
            content.append(node('div', { class: 'col-12 field-visibility' },
                node('span', { class: 'muted-text' }, field.show_when ? 'Conditional visibility' : 'Always visible'),
                node('button', { class: 'btn btn-sm btn-outline-primary', type: 'button', 'data-field-conditions': '', 'aria-label': `Edit conditions for field ${index + 1}`, onclick: () => editFormConditions(field) }, 'Edit conditions'),
                ...(problem ? [node('p', { class: 'text-danger mb-0', role: 'status', 'data-field-condition-problem': '' }, problem)] : [])));
            const settings = node('details', { class: 'designer-settings col-12', open: expandedFormSettings.has(field.key) }, node('summary', {}, 'Field settings'), node('div', { class: 'row g-3 designer-setting-fields' }, input('key', 'Stable field key'), ...formSettingControls(field, index)));
            let settingsWasOpen = settings.open;
            settings.addEventListener('toggle', () => {
                if (!settings.isConnected || settings.open === settingsWasOpen) return;
                settingsWasOpen = settings.open;
                if (settings.open) expandedFormSettings.add(field.key); else expandedFormSettings.delete(field.key);
                formDisplayTouched = true; rememberFormPresentation();
            });
            content.append(settings);
            const card = node('section', { class: 'designer-field', 'data-field-key': field.key, 'aria-labelledby': title.id }, heading, content);
            cards.set(field.key, card);
        });
        formLayoutEditor.render(container, cards);
        rememberFormPresentation();
        if (focusIndex !== null) {
            const field = container.querySelectorAll('.designer-field')[focusIndex];
            const selector = focusControl === 'remove' ? '[data-field-remove]' : focusControl === 'drag' ? '.form-field-drag' : focusControl === 'conditions' ? '[data-field-conditions]' : focusControl === 'label' ? 'input' : `[data-field-move="${focusControl}"]:not(:disabled)`;
            const control = field?.querySelector(selector);
            (control && !control.closest('[hidden]') ? control : field?.querySelector('.form-field-drag') || $one('#add-field-menu')).focus();
        }
    }

    function newFormFieldKey() { return `field_${crypto.randomUUID().replaceAll('-', '').slice(0, 28)}`; }

    for (const [selector, collapse] of [['#form-collapse-fields', true], ['#form-expand-fields', false]]) listen(selector, 'click', () => {
        formDisplayTouched = true;
        for (const field of formDraft.fields) { if (collapse) collapsedFormFields.add(field.key); else collapsedFormFields.delete(field.key); }
        renderDesigner();
    });

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-add-field]');
        if (!button) return;
        if (formDraft.fields.length >= 40) return showError(new Error('An application form supports up to 40 fields.'));
        const type = button.dataset.addField;
        const field = { key: newFormFieldKey(), label: '', help: '', type, required: false, options: ['select', 'radio'].includes(type) ? ['Option one', 'Option two'] : [], show_when: null };
        formLayoutEditor.add(field, button.dataset.container, button.dataset.column);
        formChanged(); renderDesigner(formDraft.fields.indexOf(field), 'label');
    });
    listen('#form-designer-meta', 'input', formChanged);
    listen('#form-designer-meta', 'change', formChanged);
    listen('#form-scope', 'change', syncFormScope);
    listen('#form-level-setup', 'click', () => classificationStudio.library('academic_level', '', null, [], null,
        async () => { drawer.hide(); await loadFormStudio(); }, 'Back to application form'));
    listen('#form-new', 'click', () => {
        if (dirty) { pendingNavigation = () => $one('#form-name').focus(); leaveModal.show(); } else resetFormStudio();
    });
    function resetFormStudio(focus = true) {
        cleanupFormConditions();
        formLoadSerial++; formDraft = { id: null, version: null, status: 'draft', fields: [], layout: [] };
        collapsedFormFields.clear(); expandedFormSettings.clear(); collapsedContainerSettings.clear();
        formPresentation = formPreferences.transient(); formDisplayTouched = false;
        $one('#form-name').value = ''; $one('#form-program').value = ''; $one('#form-level').value = '';
        formLibrary.select(null); syncFormScope(); resetFormEdits(); dirty = false;
        $one('#designer-state').textContent = 'New draft';
        $one('#designer-save-status').textContent = 'A draft is visible only to form administrators.';
        renderDesigner(); updateFormPublication();
        if (focus) $one('#form-name').focus();
    }
    async function saveDraft() {
        if (!$one('#form-designer-meta').reportValidity()) return false;
        const invalid = $all('input, select, textarea', $one('#designer-fields')).find(control => !control.checkValidity());
        if (invalid) {
            const disclosure = invalid.closest('details'); if (disclosure) disclosure.open = true;
            const fieldBody = invalid.closest('.designer-field-body');
            if (fieldBody?.hidden) invalid.closest('.designer-field')
                .querySelector(`[aria-controls="${CSS.escape(fieldBody.id)}"]`).click();
            invalid.reportValidity(); return false;
        }
        if (!formDraft.fields.length) throw new Error('Add at least one field before saving your form.');
        const invalidCondition = formDraft.fields.findIndex((field, index) => conditionProblem(field, index));
        if (invalidCondition !== -1) throw new Error(`Review visibility conditions for field ${invalidCondition + 1} before saving.`);
        if (formDraft.fields.some(field => !field.label.trim())) throw new Error('Give each field a label before saving.');
        if (formDraft.fields.some(field => field.required && field.settings?.max_length === 0))
            throw new Error('A required text question must allow at least one character.');
        const keys = formDraft.fields.map(field => field.key);
        if (new Set(keys).size !== keys.length || keys.some(key => !/^[a-z][a-z0-9_]{0,39}$/.test(key)))
            throw new Error('Each field needs a unique stable key using lowercase letters, numbers and underscores.');
        const source = formDraft;
        const input = { ...formTarget(), name: $one('#form-name').value, fields: structuredClone(formDraft.fields), layout: structuredClone(formDraft.layout || []) };
        formEdits.group = null;
        savingFormDraft = source; syncFormScope(); updateFormPublication();
        let record;
        try {
            record = await request('/sis/forms/', 'POST', { ...input, id: source.id || undefined, version: source.version ?? undefined });
        } catch (error) {
            if (source !== formDraft) return false;
            throw error;
        } finally {
            if (savingFormDraft === source) savingFormDraft = null;
            if ($one('#form-scope')) { syncFormScope(); updateFormPublication(); }
        }
        if (source !== formDraft) return false;
        const unchanged = JSON.stringify(input) === JSON.stringify({ ...formTarget(), name: $one('#form-name').value, fields: formDraft.fields, layout: formDraft.layout || [] });
        formDraft = unchanged ? record : { ...formDraft, id: record.id, version: record.version };
        const displayedDraft = formDraft;
        void formPreferences.record(record.id).then(presentation => {
            if (formDraft !== displayedDraft) return;
            formPresentation = presentation; rememberFormPresentation();
        });
        formEdits.current = formEditSnapshot(); formEdits.group = null; trimFormEdits(); updateFormEdits();
        if (unchanged) renderDesigner();
        dirty = !unchanged;
        if (current === 'forms') {
            $one('#designer-state').textContent = unchanged ? 'Draft saved' : 'Unsaved changes';
            $one('#designer-save-status').textContent = unchanged ? 'Saved. Preview your form or review publication.' : 'An earlier edit was saved. Save your latest changes before publication review.';
            formLibrary.select(record.id);
            await formLibrary.load();
            notify(unchanged ? 'Draft saved.' : 'Earlier edit saved. Your latest changes are still in the editor.');
        }
        updateFormPublication(); syncFormScope();
        return unchanged;
    }
    listen('#form-save', 'click', saveDraft);
    listen('#form-add-container', 'click', formLayoutEditor.addContainer);
    listen('#form-publish', 'click', () => {
        if (dirty || !formDraft.id || savingFormDraft === formDraft) throw new Error('Finish saving your draft before reviewing publication.');
        const reviewed = structuredClone(formDraft);
        const level = Boolean(reviewed.academic_level_id);
        const body = openDrawer('Review application form', 'APPLICATION PUBLICATION');
        let stale = false;
        const confirm = node('button', { type: 'button', class: 'btn btn-primary', onclick: event => task(async () => {
            try {
                await request(`/sis/forms/${reviewed.id}/publish/`, 'POST', { version: reviewed.version });
            } catch (error) {
                if (error.status === 409 && body.isConnected) { stale = true; confirm.remove(); }
                throw error;
            }
            if (formDraft.id === reviewed.id && formDraft.version === reviewed.version && !dirty) {
                formDraft = { ...formDraft, id: null, version: null, status: 'draft' };
                resetFormEdits();
                $one('#designer-state').textContent = 'Published';
                $one('#designer-save-status').textContent = 'Published forms stay unchanged. Open one from the library to create a new revision.';
                updateFormPublication(); syncFormScope();
            }
            if (!body.isConnected) return;
            notify('Application form published.');
            drawer.hide();
            await formLibrary.load();
        }, event.currentTarget) }, 'Confirm publication');
        body.append(
            node('button', { type: 'button', class: 'btn btn-link mb-3', onclick: event => task(async () => {
                drawer.hide();
                if (stale) await loadDraft(reviewed.id);
                else $one('#form-publish').focus();
            }, event.currentTarget) }, 'Back to editor'),
            recordSummary([
                ['Form', reviewed.name], [level ? 'Academic level' : 'Academic program', $one(level ? '#form-level' : '#form-program').selectedOptions[0]?.textContent || 'Selected target'],
                ['Fields', reviewed.fields.length],
            ]),
            node('p', { class: 'alert alert-info' }, (level ? 'This becomes the default form for programs assigned to this academic level. Program-specific forms take precedence. ' : 'This becomes the form for this program, taking precedence over any academic-level default. ') + 'It replaces the current form for this target. Existing applications keep their original form and answers.'),
            node('section', { class: 'record-section', 'aria-label': 'Saved application form review' },
                node('h3', {}, 'Applicant preview'), applicationControls(reviewed.fields, true, reviewed.layout || [])),
            confirm,
        );
    });

    function reviewFormDisable(form) {
        const level = Boolean(form.academic_level_id);
        const body = openDrawer('Remove application default', 'APPLICATION AVAILABILITY');
        const confirm = node('button', { type: 'button', class: 'btn btn-primary', onclick: event => task(async () => {
            try {
                await request(`/sis/forms/${level ? 'academic-levels' : 'programs'}/${level ? form.academic_level_id : form.program_id}/disable/`, 'POST', { form_id: form.id });
            } catch (error) {
                if (error.status === 409 && body.isConnected) {
                    confirm.remove();
                    body.append(node('button', { type: 'button', class: 'btn btn-outline-primary mt-3', onclick: event => task(async () => {
                        await formLibrary.load();
                        if (body.isConnected) drawer.hide();
                    }, event.currentTarget) }, 'Refresh forms'));
                }
                throw error;
            }
            if (!body.isConnected) return;
            notify('Application default removed. Existing submissions are preserved.');
            drawer.hide(); await formLibrary.load();
        }, event.currentTarget) }, 'Remove default');
        body.append(
            recordSummary([[level ? 'Academic level' : 'Academic program', form.program], ['Enabled form', form.name]]),
            node('p', { class: 'alert alert-info' }, (level ? 'Remove this form as the academic-level default. Program-specific forms stay enabled. ' : 'Remove this form as the program override. If an academic-level default is enabled, this program will use it. ') + 'Existing applications and answers are preserved.'),
            node('div', { class: 'inline-actions' },
                node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: () => drawer.hide() }, 'Keep enabled'),
                confirm,
            ),
        );
    }
    listen('#form-preview', 'click', () => {
        const body = openDrawer($one('#form-name').value || 'Application preview', 'AS YOUR APPLICANT WILL SEE IT');
        body.append(node('p', { class: 'muted-text' }, 'Preview only. Nothing you enter here is submitted.'), applicationControls(formDraft.fields, true, formDraft.layout || []));
    });

    async function startApplication(personId = null, personLabel = '') {
        const body = openDrawer('Start an application', 'ADMISSIONS INTAKE');
        body.classList.add('admission-start-panel');
        body.append(node('button', { type: 'button', class: 'btn btn-link mb-3',
            onclick: () => task(() => personId ? openPerson(personId) : drawer.hide()) },
            personId ? 'Back to person record' : 'Back to Admissions'));
        const introduction = node('p', { class: 'form-text' }, 'Choose the applicant, academic program and entry term. The next step opens the program’s published application for you to complete and review.');
        const setup = node('section', { 'aria-label': 'Application setup' });
        const stage = node('section', { hidden: true, 'aria-label': 'Program application' });
        const content = node('div');
        const back = node('button', { type: 'button', class: 'btn btn-outline-secondary mb-3' }, 'Back to application setup');
        const returnToSetup = () => {
            stage.hidden = true; setup.hidden = false; introduction.hidden = false;
            back.replaceChildren(icon('arrow-left'), node('span', {}, 'Back to application setup'));
            selector.querySelector('select')?.focus();
        };
        back.addEventListener('click', returnToSetup);
        stage.append(back, content);
        body.append(introduction, setup, stage);
        const fields = [...(personId ? [] : [['person_id', 'Person', 'lookup', 'people']]),
            ['program_id', 'Academic program', 'lookup', 'programs'], ['entry_term_id', 'Entry term', 'lookup', 'terms']];
        const selector = await buildForm(fields, {}, { layout: 'application_start' });
        if (!body.isConnected) return;
        if (personId) setup.append(recordSummary([['Applicant', personLabel || 'Selected person record']]));
        const status = node('p', { role: 'status', class: 'form-text' });
        const open = node('button', { class: 'btn btn-primary', type: 'submit' }, 'Open program application ', icon('arrow-right'));
        selector.append(node('p', { class: 'form-text' }, 'Returning to the same published form keeps your unfinished answers. A different person, program, entry term or published form starts fresh.'), status, open);
        setup.append(selector);
        let cached = null;
        selector.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                if (!selector.reportValidity()) return;
                const data = formData(selector, fields);
                status.textContent = 'Loading the published application…';
                let form;
                try { form = await request(`/sis/forms/programs/${data.program_id}/active/`); }
                catch (error) { if (!body.isConnected) return; if (error.status === 403) { cached = null; content.replaceChildren(); } throw error; }
                finally { if (status.isConnected) status.textContent = ''; }
                if (!body.isConnected || fields.some(([key]) => selector.elements.namedItem(key).value !== data[key])) return;
                const context = [['Person', personLabel || selector.elements.namedItem('person_id').selectedOptions[0].label],
                    ['Academic program', selector.elements.namedItem('program_id').selectedOptions[0].label],
                    ['Entry term', selector.elements.namedItem('entry_term_id').selectedOptions[0].label]];
                const key = JSON.stringify([personId || data.person_id, data.program_id, data.entry_term_id, form.id]);
                setup.hidden = true; introduction.hidden = true; stage.hidden = false;
                if (cached === key && content.childElementCount) {
                    content.querySelector('h3')?.focus(); return;
                }
                cached = key; clear(content);
                await renderSubmission(content, form, personId || data.person_id, data.entry_term_id, context, () => {
                    cached = null; back.replaceChildren(icon('plus-circle'), node('span', {}, 'Start another application'));
                });
            }, open);
        });
    }
    async function loadApplicantSelect() {
        const [programs, terms] = await Promise.all([options('programs'), options('terms')]);
        populate($one('#apply-program'), programs); populate($one('#apply-term'), terms);
        installLookup('#apply-program', 'programs'); installLookup('#apply-term', 'terms');
    }
    listen('#application-select-form', 'submit', async () => {
        const programId = $one('#apply-program').value, termId = $one('#apply-term').value;
        const context = [['Academic program', $one('#apply-program').selectedOptions[0].label], ['Entry term', $one('#apply-term').selectedOptions[0].label]];
        const person = await request('/sis/ui/me/');
        const form = await request(`/sis/forms/programs/${programId}/active/`);
        if (programId !== $one('#apply-program').value || termId !== $one('#apply-term').value) return;
        const container = $one('#applicant-form'); clear(container); await renderSubmission(container, form, person.id, termId, context);
    });
    async function renderSubmission(container, form, personId, termId, context = [], onComplete = null) {
        const title = node('h3', { class: 'application-review-title mb-4', tabindex: '-1' }, form.name);
        container.append(title);
        if (context.length) container.append(recordSummary(context));
        container.append(window.createApplicationIntake({ fields: form.fields, layout: form.layout || [], run: task,
            submit: answers => request(`/sis/forms/${form.id}/submissions/`, 'POST', {
                submission_id: form.submission_id, person_id: personId, program_id: form.application_program_id || form.program_id,
                entry_term_id: termId, answers,
            }),
            complete: () => {
                if (!container.isConnected) return;
                onComplete?.();
                clear(container); container.append(emptyState('You’re one step closer.', 'Your application has been submitted. The admissions team can now review your program-specific form.', 'check2-circle'));
                notify('Application submitted. Here’s to your next chapter.');
            },
        }));
        title.focus();
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
            $('#student-rule-builder').on(
                'afterAddRule.queryBuilder afterAddGroup.queryBuilder afterCreateRuleFilters.queryBuilder ' +
                'afterCreateRuleOperators.queryBuilder afterCreateRuleInput.queryBuilder ' +
                'afterUpdateRuleFilter.queryBuilder afterUpdateRuleOperator.queryBuilder afterSetRules.queryBuilder',
                accessibleRules,
            );
            accessibleRules(); ruleInitialized = true;
        }
        const rules = await records('rules');
        populate($one('#rule-saved'), rules.items.map(item => ({ value: item.id, label: item.name })), 'Start a new rule');
    }
    function accessibleRules() {
        $all('i', $one('#student-rule-builder')).forEach(icon => icon.setAttribute('aria-hidden', 'true'));
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

    async function loadAccess() { await accessStudio.load(); }
    async function createAccessDefinition(kind) {
        const body = openDrawer(kind === 'roles' ? 'Create a role' : 'Create a permission', 'A LITTLE MORE FLEXIBILITY');
        const fields = [['name', kind === 'roles' ? 'Role identifier' : 'Permission identifier'], ['description', 'Description', 'text', '', false]];
        const form = await buildForm(fields, {}, { layout: 'access_definition' }); form.append(node('p', { class: 'form-text' }, 'Use lowercase letters, digits, colons, underscores or hyphens. Identifiers stay stable; descriptions can explain the purpose.'), node('button', { class: 'btn btn-primary mt-3', type: 'submit' }, 'Create'));
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => { if (!form.reportValidity()) return; await request(`/sis/access/${kind}/`, 'POST', formData(form, fields)); notify('Created. Ready to assign.'); drawer.hide(); await loadAccess(); }, $one('[type="submit"]', form)); });
        body.append(form);
    }
    listen('#finance-reviews-open', 'click', () => financeReviewStudio.queue());
    listen('#access-new-role', 'click', () => createAccessDefinition('roles'));
    listen('#access-new-permission', 'click', () => createAccessDefinition('permissions'));

    function definitionActions(body, kind, definition, {back = null, complete = null} = {}) {
        const backLabel = kind === 'roles' ? 'Back to role' : 'Back to permission';
        const edit = node('button', { type: 'button', class: 'btn btn-outline-primary', onclick: () => task(async () => {
            const surface = openDrawer(`Edit ${definition.name}`, 'CUSTOM ACCESS DEFINITION');
            const fields = [['description', 'Description', 'text', '', false]];
            const form = await buildForm(fields, definition);
            if (!surface.isConnected) return;
            const save = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Save description'); form.append(save);
            form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
                await request(`/sis/access/${kind}/${definition.id}/`, 'PATCH', { description: form.elements.description.value });
                if (!surface.isConnected) return;
                notify('Description updated.');
                if (complete) await complete();
                else {drawer.hide(); await loadAccess();}
            }, save); });
            surface.append(form);
            if (back) surface.append(node('button', {type:'button',class:'btn btn-outline-secondary',onclick:()=>task(back)}, backLabel));
        }) }, 'Edit description');
        const remove = node('button', { type: 'button', class: 'btn btn-outline-danger', onclick: () => {
            const surface = openDrawer(`Delete ${definition.name}?`, 'CUSTOM ACCESS DEFINITION');
            surface.append(node('p', {}, 'Deleting this definition also removes its assignments. Review affected access before confirming.'));
            if (back) surface.append(node('button', {type:'button',class:'btn btn-outline-secondary',onclick:()=>task(back)}, backLabel));
            surface.append(node('button', { type: 'button', class: 'btn btn-danger', onclick: event => task(async () => {
                await request(`/sis/access/${kind}/${definition.id}/`, 'DELETE');
                if (!surface.isConnected) return;
                notify('Custom definition deleted.');
                if (complete) await complete();
                else {drawer.hide(); await loadAccess();}
            }, event.currentTarget) }, 'Confirm deletion'));
        } }, 'Delete definition');
        body.append(node('div', { class: 'inline-actions access-definition-actions' },
            actionMenu('Definition actions', [edit, remove], { context: `Definition actions for ${definition.name}` })));
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
