/* Reviewed operational settings. Personal reasons and review proofs stay in page memory. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdvisingBookingPolicy = ({ node, request, openDrawer, labels: t }) => {
        const endpoint = '/sis/advising-booking-policy/';
        let body = null, current = null, draft = null, busy = false, epoch = 0;
        const status = () => node('p', { id: `booking-policy-status-${++sequence}`, role: 'status', 'aria-live': 'polite', tabindex: '-1', class: 'form-text text-break' });
        const work = async (run, root = body) => {
            if (busy || !root?.isConnected) return;
            busy = true; root.querySelectorAll('button').forEach(b => { b.disabled = true; });
            const live = root.querySelector('[role="status"]'); if (live) live.textContent = '';
            try { await run(); }
            catch (error) {
                if (!root.isConnected || body !== root) return;
                if ([401, 403].includes(error.status)) { draft = null; current = null; epoch++; root.replaceChildren(status()); }
                const target = root.querySelector('[role="status"]');
                target.textContent = [401, 403].includes(error.status) ? t.denied
                    : (error.fields || []).map(f => f.message).join(' ') || error.message || t.failure;
                for (const f of error.fields || []) {
                    const field = typeof f.field === 'string' ? root.querySelector(`[name="${CSS.escape(f.field)}"]`) : null;
                    if (field) { field.setAttribute('aria-invalid', 'true'); field.setAttribute('aria-describedby', target.id); }
                }
                target.focus();
            } finally { busy = false; body?.querySelectorAll('button').forEach(b => { b.disabled = false; }); }
        };
        const button = (label, run, icon = 'arrow-right', primary = false) => node('button', {
            type: 'button', class: `btn ${primary ? 'btn-primary' : 'btn-outline-primary'}`, onclick: () => work(run)
        }, node('i', { class: `bi bi-${icon} me-2`, 'aria-hidden': 'true' }), label);
        const rules = data => node('dl', { class: 'mb-0' }, ...['maximum_active', 'notice_minutes', 'horizon_days'].flatMap(key => [
            node('dt', { class: 'fw-semibold text-break' }, t[key]), node('dd', { class: 'mb-3' }, String(data[key]))]));
        const actions = (...children) => node('div', { class: 'd-flex flex-wrap gap-2 mb-3' }, ...children);
        const valid = (root, ticket) => root.isConnected && body === root && ticket === epoch;
        const heading = title => node('h3', { class: 'h5', tabindex: '-1' }, title);
        async function load() {
            const root = body, ticket = ++epoch;
            const result = await request(endpoint + 'view/', 'POST', {});
            if (!valid(root, ticket)) return;
            current = result; render();
        }
        function render() {
            const root = body;
            const values = draft?.rules || current.current.rules;
            const form = node('form', { class: 'border rounded bg-white p-3' });
            const inputs = {};
            for (const [key, min, max] of [['maximum_active', 1, 20], ['notice_minutes', 0, 43200], ['horizon_days', 1, 366]]) {
                const id = `booking-policy-${++sequence}`;
                const input = node('input', { id, name: key, type: 'number', min: String(min), max: String(max), step: '1', required: true, class: 'form-control' });
                input.value = values[key]; inputs[key] = input;
                form.append(node('div', { class: 'mb-3' }, node('label', { for: id, class: 'form-label' }, t[key]), input));
            }
            const id = `booking-policy-reason-${++sequence}`;
            const reason = node('textarea', { id, name: 'reason', rows: '3', maxlength: '1000', required: true, class: 'form-control' }); reason.value = draft?.reason || '';
            form.append(node('div', { class: 'mb-3' }, node('label', { for: id, class: 'form-label' }, t.reason), reason),
                node('button', { type: 'submit', class: 'btn btn-primary' }, t.review));
            const capture = () => ({ rules: Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, Number(input.value)])), reason: reason.value });
            form.addEventListener('input', event => { event.target.removeAttribute('aria-invalid'); event.target.removeAttribute('aria-describedby'); draft = capture(); epoch++; });
            form.addEventListener('submit', event => { event.preventDefault(); work(async () => {
                if (!form.reportValidity()) return;
                draft = capture(); const submitted = JSON.stringify(draft), ticket = ++epoch;
                const review = await request(endpoint + 'review/', 'POST', { ...draft.rules, reason: draft.reason, version: current.current.revision });
                if (!valid(root, ticket)) { if (root.isConnected && body === root) { const s = root.querySelector('[role="status"]'); s.textContent = t.changed; s.focus(); } return; }
                if (JSON.stringify(capture()) !== submitted) return;
                confirm(review);
            }); });
            root.replaceChildren(heading(t.title), node('p', { class: 'form-text' }, t.intro),
                node('p', { class: 'form-text' }, `${t.revision}: ${current.current.revision}`),
                ...(current.current.revision === 0 ? [node('p', { class: 'form-text' }, t.default_info)] : []),
                node('p', { class: 'form-text' }, t.ranges), status(), actions(
                    button(t.refresh, load, 'arrow-clockwise'), button(t.defaults, () => { draft = { rules: current.defaults, reason: reason.value }; render(); }, 'arrow-counterclockwise'),
                    button(t.history, () => history(), 'clock-history')), form);
            root.querySelector('h3').focus();
        }
        function confirm(review) {
            epoch++;
            const columns = node('div', { class: 'row g-3 mb-3' });
            for (const [title, values] of [[t.before, review.before.rules], [t.after, review.rules]]) columns.append(node('section', { class: 'col-12 col-lg-6' },
                node('div', { class: 'border rounded bg-white p-3 h-100' }, node('h4', { class: 'h6' }, title), rules(values))));
            body.replaceChildren(heading(t.review), status(), columns,
                node('p', { class: 'border rounded bg-white p-3 text-break', style: 'white-space:pre-wrap' }, review.reason), actions(
                    button(t.confirm, async () => {
                        const root = body, ticket = ++epoch;
                        await request(endpoint + 'save/', 'POST', { id: review.id, version: review.version, ...review.rules, reason: review.reason, review_token: review.review_token });
                        if (!valid(root, ticket)) return;
                        draft = null; await load(); const s = body.querySelector('[role="status"]'); s.textContent = t.saved; s.focus();
                    }, 'check2-circle', true), button(t.keep, () => render(), 'arrow-left')));
            body.querySelector('h3').focus();
        }
        async function history(before = null, stack = []) {
            const root = body, ticket = ++epoch;
            const result = await request(endpoint + 'history/', 'POST', { before });
            if (!valid(root, ticket)) return;
            root.replaceChildren(heading(t.history), status(), button(t.back, load, 'arrow-left'));
            for (const entry of result.items) root.append(node('article', { class: 'border rounded bg-white p-3 mb-3 text-break' },
                node('h4', { class: 'h6' }, `${t.revision} ${entry.revision} · ${entry.at}`), rules(entry.rules),
                node('p', { style: 'white-space:pre-wrap;overflow-wrap:anywhere' }, entry.reason)));
            if (!result.items.length) root.append(node('p', {}, t.empty));
            root.append(actions(...(stack.length ? [button(t.previous, () => history(stack.at(-1), stack.slice(0, -1)), 'arrow-left')] : []),
                ...(result.next_before ? [button(t.more, () => history(result.next_before, [...stack, before]))] : [])));
            root.querySelector('h3').focus();
        }
        return { open() { epoch++; draft = null; current = null; body = openDrawer(t.title); body.append(status()); return work(load); } };
    };
})();
