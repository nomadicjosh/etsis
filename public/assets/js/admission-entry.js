/* Staff intake approval: one application, original answers, immutable before/after terms and a fresh offer. */
(() => {
    'use strict';
    let sequence = 0;
    const defaults = {
        "loading": "Loading entry term…",
        "load_failed": "The intake could not be loaded. Return to the application and try again.",
        "denied": "Access changed. Return to the application to review your available actions.",
        "previous": "Previously approved intake",
        "current": "Current entry term",
        "approval_help": "Keep this application and its answers. A new intake approval needs a fresh offer under institutional policy.",
        "correction_help": "Correct the planned intake before a decision. Keep original intake and answers; no student is created.",
        "withdrawal_pending": "Resolve the pending withdrawal request before approving an intake.",
        "answers_pending": "Resolve the answer correction request before correcting the entry term.",
        "closed": "Only submitted or accepted, unmatriculated applications can receive an intake change.",
        "calendar_changed": "The approved calendar changed. Review the current intake before issuing an offer or matriculating.",
        "review_current": "Review current intake",
        "choose_later": "Choose later intake",
        "choose_term": "Correct entry term",
        "replace_draft": "Replace the unfinished intake review?",
        "later_title": "Choose a later intake",
        "correct_title": "Correct planned intake",
        "updated_title": "Review updated intake",
        "search_placeholder": "Term code or name",
        "available": "Available intakes",
        "search_pages": "Intake search pages",
        "find": "Find terms",
        "find_intake": "Find an intake",
        "find_later": "Find a later intake",
        "finding_intakes": "Finding available intakes…",
        "finding_later": "Finding later intakes…",
        "search_changed": "Search changed. Choose Find terms.",
        "first": "First results",
        "more": "More terms",
        "choose_reason": "Choose an intake, then add the reason.",
        "no_intakes": "No matching intakes. Choose a term that has not started and has an academic year with valid dates.",
        "no_later": "No matching later intakes. Review academic year and term setup with the catalog office.",
        "search_failed": "Could not find terms. Your reason and selected intake are unchanged.",
        "updated_calendar": "Updated calendar definition",
        "review": "Review intake",
        "approval_reason": "Reason for approval",
        "correction_reason": "Reason for correction",
        "choose_first": "Choose an intake first.",
        "later_first": "Choose a later intake first.",
        "preparing": "Preparing intake review…",
        "review_heading": "Review before confirming",
        "before_correction": "Before correction",
        "after_correction": "After correction",
        "before_approval": "Before approval",
        "after_approval": "After approval",
        "approval_review_help": "Keep earlier terms, answers and offers. Issue a fresh offer for this intake; no student is created.",
        "correction_review_help": "The application stays awaiting a decision. Earlier intake and answers remain; no student is created.",
        "revise": "Revise",
        "approve": "Approve intake",
        "confirm_correction": "Confirm entry-term correction",
        "approved": "Intake approved. Issue a fresh offer.",
        "corrected": "Entry term corrected. The application still awaits a decision.",
        "commit_failed": "The change could not be completed. Retry this confirmation or revise the review.",
        "ready": "Review ready. Confirm to save.",
        "review_failed": "Review could not be completed. Your reason and choice are still shown.",
        "leave": "Leave the unfinished intake review?",
        "dates": "{start} through {end}",
        "year": "Academic year {year}",
        "no_year": "Academic year not linked",
        "institutional_id": "Institutional ID: {id}",
        "selected": "Selected intake: {code}",
        "selected_year": "Selected intake: {code} · {year}",
        "entry_title": "Entry term",
        "entry_context": "ADMISSIONS INTAKE",
        "back_application": "Back to application",
        "history_correct": "Entry term corrected",
        "history_recheck": "Corrected intake calendar reviewed",
        "history_defer": "Entry deferred",
        "history_reconfirm": "Updated intake approved"
};
    window.createAdmissionEntry = ({ node, request, task, notify, labels = {} }) => ({
        mount(container, application, { onComplete = async () => {} } = {}) {
            const base = `/sis/applications/${application}/entry/`;
            const status = node('p', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true', class: 'form-text' });
            const body = node('div'); container.append(status, body);
            const text = key => labels[key] || defaults[key];
            const format = (key, values) => text(key).replace(/\{([a-z_]+)\}/g, (_, name) => String(values[name] ?? ''));
            let busy = true, searching = false, dirty = false, generation = 0;
            function failed(error, fallback) {
                if ([401, 403].includes(error.status)) {
                    body.replaceChildren(); dirty = false; busy = false; searching = false; generation++;
                    status.textContent = text('denied');
                } else status.textContent = text(fallback);
            }
            const alive = ticket => ticket === generation && container.isConnected;
            const button = (text, action, style = 'btn-outline-secondary') => {
                const b = node('button', { type: 'button', class: `btn ${style}` }, text);
                b.addEventListener('click', () => { if (!busy && !searching) task(action); }); return b;
            };
            const summary = (target, term, title) => {
                target.append(node('h3', { class: 'h6' }, title), node('p', { class: 'fw-semibold text-break' }, `${term.code} · ${term.name}`),
                    node('p', {}, format('dates', { start: term.starts_on, end: term.ends_on })),
                    node('p', { class: 'form-text' }, term.year_code ? format('year', { year: term.year_code }) : text('no_year')));
            };
            async function load() {
                const ticket = ++generation; busy = true; status.textContent = text('loading');
                try {
                    const data = await request(base);
                    if (!alive(ticket)) return;
                    render(data); status.textContent = '';
                } catch (error) {
                    if (alive(ticket)) failed(error, 'load_failed');
                    throw error;
                } finally { if (alive(ticket)) busy = false; }
            }
            function render(data) {
                const person = data.person;
                const correction = data.application_status === 'submitted';
                body.replaceChildren(node('h2', { class: 'h5' }, `${person.given_name} ${person.family_name}`),
                    node('p', { class: 'form-text' }, format('institutional_id', { id: person.institutional_id })));
                summary(body, data.approved || data.current, data.approved ? text('previous') : text('current'));
                body.append(node('p', { class: 'form-text' }, text(correction ? 'correction_help' : 'approval_help')));
                if (!['accepted', 'submitted'].includes(data.application_status) || data.withdrawal_pending || data.answers_pending) {
                    body.append(node('p', { class: 'alert alert-info' }, data.withdrawal_pending
                        ? text('withdrawal_pending') : data.answers_pending ? text('answers_pending') : text('closed')));
                    return;
                }
                const editor = node('div'); body.append(editor);
                if (data.needs_review) {
                    body.append(node('p', { class: 'alert alert-warning' }, text('calendar_changed')));
                    body.append(button(text('review_current'), () => edit(correction ? 'recheck' : 'reconfirm'), 'btn-outline-primary'));
                }
                const defer = button(text(correction ? 'choose_term' : 'choose_later'), () => edit(correction ? 'correct' : 'defer'), 'btn-primary'); body.append(defer);
                function edit(kind) {
                    if (dirty && !window.confirm(text('replace_draft'))) return;
                    generation++; dirty = false; editor.replaceChildren(); status.textContent = '';
                    const form = node('form', { class: 'record-form mt-3' }), controls = node('fieldset', { class: 'record-form-section' }), preview = node('div', { class: 'mt-3' });
                    controls.append(node('legend', { class: 'h6' }, text(kind === 'defer' ? 'later_title' : kind === 'correct' ? 'correct_title' : 'updated_title')));
                    let selected = ['reconfirm', 'recheck'].includes(kind) ? data.current : null;
                    const chosen = node('p', { role: 'status', 'aria-atomic': 'true', class: 'form-text' });
                    if (['defer', 'correct'].includes(kind)) {
                        const identity = `intake-search-${++sequence}`;
                        const search = node('input', { type: 'search', id: identity, class: 'form-control', maxlength: 80, placeholder: text('search_placeholder') });
                        const results = node('fieldset', { class: 'record-form-section mt-3' }, node('legend', { class: 'h6' }, text('available')));
                        const choices = node('div'), pages = node('nav', { class: 'd-flex flex-wrap gap-2 mt-2', 'aria-label': text('search_pages') }); results.append(choices, pages);
                        let searchGeneration = 0;
                        const find = button(text('find'), () => list());
                        controls.append(node('label', { class: 'form-label', for: identity }, text(correction ? 'find_intake' : 'find_later')),
                            node('div', { class: 'd-flex flex-wrap gap-2 admission-intake-search' }, search, find), results);
                        search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); if (!busy && !searching) task(() => list()); } });
                        async function list(after = null) {
                            const ticket = generation, serial = ++searchGeneration; searching = true; find.disabled = true;
                            const searchText = search.value, query = new URLSearchParams({ search: searchText }); if (after) query.set('after', after);
                            chosen.textContent = text(correction ? 'finding_intakes' : 'finding_later');
                            try {
                                const packet = await request(`${base}terms/?${query}`);
                                if (!alive(ticket) || !form.isConnected || serial !== searchGeneration) return;
                                if (searchText !== search.value) { chosen.textContent = text('search_changed'); return; }
                                choices.replaceChildren(); pages.replaceChildren();
                                for (const term of packet.items) {
                                    const identity = `intake-choice-${++sequence}`;
                                    const radio = node('input', { id: identity, type: 'radio', name: 'target_term', value: term.id, class: 'form-check-input me-2', checked: selected?.id === term.id });
                                    radio.addEventListener('change', () => { selected = term; dirty = true; chosen.textContent = format('selected_year', { code: term.code, year: term.year_code }); });
                                    choices.append(node('div', { class: 'form-check border rounded p-3 ps-5 mb-2 text-start' }, radio,
                                        node('label', { for: identity, class: 'form-check-label text-break' },
                                            node('strong', {}, `${term.code} · ${term.name}`), node('span', { class: 'd-block form-text' }, `${format('dates', { start: term.starts_on, end: term.ends_on })} · ${term.year_code}`))));
                                }
                                if (after) pages.append(button(text('first'), () => list()));
                                if (packet.next) pages.append(button(text('more'), () => list(packet.next)));
                                chosen.textContent = selected ? format('selected', { code: selected.code }) : packet.items.length ? text('choose_reason') : text(correction ? 'no_intakes' : 'no_later');
                            } catch (error) {
                                if (alive(ticket)) failed(error, 'search_failed');
                                throw error;
                            } finally { if (alive(ticket)) { searching = false; find.disabled = false; } }
                        }
                        task(() => list());
                    } else summary(controls, data.current, text('updated_calendar'));
                    const reasonId = `intake-reason-${++sequence}`;
                    const reason = node('textarea', { id: reasonId, class: 'form-control mb-3', rows: 3, maxlength: 2000, required: true });
                    reason.addEventListener('input', () => { dirty = true; });
                    const review = node('button', { type: 'submit', class: 'btn btn-primary' }, text('review'));
                    controls.append(chosen, node('label', { class: 'form-label', for: reasonId }, text(correction ? 'correction_reason' : 'approval_reason')), reason, review);
                    form.append(controls, preview); editor.append(form);
                    form.addEventListener('submit', event => {
                        event.preventDefault(); if (busy || searching || controls.disabled || !form.reportValidity()) return;
                        if (!selected) { chosen.textContent = text(correction ? 'choose_first' : 'later_first'); return; }
                        const ticket = generation; busy = true; dirty = true; controls.disabled = true;
                        status.textContent = text('preparing');
                        task(async () => {
                            try {
                                const reviewed = await request(`${base}review/`, 'POST', { target_term: selected.id, kind, reason: reason.value });
                                if (!alive(ticket) || !form.isConnected) return;
                                preview.replaceChildren(node('h3', { class: 'h6' }, text('review_heading')));
                                preview.append(node('p', { class: 'fw-semibold' }, `${reviewed.person.given_name} ${reviewed.person.family_name} · ${reviewed.person.institutional_id}`));
                                summary(preview, reviewed.before, text(correction ? 'before_correction' : 'before_approval')); summary(preview, reviewed.after, text(correction ? 'after_correction' : 'after_approval'));
                                preview.append(node('p', { class: 'private-text text-break', style: 'white-space: pre-wrap' }, reason.value),
                                    node('p', { class: 'form-text' }, text(correction ? 'correction_review_help' : 'approval_review_help')));
                                const revise = button(text('revise'), () => { preview.replaceChildren(); controls.disabled = false; reason.focus(); });
                                const confirm = button(text(correction ? 'confirm_correction' : 'approve'), async () => {
                                    if (!alive(ticket) || !form.isConnected) return;
                                    busy = true; confirm.disabled = true; revise.disabled = true;
                                    try {
                                        const result = await request(`${base}commit/`, 'POST', { review_token: reviewed.review_token });
                                        if (!alive(ticket) || !form.isConnected) return;
                                        dirty = false; notify(text(correction ? 'corrected' : 'approved')); await onComplete(result);
                                    } catch (error) {
                                        if (alive(ticket)) failed(error, 'commit_failed');
                                        throw error;
                                    } finally { if (alive(ticket)) { busy = false; confirm.disabled = false; revise.disabled = false; } }
                                }, 'btn-primary');
                                preview.append(node('div', { class: 'd-flex flex-wrap gap-2' }, confirm, revise)); status.textContent = text('ready'); confirm.focus();
                            } catch (error) {
                                if (alive(ticket)) { controls.disabled = false; failed(error, 'review_failed'); }
                                throw error;
                            } finally { if (alive(ticket)) busy = false; }
                        });
                    });
                }
            }
            task(load);
            return { beforeLeave: () => !busy && !searching && (!dirty || window.confirm(text('leave'))) };
        },
    });
})();
