/* CEUs are independent of academic credits and contact hours. Drafts stay in page memory. */
window.continuingEducationText = function (value, labels = {}) {
    if (!value) return labels.unrecorded || 'Not recorded in this publication';
    if (value.ceu_milli === null) return labels.none || 'No CEUs';
    return (labels.quantity || '{value} CEUs').replace('{value}',
        new Intl.NumberFormat(undefined, { maximumFractionDigits: 3 }).format(value.ceu_milli / 1000));
};
window.createWorkspaceCeus = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, labels = {} } = ui;
    const text = (key, fallback) => labels[key] || fallback;
    const button = (label, icon, run) => node('button', { type: 'button',
        class: 'btn btn-outline-primary d-inline-flex align-items-center gap-2',
        onclick: event => task(run, event.currentTarget) },
    node('i', { class: `bi bi-${icon}`, 'aria-hidden': 'true' }), label);
    async function open(kind, id, back, review) {
        const isCourse = kind === 'courses';
        const title = isCourse ? text('course_title', 'Course CEUs') : text('section_title', 'Offered CEUs');
        const body = openDrawer(title);
        const view = node('div', {}, node('p', { role: 'status' }, text('loading', 'Loading CEU settings…')));
        body.append(view, button(text('back', 'Back to record'), 'arrow-left', back));
        let saved = await request(`/sis/ui/catalog/${kind}/${id}/ceus/`);
        if (!view.isConnected) return;
        const form = node('form');
        const fields = node('fieldset', { class: 'record-form-section' }, node('legend', {}, title));
        if (!isCourse) {
            fields.append(node('p', { class: 'form-text' }, text('course_value', 'Course declaration: {value}')
                .replace('{value}', window.continuingEducationText(saved.course_continuing_education, labels))));
            if (saved.course_version_id === null) fields.append(node('p', { class: 'alert alert-warning' },
                text('unbound', 'No course publication is selected. The declaration above comes from the course draft. Select a course publication before publishing this offering.')));
        }
        const mode = node('select', { id: 'ceu-mode', name: 'mode', class: 'form-select' },
            ...(!isCourse ? [node('option', { value: 'inherit' }, saved.course_version_id === null
                ? text('draft_inherit', 'Use the course draft declaration') : text('inherit', 'Use the retained course declaration'))] : []),
            node('option', { value: 'none' }, text('none', 'No CEUs')),
            node('option', { value: 'fixed' }, text('fixed', 'Declare a CEU value')));
        mode.value = saved.mode;
        const amount = node('input', { type: 'number', id: 'ceu-value', name: 'ceu_milli', class: 'form-control',
            min: '0', max: '999.999', step: '0.001', inputmode: 'decimal', required: true,
            value: saved.ceu_milli === null ? '' : String(saved.ceu_milli / 1000), 'aria-describedby': 'ceu-help' });
        fields.append(node('div', { class: 'mb-3' }, node('label', { for: mode.id, class: 'form-label' },
            text('mode', 'CEU declaration')), mode),
        node('div', { class: 'mb-3' }, node('label', { for: amount.id, class: 'form-label' },
            text('value', 'Continuing education units (CEUs)')), amount),
        node('p', { class: 'form-text', id: 'ceu-help' }, text('help',
            'Use up to three decimal places, at most 999.999. CEUs do not convert to academic credits or contact hours.')));
        const update = () => { amount.disabled = mode.value !== 'fixed'; };
        mode.addEventListener('change', update); update();
        const status = node('p', { role: 'status' });
        const save = node('button', { type: 'submit', class: 'btn btn-primary' }, text('save', 'Save CEU draft'));
        const publish = button(text('review', 'Review publication'), 'clipboard-check', review);
        publish.hidden = true;
        form.append(fields, node('p', { class: 'form-text' }, text('publication_help',
            'Save the draft, then publish it. Offered CEUs describe the offering; they do not award earned units. Existing registrations retain their accepted publications.')),
        node('div', { class: 'd-flex flex-wrap gap-2' }, save, publish), status);
        form.addEventListener('input', () => { status.textContent = ''; publish.hidden = true; });
        form.addEventListener('submit', event => {
            event.preventDefault();
            void task(async () => {
                if (!form.reportValidity()) return;
                let milli = null;
                if (mode.value === 'fixed') {
                    const raw = amount.value.trim();
                    if (!/^\d{1,3}(?:\.\d{1,3})?$/.test(raw)) throw new Error(text('invalid', 'Use a CEU value from 0 to 999.999 with up to three decimal places.'));
                    const [whole, fraction = ''] = raw.split('.');
                    milli = Number(whole) * 1000 + Number(fraction.padEnd(3, '0'));
                }
                fields.disabled = true;
                try {
                    const result = await request(`/sis/ui/catalog/${kind}/${id}/ceus/`, 'POST', {
                        mode: mode.value, ceu_milli: milli, revision: saved.revision,
                    });
                    if (!form.isConnected) return;
                    saved = result;
                    status.textContent = text('saved', 'CEU draft saved. Review its publication before using it.');
                    publish.hidden = false;
                } catch (error) {
                    if ([401, 403].includes(error.status)) form.replaceChildren();
                    throw error;
                } finally { fields.disabled = false; }
            }, save);
        });
        view.replaceChildren(form);
    }
    return { open };
};
