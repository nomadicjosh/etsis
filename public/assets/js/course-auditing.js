/* Shared staff/student wording; no private drafts or review tokens enter browser storage. */
window.createCourseAuditing = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, labels: t } = ui;
    async function open(section, back) {
        const body = openDrawer(t.title, t.basis);
        body.append(node('p', { role: 'status' }, t.loading));
        const data = await request(`/sis/sections/${section}/auditing/current/`, 'POST', {});
        if (!body.isConnected) return;
        let revision = data.revision, dirty = false;
        const digits = new Intl.NumberFormat('en', { style: 'currency', currency: data.currency })
            .resolvedOptions().maximumFractionDigits;
        const divisor = 10 ** digits;
        const allowed = node('input', { id: 'audit-allowed', type: 'checkbox', class: 'form-check-input',
            checked: data.policy !== null });
        const selfService = node('input', { id: 'audit-self', type: 'checkbox', class: 'form-check-input',
            checked: data.policy?.self_service === true });
        const fee = node('input', { id: 'audit-fee', type: 'number', class: 'form-control', min: '0',
            step: String(1 / divisor), max: String(2000000000 / divisor), required: true,
            value: ((data.policy?.fee_minor ?? 0) / divisor).toFixed(digits),
            'aria-describedby': 'audit-price-help' });
        const status = node('p', { role: 'status', 'aria-live': 'polite' });
        const save = node('button', { type: 'submit', class: 'btn btn-primary' }, t.save);
        const publish = node('button', { type: 'button', class: 'btn btn-outline-primary',
            onclick: () => ui.publish(section, () => open(section, back)) }, t.publish);
        const check = (input, label) => node('div', { class: 'form-check mb-3' }, input,
            node('label', { class: 'form-check-label', for: input.id }, label));
        const form = node('form', { class: 'record-form', 'aria-label': t.title },
            node('section', { class: 'record-section bg-white border rounded p-3' },
                node('h3', { class: 'h6' }, data.section_code),
                node('p', {}, t.policy_help), check(allowed, t.allowed), check(selfService, t.self_service),
                node('label', { class: 'form-label', for: fee.id }, `${t.fee} (${data.currency})`), fee,
                node('p', { id: 'audit-price-help', class: 'form-text' }, t.pricing_help)),
            node('p', { class: 'form-text' }, t.publication_help),
            node('div', { class: 'inline-actions' }, save, publish), status);
        let busy = false;
        const sync = () => { selfService.disabled = fee.disabled = busy || !allowed.checked; };
        const changed = () => { dirty = true; publish.disabled = true; status.textContent = ''; sync(); };
        allowed.addEventListener('change', changed);
        selfService.addEventListener('change', changed);
        fee.addEventListener('input', changed);
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (busy || !form.reportValidity()) return;
            task(async () => {
                const text = fee.value;
                const minor = allowed.checked ? ui.moneyInput(text, data.currency) : 0;
                busy = true; allowed.disabled = true; publish.disabled = true; sync();
                try {
                    const saved = await request(`/sis/sections/${section}/auditing/save/`, 'POST', {
                        allowed: allowed.checked, self_service: selfService.checked,
                        fee_minor: minor, revision,
                    });
                    if (!body.isConnected) return;
                    revision = saved.revision; dirty = false;
                    status.textContent = t.saved;
                } catch (error) {
                    if ([401, 403].includes(error.status) && body.isConnected) body.replaceChildren();
                    throw error;
                } finally {
                    busy = false; allowed.disabled = false; publish.disabled = dirty; sync();
                }
            }, save);
        });
        body.replaceChildren(form, node('button', { type: 'button', class: 'btn btn-link',
            onclick: () => { if (!busy) back(); } }, node('i', { class: 'bi bi-arrow-left', 'aria-hidden': 'true' }),
            ` ${t.back}`));
        sync();
    }
    return { open };
};
