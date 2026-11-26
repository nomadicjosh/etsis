'use strict';
window.createPersonSsn = function ({ node, request, task, openDrawer, labels }) {
    const t = key => labels[key];
    const action = (key, icon, run, style = 'btn-outline-primary') => node('button', {
        type: 'button', class: `btn ${style} d-inline-flex align-items-center gap-2`,
        onclick: event => task(run, event.currentTarget),
    }, node('i', { class: `bi bi-${icon}`, 'aria-hidden': 'true' }), t(key));
    const field = (key, input, help = null) => node('div', { class: 'mb-3' },
        node('label', { class: 'form-label', for: input.id }, t(key)), input,
        help ? node('p', { class: 'form-text mb-0', id: `${input.id}-help` }, t(help)) : null);
    const card = (...contents) => node('section', { class: 'record-section bg-white border rounded p-3 mb-3' }, ...contents);

    async function open(person, back) {
        const base = `/sis/people/${person}/ssn/`;
        let current = null, pending = false, draft = { ssn: '', ssn_confirmation: '', reason: '' }, turn = 0;
        const header = body => { if (back) body.append(action('back', 'arrow-left', back, 'btn-link')); };
        function fail(body, error) {
            if (!body.isConnected) return;
            if ([401, 403].includes(error.status)) {
                draft = null; current = null; turn++;
                body.replaceChildren(node('p', { class: 'alert alert-warning', role: 'alert' }, t('denied')));
                header(body); return;
            }
            body.querySelector('.ssn-error')?.remove();
            const notice = node('p', { class: 'alert alert-warning ssn-error', role: 'alert', tabindex: -1 }, error.message || t('error'));
            body.prepend(notice); notice.focus();
        }
        function lock(body, value) {
            pending = value; body.setAttribute('aria-busy', String(value));
            body.querySelectorAll('input,textarea,button').forEach(input => { input.disabled = value; });
        }
        async function load() {
            const body = openDrawer(t('title'));
            header(body); const token = ++turn;
            try {
                const data = await request(base);
                if (!body.isConnected || token !== turn) return;
                current = data;
                if (!data.can_edit) draft = { ssn: '', ssn_confirmation: '', reason: '' };
                render();
            } catch (error) { fail(body, error); }
        }
        async function review(body, clear = false) {
            if (pending || !body.isConnected || !draft || !current?.can_edit) return;
            const command = { version: current.version, reason: draft.reason,
                ssn: clear ? null : draft.ssn, ssn_confirmation: clear ? null : draft.ssn_confirmation };
            const token = ++turn;
            lock(body, true);
            try {
                const data = await request(base + 'review/', 'POST', command);
                body.querySelectorAll('input[type="password"]').forEach(input => { input.value = ''; input.removeAttribute('value'); });
                if (!body.isConnected || token !== turn || !draft) return;
                pending = false; confirm(data, command);
            } catch (error) { fail(body, error); }
            finally { if (body.isConnected && draft) lock(body, false); }
        }
        function render(message = null) {
            if (!current || !draft) return;
            const body = openDrawer(t('title')); header(body);
            if (message) body.append(node('p', { class: 'alert alert-success', role: 'status' }, message));
            body.append(card(node('h3', { class: 'h6' }, t('saved')),
                node('p', { class: 'fs-5 font-monospace mb-2', 'data-ssn-mask': '' }, current.masked || t('none')),
                node('p', { class: 'form-text mb-0' }, t('help'))));
            if (current.can_edit) {
                const first = node('input', { id: 'person-ssn-replacement', type: 'password', inputmode: 'numeric',
                    class: 'form-control', autocomplete: 'off', maxlength: 11, value: draft.ssn, required: true });
                const again = node('input', { id: 'person-ssn-confirmation', type: 'password', inputmode: 'numeric',
                    class: 'form-control', autocomplete: 'off', maxlength: 11, value: draft.ssn_confirmation, required: true });
                const reason = node('textarea', { id: 'person-ssn-reason', class: 'form-control', rows: 3,
                    maxlength: 1000, required: true, 'aria-describedby': 'person-ssn-reason-help' });
                reason.value = draft.reason;
                first.addEventListener('input', () => { draft.ssn = first.value; });
                again.addEventListener('input', () => { draft.ssn_confirmation = again.value; });
                reason.addEventListener('input', () => { draft.reason = reason.value; });
                const form = node('form', { autocomplete: 'off' }, card(field('replacement', first), field('confirmation', again),
                    field('reason', reason, 'reason_help')));
                const submit = node('button', { type: 'submit', class: 'btn btn-primary d-inline-flex align-items-center gap-2' },
                    node('i', { class: 'bi bi-check2-square', 'aria-hidden': 'true' }), t('review'));
                form.addEventListener('submit', event => { event.preventDefault(); if (form.reportValidity()) task(() => review(body), submit); });
                form.append(node('div', { class: 'inline-actions' }, submit,
                    current.masked ? action('clear', 'eraser', () => {
                        if (!reason.reportValidity()) return;
                        return review(body, true);
                    }, 'btn-outline-danger') : null,
                    action('reload', 'arrow-clockwise', load)), node('p', { class: 'form-text' }, t('reload_help')));
                body.append(form);
            } else body.append(node('p', { class: 'alert alert-info' }, t(current.at_limit ? 'limit' : 'readonly')));
            if (current.can_history) body.append(node('div', { class: 'inline-actions mt-3' }, action('history', 'clock-history', () => history())));
        }
        function confirm(data, command) {
            const body = openDrawer(t('title')); header(body);
            body.append(card(node('h3', { class: 'h6' }, t('before')), node('p', { class: 'font-monospace' }, data.before || t('none')),
                node('h3', { class: 'h6' }, t('after')), node('p', { class: 'font-monospace' }, data.after || t('none')),
                node('p', { class: 'form-text' }, t('masked_help')), node('p', { class: 'text-break' }, data.reason)),
                node('div', { class: 'inline-actions' }, action('confirm', 'check2-circle', async () => {
                    if (pending || !body.isConnected || !draft) return;
                    const token = ++turn;
                    lock(body, true);
                    try {
                        await request(base + 'confirm/', 'POST', { ...command, operation_id: data.operation_id, review_token: data.review_token });
                        if (!body.isConnected || token !== turn) return;
                        command.ssn = command.ssn_confirmation = null;
                        draft = { ssn: '', ssn_confirmation: '', reason: '' };
                        const fresh = await request(base);
                        if (!body.isConnected || token !== turn) return;
                        current = fresh; pending = false; render(t('success'));
                    } catch (error) {
                        if ([401, 403].includes(error.status)) command.ssn = command.ssn_confirmation = null;
                        fail(body, error);
                    }
                    finally { if (body.isConnected && draft) lock(body, false); }
                }, 'btn-primary'), action('edit', 'pencil', () => { if (!pending) render(); })));
        }
        async function history(cursor = null, trail = []) {
            if (pending || !draft) return;
            const body = openDrawer(t('history')); header(body); const token = ++turn;
            try {
                const data = await request(base + 'history/', 'POST', { cursor });
                if (!body.isConnected || token !== turn || !draft) return;
                if (!data.items.length) body.append(node('p', { role: 'status' }, t('empty')));
                for (const item of data.items) body.append(card(node('h3', { class: 'h6' }, `${t(item.action)} · ${item.version}`),
                    node('p', { class: 'form-text' }, item.recorded_at), node('p', { class: 'text-break' }, item.reason)));
                body.append(node('div', { class: 'inline-actions' }, action('edit', 'arrow-left', render),
                    trail.length ? action('previous', 'arrow-left', () => history(trail.at(-1), trail.slice(0, -1))) : null,
                    data.next_cursor ? action('more', 'arrow-right', () => history(data.next_cursor, [...trail, cursor])) : null));
            } catch (error) { fail(body, error); }
        }
        await load();
    }
    return { open };
};
