/* Dated employee assignments. Private drafts and reviewed terms stay in page memory. */
window.createWorkspacePositions = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, table, badge, flow, amounts, openEmployee, notify, can } = ui;
    const base = employee => `/sis/employees/${employee}/positions`;
    const button = (label, callback, style = 'btn-outline-primary') => node('button', {
        type: 'button', class: `btn ${style}`, onclick: event => task(callback, event.currentTarget),
    }, label);
    const grid = (headers, rows, label) => node('div', { class: 'table-responsive', role: 'region', tabindex: '0',
        'aria-label': label }, table(headers, rows, label));
    const rate = p => `${amounts.format(p.rate_minor, p.currency ?? p.job.currency, p.currency_digits)} ${
        amounts.basisLabel(p.basis ?? p.job.basis).toLowerCase()}`;
    const workload = minutes => minutes == null ? 'Not set' : `${Math.floor(minutes / 60)}h ${minutes % 60}m per week`;
    const status = (p, today) => p.voided ? 'Voided' : p.valid_from > today ? 'Scheduled'
        : p.valid_through && p.valid_through < today ? 'Ended' : 'Current';
    let navigation = 0;

    async function page(employee, after = null, stack = []) {
        navigation++;
        const body = openDrawer('Position assignments', 'HUMAN RESOURCES');
        body.append(button('Back to employment', () => openEmployee(employee)));
        body.append(button('Primary position', () => primary(employee)));
        const data = await request(`${base(employee)}/page/`, 'POST', after ? { after } : {});
        if (!body.isConnected) return;
        body.append(node('p', { class: 'form-text' },
            'Each assignment retains its reviewed job, pay grade and agreed terms. Different positions may run concurrently. Recording a position preserves its stated dates without inferring a hire or termination date.'),
            button('Record position assignment', () => assign(employee, data.new_id, data.today), 'btn-primary'));
        const rows = data.items.map(item => [item.job_code, item.title,
            `${item.valid_from} through ${item.valid_through || 'open ended'}`, rate(item), workload(item.weekly_minutes),
            badge(status(item, data.today)), button('Open position assignment', () => detail(employee, item.id))]);
        body.append(rows.length ? grid(['Job', 'Title', 'Dates', 'Agreed rate', 'Workload', 'Status', 'Action'], rows,
            'Employee position assignments') : node('p', {}, 'No position assignments have been recorded. Earlier positions remain unknown.'));
        const previous = button('Previous assignments', () => {
            const history = [...stack];
            return page(employee, history.pop() ?? null, history);
        });
        const next = button('Next assignments', () => page(employee, data.next_after, [...stack, after]));
        previous.disabled = !stack.length;
        next.disabled = !data.next_after;
        body.append(node('div', { class: 'inline-actions' }, previous, next));
    }
    async function detail(employee, id) {
        navigation++;
        const body = openDrawer('Position assignment', 'RETAINED HR AGREEMENT');
        body.append(button('Back to assignments', () => page(employee)));
        const record = await request(`${base(employee)}/${id}/`);
        if (!body.isConnected) return;
        body.append(node('h3', { class: 'private-text' }, `${record.job.code} · ${record.job.title}`),
            badge(record.voided ? 'Voided' : 'Recorded'),
            grid(['From', 'Through', 'Agreed rate', 'Workload'], [[record.valid_from,
                record.valid_through || 'Open ended', rate(record), workload(record.weekly_minutes)]], 'Retained position terms'),
            node('p', { class: 'form-text' }, record.grade
                ? `Reviewed pay grade: ${record.grade.code} · ${record.grade.title}. Job and pay-grade labels are retained as accepted.`
                : 'No pay grade was selected for this assignment.'),
            node('p', { class: 'form-text private-text' },
                `Last recorded: ${new Date(record.recorded_at).toLocaleString()}. Recorded by: ${record.recorded_by}.`));
        if (record.replaces_id) body.append(button('Open previous assignment', () => detail(employee, record.replaces_id)));
        if (can('sis:hr:primary') && !record.voided) {
            body.append(button('Designate as primary position', () => primary(employee, record)));
        }
        if (!record.voided) {
            body.append(button('Change position terms', async () => {
                const data = await request(`${base(employee)}/page/`, 'POST', {});
                if (!body.isConnected) return;
                await assign(employee, data.new_id, data.today, record);
            }), button('Change end date', () => flow('Save assignment end date', [
                ['valid_through', 'Through date', 'date', '', false], ['reason', 'Reason', 'textarea'],
            ], `${base(employee)}/${id}/end/`, data => ({ ...data, valid_through: data.valid_through || null,
                version: record.version }), () => detail(employee, id), record,
            'The through date is inclusive. Leave it blank to remove a scheduled closure. Earlier decisions remain in protected history.')));
        }
        body.append(button(record.voided ? 'Restore assignment' : 'Void assignment', () => flow(
            record.voided ? 'Confirm assignment restoration' : 'Confirm assignment void', [['reason', 'Reason', 'textarea']],
            `${base(employee)}/${id}/void/`, data => ({ ...data, voided: !record.voided, version: record.version }),
            () => detail(employee, id), {}, record.voided
                ? 'Mark this recorded agreement valid again. The earlier void remains in protected history.'
                : 'Record why this assignment should be marked incorrect. Earlier facts stay protected.')));
    }
    async function assign(employee, id, today, previous = null) {
        navigation++;
        const body = openDrawer(previous ? 'Change position terms' : 'Record position assignment', 'HUMAN RESOURCES');
        body.append(button(previous ? 'Back to assignment' : 'Back to assignments', () => previous
            ? detail(employee, previous.id) : page(employee)));
        body.append(node('p', { class: 'form-text' }, previous
            ? 'The previous agreement will end the day before the new one starts. Both decisions save together. Review the new terms and explain the change.'
            : 'Select an active job, review its defaults and enter the agreed terms. Recording dates is an HR assertion; earlier positions are not inferred.'));
        const form = node('form', { class: 'record-form' });
        const controls = {};
        const field = (key, label, type = 'text', value = '', required = true) => {
            const control = node(type === 'select' ? 'select' : type === 'textarea' ? 'textarea' : 'input', {
                id: `position-${key}`, name: key, class: type === 'select' ? 'form-select' : 'form-control', required,
                ...(type === 'select' || type === 'textarea' ? {} : { type }),
                ...(type === 'textarea' ? { rows: 3, maxlength: 1000 } : {}),
                ...(type === 'number' ? { min: 0, max: key === 'extra_minutes' ? 59 : 168, step: 1 } : {}),
            });
            control.value = value;
            control.addEventListener('input', () => {
                control.setCustomValidity(''); control.classList.remove('is-invalid'); control.removeAttribute('aria-invalid');
            });
            controls[key] = control;
            form.append(node('div', { class: 'mb-3' }, node('label', { for: control.id, class: 'form-label' }, label), control));
            return control;
        };
        const jobs = field('job_id', 'Job definition', 'select');
        jobs.append(node('option', { value: '' }, 'Choose a job'));
        const search = node('input', { type: 'search', maxlength: 100, class: 'form-control',
            'aria-label': 'Search job definitions', placeholder: 'Search by code or title' });
        let cursor = null, selected = null, epoch = 0, choiceEpoch = 0;
        let iso = [];
        const source = node('div', { class: 'record-section' });
        const info = node('p', { role: 'status', class: 'form-text' });
        const find = button('Find jobs', () => choices(search.value));
        const more = button('More jobs', () => choices(search.value, cursor));
        more.hidden = true;
        form.append(search, node('div', { class: 'inline-actions my-2' }, find, more), info, source);
        field('valid_from', previous ? 'Change effective on' : 'From date', 'date', previous && previous.valid_from >= today ? '' : today);
        field('valid_through', 'Through date', 'date', '', false);
        const amount = field('rate_minor', 'Agreed rate', 'text', '', false);
        amount.inputMode = 'decimal';
        field('weekly_hours', 'Weekly hours', 'number', '', false);
        field('extra_minutes', 'Additional minutes', 'number', '', false);
        field('reason', 'Reason for this agreement', 'textarea');
        form.append(node('p', { class: 'form-text' },
            'Blank rate or workload means unknown; zero is explicit. These terms do not calculate payroll or relabel earlier time entries.'));
        const review = node('button', { type: 'submit', class: 'btn btn-primary', disabled: true }, 'Review assignment');
        form.append(review);
        const confirmation = node('div', { hidden: true });
        body.append(form, confirmation);
        async function choices(query, after = null) {
            const token = ++choiceEpoch;
            const data = await request('/sis/hr/jobs/page/', 'POST', { search: query, ...(after ? { after } : {}) });
            if (!body.isConnected || token !== choiceEpoch) return;
            iso = data.currencies;
            const value = jobs.value;
            const option = [...jobs.options].find(item => item.value === value)?.cloneNode(true);
            if (!after) { jobs.replaceChildren(node('option', { value: '' }, 'Choose a job')); if (option && value) jobs.append(option); }
            data.items.filter(item => item.active).forEach(item => {
                if (![...jobs.options].some(option => option.value === item.id)) jobs.append(node('option', { value: item.id }, `${item.code} · ${item.title}`));
            });
            jobs.value = value;
            cursor = data.next_after;
            more.hidden = !cursor;
            info.textContent = `${jobs.options.length - 1} active choices loaded. Search to find another job.`;
        }
        async function loadSource() {
            const token = ++epoch;
            selected = null; review.disabled = true; amount.disabled = true;
            source.replaceChildren();
            if (!jobs.value) return;
            const job = await request(`/sis/hr/jobs/${jobs.value}/`);
            const grade = job.pay_grade_id ? await request(`/sis/hr/pay-grades/${job.pay_grade_id}/`) : null;
            if (!body.isConnected || token !== epoch) return;
            if (!job.active || (grade && !grade.active)) throw new Error('Choose an active job and pay grade.');
            const places = iso.find(item => item.code === job.currency)?.digits;
            if (!Number.isInteger(places)) throw new Error('Review the supported job currency again.');
            selected = { job, grade, digits: places };
            const sameUnits = previous && previous.job.id === job.id && previous.job.currency === job.currency
                && previous.job.basis === job.basis && previous.currency_digits === places;
            amount.value = amounts.text(sameUnits ? previous.rate_minor : job.rate_minor, places);
            const minutes = sameUnits ? previous.weekly_minutes : job.weekly_minutes;
            controls.weekly_hours.value = minutes == null ? '' : Math.floor(minutes / 60);
            controls.extra_minutes.value = minutes == null ? '' : minutes % 60;
            source.append(node('h3', { class: 'h5 private-text' }, `${job.code} · ${job.title}`),
                node('p', {}, `${job.currency} · ${amounts.basisLabel(job.basis)} · ${places} decimal places`),
                node('p', { class: 'form-text' }, grade
                    ? `Pay grade ${grade.code}: ${amounts.format(grade.minimum_minor, job.currency, places)} through ${amounts.format(grade.maximum_minor, job.currency, places)}.`
                    : 'This job has no pay grade.'));
            review.disabled = false; amount.disabled = false;
        }
        jobs.addEventListener('change', () => task(loadSource));
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (!selected) return;
            amount.setCustomValidity('');
            if (!form.reportValidity()) return;
            const rateMinor = amounts.parse(amount.value, selected.digits, amount);
            if (rateMinor === false) { form.reportValidity(); return; }
            const minutes = controls.weekly_hours.value === '' && controls.extra_minutes.value === '' ? null
                : Number(controls.weekly_hours.value || 0) * 60 + Number(controls.extra_minutes.value || 0);
            if (minutes > 10080) { controls.weekly_hours.setCustomValidity('Weekly hours must fit within a seven-day week.'); form.reportValidity(); return; }
            const payload = { id, job_id: selected.job.id, job_version: selected.job.version,
                grade_version: selected.grade?.version ?? null, valid_from: controls.valid_from.value,
                valid_through: controls.valid_through.value || null, rate_minor: rateMinor, weekly_minutes: minutes,
                reason: controls.reason.value, replaces_id: previous?.id ?? null, replaced_version: previous?.version ?? null };
            confirmation.replaceChildren(node('h3', { class: 'h5' }, 'Review position agreement'),
                grid(['Job', 'From', 'Through', 'Agreed rate', 'Workload'], [[`${selected.job.code} · ${selected.job.title}`, payload.valid_from,
                    payload.valid_through || 'Open ended', rate({ rate_minor: rateMinor,
                        job: selected.job, currency_digits: selected.digits }),
                    workload(minutes)]], 'Reviewed position agreement'),
                node('p', { class: 'form-text' }, previous ? `Previous assignment closes the day before ${payload.valid_from}.`
                    : 'The reviewed job and pay-grade definitions will be retained with this agreement.'),
                node('p', { class: 'private-text' }, `Reason: ${payload.reason}`),
                button('Back to assignment form', () => { confirmation.hidden = true; form.hidden = false; review.focus(); }),
                button('Confirm assignment', async () => {
                    const saved = await request(`${base(employee)}/assign/`, 'POST', payload);
                    if (!body.isConnected) return;
                    notify('Position agreement recorded.');
                    await detail(employee, saved.id);
                }, 'btn-primary'));
            form.hidden = true; confirmation.hidden = false;
            confirmation.querySelector('button').focus();
        });
        await choices('');
    }
    const primaryLabel = selection => selection ? `${selection.code} · ${selection.title}` : 'No primary designation';
    const primaryStatus = status => ({ current: 'Current', changed: 'Appointment changed — HR review required',
        ended: 'Appointment ended — HR review required', voided: 'Appointment voided — HR review required',
        scheduled: 'Appointment does not cover today — HR review required', cleared: 'Explicitly cleared',
        unrecorded: 'Not recorded; earlier designations are unknown' })[status] || 'Review required';

    async function primary(employee, proposed = undefined) {
        navigation++;
        const body = openDrawer('Primary position', 'EMPLOYMENT');
        body.append(button('Back to employment', () => openEmployee(employee)));
        const data = await request(`/sis/employees/${employee}/primary-position/`);
        if (!body.isConnected) return;
        body.append(node('h3', { class: 'h5 private-text' }, primaryLabel(data.selection)),
            node('p', { role: 'status', class: 'private-text' }, primaryStatus(data.status)),
            node('p', { class: 'form-text' }, 'This is an explicit HR designation from confirmation onward. It does not change pay, security access or the position actually worked on a timesheet. Ended or replaced appointments stay visible until HR reviews the designation.'));
        const allowed = can('sis:hr:manage') && can('sis:hr:primary');
        if (can('sis:hr:manage')) body.append(button('Choose from position assignments', () => page(employee)));
        if (can('sis:hr:manage') && can('sis:audit:read')) {
            body.append(button('Primary designation history', () => primaryHistory(employee)));
        }
        if (!allowed) return;
        if (proposed === undefined) {
            if (data.selection) body.append(button('Clear primary designation', () => primary(employee, null)));
            return;
        }
        const form = node('form', { class: 'record-form' });
        const reason = node('textarea', { id: 'primary-position-reason', class: 'form-control', required: true,
            rows: 3, maxlength: 1000 });
        const submit = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Review primary designation');
        form.append(node('h3', { class: 'h5 private-text' }, `Proposed: ${primaryLabel(proposed?.job
            ? { code: proposed.job.code, title: proposed.job.title } : proposed)}`),
            node('label', { for: reason.id, class: 'form-label' }, 'Reason for primary designation'), reason, submit);
        const confirmation = node('div', { hidden: true });
        body.append(form, confirmation);
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            task(async () => {
                const payload = { operation_id: data.operation_id, version: data.version,
                    position_id: proposed?.id ?? null, position_version: proposed?.version ?? null, reason: reason.value };
                const reviewed = await request(`/sis/employees/${employee}/primary-position/review/`, 'POST', payload);
                if (!body.isConnected) return;
                confirmation.replaceChildren(node('h3', { class: 'h5' }, 'Review primary designation'),
                    node('p', { class: 'private-text' }, `Before: ${primaryLabel(reviewed.before)}`),
                    node('p', { class: 'private-text' }, `After: ${primaryLabel(reviewed.after)}`),
                    node('p', { class: 'private-text' }, `Reason: ${payload.reason}`),
                    button('Revise primary designation', () => { confirmation.hidden = true; form.hidden = false; reason.focus(); }),
                    button('Confirm primary designation', async () => {
                        await request(`/sis/employees/${employee}/primary-position/confirm/`, 'POST', {
                            ...payload, review_token: reviewed.review_token,
                        });
                        if (!body.isConnected) return;
                        notify('Primary position designation recorded.');
                        await primary(employee);
                    }, 'btn-primary'));
                form.hidden = true; confirmation.hidden = false;
                confirmation.querySelector('button').focus();
            }, submit);
        });
    }
    async function primaryHistory(employee, after = 0, through = null, stack = []) {
        const token = ++navigation;
        const origin = document.getElementById('drawer-body')?.firstChild;
        const data = await request(`/sis/employees/${employee}/primary-position/history/`, 'POST', {
            after, through_version: through,
        });
        if (token !== navigation || (origin && !origin.isConnected)) return;
        const body = openDrawer('Primary designation history', 'RETAINED HR DECISIONS');
        body.append(button('Back to primary position', () => primary(employee)));
        body.append(node('p', { class: 'form-text' }, 'Only recorded designations appear. Earlier designations remain unknown. Recorder labels reflect current account names.'),
            data.items.length ? grid(['Decision', 'Primary position', 'Effective', 'Recorded by', 'Reason'], data.items.map(item => [
                item.version, primaryLabel(item.selection), new Date(item.effective_at).toLocaleString(),
                item.recorded_by, item.reason,
            ]), 'Primary designation decisions') : node('p', {}, 'No designation decisions have been recorded.'));
        const previous = button('Previous decisions', () => {
            const prior = [...stack]; return primaryHistory(employee, prior.pop() ?? 0, data.through_version, prior);
        });
        const next = button('Next decisions', () => primaryHistory(employee, data.next_after, data.through_version, [...stack, after]));
        previous.disabled = !stack.length; next.disabled = data.next_after === null;
        body.append(node('div', { class: 'inline-actions' }, previous, next));
    }
    return { page, detail, primary };
};
