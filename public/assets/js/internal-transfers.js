/* Reviewed receiving decisions; private grades, reasons and tokens remain in page memory. */
window.createInternalTransfers = function (ui) {
    'use strict';
    const { node, request, task, labels: l, buildForm, formData, notify } = ui;
    const base = (s, a) => `/sis/students/${s}/programs/${a}/internal-transfers/`;
    const button = (text, run, icon = 'arrow-right', primary = false) => node('button', { type: 'button',
        class: `btn ${primary ? 'btn-primary' : 'btn-outline-primary'} d-inline-flex align-items-center gap-2`,
        onclick: event => task(run, event.currentTarget) }, node('i', { class: `bi bi-${icon}`, 'aria-hidden': 'true' }), node('span', {}, text));
    const units = value => String(value / 1000);
    const drawer = title => { const host = ui.openDrawer(title); host.classList.add('internal-transfer-workbench'); return host; };
    function card(title, rows) {
        const list = node('dl', { class: 'row mb-0' });
        for (const [label, value] of rows) list.append(node('dt', { class: 'col-sm-5' }, label), node('dd', { class: 'col-sm-7' }, value));
        return node('section', { class: 'bg-white border rounded p-3 mb-3', 'aria-label': title }, node('h3', { class: 'h5' }, title), list);
    }
    const targetCard = target => card(l.receiving, [...(target.identity ? [[l.student, target.identity.name], [l.institutional_id, target.identity.institutional_id]] : []), [l.receiving, `${target.program_code} · ${target.program_title}`]]);
    const sourceCard = source => card(l.source, [[l.source_program, `${source.source_program_code} · ${source.source_program_title}`],
        [l.credential, source.credential_title], [l.graduated, source.credential_awarded_on],
        [l.source, `${source.course_code} · ${source.course_title}`], [l.original_credits, units(source.credits_milli)],
        [l.completed, source.completed_on], ...(source.grade ? [[l.source_grade, source.grade.code]] : [])]);
    function returns(host, root, list = null) {
        host.append(button(l.back_record, root, 'arrow-left'));
        if (list) host.append(node('section', { class: 'my-3', 'aria-label': l.back_list }, button(l.back_list, list, 'arrow-left')));
    }
    async function open(student, assignment, back, after = null, host = null) {
        if (!host) { host = drawer(l.title); returns(host, back); host.append(node('p', { class: 'my-3' }, l.help)); }
        const page = await request(base(student, assignment) + 'page/', 'POST', { after });
        if (!host.isConnected) return;
        if (!after) {
            host.append(targetCard(page.receiving));
            if (page.receiving.status === 'active') host.append(button(l.evaluate, () => candidates(student, assignment, back), 'clipboard-check', true));
            else host.append(node('p', { class: 'alert alert-info' }, l.closed));
        }
        for (const record of page.items) {
            const box = card(l.received, [[l.course, `${record.credit.course_code} · ${record.credit.course_title}`],
                [l.credits, units(record.credit.completed_milli)], [l.grade, record.credit.grade_code || l.voided], [l.completed, record.completed_on]]);
            box.append(button(l.open, () => detail(student, assignment, record.id, back), 'journal-check')); host.append(box);
        }
        if (!page.items.length && !after) host.append(node('p', { class: 'mt-3' }, l.no_awards));
        if (page.next_after) { const more = button(l.more, async () => { await open(student, assignment, back, page.next_after, host); more.remove(); }, 'chevron-down'); host.append(more); }
    }
    async function candidates(student, assignment, back, after = null, host = null) {
        if (!host) { host = drawer(l.candidates); returns(host, back, () => open(student, assignment, back)); }
        const page = await request(base(student, assignment) + 'candidates/', 'POST', { after });
        if (!host.isConnected) return;
        if (!after) host.append(targetCard(page.receiving), node('p', {}, l.source_help));
        for (const source of page.items) {
            const box = sourceCard(source); box.append(button(l.evaluate, () => edit(student, assignment, 'grant', source, page.grades, back), 'clipboard-check')); host.append(box);
        }
        if (!page.items.length && !after) host.append(node('p', { role: 'status' }, l.empty));
        if (page.next_after) { const more = button(l.more_candidates, async () => { await candidates(student, assignment, back, page.next_after, host); more.remove(); }, 'chevron-down'); host.append(more); }
    }
    async function detail(student, assignment, award, back) {
        const host = drawer(l.title); returns(host, back, () => open(student, assignment, back));
        const record = await request(base(student, assignment) + award + '/');
        if (!host.isConnected) return;
        host.append(sourceCard(record.source), card(l.received, [[l.course, `${record.credit.course_code} · ${record.credit.course_title}`],
            [l.credits, units(record.credit.completed_milli)], [l.grade, record.credit.grade_code || l.voided],
            [l.posting_term, record.term_code], [l.completed, record.completed_on]]));
        const actions = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' });
        const kinds = record.credit.status === 'V' ? ['reinstate'] : ['amend', 'void'];
        for (const action of kinds) actions.append(button(l[action], () => edit(student, assignment, action, record.source, record.grades, back, record), action === 'void' ? 'slash-circle' : 'pencil-square'));
        if (record.credit.status !== 'V') actions.append(button(l.replace_course, () => course(student, assignment, record, back), 'journal-bookmark'));
        if (record.credit.status !== 'V') actions.append(button(l.change_term, () => postingTerm(student, assignment, record, back), 'calendar3'));
        host.append(actions);
        const history = node('section', { 'aria-label': l.history }, node('h3', { class: 'h5' }, l.history)); host.append(history);
        await timeline(history, student, assignment, award);
    }
    async function timeline(host, student, assignment, award, cursor = null) {
        const page = await request(base(student, assignment) + award + '/history/', 'POST', { cursor });
        if (!host.isConnected) return;
        for (const row of page.items) host.append(node('p', { class: 'bg-white border rounded p-3' },
            `${l[row.action] || l.course_history} · ${row.recorded_at.slice(0, 10)} · ${row.reason}${row.credits_milli !== null ? ` · ${units(row.credits_milli)}` : ''}${row.grade_code ? ` · ${row.grade_code}` : ''}`));
        if (page.next_cursor) { const more = button(l.earlier, async () => { await timeline(host, student, assignment, award, page.next_cursor); more.remove(); }, 'chevron-down'); host.append(more); }
    }
    async function coursePicker(form, student, assignment, selected, draft) {
        let requestNumber = 0;
        const selection = form.elements.course_id, publication = form.elements.course_version_id;
        const status = node('p', { role: 'status', class: 'form-text' });
        publication.parentElement.append(status);
        let cursor = null;
        const more = button(l.more_publications, async () => load(true), 'chevron-down'); more.hidden = true; publication.parentElement.append(more);
        async function load(append = false) {
            const serial = ++requestNumber, courseId = selection.value;
            if (!append) { publication.replaceChildren(node('option', { value: '' }, l.publication)); publication.disabled = true; }
            const page = await request(base(student, assignment) + `courses/${courseId}/publications/`, 'POST', { cursor: append ? cursor : null });
            if (serial !== requestNumber || !form.isConnected || selection.value !== courseId) return;
            for (const item of page.items) publication.append(node('option', { value: item.version_id }, `${item.code} · ${item.title} · ${item.version}`));
            publication.disabled = false; cursor = page.next_cursor; more.hidden = !cursor;
            status.textContent = publication.options.length === 1 ? l.no_publications : l.pending;
            if (draft.course_id === courseId && draft.course_version_id && [...publication.options].some(o => o.value === draft.course_version_id)) publication.value = draft.course_version_id;
        }
        selection.addEventListener('change', () => task(() => load()));
        const inputId = `internal-course-search-${++courseSequence}`;
        const input = node('input', { id: inputId, class: 'form-control', type: 'search', maxlength: 80 });
        const results = node('div', { class: 'd-flex flex-column align-items-start gap-2 mt-3' });
        let after = null, query = '';
        const moreCourses = button(l.more_courses, async () => find(true), 'chevron-down'); moreCourses.hidden = true;
        async function find(append = false) {
            if (!append) { query = input.value.trim(); results.replaceChildren(); }
            const page = await request(base(student, assignment) + 'search/', 'POST', { search: query, after: append ? after : null });
            if (!form.isConnected || input.value.trim() !== query) return;
            for (const item of page.items) results.append(button(`${l.choose}: ${item.code} · ${item.title}`, async () => {
                if (![...selection.options].some(o => o.value === item.id)) selection.append(node('option', { value: item.id }, `${item.code} · ${item.title}`));
                selection.value = item.id; await load();
            }, 'check2'));
            after = page.next_after; moreCourses.hidden = !after;
            if (!page.items.length && !append) results.append(node('p', { role: 'status' }, l.no_courses));
        }
        const findButton = button(l.find, () => find(), 'search');
        input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); void task(() => find(), findButton); } });
        form.prepend(node('details', { class: 'my-3' }, node('summary', {}, l.search),
            node('label', { for: inputId, class: 'form-label mt-3' }, l.search), input, findButton, results, moreCourses));
        await load();
    }
    let courseSequence = 0;
    async function edit(student, assignment, action, source, grades, back, record = null, draft = {}) {
        const host = drawer(action === 'grant' ? l.title : l[action]);
        returns(host, back, () => record ? detail(student, assignment, record.id, back) : candidates(student, assignment, back));
        host.append(sourceCard(source), node('p', { class: action === 'void' ? 'alert alert-warning' : 'form-text' }, action === 'void' ? l.void_help : l.source_help));
        const selected = { id: draft.course_id || source.course_id, label: draft.course_label || `${source.course_code} · ${source.course_title}` };
        const fields = action === 'grant' ? [['course_id', l.course, 'choice', [{ value: selected.id, label: selected.label }]], ['course_version_id', l.publication, 'choice', []], ['term_id', l.posting_term, 'lookup', 'terms']] : [];
        if (action !== 'void') fields.push(['credits_milli', l.credits, 'credits'], ['grade_code', l.grade, 'choice', grades.map(g => ({ value: g.code, label: `${g.code} · ${g.counts_gpa ? l.gpa_included : l.gpa_excluded}` }))]);
        fields.push(['reason', l.reason, 'textarea']);
        const preset = { grade_code: record?.credit.grade_code || record?.retained_grade_code || '', ...draft, ...(draft.credits_milli === undefined ? { credits_milli: units(record?.retained_credits_milli || record?.credit.attempted_milli || source.credits_milli) } : { credits_milli: units(draft.credits_milli) }) };
        const form = await buildForm(fields, preset); if (!host.isConnected) return;
        if (form.elements.credits_milli) { form.elements.credits_milli.max = units(source.credits_milli); form.elements.credits_milli.min = '.001'; }
        form.elements.reason.maxLength = 1000;
        if (action !== 'void') host.append(node('p', { class: 'form-text' }, l.choice_help));
        host.append(form);
        if (action === 'grant') { form.elements.course_id.value = selected.id; await coursePicker(form, student, assignment, selected, draft); }
        const review = node('button', { type: 'submit', class: 'btn btn-primary' }, l.review); form.append(review);
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!form.reportValidity()) return;
            const values = formData(form, fields), reason = values.reason; delete values.reason;
            const details = { ...values, ...(record ? { award_id: record.id } : { source_credit_id: source.source_credit_id }) };
            const result = await request(base(student, assignment) + 'review/', 'POST', { action, request: details, reason });
            if (!host.isConnected) return;
            const saved = { ...values, reason, ...(action === 'grant' ? { course_label: form.elements.course_id.selectedOptions[0].label } : {}) };
            const confirm = drawer(l.review); returns(confirm, back);
            confirm.append(button(l.edit, () => edit(student, assignment, action, source, grades, back, record, saved), 'arrow-left'), sourceCard(result.summary.source),
                targetCard(result.summary.receiving), node('p', {}, l.review_help), node('p', { class: 'fw-semibold' }, action === 'grant' ? l.grant : l[action]), node('p', {}, reason));
            if (action !== 'void') confirm.append(card(l.received, [[l.course, `${result.summary.course.definition.code} · ${result.summary.course.definition.title}`],
                [l.credits, units(result.summary.credits_milli)], [l.grade, result.summary.grade_code],
                [l.gpa_treatment, result.summary.counts_gpa ? l.gpa_included : l.gpa_excluded], [l.posting_term, result.summary.term.code]]));
            confirm.append(button(l.confirm, async () => {
                const accepted = await request(base(student, assignment) + 'confirm/', 'POST', result);
                notify(l.saved); if (confirm.isConnected) await detail(student, assignment, accepted.award_id, back);
            }, 'check2-circle', true));
        }, review); });
    }
    async function course(student, assignment, record, back, draft = {}) {
        const host = drawer(l.replace_course); returns(host, back, () => detail(student, assignment, record.id, back)); host.append(sourceCard(record.source));
        const selected = { id: draft.course_id || record.credit.course_id, label: draft.course_label || `${record.credit.course_code} · ${record.credit.course_title}` };
        const fields = [['course_id', l.course, 'choice', [{ value: selected.id, label: selected.label }]], ['course_version_id', l.publication, 'choice', []], ['effective_on', l.effective, 'date'], ['reason', l.reason, 'textarea']];
        const form = await buildForm(fields, { effective_on: record.effective_on_default, ...draft }); if (!host.isConnected) return;
        form.elements.effective_on.min = record.completed_on; form.elements.effective_on.max = record.effective_on_default; form.elements.reason.maxLength = 1000;
        host.append(node('p', { class: 'form-text' }, l.date_help), form); form.elements.course_id.value = selected.id;
        await coursePicker(form, student, assignment, selected, draft);
        const review = node('button', { type: 'submit', class: 'btn btn-primary' }, l.course_review); form.append(review);
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!form.reportValidity()) return;
            const values = formData(form, fields), prefix = `/sis/students/${student}/transfer-awards/${record.id}/accepted-course/`;
            const result = await request(prefix + 'review/', 'POST', { ...values, version: record.credit.version });
            if (!host.isConnected) return;
            const saved = { ...values, course_label: form.elements.course_id.selectedOptions[0].label };
            const confirm = drawer(l.course_review); returns(confirm, back);
            confirm.append(button(l.edit, () => course(student, assignment, record, back, saved), 'arrow-left'), sourceCard(record.source),
                card(l.received, [[l.course, `${result.before.course_code} → ${result.after.course_code}`], [l.effective, result.effective_on], [l.reason, result.reason]]),
                button(l.course_confirm, async () => {
                    await request(prefix + 'commit/', 'POST', { review_token: result.review_token }); notify(l.saved);
                    if (confirm.isConnected) await detail(student, assignment, record.id, back);
                }, 'check2-circle', true));
        }, review); });
    }
    async function postingTerm(student, assignment, record, back, draft = {}) {
        const host = drawer(l.change_term); returns(host, back, () => detail(student, assignment, record.id, back));
        host.append(sourceCard(record.source), node('p', { class: 'form-text' }, l.term_help));
        const fields = [['term_id', l.posting_term, 'lookup', 'terms'], ['effective_on', l.effective, 'date'], ['reason', l.reason, 'textarea']];
        const form = await buildForm(fields, { term_id: record.credit.term_id, effective_on: record.effective_on_default, ...draft });
        if (!host.isConnected) return;
        form.elements.effective_on.min = record.completed_on; form.elements.effective_on.max = record.effective_on_default; form.elements.reason.maxLength = 1000;
        const review = node('button', { type: 'submit', class: 'btn btn-primary' }, l.term_review); form.append(review); host.append(form);
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (!form.reportValidity()) return;
            const values = formData(form, fields), prefix = `/sis/students/${student}/transfer-awards/${record.id}/source-period/`;
            const result = await request(prefix + 'review/', 'POST', { ...values, version: record.credit.version,
                completed_on: record.completed_on, academic_on: record.academic_on });
            if (!host.isConnected) return;
            const confirm = drawer(l.term_review); returns(confirm, back);
            confirm.append(button(l.edit, () => postingTerm(student, assignment, record, back, values), 'arrow-left'), sourceCard(record.source),
                card(l.received, [[l.posting_term, form.elements.term_id.selectedOptions[0].label], [l.effective, values.effective_on], [l.reason, values.reason]]),
                button(l.term_confirm, async () => { await request(prefix + 'commit/', 'POST', { review_token: result.review_token });
                    notify(l.saved); if (confirm.isConnected) await detail(student, assignment, record.id, back);
                }, 'check2-circle', true));
        }, review); });
    }
    return { open, detail };
};
