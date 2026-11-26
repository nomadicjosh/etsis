/* Website inquiries use their own published definitions and never create admission applications. */
(() => {
    'use strict';
    function mount(form) {
        const definition = JSON.parse(form.dataset.definition);
        const labels = JSON.parse(form.dataset.labels);
        const fields = form.querySelector('.website-form-fields');
        const submit = form.querySelector('[type="submit"]');
        const reload = form.querySelector('[data-form-reload]');
        const status = form.querySelector('[data-form-status]');
        const summary = form.querySelector('[data-form-errors]');
        const entries = definition.fields.map(field => ({ field,
            wrapper: form.querySelector(`[data-question="${CSS.escape(field.name)}"]`),
            controls: [...form.querySelectorAll(`[data-question="${CSS.escape(field.name)}"] input, [data-question="${CSS.escape(field.name)}"] select, [data-question="${CSS.escape(field.name)}"] textarea`)],
        }));
        let credentials = null, original = null, sending = false, disposed = false;
        const raw = entry => {
            const type = entry.field.type;
            if (type === 'checkbox') return entry.controls[0].checked;
            if (type === 'multi_select') return entry.controls.filter(control => control.checked).map(control => control.value);
            if (type === 'radio') return entry.controls.find(control => control.checked)?.value || '';
            return entry.controls[0].value.trim();
        };
        const clear = entry => {
            for (const control of entry.controls) {
                if (['radio', 'checkbox'].includes(control.type)) control.checked = false;
                else control.value = '';
            }
        };
        function conditions() {
            const values = {};
            for (const entry of entries) {
                const condition = entry.field.show_when;
                const visible = !condition || values[condition.field] === condition.value;
                entry.wrapper.hidden = !visible;
                entry.controls.forEach(control => { control.disabled = !visible; });
                if (!visible) clear(entry);
                else values[entry.field.name] = raw(entry);
            }
        }
        function errors(items) {
            summary.querySelector('ul').replaceChildren();
            for (const entry of entries) {
                entry.wrapper.querySelector('[data-field-error]').textContent = '';
                entry.wrapper.querySelector('[data-field-error]').hidden = true;
                entry.controls.forEach(control => control.removeAttribute('aria-invalid'));
            }
            for (const item of items) {
                const entry = entries.find(entry => entry.field.name === item.field);
                const message = String(item.message || labels.invalid);
                const li = document.createElement('li');
                if (entry && !entry.wrapper.hidden) {
                    const error = entry.wrapper.querySelector('[data-field-error]');
                    error.textContent = message; error.hidden = false;
                    entry.controls.forEach(control => control.setAttribute('aria-invalid', 'true'));
                    const link = document.createElement('a'); link.href = `#${entry.controls[0].id}`;
                    link.textContent = `${entry.field.label}: ${message}`;
                    link.addEventListener('click', event => { event.preventDefault(); entry.controls[0].focus(); });
                    li.append(link);
                } else li.textContent = message;
                summary.querySelector('ul').append(li);
            }
            summary.hidden = !items.length;
            if (items.length) summary.focus();
        }
        async function prepare() {
            credentials = null; original = null; fields.disabled = true; submit.disabled = true;
            reload.hidden = true; status.textContent = labels.loading;
            try {
                const url = `${form.dataset.endpoint}prepare/?locale=${encodeURIComponent(form.dataset.locale)}`;
                const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error();
                const prepared = await response.json();
                if (disposed || !form.isConnected) return;
                credentials = prepared;
                fields.disabled = false; submit.disabled = false; submit.textContent = definition.submit_label;
                status.textContent = labels.ready; conditions();
            } catch (_) {
                if (!disposed) { status.textContent = labels.unavailable; reload.hidden = false; }
            }
        }
        for (const entry of entries) {
            const field = entry.field;
            for (const control of entry.controls) {
                if (field.min_length != null && ['text', 'textarea', 'email', 'tel', 'url'].includes(field.type)) control.minLength = field.min_length;
                if (field.max_length != null && ['text', 'textarea', 'email', 'tel', 'url'].includes(field.type)) control.maxLength = field.max_length;
                if (field.min != null) control.min = field.min;
                if (field.max != null) control.max = field.max;
                control.addEventListener('input', conditions);
                control.addEventListener('change', conditions);
            }
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (sending || !credentials || disposed || form.dataset.preview === '1') return;
            if (!original) {
                conditions();
                const answers = {}, problems = [];
                for (const entry of entries) {
                    if (entry.wrapper.hidden) continue;
                    const value = raw(entry), field = entry.field;
                    const empty = value === '' || value === false || (Array.isArray(value) && !value.length);
                    if (field.required && empty) problems.push({ field: field.name, message: labels.required });
                    else if (!empty && entry.controls.some(control => !control.checkValidity())) problems.push({ field: field.name, message: labels.invalid });
                    else if (!empty && field.min_length != null && Array.from(value).length < field.min_length) problems.push({ field: field.name, message: labels.invalid });
                    else if (!empty || field.type === 'checkbox') answers[field.name] = value;
                }
                errors(problems);
                if (problems.length) return;
                original = { ticket: credentials.ticket, answers, website_url: form.querySelector('.website-form-trap input').value };
            }
            sending = true; submit.disabled = true; fields.disabled = true; status.textContent = labels.sending;
            try {
                const response = await fetch(`${form.dataset.endpoint}submit/`, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', [credentials.csrf_header]: credentials.csrf },
                    body: new URLSearchParams({ _workspace_json: JSON.stringify(original) }),
                });
                const result = await response.json();
                if (disposed || !form.isConnected) return;
                if (response.ok && result.received) {
                    entries.forEach(clear); original = null; credentials = null;
                    fields.hidden = true; submit.hidden = true; summary.hidden = true;
                    status.textContent = result.message;
                } else if (response.status === 422) {
                    original = null; fields.disabled = false; submit.disabled = false;
                    status.textContent = ''; errors(result.errors || [{ message: result.error }]);
                } else if ([401, 403, 404, 409].includes(response.status)) {
                    original = null; credentials = null; status.textContent = labels.unavailable; reload.hidden = false;
                } else throw new Error();
            } catch (_) {
                if (!disposed) { status.textContent = labels.failed; submit.disabled = false; submit.textContent = labels.retry; }
            } finally { sending = false; }
        });
        reload.addEventListener('click', () => { errors([]); void prepare(); });
        window.addEventListener('pagehide', () => {
            disposed = true; original = null; credentials = null; entries.forEach(clear); fields.disabled = true;
        }, { once: true });
        if (form.dataset.preview === '1') status.textContent = labels.preview;
        else void prepare();
    }
    function start() { document.querySelectorAll('[data-website-form]').forEach(mount); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
