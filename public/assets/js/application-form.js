/* Shared applicant/staff rendering. Answers remain in memory; submission and authorization belong to callers. */
(() => {
    'use strict';
    let sequence = 0;
    window.applicationQuestionTypes = Object.freeze({ text: 'Short answer', textarea: 'Long answer',
        select: 'Dropdown choice', radio: 'Single choice', email: 'Email address', date: 'Date',
        number: 'Whole number', checkbox: 'Confirmation' });
    function node(tag, attributes = {}, ...children) {
        const element = document.createElement(tag);
        for (const [key, value] of Object.entries(attributes)) {
            if (value === undefined || value === null || value === false) continue;
            if (key.startsWith('on')) element.addEventListener(key.slice(2), value);
            else element.setAttribute(key, value === true ? '' : String(value));
        }
        for (const child of children.flat()) if (child !== undefined && child !== null)
            element.append(child instanceof Node ? child : document.createTextNode(String(child)));
        return element;
    }
    function matches(group, facts) {
        const results = group.rules.map(rule => {
            if (rule.condition) return matches(rule, facts);
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

    window.createApplicationForm = ({ fields, layout = [], preview = false }) => {
        const prefix = `application-${++sequence}`;
        const form = node('form', { class: 'application-fields', novalidate: true,
            'aria-label': preview ? 'Application form preview' : 'Application answers' });
        const summary = node('div', { class: 'application-errors alert alert-danger', role: 'alert', tabindex: '-1', hidden: true });
        const entries = new Map(), remote = new Map();
        let showValidation = false;
        form.append(summary);
        fields.forEach((field, index) => {
            const id = `${prefix}-field-${index}`, settings = field.settings || {};
            const group = field.type === 'radio';
            const wrapper = node(group ? 'fieldset' : 'div', { class: 'application-question', 'data-answer-key': field.key });
            const label = node(group ? 'legend' : 'label', { class: group ? 'application-question-legend' : field.type === 'checkbox' ? 'form-check-label' : 'form-label', ...(group ? {} : { for: id }) },
                field.label || 'Untitled question', field.required ? node('span', { 'aria-hidden': 'true' }, '\u00a0*') : null);
            const common = { id, name: field.key, required: field.required };
            let controls;
            if (group) {
                wrapper.append(label);
                controls = (field.options || []).map((option, position) => {
                    const control = node('input', { ...common, class: 'form-check-input', type: 'radio', value: option, id: `${id}-${position}` });
                    wrapper.append(node('div', { class: 'form-check' }, control,
                        node('label', { class: 'form-check-label', for: control.id }, option)));
                    return control;
                });
            } else {
                let control;
                if (field.type === 'select') {
                    control = node('select', { ...common, class: 'form-select' }, node('option', { value: '' }, 'Choose…'),
                        (field.options || []).map(option => node('option', { value: option }, option)));
                } else if (field.type === 'textarea') control = node('textarea', { ...common, class: 'form-control', rows: 4 });
                else control = node('input', { ...common, class: field.type === 'checkbox' ? 'form-check-input' : 'form-control', type: field.type,
                    ...(field.type === 'number' ? { step: 1, min: settings.min_value ?? -999999999, max: settings.max_value ?? 999999999 } : {}),
                    ...(field.type === 'date' ? { min: settings.earliest_date, max: settings.latest_date } : {}) });
                if (settings.placeholder) control.setAttribute('placeholder', settings.placeholder);
                // Allow surrogate pairs; code-point limits are enforced below and again by the domain.
                if (['text', 'textarea', 'email'].includes(field.type)) control.maxLength = 2 * (settings.max_length ?? (field.type === 'textarea' ? 4000 : 191));
                if (field.type === 'checkbox') wrapper.append(node('div', { class: 'form-check' }, control, label));
                else wrapper.append(label, control);
                controls = [control];
            }
            const descriptions = [];
            if (field.help) {
                const help = node('p', { class: 'form-text mb-0', id: `${id}-help` }, field.help);
                wrapper.append(help); descriptions.push(help.id);
            }
            const guidance = [];
            if (settings.min_length !== undefined) guidance.push(`At least ${settings.min_length} characters`);
            if (settings.max_length !== undefined) guidance.push(`Up to ${settings.max_length} characters`);
            if (settings.min_value !== undefined) guidance.push(`Minimum ${settings.min_value}`);
            if (settings.max_value !== undefined) guidance.push(`Maximum ${settings.max_value}`);
            if (settings.earliest_date) guidance.push(`On or after ${settings.earliest_date}`);
            if (settings.latest_date) guidance.push(`On or before ${settings.latest_date}`);
            if (guidance.length) {
                const hint = node('p', { class: 'form-text mb-0', id: `${id}-limits` }, `${guidance.join(' · ')}.`);
                wrapper.append(hint); descriptions.push(hint.id);
            }
            const error = node('p', { class: 'invalid-feedback d-block mb-0', id: `${id}-error`, hidden: true });
            wrapper.append(error);
            entries.set(field.key, { field, wrapper, controls, error, descriptions, message: '' });
        });
        if (!layout.length) form.append(...[...entries.values()].map(entry => entry.wrapper));
        for (const container of layout) {
            const section = node('fieldset', { class: 'application-container' },
                node('legend', { class: 'application-container-title' }, container.label));
            if (container.help) section.append(node('p', { class: 'form-text mt-0' }, container.help));
            const columns = node('div', { class: 'application-columns', 'data-columns': container.columns.length });
            for (const column of container.columns) columns.append(node('div', { class: 'application-column' },
                column.map(key => entries.get(key)?.wrapper).filter(Boolean)));
            section.append(columns); form.append(section);
        }
        function value(entry) {
            if (entry.field.type === 'radio') return entry.controls.find(control => control.checked)?.value;
            const control = entry.controls[0];
            if (entry.field.type === 'checkbox') return control.checked;
            if (control.value === '') return undefined;
            return entry.field.type === 'number' ? Number(control.value) : control.value;
        }
        function validate(entry) {
            const control = entry.controls[0];
            if (!control) return '';
            for (const item of entry.controls) item.setCustomValidity('');
            const answer = value(entry), settings = entry.field.settings || {};
            let message = '';
            if (entry.field.required && (answer === undefined || (entry.field.type === 'checkbox' && !answer))) message = 'This field is required.';
            else if (answer !== undefined) {
                if (entry.field.type === 'number' && (!Number.isInteger(answer) || Math.abs(answer) > 999999999)) message = 'Enter a whole number within the supported range.';
                else if (entry.field.type === 'number' && answer < (settings.min_value ?? -999999999)) message = `Enter ${settings.min_value} or more.`;
                else if (entry.field.type === 'number' && answer > (settings.max_value ?? 999999999)) message = `Enter ${settings.max_value} or less.`;
                else if (['text', 'textarea', 'email'].includes(entry.field.type)) {
                    const count = Array.from(answer).length, max = settings.max_length ?? (entry.field.type === 'textarea' ? 4000 : 191);
                    if (count < (settings.min_length ?? 0)) message = `Use at least ${settings.min_length} characters.`;
                    else if (count > max) message = `Use no more than ${max} characters.`;
                }
                if (!message && entry.field.type === 'date' && settings.earliest_date && answer < settings.earliest_date) message = `Choose a date on or after ${settings.earliest_date}.`;
                if (!message && entry.field.type === 'date' && settings.latest_date && answer > settings.latest_date) message = `Choose a date on or before ${settings.latest_date}.`;
            }
            if (!message && !control.validity.valid) message = control.validity.badInput ? 'Enter a valid value for this field.' : control.validationMessage;
            message = remote.get(entry.field.key) || message;
            if (message) control.setCustomValidity(message);
            return message;
        }
        function update() {
            const facts = Object.create(null);
            for (const entry of entries.values()) {
                const visible = !entry.field.show_when || matches(entry.field.show_when, facts);
                entry.wrapper.hidden = !visible;
                for (const control of entry.controls) control.disabled = !visible;
                entry.message = visible ? validate(entry) : '';
                if (visible && !entry.message) {
                    const answer = value(entry);
                    if (answer !== undefined) facts[entry.field.key] = answer;
                }
                const invalid = showValidation && Boolean(entry.message);
                entry.error.hidden = !invalid;
                entry.error.textContent = invalid ? entry.message : '';
                for (const control of entry.controls) {
                    control.classList.toggle('is-invalid', invalid);
                    if (invalid) control.setAttribute('aria-invalid', 'true'); else control.removeAttribute('aria-invalid');
                    const described = [...entry.descriptions, ...(invalid ? [entry.error.id] : [])];
                    if (described.length) control.setAttribute('aria-describedby', described.join(' ')); else control.removeAttribute('aria-describedby');
                }
            }
            if (showValidation) {
                const errors = [...entries.values()].filter(entry => entry.message);
                summary.replaceChildren(); summary.hidden = !errors.length;
                if (errors.length) summary.append(node('strong', {}, 'Please review these questions.'),
                    node('ul', { class: 'mb-0 mt-2' }, errors.map(entry => node('li', {}, node('a', {
                        href: `#${entry.controls[0].id}`, onclick: event => { event.preventDefault(); entry.controls[0].focus(); },
                    }, `${entry.field.label || 'Untitled question'}: ${entry.message}`)))));
            }
            return facts;
        }
        const edit = event => { if (entries.has(event.target.name)) remote.delete(event.target.name); update(); };
        form.addEventListener('input', edit); form.addEventListener('change', edit);
        form.addEventListener('submit', event => event.preventDefault());
        update();
        const reportValidity = () => {
            showValidation = true; update();
            if (!summary.hidden) { summary.focus(); return false; }
            return true;
        };
        if (preview) form.append(node('button', { type: 'button', class: 'btn btn-outline-primary application-preview-check', onclick: reportValidity }, 'Test answers'));
        return {
            form,
            answers: update,
            reportValidity,
            showErrors: fields => {
                const relevant = (fields || []).filter(item => entries.has(item.field) && !entries.get(item.field).wrapper.hidden);
                if (!relevant.length) return false;
                for (const item of relevant) remote.set(item.field, item.message);
                showValidation = true; update(); summary.focus(); return true;
            },
            reset: () => { HTMLFormElement.prototype.reset.call(form); remote.clear(); showValidation = false; summary.hidden = true; update(); },
        };
    };
})();
