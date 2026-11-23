/* Academic credits stay exact; no student records or unsaved policies enter browser storage. */
window.createWorkspaceCreditPolicy = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, labels = {} } = ui;
    const text = (key, fallback) => labels[key] || fallback;
    const format = value => String(Number(value) / 1000);
    const milli = input => {
        if (!/^\d{1,6}(?:\.\d{1,3})?$/.test(input.value.trim())) throw new Error(text('invalid',
            'Use a nonnegative credit value with up to three decimal places, at most 999.999.'));
        const [whole, fraction = ''] = input.value.trim().split('.');
        const value = Number(whole) * 1000 + Number(fraction.padEnd(3, '0'));
        if (value > 999999) throw new Error(text('invalid',
            'Use a nonnegative credit value with up to three decimal places, at most 999.999.'));
        return value;
    };
    const button = (key, fallback, icon, run) => node('button', { type: 'button',
        class: 'btn btn-outline-primary d-inline-flex align-items-center gap-2', onclick: event => task(run, event.currentTarget) },
    node('i', { class: `bi bi-${icon}`, 'aria-hidden': 'true' }), text(key, fallback));
    async function open(kind, id, back, review) {
        const isCourse = kind === 'courses';
        const body = openDrawer(isCourse ? text('course_title', 'Course credit policy')
            : text('section_title', 'Offering credits'));
        const view = node('div'); body.append(view);
        const backButton = button('back', 'Back to record', 'arrow-left', back);
        body.append(backButton);
        view.append(node('p', { role: 'status' }, text('loading', 'Loading credit settings…')));
        let saved = await request(`/sis/ui/catalog/${kind}/${id}/credits/`);
        if (!body.isConnected || !view.isConnected) return;
        const form = node('form');
        const unbound = !isCourse && saved.course_version_id === null;
        if (unbound) form.append(node('p', { class: 'alert alert-warning' }, text('unbound',
            'No course publication is selected. These limits come from the course draft. Choose a published course definition before publishing this offering.')));
        const fields = node('fieldset', { class: 'record-form-section' }, node('legend', {}, isCourse
            ? text('course_title', 'Course credit policy') : text('section_title', 'Offering credits')));
        const number = (key, fallback, value) => {
            const input = node('input', { type: 'number', id: `credit-policy-${key}`, class: 'form-control',
                name: { default: 'credits_milli', minimum: 'minimum_milli', maximum: 'maximum_milli',
                    increment: 'increment_milli', section_value: 'offering_credits_milli' }[key],
                min: '0', max: '999.999', step: '0.001', required: true, value: format(value) });
            fields.append(node('div', { class: 'mb-3' }, node('label', { for: input.id, class: 'form-label' },
                text(key, fallback)), input));
            return input;
        };
        let mode, defaultValue, minimum, maximum, increment, inherit, choice;
        if (isCourse) {
            defaultValue = number('default', 'Default academic credits', saved.credits_milli);
            mode = node('select', { id: 'credit-policy-mode', name: 'credit_policy', class: 'form-select' },
                node('option', { value: 'fixed' }, text('fixed', 'Fixed credits')),
                node('option', { value: 'range' }, text('range', 'Credit range')));
            mode.value = saved.credit_policy.minimum_milli === saved.credit_policy.maximum_milli ? 'fixed' : 'range';
            fields.append(node('div', { class: 'mb-3' }, node('label', { for: mode.id, class: 'form-label' },
                text('mode', 'Course credit rule')), mode));
            minimum = number('minimum', 'Minimum academic credits', saved.credit_policy.minimum_milli);
            maximum = number('maximum', 'Maximum academic credits', saved.credit_policy.maximum_milli);
            increment = number('increment', 'Credit increment', saved.credit_policy.increment_milli);
            const update = () => { for (const input of [minimum, maximum, increment]) input.disabled = mode.value === 'fixed'; };
            mode.addEventListener('change', update); update();
        } else {
            const policy = saved.credit_policy;
            fields.append(node('p', { class: 'form-text' }, policy.minimum_milli === policy.maximum_milli
                ? (unbound ? text('draft_fixed_help', 'The course draft allows {value} academic credits.')
                    : text('fixed_help', 'The retained course allows {value} academic credits.')).replace('{value}', format(saved.credits_milli))
                : text('range_help', 'Allowed: {min}–{max} academic credits, in increments of {step}.')
                    .replace('{min}', format(policy.minimum_milli)).replace('{max}', format(policy.maximum_milli))
                    .replace('{step}', format(policy.increment_milli))));
            inherit = node('input', { id: 'credit-policy-inherit', type: 'checkbox', class: 'form-check-input',
                checked: saved.offering_credits_milli === null });
            fields.append(node('div', { class: 'form-check mb-3' }, inherit,
                node('label', { for: inherit.id, class: 'form-check-label' }, unbound
                    ? text('draft_inherit', 'Use the course draft default') : text('inherit', 'Use the retained course default'))));
            choice = number('section_value', 'Fixed credits for this offering', saved.offering_credits_milli ?? saved.credits_milli);
            const update = () => { choice.disabled = inherit.checked; };
            inherit.addEventListener('change', update); update();
        }
        fields.append(node('p', { class: 'form-text' }, text('precision',
            'Use up to three decimal places. These are academic credits; CEUs are separate.')));
        const status = node('p', { role: 'status', tabindex: '-1' });
        const save = node('button', { type: 'submit', class: 'btn btn-primary' }, text('save', 'Save credit draft'));
        const publish = button('review', 'Review publication', 'clipboard-check', review);
        publish.hidden = true;
        form.append(fields, node('p', { class: 'form-text' }, isCourse ? text('course_help',
            'Save a draft default and permitted credit range together, then publish the course definition. Existing publications, student attempts and charges retain their accepted facts.')
            : text('section_help', 'Choose one fixed offering value within its selected course policy. Publish and select the offering before new registrations use it. Students cannot choose individual credit values in this stage.')),
        save, status, publish);
        form.addEventListener('input', () => { publish.hidden = true; status.textContent = ''; });
        form.addEventListener('submit', event => {
            event.preventDefault();
            void task(async () => {
                if (!form.reportValidity()) return;
                const amount = isCourse ? milli(defaultValue) : null;
                const data = isCourse ? { credits_milli: amount,
                    minimum_milli: mode.value === 'fixed' ? amount : milli(minimum),
                    maximum_milli: mode.value === 'fixed' ? amount : milli(maximum),
                    increment_milli: mode.value === 'fixed' ? 0 : milli(increment) }
                    : { use_default: inherit.checked, offering_credits_milli: inherit.checked ? null : milli(choice) };
                fields.disabled = true;
                try {
                    const result = await request(`/sis/ui/catalog/${kind}/${id}/credits/`, 'POST', { ...data, revision: saved.revision });
                    if (!body.isConnected || !form.isConnected) return;
                    saved = result;
                    ui.saved?.(result);
                    status.textContent = text('saved', 'Credit draft saved. Review its publication before using it for new registrations.');
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
