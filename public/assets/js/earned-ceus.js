/* Explicit institutional awards. Drafts and review grants live only in this drawer. */
window.createEarnedCeus = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, labels = {} } = ui;
    const text = (key, fallback) => labels[key] || fallback;
    const quantity = value => window.earnedCeuText(value, labels);
    const dateTime = value => new Intl.DateTimeFormat(undefined, {
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short',
    }).format(new Date(value));
    const button = (label, icon, run) => node('button', { type: 'button',
        class: 'btn btn-outline-primary d-inline-flex align-items-center gap-2',
        onclick: event => task(run, event.currentTarget) },
    node('i', { class: `bi bi-${icon}`, 'aria-hidden': 'true' }), label);
    async function open(student, section, enrollment, back, preset = null, returnLabel = null) {
        const body = openDrawer(text('earned_title', 'Earned CEUs'));
        const panel = node('section', { 'aria-label': text('earned_title', 'Earned CEUs') });
        body.append(panel, button(returnLabel || text('back', 'Back to record'), 'arrow-left', back));
        panel.append(node('p', { role: 'status' }, text('earned_loading', 'Loading earned CEU evidence…')));
        const base = `/sis/students/${student}/sections/${section}/enrollments/${enrollment}/earned-ceus/`;
        const data = await request(base + 'view/', 'POST', {});
        if (!panel.isConnected) return;
        let epoch = 0;
        const current = ticket => panel.isConnected && ticket === epoch;
        const draft = preset || { mode: 'value', value: data.earned_ceus?.earned_milli === null
            || !data.earned_ceus ? '' : String(data.earned_ceus.earned_milli / 1000),
        effective: data.today, completion: data.earned_ceus?.completed_on || '', reason: '', acknowledged: false };
        const details = (pairs) => node('dl', { class: 'detail-list' }, pairs.map(([label, value]) =>
            node('div', {}, node('dt', {}, label), node('dd', {}, value))));
        const context = () => node('div', {}, node('p', { class: 'fw-semibold' }, `${data.course_code} · ${data.course_title}`),
            details([[text('section_title', 'Offered CEUs'), window.continuingEducationText(
                data.offered_milli === null ? null : { ceu_milli: data.offered_milli }, labels)],
            [text('earned_title', 'Earned CEUs'), quantity(data.earned_ceus)],
            [data.withdrawn ? text('earned_previous_completion', 'Previously recorded CEU completion date') : text('earned_completion', 'Verified actual completion'),
                (data.withdrawn ? data.earned_ceus?.completed_on : data.completed_on) || text('earned_date_unknown', 'Not recorded')]]),
            ...(data.withdrawn ? [node('p', { class: 'alert alert-info' }, text('earned_withdrawn',
                'Withdrawn academic attempt. Record CEUs only for independently verified completed continuing education. The CEU completion date belongs to this award; enrollment stays withdrawn and academic earned credit stays zero.'))] : []));
        const purge = error => { if ([401, 403].includes(error.status)) panel.replaceChildren(); throw error; };
        function edit() {
            ++epoch;
            panel.replaceChildren(context(), node('p', { class: 'form-text' }, text('earned_help',
                'Record earned CEUs only after verifying institutional completion evidence. Offered CEUs are a separate publication; this decision changes neither academic credits, GPA, registration load nor charges.')));
            if (!data.can_record) {
                panel.append(node('p', { class: 'alert alert-info', role: 'status' }, data.can_manage ? text('earned_unavailable',
                    'This attempt needs registrar review: a retained offered CEU declaration, a verified passing grade, completion evidence and no pending grade correction are required. Removed or unknown attempts need separate resolution.')
                    : data.withdrawn ? text('earned_withdrawn_read_only', 'Decisions and protected history for withdrawn attempts require both earned-CEU and closed-record permissions.')
                        : text('earned_read_only', 'You can review recorded CEUs. Award and correction actions require the earned-CEU permission.')));
                if (data.can_manage) panel.append(button(text('earned_history', 'Earned CEU history'), 'clock-history', () => history()));
                return;
            }
            const form = node('form');
            const fields = node('fieldset', { class: 'record-form-section' },
                node('legend', {}, text('earned_mode', 'Earned CEU decision')));
            const field = (label, element) => { fields.append(node('div', { class: 'mb-3' },
                node('label', { for: element.id, class: 'form-label' }, label), element)); return element; };
            const mode = field(text('earned_mode', 'Earned CEU decision'), node('select', { id: 'earned-ceu-mode', class: 'form-select' },
                node('option', { value: 'value' }, text('earned_value_mode', 'Record earned CEUs')),
                ...(data.earned_ceus ? [node('option', { value: 'revoke' }, text('earned_revoke', 'Revoke the recorded CEU award'))] : [])));
            mode.value = draft.mode;
            const amount = field(text('earned_value', 'Earned continuing education units (CEUs)'), node('input', {
                type: 'number', id: 'earned-ceu-value', class: 'form-control', min: '0', max: String(data.offered_milli / 1000),
                step: '0.001', required: true, value: draft.value, inputmode: 'decimal',
            }));
            const effective = field(text('earned_effective', 'Effective date of this CEU decision'), node('input', {
                type: 'date', id: 'earned-ceu-effective', class: 'form-control', required: true,
                min: data.completed_on, max: data.today, value: draft.effective,
            }));
            const completion = data.withdrawn ? field(text('earned_ceu_completion', 'Verified CEU completion date'), node('input', {
                type: 'date', id: 'earned-ceu-completion', class: 'form-control', required: true,
                max: data.today, value: draft.completion,
            })) : null;
            const reason = field(text('earned_reason', 'Reason and institutional completion evidence'), node('textarea', {
                id: 'earned-ceu-reason', class: 'form-control', maxlength: '1000', rows: '4', required: true,
            }, draft.reason));
            const ack = node('input', { type: 'checkbox', id: 'earned-ceu-ack', class: 'form-check-input',
                required: true, checked: draft.acknowledged });
            fields.append(node('div', { class: 'form-check mb-3' }, ack, node('label', { for: ack.id, class: 'form-check-label' },
                text('earned_ack', 'I verified the institutional evidence and the separate academic-credit and billing consequences.'))));
            const update = () => { amount.disabled = mode.value === 'revoke'; };
            mode.addEventListener('change', update); update();
            form.addEventListener('input', () => Object.assign(draft, { mode: mode.value, value: amount.value,
                effective: effective.value, completion: completion?.value || '', reason: reason.value, acknowledged: ack.checked }));
            const submit = node('button', { type: 'submit', class: 'btn btn-primary' }, text('earned_review', 'Review earned CEUs'));
            form.append(fields, node('div', { class: 'inline-actions' }, submit,
                button(text('earned_history', 'Earned CEU history'), 'clock-history', () => history()),
                button(text('earned_reload', 'Reload current academic evidence'), 'arrow-clockwise',
                    () => open(student, section, enrollment, back, draft, returnLabel))));
            form.addEventListener('submit', event => {
                event.preventDefault();
                void task(async () => {
                    if (!form.reportValidity()) return;
                    const ticket = ++epoch;
                    const raw = amount.value.trim();
                    let milli = null;
                    if (mode.value !== 'revoke') {
                        if (!/^\d{1,3}(?:\.\d{1,3})?$/.test(raw)) throw new Error(text('earned_invalid',
                            'Use a CEU value with up to three decimal places within the accepted offered amount.'));
                        const [whole, fraction = ''] = raw.split('.');
                        milli = Number(whole) * 1000 + Number(fraction.padEnd(3, '0'));
                    }
                    fields.disabled = true;
                    try {
                        const reviewed = await request(base + 'review/', 'POST', { expected_version: data.expected_version,
                            expected_position: data.expected_position, earned_milli: milli, effective_on: effective.value,
                            reason: reason.value, acknowledged: ack.checked,
                            ...(completion ? { completed_on: completion.value } : {}) });
                        if (current(ticket)) review(reviewed);
                    } catch (error) { purge(error); } finally { fields.disabled = false; }
                }, submit);
            });
            panel.append(form);
        }
        function review(reviewed) {
            const ticket = ++epoch;
            const heading = node('h3', { class: 'h5', tabindex: '-1' }, text('earned_review', 'Review earned CEUs'));
            panel.replaceChildren(heading, context(), details([
                [text('earned_before', 'Previously recorded CEUs'), quantity(reviewed.command.previous_award)],
                [text('earned_previous_completion', 'Previously recorded CEU completion date'),
                    reviewed.command.previous_award?.completed_on || text('earned_date_unknown', 'Not recorded')],
                [text('earned_after', 'Reviewed earned CEUs'), quantity(reviewed.command.award)],
                [text('earned_ceu_completion', 'Verified CEU completion date'), reviewed.command.award.completed_on],
                [text('earned_effective', 'Effective date of this CEU decision'), reviewed.command.effective_on],
                [text('earned_reason', 'Reason and institutional completion evidence'), reviewed.command.reason],
            ]));
            panel.append(button(text('earned_confirm', 'Confirm CEU decision'), 'check2', async () => {
                try {
                    await request(base + 'confirm/', 'POST', { command: reviewed.command, review_token: reviewed.review_token });
                    if (!current(ticket)) return;
                    ++epoch;
                    panel.replaceChildren(node('p', { role: 'status', class: 'alert alert-success' }, text('earned_saved',
                        'Earned CEU decision recorded. Earlier decisions remain in protected history.')),
                    button(text('earned_reload', 'Reload current academic evidence'), 'arrow-clockwise',
                        () => open(student, section, enrollment, back, null, returnLabel)),
                    button(text('earned_history', 'Earned CEU history'), 'clock-history', () => history()));
                } catch (error) { purge(error); }
            }), button(text('earned_edit', 'Change CEU decision'), 'pencil', edit));
            heading.focus();
        }
        async function history(cursor = null, stack = []) {
            const ticket = ++epoch;
            panel.replaceChildren(node('p', { role: 'status' }, text('earned_history_loading', 'Loading earned CEU decisions…')),
                button(text('earned_return', 'Return to CEU decision'), 'arrow-left', edit));
            try {
                const result = await request(base + 'history/', 'POST', cursor ? { cursor } : {});
                if (!current(ticket)) return;
                const cards = result.items.map(item => node('section', { class: 'record-form-section' }, details([
                    [text('earned_before', 'Previously recorded CEUs'), quantity(item.before)],
                    [text('earned_previous_completion', 'Previously recorded CEU completion date'),
                        item.before?.completed_on || text('earned_date_unknown', 'Not recorded')],
                    [text('earned_after', 'Reviewed earned CEUs'), quantity(item.after)],
                    [text('earned_ceu_completion', 'Verified CEU completion date'), item.after.completed_on || text('earned_date_unknown', 'Not recorded')],
                    [text('earned_effective_at', 'Effective at'), dateTime(item.effective_at)],
                    [text('earned_recorded', 'Recorded at'), dateTime(item.recorded_at)],
                    [text('earned_actor', 'Recorded by (current name)'), item.actor_name
                        || text('earned_actor_unknown', 'Staff account name unavailable')],
                    [text('earned_reason', 'Reason and institutional completion evidence'), item.reason],
                ])));
                panel.replaceChildren(node('h3', { class: 'h5' }, text('earned_history', 'Earned CEU history')),
                    ...cards, ...(cards.length ? [] : [node('p', { role: 'status' }, text('earned_history_empty',
                        'No earned CEU decisions appear on this history page.'))]),
                    node('div', { class: 'inline-actions' },
                        ...(stack.length ? [button(text('earned_previous', 'Previous history page'), 'arrow-left',
                            () => history(stack.at(-1), stack.slice(0, -1)))] : []),
                        ...(result.next_cursor ? [button(text('earned_more', 'Older history'), 'arrow-right',
                            () => history(result.next_cursor, [...stack, cursor]))] : []),
                        button(text('earned_return', 'Return to CEU decision'), 'arrow-left', edit)));
            } catch (error) { purge(error); }
        }
        edit();
    }
    async function records(student, back, after = null, stack = []) {
        const body = openDrawer(text('earned_records', 'Earned CEU records'));
        const panel = node('section');
        body.append(panel, button(text('back', 'Back to record'), 'arrow-left', back));
        panel.append(node('p', { role: 'status' }, text('earned_loading', 'Loading earned CEU evidence…')));
        const result = await request(`/sis/students/${student}/earned-ceus/records/`, 'POST', after ? { after } : {});
        if (!panel.isConnected) return;
        panel.replaceChildren(...result.items.map(item => node('section', { class: 'record-form-section' },
            button(`${item.course_code} · ${item.course_title}`, 'award', () => open(student,
                item.section_id, item.enrollment_id, () => records(student, back, after, stack), null,
                text('earned_back_records', 'Back to CEU records'))))),
        ...(result.items.length ? [] : [node('p', { role: 'status' }, text('earned_records_empty',
            'No CEU-bearing attempts appear on this page.'))]), node('div', { class: 'inline-actions' },
            ...(stack.length ? [button(text('earned_records_previous', 'Previous CEU records'), 'arrow-left',
                () => records(student, back, stack.at(-1), stack.slice(0, -1)))] : []),
            ...(result.next_after ? [button(text('earned_records_more', 'More CEU records'), 'arrow-right',
                () => records(student, back, result.next_after, [...stack, after]))] : [])));
    }
    return { open, records };
};
