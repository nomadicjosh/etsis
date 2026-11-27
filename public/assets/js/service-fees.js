/* Private fee views stay in page memory. Browser return URLs never grant payment clearance. */
window.createServiceFees = function (ui) {
    "use strict";
    const { node, request, task, labels: l } = ui;
    const button = (label, run, glyph = 'arrow-right') => node('button', { type: 'button',
        class: 'btn btn-outline-primary d-inline-flex align-items-center gap-2', onclick: event => task(run, event.currentTarget) },
        node('i', { class: `bi bi-${glyph}`, 'aria-hidden': 'true' }), node('span', {}, label));
    const money = (minor, currency) => {
        const f = new Intl.NumberFormat(undefined, { style: 'currency', currency });
        return f.format(minor / 10 ** f.resolvedOptions().maximumFractionDigits);
    };
    const heading = (data) => `${l[data.purpose]} · ${data.labels.title}${data.labels.term ? ` · ${data.labels.term}` : ''}`;
    function summary(data) {
        const box = node('section', { class: 'service-fee-summary bg-white border rounded p-3 mb-3', 'aria-label': l.title });
        box.append(node('h3', { class: 'h5' }, heading(data)));
        const list = node('dl', { class: 'row mb-0' });
        for (const [label, value] of [[l.quoted, money(data.policy.minor, data.policy.currency)],
            [l.paid, money(data.net_minor, data.policy.currency)],
            [l.remaining, money(data.waived || data.applied_to ? 0 : Math.max(0, data.policy.minor - data.net_minor), data.policy.currency)]]) {
            list.append(node('dt', { class: 'col-sm-5' }, label), node('dd', { class: 'col-sm-7' }, value));
        }
        box.append(list, node('p', { class: 'mb-0 fw-semibold' }, data.applied_to ? l.used : data.waived ? l.waived : data.cleared ? l.cleared : l.due));
        if (data.net_minor > data.policy.minor) box.append(node('p', { class: 'alert alert-warning mt-3' }, l.overpaid));
        return box;
    }
    async function ownerGate(host, data, onClear) {
        host.replaceChildren();
        if (!data.required || data.cleared) { await onClear(data); return; }
        host.append(summary(data), node('p', {}, l.checkout_help), node('p', {}, l.contact));
        host.append(node('div', { class: 'd-flex flex-wrap gap-2' },
            button(l.pay, async () => {
                const result = await request(`/portal/api/service-fees/${data.fee_id}/checkout/`, 'POST', {});
                if (!host.isConnected) return;
                if (result.cleared) return ownerGate(host, await request(`/portal/api/service-fees/${data.fee_id}/`), onClear);
                const url = new URL(result.url);
                if (url.protocol !== 'https:' || url.hostname !== 'checkout.stripe.com' || url.username || url.password) throw new Error(l.contact);
                location.assign(url.href);
            }, 'credit-card'),
            button(l.refresh, async () => ownerGate(host, await request(`/portal/api/service-fees/${data.fee_id}/`), onClear), 'arrow-clockwise')));
    }
    async function ownerList(host, purpose, resume, after = null) {
        const page = await request('/portal/api/service-fees/page/', 'POST', { after });
        if (!host.isConnected) return;
        if (!after) host.replaceChildren(node('h2', { class: 'h4' }, l.title));
        for (const data of page.items.filter(item => item.purpose === purpose)) {
            const box = summary(data);
            if (!data.applied_to && resume) box.append(button(l.resume, () => resume(data), 'arrow-right'));
            box.append(button(l.history, () => history(box, data, 'owner'), 'clock-history'));
            host.append(box);
        }
        if (page.next_after) {
            const more = button(l.more, async () => { await ownerList(host, purpose, resume, page.next_after); more.remove(); }, 'chevron-down');
            host.append(more);
        }
    }
    async function history(host, data, channel, before = null, list = null) {
        if (!list) {
            host.querySelector('[data-fee-history]')?.remove();
            list = node('section', { 'data-fee-history': '', class: 'mt-3', 'aria-label': l.history });
            list.append(node('h4', { class: 'h6' }, l.history)); host.append(list);
        }
        const page = await request(`${channel === 'owner' ? '/portal/api' : '/sis'}/service-fees/${data.fee_id}/history/`, 'POST', { before });
        if (!host.isConnected) return;
        for (const item of page.items) list.append(node('p', { class: 'border-top pt-2' },
            `${l[item.action]} · ${item.recorded_at.slice(0, 10)}${item.reason ? ` · ${item.reason}` : ''}`));
        if (page.next_before) {
            const more = button(l.more_history, async () => { await history(host, data, channel, page.next_before, list); more.remove(); }, 'chevron-down');
            list.append(more);
        }
    }
    async function policies() {
        const host = ui.openDrawer(l.policies); host.classList.add('service-fee-workbench');
        const data = await request('/sis/service-fees/policies/');
        if (!host.isConnected) return;
        host.append(node('p', {}, l.policy_help));
        for (const purpose of ['application', 'transcript']) {
            const p = data[purpose];
            const fields = [['minor', l.amount, 'money'], ['currency', l.currency, 'currency'], ['waivers', l.waivers, 'checkbox']];
            const digits = new Intl.NumberFormat(undefined, { style: 'currency', currency: p.currency }).resolvedOptions().maximumFractionDigits;
            const form = await ui.buildForm(fields, { ...p, minor: (p.minor / 10 ** digits).toFixed(digits) });
            if (!host.isConnected) return;
            form.elements.minor.min = '0';
            form.prepend(node('h3', { class: 'h5' }, l[purpose]));
            const save = node('button', { type: 'submit', class: 'btn btn-primary' }, l.save);
            form.append(save); host.append(node('section', { class: 'bg-white border rounded p-3 mb-3' }, form));
            form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
                if (!form.reportValidity()) return;
                const saved = await request(`/sis/service-fees/policies/${purpose}/`, 'POST', { ...ui.formData(form, fields), version: p.revision });
                p.revision = saved.revision; ui.notify?.(l.recorded);
            }, save); });
        }
    }
    async function person(personId, back, after = null, host = null) {
        const page = await request(`/sis/people/${personId}/service-fees/page/`, 'POST', { after });
        if (!host) {
            host = ui.openDrawer(l.title); host.append(button(l.back, back, 'arrow-left'), node('p', { class: 'mt-3' }, l.person_help));
            const actions = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' });
            for (const purpose of ['application', 'transcript']) actions.append(button(l[purpose], () => prepare(personId, purpose, back), 'plus-circle'));
            host.append(actions);
        }
        for (const data of page.items) {
            const box = summary(data); box.append(button(l.open, () => detail(data.fee_id, () => person(personId, back), back), 'receipt')); host.append(box);
        }
        if (!page.items.length && !after) host.append(node('p', {}, l.empty));
        if (page.next_after) {
            const more = button(l.more, async () => { await person(personId, back, page.next_after, host); more.remove(); }, 'chevron-down'); host.append(more);
        }
    }
    async function prepare(personId, purpose, back) {
        const host = ui.openDrawer(l.prepare); host.classList.add('service-fee-workbench');
        host.append(button(l.back_fees, () => person(personId, back), 'arrow-left'));
        const fields = purpose === 'application' ? [['program_id', l.program, 'lookup', 'programs'], ['term_id', l.term, 'lookup', 'terms']]
            : [['student_id', l.student, 'lookup', 'students']];
        const form = await ui.buildForm(fields);
        const save = node('button', { type: 'submit', class: 'btn btn-primary' }, l.prepare); form.append(save); host.append(form);
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!form.reportValidity()) return;
            const data = await request(`/sis/people/${personId}/service-fees/prepare/`, 'POST', { purpose, ...ui.formData(form, fields) });
            if (!host.isConnected) return;
            if (data.required) return detail(data.fee_id, () => person(personId, back), back);
            host.append(node('p', { role: 'status', class: 'alert alert-info' }, l.free));
        }, save); });
    }
    async function detail(id, back, recordBack = null) {
        const host = ui.openDrawer(l.title); host.classList.add('service-fee-workbench');
        const data = await request(`/sis/service-fees/${id}/`);
        if (!host.isConnected) return;
        if (recordBack) host.append(button(l.back, recordBack, 'arrow-left'));
        host.append(node('section', { class: 'my-3', 'aria-label': l.back_fees },
            button(l.back_fees, back, 'arrow-left')), summary(data));
        const actions = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' });
        if (!data.cleared && !data.applied_to) actions.append(button(l.payment, () => decision(data, 'payment', back, {}, recordBack), 'cash-coin'));
        if (!data.cleared && !data.applied_to && data.policy.waivers) actions.append(button(l.waive, () => decision(data, 'waive', back, {}, recordBack), 'shield-check'));
        if (data.payments.some(p => p.minor > data.refunds.filter(r => r.payment_id === p.payment_id).reduce((n, r) => n + r.minor, 0))) {
            actions.append(button(l.refund, () => decision(data, 'refund', back, {}, recordBack), 'arrow-return-left'));
        }
        host.append(actions);
        for (const key of ['payments', 'refunds']) {
            if (!data[key].length) continue;
            const section = node('section', { class: 'bg-white border rounded p-3 mb-3' }, node('h3', { class: 'h5' }, l[key]));
            for (const item of data[key]) section.append(node('p', {}, `${money(item.minor, data.policy.currency)}${item.method ? ` · ${l[item.method] || item.method}` : ''}${item.reference ? ` · ${item.reference}` : ''}`));
            host.append(section);
        }
        await history(host, data, 'staff');
    }
    async function decision(data, action, back, draft = {}, recordBack = null) {
        const host = ui.openDrawer(l[action]); host.classList.add('service-fee-workbench'); host.append(button(l.edit, () => detail(data.fee_id, back, recordBack), 'arrow-left'), summary(data));
        if (action !== 'waive') host.append(node('p', { class: 'alert alert-info' }, action === 'refund' ? l.refund_help : l.receipt_help));
        const fields = action === 'payment' ? [['minor', l.amount, 'money'], ['method', l.method, 'choice', ['cash', 'check', 'card', 'bank_transfer', 'wire_transfer', 'money_order', 'paypal', 'other'].map(value => ({ value, label: l[value] }))], ['reference', l.reference, 'text', '', false]]
            : action === 'refund' ? [['payment_id', l.receipt, 'choice', data.payments.map(p => ({ value: p.payment_id,
                label: `${money(p.minor, p.currency)}${p.reference ? ` · ${p.reference}` : ''} · ${p.payment_id.slice(-6)}` }))], ['minor', l.amount, 'money'], ['reference', l.refund_reference]] : [];
        fields.push(['reason', l.reason, 'textarea']);
        const digits = new Intl.NumberFormat(undefined, { style: 'currency', currency: data.policy.currency }).resolvedOptions().maximumFractionDigits;
        const preset = { ...draft };
        if (preset.minor !== undefined) preset.minor = (preset.minor / 10 ** digits).toFixed(digits);
        const form = await ui.buildForm(fields, preset); form.dataset.currency = data.policy.currency;
        if (!host.isConnected) return;
        if (form.elements.minor) {
            const digits = new Intl.NumberFormat(undefined, { style: 'currency', currency: data.policy.currency }).resolvedOptions().maximumFractionDigits;
            form.elements.minor.min = form.elements.minor.step = String(10 ** -digits);
            form.elements.minor.max = String(2000000000 / 10 ** digits);
        }
        form.elements.reason.maxLength = 1000;
        const review = node('button', { type: 'submit', class: 'btn btn-primary' }, l.review); form.append(review); host.append(form);
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!form.reportValidity()) return;
            const values = ui.formData(form, fields), reason = values.reason; delete values.reason;
            if (action === 'payment') values.reference ??= null;
            const result = await request(`/sis/service-fees/${data.fee_id}/review/`, 'POST', { action, details: values, reason });
            if (!host.isConnected) return;
            const confirmHost = ui.openDrawer(l.review); confirmHost.classList.add('service-fee-workbench'); confirmHost.append(button(l.edit, () => decision(data, action, back, { ...values, reason }, recordBack), 'arrow-left'), summary(result.summary),
                node('p', {}, l.review_help), node('p', { class: 'fw-semibold' }, l[action]), node('p', {}, result.reason));
            if (result.details.minor) confirmHost.append(node('p', {}, money(result.details.minor, data.policy.currency)));
            if (result.details.method) confirmHost.append(node('p', {}, `${l.method}: ${l[result.details.method] || result.details.method}`));
            if (action === 'refund') {
                const original = data.payments.find(p => p.payment_id === result.details.payment_id);
                if (original) confirmHost.append(node('p', {}, `${l.receipt}: ${money(original.minor, original.currency)}${original.reference ? ` · ${original.reference}` : ''}`));
            }
            if (result.details.reference) confirmHost.append(node('p', {}, result.details.reference));
            if (action === 'refund') confirmHost.append(node('p', { class: 'alert alert-info' }, l.refund_help));
            confirmHost.append(button(l.confirm, async () => {
                await request(`/sis/service-fees/${data.fee_id}/confirm/`, 'POST', result);
                ui.notify?.(l.recorded); if (confirmHost.isConnected) await detail(data.fee_id, back, recordBack);
            }, 'check2-circle'));
        }, review); });
    }
    return { policies, person, detail, ownerGate, ownerList, summary };
};
