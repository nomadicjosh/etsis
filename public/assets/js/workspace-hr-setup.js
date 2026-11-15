/* Editable HR reference setup. Private drafts stay in page memory. */
window.createWorkspaceHrSetup = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, table, badge, notify } = ui;
    let currencies = [];
    let serial = 0;
    const title = kind => kind === 'jobs' ? 'Job definitions' : 'Pay grades';
    const button = (label, callback, style = 'btn-outline-primary') => node('button', {
        type: 'button', class: `btn ${style}`, onclick: event => task(callback, event.currentTarget),
    }, label);
    const digits = code => currencies.find(item => item.code === code)?.digits ?? 2;
    const amountText = (minor, code) => minor == null ? '' : (minor / 10 ** digits(code)).toFixed(digits(code));
    const amount = (minor, code) => minor == null ? 'Not set' : new Intl.NumberFormat(undefined, {
        style: 'currency', currency: code,
    }).format(minor / 10 ** digits(code));
    const minor = (value, code, control) => {
        if (value.trim() === '') return null;
        const places = digits(code);
        if (!new RegExp(`^[0-9]+(?:\\.[0-9]{1,${Math.max(1, places)}})?$`).test(value)
            || (places === 0 && value.includes('.'))) {
            control.setCustomValidity(`Use an amount with at most ${places} decimal places.`);
            return false;
        }
        const [whole, fraction = ''] = value.split('.');
        const result = Number(whole) * 10 ** places + Number(fraction.padEnd(places, '0'));
        if (!Number.isSafeInteger(result) || result > 2000000000) {
            control.setCustomValidity('This amount exceeds the supported range.');
            return false;
        }
        return result;
    };
    async function library(kind = 'jobs', search = '', after = null, stack = []) {
        const body = openDrawer('HR setup', title(kind));
        body.append(button('Refresh setup', () => library(kind, search, after, stack)));
        const data = await request(`/sis/hr/${kind}/page/`, 'POST', { search, ...(after ? { after } : {}) });
        if (!body.isConnected) return;
        currencies = data.currencies;
        body.replaceChildren();
        body.append(node('div', { class: 'inline-actions mb-3', role: 'group', 'aria-label': 'HR setup category' },
            ...['jobs', 'pay-grades'].map(key => {
                const tab = button(title(key), () => library(key), key === kind ? 'btn-primary' : 'btn-outline-primary');
                tab.setAttribute('aria-pressed', String(key === kind));
                return tab;
            })), node('p', { class: 'form-text' },
            'Reusable setup for HR. These defaults do not change an employee, grant access or calculate pay. Retire a definition when it should no longer be used.'));
        body.append(button(kind === 'jobs' ? 'Add job definition' : 'Add pay grade', () => edit(kind, {
            id: data.new_id, currency: 'USD', basis: 'hourly', active: true,
        })));
        const form = node('form', { class: 'my-3' });
        const input = node('input', { type: 'search', class: 'form-control', maxlength: 100,
            id: `hr-search-${++serial}`, value: search });
        form.append(node('label', { for: input.id, class: 'form-label' }, `Search ${title(kind).toLowerCase()}`), input,
            node('button', { class: 'btn btn-outline-primary mt-2', type: 'submit' }, 'Find definitions'));
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(() => library(kind, input.value), form.querySelector('button'));
        });
        body.append(form);
        const rows = data.items.map(item => [item.code, item.title,
            kind === 'jobs' ? amount(item.rate_minor, item.currency)
                : `${amount(item.minimum_minor, item.currency)} – ${amount(item.maximum_minor, item.currency)}`,
            item.basis === 'hourly' ? 'Per hour' : 'Per year', badge(item.active ? 'Active' : 'Retired'),
            button('Edit definition', () => edit(kind, item))]);
        body.append(rows.length ? node('div', { class: 'table-responsive', role: 'region', tabindex: '0',
            'aria-label': `${title(kind)} list` }, table(['Code', 'Title', 'Rate or range', 'Basis', 'Status', 'Action'], rows,
            title(kind))) : node('p', {}, 'No definitions match this search.'));
        const previous = button('Previous definitions', () => {
            const history = [...stack];
            return library(kind, search, history.pop() ?? null, history);
        });
        const next = button('Next definitions', () => library(kind, search, data.next_after, [...stack, after]));
        previous.disabled = !stack.length;
        next.disabled = !data.next_after;
        body.append(node('div', { class: 'inline-actions' }, previous, next));
    }
    async function edit(kind, record, returnTo = null) {
        const job = kind === 'jobs';
        const body = openDrawer(record.version == null ? (job ? 'New job definition' : 'New pay grade') : record.title,
            'HR SETUP');
        const back = () => returnTo ? returnTo(null) : library(kind);
        body.append(button(returnTo ? 'Back to job draft' : 'Back to HR setup', back, 'btn-link'));
        const form = node('form', { class: 'record-form' });
        const controls = {};
        const field = (key, label, type = 'text', value = '', required = true, choices = []) => {
            const control = node(type === 'select' ? 'select' : 'input', {
                id: `hr-field-${++serial}`, name: key, class: type === 'checkbox' ? 'form-check-input'
                    : type === 'select' ? 'form-select' : 'form-control', required: required && type !== 'checkbox',
                ...(type === 'select' ? {} : { type }),
                ...(type === 'text' ? { maxlength: key === 'code' ? 40 : 191 } : {}),
                ...(type === 'number' ? { min: 0, max: key === 'weekly_extra' ? 59 : 168, step: 1 } : {}),
            });
            if (type === 'select') choices.forEach(item => control.append(node('option', {
                value: item.value, disabled: item.disabled,
            }, item.label)));
            if (type === 'checkbox') control.checked = value === true;
            else control.value = value ?? '';
            control.addEventListener('input', () => {
                control.setCustomValidity(''); control.removeAttribute('aria-invalid'); control.classList.remove('is-invalid');
            });
            controls[key] = control;
            const labelNode = node('label', { for: control.id, class: 'form-label' }, label);
            form.append(node('div', { class: type === 'checkbox' ? 'form-check mb-3' : 'mb-3' },
                ...(type === 'checkbox' ? [control, labelNode] : [labelNode, control])));
            return control;
        };
        form.append(node('h3', { class: 'h5' }, 'Definition'));
        field('code', 'Code', 'text', record.code);
        field('title', 'Title', 'text', record.title);
        field('active', 'Active definition', 'checkbox', record.active);
        form.append(node('h3', { class: 'h5 mt-4' }, job ? 'Pay defaults' : 'Pay range'),
            node('p', { class: 'form-text' },
                'Amounts use the selected currency and rate basis. Hourly and annual values are never converted automatically.'));
        field('currency', 'Currency', 'select', record.currency, true,
            currencies.map(item => ({ value: item.code, label: item.code })));
        field('basis', 'Rate basis', 'select', record.basis, true,
            [{ value: 'hourly', label: 'Hourly' }, { value: 'annual', label: 'Annual' }]);
        if (job) {
            field('rate_minor', 'Default rate', 'text', record.rate_text ?? amountText(record.rate_minor, record.currency), false);
            controls.rate_minor.inputMode = 'decimal';
            form.append(node('p', { class: 'form-text' }, 'Leave the rate blank if it has not been defined.'));
            field('pay_grade_id', 'Pay grade', 'select', '', false, [{ value: '', label: 'No pay grade' }]);
            const lookup = node('input', { type: 'search', maxlength: 100, class: 'form-control',
                'aria-label': 'Search pay grades', placeholder: 'Find a pay grade by code or title' });
            let gradePage = null;
            const find = button('Find pay grades', () => loadGrades(lookup.value));
            const more = button('More pay grades', () => loadGrades(lookup.value, gradePage.next_after));
            more.hidden = true;
            form.append(lookup, node('div', { class: 'inline-actions my-2' }, find, more));
            const gradeStatus = node('p', { class: 'form-text', role: 'status' });
            form.append(gradeStatus, button('Create pay grade', async () => {
                const draft = snapshot();
                await edit('pay-grades', { id: gradePage.new_id, currency: draft.currency, basis: draft.basis, active: true },
                    saved => edit('jobs', { ...record, ...draft, pay_grade_id: saved?.id ?? draft.pay_grade_id }));
            }));
            const selectedGrade = record.pay_grade_id ? await request(`/sis/hr/pay-grades/${record.pay_grade_id}/`) : null;
            if (!body.isConnected) return;
            if (selectedGrade) controls.pay_grade_id.append(node('option', { value: selectedGrade.id },
                `${selectedGrade.code} · ${selectedGrade.title}${selectedGrade.active ? '' : ' · Retired'}`));
            controls.pay_grade_id.value = record.pay_grade_id ?? '';
            async function loadGrades(search, after = null) {
                const selected = controls.pay_grade_id.value;
                const selectedOption = [...controls.pay_grade_id.options].find(item => item.value === selected)?.cloneNode(true);
                const data = await request('/sis/hr/pay-grades/page/', 'POST', { search, ...(after ? { after } : {}) });
                if (!body.isConnected) return;
                gradePage = data;
                if (!after) {
                    controls.pay_grade_id.replaceChildren(node('option', { value: '' }, 'No pay grade'));
                    if (selectedOption && selected) controls.pay_grade_id.append(selectedOption);
                }
                data.items.forEach(item => {
                    if (![...controls.pay_grade_id.options].some(option => option.value === item.id)) {
                        controls.pay_grade_id.append(node('option', { value: item.id, disabled: !item.active },
                            `${item.code} · ${item.title}${item.active ? '' : ' · Retired'}`));
                    }
                });
                controls.pay_grade_id.value = selected;
                more.hidden = !data.next_after;
                gradeStatus.textContent = `${controls.pay_grade_id.options.length - 1} choices loaded. Search to find another grade.`;
            }
            await loadGrades('');
            if (!body.isConnected) return;
            form.append(node('h3', { class: 'h5 mt-4' }, 'Default workload'),
                node('p', { class: 'form-text' }, 'Optional planning hours, not a timesheet limit or a pay calculation.'));
            field('weekly_hours', 'Weekly hours', 'number', record.weekly_hours ?? (record.weekly_minutes == null
                ? '' : Math.floor(record.weekly_minutes / 60)), false);
            field('weekly_extra', 'Additional minutes', 'number', record.weekly_extra ?? (record.weekly_minutes == null
                ? '' : record.weekly_minutes % 60), false);
        } else {
            field('minimum_minor', 'Minimum rate', 'text', amountText(record.minimum_minor, record.currency));
            field('maximum_minor', 'Maximum rate', 'text', amountText(record.maximum_minor, record.currency));
            controls.minimum_minor.inputMode = controls.maximum_minor.inputMode = 'decimal';
        }
        const help = node('p', { class: 'form-text', role: 'status' });
        const unitHelp = () => {
            const places = digits(controls.currency.value);
            help.textContent = `${controls.currency.value} uses ${places} decimal places. Changing currency or basis requires reviewing the entered amounts.`;
            ['rate_minor', 'minimum_minor', 'maximum_minor'].forEach(key => controls[key]?.setCustomValidity(''));
        };
        controls.currency.addEventListener('change', unitHelp);
        controls.basis.addEventListener('change', unitHelp);
        unitHelp();
        form.append(help);
        function snapshot() {
            const draft = Object.fromEntries(Object.entries(controls).map(([key, control]) => [key,
                control.type === 'checkbox' ? control.checked : control.value]));
            if (job) { draft.rate_text = controls.rate_minor.value; delete draft.rate_minor; }
            return draft;
        }
        const save = node('button', { type: 'submit', class: 'btn btn-primary' }, job ? 'Save job definition' : 'Save pay grade');
        form.append(save);
        form.addEventListener('submit', event => {
            event.preventDefault();
            task(async () => {
                Object.values(controls).forEach(control => control.setCustomValidity(''));
                if (!form.reportValidity()) return;
                const draft = snapshot();
                const payload = { id: record.id, code: draft.code, title: draft.title, active: draft.active,
                    currency: draft.currency, basis: draft.basis, version: record.version ?? null };
                const keys = job ? ['rate_minor'] : ['minimum_minor', 'maximum_minor'];
                for (const key of keys) {
                    payload[key] = minor(controls[key].value, draft.currency, controls[key]);
                    if (payload[key] === false) { form.reportValidity(); return; }
                }
                if (job) {
                    payload.pay_grade_id = draft.pay_grade_id || null;
                    payload.weekly_minutes = draft.weekly_hours === '' && draft.weekly_extra === '' ? null
                        : Number(draft.weekly_hours || 0) * 60 + Number(draft.weekly_extra || 0);
                    if (payload.weekly_minutes > 10080) {
                        controls.weekly_hours.setCustomValidity('Weekly hours must fit within a seven-day week.');
                        form.reportValidity(); return;
                    }
                }
                const saved = await request(`/sis/hr/${kind}/save/`, 'POST', payload);
                notify('HR definition saved.');
                if (returnTo) await returnTo(saved);
                else await library(kind);
            }, save);
        });
        body.append(form);
    }
    return { library };
};
