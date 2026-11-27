/* Reviewed graduation and bounded group conferral. Private drafts stay in this page's memory. */
window.createGraduation = function (ui) {
    'use strict';
    const { node, request, task, labels: t } = ui;
    const icons = new Map([[t.queue, 'people'], [t.confer, 'award'], [t.review_eligibility, 'clipboard-check'],
        [t.correct, 'pencil-square'], [t.revoke, 'slash-circle'], [t.history, 'clock-history'],
        [t.review_group, 'list-check'], [t.confirm_group, 'award']]);
    const btn = (label, run, primary = false) => node('button', { type: 'button',
        class: ui.buttonClass || `btn ${primary ? 'btn-primary' : 'btn-outline-primary'}`,
        onclick: () => task(run) },
        icons.has(label) ? node('i', { class: `bi bi-${icons.get(label)}`, 'aria-hidden': 'true' }) : null,
        node('span', {}, label));
    const card = (...children) => node('section', { class: 'record-section bg-white border rounded p-3 mb-3' }, ...children);
    const text = value => node('p', { class: 'mb-2 text-break' }, value);
    const base = (student, pursuit) => `/sis/students/${student}/programs/${pursuit}/graduation/`;
    const credential = c => card(node('h3', { class: 'h5' }, c.proposal.title),
        text(`${t.awarded_on}: ${c.proposal.awarded_on} · ${t.term}: ${c.term.code}`),
        text(`${t.program}: ${c.program.code} · ${c.program.title}`),
        c.proposal.honors ? text(`${t.honors}: ${c.proposal.honors}`) : null,
        node('ul', { class: 'mb-0' }, ...c.components.map(item => node('li', {}, `${item.code} · ${item.title}`))));
    const status = data => data.credential ? credential(data.credential) : card(
        node('h3', { class: 'h6' }, data.eligibility?.eligible ? t.eligible : data.eligibility ? t.deferred : t.current),
        text(t.empty), data.eligibility ? text(`${data.eligibility.proposal.title} · ${data.eligibility.proposal.awarded_on}`) : null);

    async function history(host, path, cursor = null) {
        const page = await request(path + 'history/', 'POST', { cursor });
        if (!host.isConnected) return;
        const names = { eligibility: t.eligibility, confer: t.conferred, correct: t.corrected, revoke: t.revoked };
        const list = card(node('h3', { class: 'h5' }, t.history), text(t.history_note));
        if (!page.items.length) list.append(text(t.history_empty));
        page.items.forEach(item => list.append(card(
            node('h4', { class: 'h6' }, names[item.action]), text(`${t.recorded}: ${item.recorded_at}`),
            item.credential ? credential(item.credential) : null,
            item.eligible === null ? null : text(item.eligible ? t.eligible : t.deferred))));
        if (page.next_cursor) {
            const more = btn(t.more, async () => { more.disabled = true;
                try { await history(host, path, page.next_cursor); more.remove(); }
                finally { more.disabled = false; } });
            list.append(more);
        }
        host.append(list);
    }

    async function owner(host, path) {
        const pending = text(t.loading);
        host.replaceChildren(pending);
        const data = await request(path, 'GET');
        if (!host.isConnected || !host.contains(pending)) return;
        const historyHost = node('div');
        const launch = btn(t.history, async () => {
            launch.disabled = true;
            try { await history(historyHost, path); launch.remove(); }
            finally { launch.disabled = false; }
        });
        host.replaceChildren(node('h2', { class: 'h4' }, t.title), status(data), launch, historyHost);
    }

    async function open(student, pursuit, back, returnLabel = t.back) {
        const body = ui.openDrawer(t.title, t.details);
        body.append(btn(returnLabel, back), text(t.loading));
        const data = await request(base(student, pursuit), 'GET');
        if (!body.isConnected) return;
        body.replaceChildren(btn(returnLabel, back),
            card(node('h3', { class: 'h5' }, data.identity.name), text(data.identity.institutional_id)),
            text(t.help), status(data));
        const actions = node('div', { class: 'inline-actions mb-3' });
        if (data.can_review && !data.credential)
            actions.append(btn(t.review_eligibility, () => edit(student, pursuit, data, 'eligibility', back, null, returnLabel)));
        if (data.can_confer) {
            if (data.eligibility?.eligible) actions.append(btn(t.confer, () => edit(student, pursuit, data, 'confer', back, null, returnLabel), true));
            if (data.credential) actions.append(btn(t.correct, () => edit(student, pursuit, data, 'correct', back, null, returnLabel)),
                btn(t.revoke, () => edit(student, pursuit, data, 'revoke', back, null, returnLabel)));
            actions.append(btn(t.queue, () => queue(() => open(student, pursuit, back, returnLabel))));
        }
        const historyHost = node('div');
        const viewHistory = btn(t.history, async () => {
            viewHistory.disabled = true;
            try { historyHost.replaceChildren(); await history(historyHost, base(student, pursuit)); }
            finally { viewHistory.disabled = false; }
        });
        actions.append(viewHistory);
        body.append(actions, historyHost);
    }

    function summary(review) {
        const s = review.summary;
        return card(s.identity ? text(`${s.identity.name} · ${s.identity.institutional_id}`) : null,
            node('h3', { class: 'h5' }, s.proposal?.title || t.title),
            s.proposal ? text(`${t.awarded_on}: ${s.proposal.awarded_on}`) : null,
            s.term ? text(`${t.term}: ${s.term.code}`) : null,
            s.proposal?.honors ? text(`${t.honors}: ${s.proposal.honors}`) : null,
            s.eligible === null ? null : text(s.eligible ? t.eligible_summary : t.deferred_summary),
            s.progress ? node('ul', {}, ...s.progress.components.map(c => node('li', {},
                c.context.name))) : null,
            s.closes_pursuit ? text(t.close_help) : null,
            review.action === 'revoke' ? text(t.revoke_help) : null);
    }

    async function edit(student, pursuit, data, action, back, preset = null, returnLabel = t.back) {
        const names = { eligibility: t.review_eligibility, confer: t.confer, correct: t.correct, revoke: t.revoke };
        const body = ui.openDrawer(names[action], t.title);
        body.append(btn(t.back, () => open(student, pursuit, back, returnLabel)), text(t.academic_help));
        const existing = data.credential?.proposal || data.eligibility?.proposal || {};
        const fields = action === 'eligibility' ? [
            ['title', t.credential_title], ['awarded_on', t.awarded_on, 'date'], ['term_id', t.term, 'lookup', 'terms'],
            ['honors', t.honors, 'text', '', false], ['attested', t.attested, 'checkbox'],
            ['history_acknowledged', t.history_acknowledged, 'checkbox'],
        ] : action === 'correct' ? [['title', t.credential_title], ['honors', t.honors, 'text', '', false]] : [];
        fields.push(['reason', t.reason, 'textarea']);
        const form = await ui.buildForm(fields, preset || { ...existing, awarded_on: existing.awarded_on || new Date().toLocaleDateString('en-CA') });
        if (!body.isConnected) return;
        form.elements.reason.maxLength = 1000;
        for (const key of ['title', 'honors']) if (form.elements[key]) form.elements[key].maxLength = 200;
        if (form.elements.awarded_on) form.elements.awarded_on.max = new Date().toLocaleDateString('en-CA');
        const content = card(action === 'eligibility' ? text(t.date_help) : null,
            action === 'correct' ? text(t.correct_help) : null, action === 'revoke' ? text(t.revoke_help) : null);
        while (form.firstChild) content.append(form.firstChild);
        const submit = node('button', { type: 'submit', class: 'btn btn-primary' }, t.review);
        form.append(content, submit);
        let busy = false;
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (busy || !form.reportValidity()) return;
            busy = submit.disabled = true;
            task(async () => {
                try {
                    const values = Object.fromEntries(new FormData(form));
                    for (const key of ['attested', 'history_acknowledged']) values[key] = Boolean(form.elements[key]?.checked);
                    const command = action === 'eligibility' ? { proposal: { title: values.title.trim(),
                        awarded_on: values.awarded_on, term_id: values.term_id, honors: values.honors.trim() },
                        attested: values.attested, history_acknowledged: values.history_acknowledged }
                        : action === 'correct' ? { title: values.title.trim(), honors: values.honors.trim() } : {};
                    const reviewed = await request(base(student, pursuit) + 'review/', 'POST', { action, request: command, reason: values.reason });
                    if (!body.isConnected) return;
                    const confirm = ui.openDrawer(t.review, t.title);
                    const accept = btn(t.confirm, async () => {
                        accept.disabled = true;
                        try { await request(base(student, pursuit) + 'confirm/', 'POST', reviewed);
                            if (confirm.isConnected) await open(student, pursuit, back, returnLabel); }
                        finally { accept.disabled = false; }
                    }, true);
                    confirm.append(btn(t.revise, () => edit(student, pursuit, data, action, back, values, returnLabel)), summary(reviewed),
                        text(reviewed.reason), accept);
                } finally { busy = submit.disabled = false; }
            });
        });
        body.append(form);
    }

    async function queue(back, after = null, previous = [], draft = null) {
        const body = ui.openDrawer(t.queue, t.title);
        body.append(btn(t.back, back), text(t.loading));
        const page = await request('/sis/graduation-conferral/page/', 'POST', { after });
        if (!body.isConnected) return;
        body.replaceChildren(btn(t.back, back), text(t.queue_help));
        const form = node('form', { class: 'record-form' });
        const selected = new Map();
        page.items.forEach((item, index) => {
            const id = `graduation-candidate-${index}`;
            const check = node('input', { type: 'checkbox', id, class: 'form-check-input',
                onchange: () => check.checked ? selected.set(item.pursuit_id, item) : selected.delete(item.pursuit_id) });
            if (draft?.selected.includes(item.pursuit_id)) { check.checked = true; selected.set(item.pursuit_id, item); }
            form.append(card(node('div', { class: 'form-check' }, check,
                node('label', { class: 'form-check-label fw-semibold', for: id }, `${item.name} · ${item.student_number}`)),
                text(`${item.proposal.title} · ${item.proposal.awarded_on}`),
                btn(t.details, () => open(item.student_id, item.pursuit_id, restore, t.back_queue))));
        });
        if (!page.items.length) body.append(text(t.queue_empty));
        const reasonId = 'graduation-group-reason';
        const reason = node('textarea', { id: reasonId, class: 'form-control', rows: 3, required: true, maxlength: 1000 });
        reason.value = draft?.reason || '';
        function restore() {
            return queue(back, after, previous, { selected: [...selected.keys()], reason: reason.value });
        }
        const note = node('p', { role: 'status', 'aria-live': 'polite' });
        const submit = node('button', { type: 'submit', class: 'btn btn-primary mt-3' }, t.review_group);
        if (page.items.length) form.append(card(node('label', { for: reasonId, class: 'form-label' }, t.reason), reason), submit, note);
        let busy = false;
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (busy || !form.reportValidity()) return;
            if (!selected.size) { note.textContent = t.choose; return; }
            busy = submit.disabled = true;
            task(async () => {
                try {
                    const candidates = [...selected.values()].map(item => ({ student_id: item.student_id, pursuit_id: item.pursuit_id }));
                    const reviewed = await request('/sis/graduation-conferral/review/', 'POST', { candidates, reason: reason.value });
                    if (!body.isConnected) return;
                    const confirm = ui.openDrawer(t.confirm_group, t.queue);
                    const accept = btn(t.confirm_group, async () => {
                        accept.disabled = true;
                        try { await request('/sis/graduation-conferral/confirm/', 'POST', reviewed);
                            if (confirm.isConnected) { await queue(back, after, previous); ui.notify?.(t.group_saved); } }
                        finally { accept.disabled = false; }
                    }, true);
                    confirm.append(btn(t.back_queue, restore), text(t.queue_help));
                    reviewed.reviews.forEach(review => {
                        confirm.append(summary(review));
                    });
                    confirm.append(text(reviewed.reviews[0].reason), accept);
                } finally { busy = submit.disabled = false; }
            });
        });
        body.append(form);
        const nav = node('div', { class: 'inline-actions mt-3' });
        if (previous.length) nav.append(btn(t.previous, () => queue(back, previous.at(-1), previous.slice(0, -1))));
        if (page.next_after) nav.append(btn(t.next, () => queue(back, page.next_after, [...previous, after])));
        body.append(nav);
    }
    return { open, queue, owner };
};
