/* Reviewed LMS observations; drafts and encrypted review proofs remain in page memory. */
window.createMoodleGradeImports = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, buildForm, formData, notify, labels: l } = ui;
    const base = section => `/sis/moodle/grade-imports/${encodeURIComponent(section)}/`;
    const button = (label, action, glyph = 'arrow-right', primary = false) => node('button', {
        type: 'button', class: `btn ${primary ? 'btn-primary' : 'btn-outline-secondary'} text-start`,
        onclick: event => task(action, event.currentTarget),
    }, node('i', { class: `bi bi-${glyph} me-2`, 'aria-hidden': 'true' }), label);
    const drawer = title => { openDrawer(title, '', 'lg'); return document.querySelector('#drawer-body'); };
    function returns(host, back, parent = null) {
        host.append(button(l.back_record, back, 'arrow-left'));
        if (parent) host.append(node('section', { class: 'mt-3', 'aria-label': l.back_results },
            button(l.back_results, parent, 'arrow-left')));
    }
    function card(title, values) {
        const body = node('dl', { class: 'row g-2 mb-0' });
        for (const [label, value] of values) body.append(node('dt', { class: 'col-12 col-sm-5' }, label),
            node('dd', { class: 'col-12 col-sm-7 text-break mb-0' }, String(value ?? l.none)));
        return node('section', { class: 'bg-white border rounded-3 p-3 my-3 text-start' },
            node('h3', { class: 'h6 mb-3' }, title), body);
    }
    function evidence(record) {
        const grade = record.grade;
        return card(l.source, [[l.student, record.identity.name], [l.institutional_id, record.identity.institutional_id],
            [l.total, grade?.state === 'available' ? grade.grade : grade ? l[grade.state] : l.source_unavailable],
            [l.scale, grade?.state === 'available' ? `${grade.minimum} – ${grade.maximum}` : grade ? l[grade.state] : l.source_unavailable],
            [l.observed, new Date(record.observed_at).toLocaleString()]]);
    }
    async function open(section, back) {
        const host = drawer(l.title); returns(host, back);
        host.append(node('p', { class: 'text-secondary mt-3' }, l.help));
        const data = await request(base(section) + 'page/', 'POST', {}); if (!host.isConnected) return;
        host.append(node('h3', { class: 'h5 mt-3 text-break' }, `${data.section.code} · ${data.section.title}`));
        if (data.job) host.append(card(l.state, [[l.state, l[data.job.state]], [l.processed, data.job.processed], [l.ready, data.job.ready]]));
        if (data.job?.issue !== 'none' && data.job) host.append(node('p', { role: 'status' }, l.issue));
        const controls = node('div', { class: 'd-flex flex-wrap gap-2 my-3' }, button(l.refresh, () => open(section, back), 'arrow-clockwise'));
        host.append(controls);
        if (['queued', 'running'].includes(data.job?.state)) {
            controls.append(button(l.cancel, async () => { await request(base(section) + 'cancel/', 'POST', { revision: data.revision });
                if (host.isConnected) await open(section, back); }, 'stop-circle'));
        } else if (data.enabled) {
            const fields = [['reason', l.reason, 'textarea']];
            const form = await buildForm(fields, {}); if (!host.isConnected) return;
            form.elements.reason.required = true; form.elements.reason.maxLength = 1000;
            const submit = node('button', { class: 'btn btn-primary mt-3', type: 'submit' },
                node('i', { class: 'bi bi-cloud-download me-2', 'aria-hidden': 'true' }), l.queue);
            form.append(node('p', { class: 'form-text' }, l.queue_help), submit); host.append(form);
            form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
                if (!form.reportValidity()) return;
                await request(base(section) + 'request/', 'POST', { ...formData(form, fields), revision: data.revision, request_id: data.request_id });
                notify(l.queued); if (host.isConnected) await open(section, back);
            }, submit); });
        } else host.append(node('p', { role: 'status', class: 'alert alert-info' }, l.disabled));
        const list = node('div', { class: 'mt-4' }); host.append(list);
        const more = button(l.more, async () => { const next = await request(base(section) + 'page/', 'POST', { after });
            if (!host.isConnected) return; append(next.items); after = next.next_after; more.hidden = !after; }, 'chevron-down');
        let after = data.next_after;
        function append(items) {
            for (const item of items) {
                const entry = card(item.identity.name, [[l.institutional_id, item.identity.institutional_id],
                    [l.state, l[item.state]], [l.total, item.grade?.state === 'available' ? item.grade.grade : l[item.grade.state]]]);
                entry.append(button(l.record, () => detail(section, item.id, back), 'journal-check')); list.append(entry);
            }
        }
        if (!data.items.length) list.append(node('p', { role: 'status' }, l.empty));
        append(data.items); more.hidden = !after; host.append(more);
    }
    async function detail(section, candidate, back, draft = {}) {
        const host = drawer(l.title); returns(host, back, () => open(section, back));
        const record = await request(base(section) + `${candidate}/${draft.assignment_id ? '?assignment_id=' + encodeURIComponent(draft.assignment_id) : ''}`);
        if (!host.isConnected) return;
        host.append(evidence(record), node('p', { class: 'fw-semibold', role: 'status' }, l[record.state]));
        if (record.disposition) host.append(card(l.state, [[l.reason, record.disposition.reason],
            [l.recorded, new Date(record.disposition.decided_at).toLocaleString()]]));
        if (record.stale) host.append(node('p', { class: 'alert alert-warning' }, l.stale));
        if (record.grade_change_request_id) host.append(button(l.open_correction,
            () => ui.openGradeChange(record.grade_change_request_id, () => detail(section, candidate, back)), 'clipboard-check'));
        if (!['pending', 'unavailable'].includes(record.state)) return;
        const choices = record.choices;
        const fields = [];
        if (choices?.pursuits?.length) fields.push(['assignment_id', l.pursuit, 'choice', choices.pursuits.map(p => ({ value: p.id, label: `${p.program_code || p.code} · ${p.program_title || p.title}` }))]);
        if (choices) fields.push(['grade_id', l.grade, 'choice', choices.items.map(g => ({ value: g.id, label: g.code }))], ['effective_on', l.effective, 'date']);
        fields.push(['reason', l.reason, 'textarea']);
        const form = await buildForm(fields, { effective_on: choices?.effective_on_default, ...draft }); if (!host.isConnected) return;
        form.elements.reason.required = true; form.elements.reason.maxLength = 1000;
        if (form.elements.assignment_id) form.elements.assignment_id.required = !!choices.needs_assignment;
        form.elements.assignment_id?.addEventListener('change', () => task(() => detail(section, candidate, back, formData(form, fields))));
        host.append(node('p', { class: 'form-text' }, l.review_help), form);
        if (choices) host.append(card(l.before, [[l.before, choices.current_grade_code || l.none]]));
        const actions = node('div', { class: 'd-flex flex-wrap gap-2 mt-3' }); form.append(actions);
        if (choices?.items?.length) actions.append(button(l.accept, () => review('accept'), 'journal-check', true));
        actions.append(button(l.dismiss, () => review('dismiss'), 'x-circle'));
        async function review(action) {
            if (form.elements.grade_id) form.elements.grade_id.required = action === 'accept';
            if (!form.reportValidity()) return;
            const draft = formData(form, fields);
            const value = await request(base(section) + `${candidate}/review/`, 'POST', { ...draft, action, revision: record.revision });
            if (!host.isConnected) return;
            const confirm = drawer(l.review); returns(confirm, back);
            confirm.append(button(l.revise, () => detail(section, candidate, back, draft), 'arrow-left'), evidence(value.record),
                node('p', { class: 'mt-3' }, value.reason));
            if (value.grade_review) {
                confirm.append(card(l.after, [[l.grade, value.grade_review.grade.code], [l.effective, value.grade_review.effective_at]]));
                if (value.grade_review.requires_approval) confirm.append(node('p', { class: 'alert alert-info' }, l.approval));
            } else confirm.append(node('p', {}, l.dismissed));
            confirm.append(button(l.confirm, async () => {
                await request(base(section) + `${candidate}/confirm/`, 'POST', { review_token: value.review_token });
                notify(l.saved); if (confirm.isConnected) await detail(section, candidate, back);
            }, 'check2-circle', true));
        }
    }
    return { open, detail };
};
