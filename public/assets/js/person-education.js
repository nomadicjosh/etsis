/* Shared educational-background editor. Private values remain in this page's memory. */
(() => {
    'use strict';
    const keys = Object.freeze(['key', 'institution', 'institution_code', 'start_date', 'end_date',
        'major', 'degree', 'conferred_date', 'gpa', 'gpa_max']);
    const details = entries => entries.map(entry => Object.fromEntries(keys.map(key => [key, entry[key] || ''])));
    window.createPersonEducationDraft = record => {
        const baseline = JSON.stringify(details(record.entries));
        const version = record.version;
        return Object.freeze({
            changed: entries => JSON.stringify(details(entries)) !== baseline,
            command: (entries, reason) => ({ entries: details(entries), version, reason }),
        });
    };
    let sequence = 0;
    window.createPersonEducation = ({ node, request, task, notify }) => ({
        mount(container, endpoint, { canReview = false, open = false, requestUpdate = null } = {}) {
            const identity = `education-editor-${++sequence}`;
            const content = node('section', { id: identity, hidden: true, 'aria-label': 'Institutions attended editor' });
            const toggle = node('button', { type: 'button', class: 'btn btn-outline-primary',
                'aria-controls': identity, 'aria-expanded': 'false' }, 'Institutions attended');
            let serial = 0, expanded = false, pending = false, record = null, draft = null, leave = () => true;
            const current = ticket => ticket === serial && container.isConnected;
            const show = () => {
                expanded = true; content.hidden = false;
                toggle.textContent = 'Close institutions'; toggle.setAttribute('aria-expanded', 'true');
            };
            const close = () => {
                serial++; expanded = false; content.replaceChildren(); content.hidden = true;
                record = null; draft = null;
                toggle.textContent = 'Institutions attended'; toggle.setAttribute('aria-expanded', 'false'); toggle.focus();
            };
            async function load() {
                const ticket = ++serial;
                show(); content.replaceChildren(node('p', { role: 'status' }, 'Loading educational background…'));
                try {
                    const result = await request(endpoint);
                    if (!current(ticket)) return;
                    record = result; draw();
                } catch (error) {
                    if (!current(ticket)) return;
                    content.replaceChildren(node('p', { role: 'alert', class: 'alert alert-danger' },
                        'Educational background could not be loaded. Close this panel and try again.'));
                    throw error;
                }
            }
            function draw(focusKey = null) {
                let entries = record.entries.map(entry => ({ ...entry }));
                draft = window.createPersonEducationDraft(record);
                const form = node('form', { class: 'record-form mt-3' });
                const controls = node('fieldset');
                const list = node('div');
                const status = node('p', { class: 'form-text', role: 'status', 'aria-atomic': 'true' });
                const reasonId = `${identity}-reason`;
                const reason = node('textarea', { id: reasonId, name: 'reason', class: 'form-control',
                    rows: '2', maxlength: '1000', required: record.version > 0 });
                const save = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Save background');
                const add = node('button', { type: 'button', class: 'btn btn-outline-primary' }, 'Add institution');
                function read() {
                    return entries.map(entry => ({ ...entry, ...Object.fromEntries(keys.filter(key => key !== 'key')
                        .map(key => [key, list.querySelector(`[data-entry="${entry.key}"] [data-field="${key}"]`).value])) }));
                }
                async function write(path, payload) {
                    if (pending) return;
                    const ticket = serial;
                    pending = true; controls.disabled = true; toggle.disabled = true;
                    status.textContent = 'Saving educational background…';
                    let accepted = false;
                    try {
                        await request(path, 'POST', payload);
                        accepted = true;
                        if (!current(ticket)) return;
                        const saved = await request(endpoint);
                        if (!current(ticket)) return;
                        record = saved; draw();
                        notify('Educational background saved.');
                    } catch (error) {
                        if (!current(ticket)) return;
                        status.textContent = accepted
                            ? 'Saved. Close and reopen this panel to load the latest background before another change.'
                            : 'Your entries are unchanged. Review the error and try again.';
                        throw error;
                    } finally {
                        pending = false; toggle.disabled = false;
                        if (!accepted) controls.disabled = false;
                        if (accepted && current(ticket)) toggle.focus();
                    }
                }
                function paint(focus = null) {
                    list.replaceChildren();
                    entries.forEach((entry, index) => {
                        const card = node('details', { class: 'border rounded p-3 mb-3', open: focus === entry.key || !entry.institution,
                            'data-entry': entry.key });
                        const summary = node('summary', { class: 'fw-semibold mb-3' }, entry.institution || `Institution ${index + 1}`);
                        card.append(summary);
                        const state = { unreviewed: 'Not yet reviewed', reviewed: 'Reviewed', changes_requested: 'Changes requested' }[entry.review_status] || 'Not yet reviewed';
                        card.append(node('p', { class: entry.review_status === 'changes_requested' ? 'alert alert-warning' : 'form-text' }, state));
                        if (entry.feedback) card.append(node('p', {}, entry.feedback));
                        const grid = node('div', { class: 'row g-3 mb-3' });
                        for (const [key, label, type, maximum] of [
                            ['institution', 'Institution name', 'text', 191], ['institution_code', 'School code', 'text', 40],
                            ['start_date', 'Attended from', 'date'], ['end_date', 'Attended through', 'date'],
                            ['major', 'Field of study', 'text', 191], ['degree', 'Qualification awarded', 'text', 191],
                            ['conferred_date', 'Awarded on', 'date'], ['gpa', 'Reported GPA', 'text', 191],
                            ['gpa_max', 'Original GPA maximum', 'text', 191],
                        ]) {
                            const id = `${identity}-${++sequence}`;
                            const input = node('input', { id, type, class: 'form-control', value: entry[key] || '',
                                name: `entries.${index}.${key}`, 'data-field': key, maxlength: maximum,
                                required: key === 'institution', autocomplete: 'off',
                                ...(['gpa', 'gpa_max'].includes(key) ? { inputmode: 'decimal' } : {}) });
                            if (key === 'institution') input.addEventListener('input', () => {
                                summary.textContent = input.value.trim() || `Institution ${index + 1}`;
                            });
                            grid.append(node('div', { class: 'col-12 col-md-6' },
                                node('label', { for: id, class: 'form-label' }, label), input));
                        }
                        card.append(grid);
                        const actions = node('div', { class: 'd-flex flex-wrap gap-2' });
                        for (const [label, offset] of [['Move up', -1], ['Move down', 1]]) {
                            actions.append(node('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary',
                                disabled: index + offset < 0 || index + offset >= entries.length,
                                'aria-label': `${label}: ${entry.institution || 'institution ' + (index + 1)}`,
                                onclick: () => {
                                    if (pending) return;
                                    entries = read();
                                    [entries[index], entries[index + offset]] = [entries[index + offset], entries[index]];
                                    paint(entry.key);
                                } }, label));
                        }
                        actions.append(node('button', { type: 'button', class: 'btn btn-sm btn-outline-danger',
                            'aria-label': `Remove ${entry.institution || 'institution ' + (index + 1)}`, onclick: () => {
                                if (pending) return;
                                entries = read(); entries.splice(index, 1); paint(); add.focus();
                            } }, node('i', { class: 'bi bi-trash', 'aria-hidden': 'true' }), node('span', { class: 'visually-hidden' }, 'Remove institution')));
                        card.append(actions);
                        if (canReview) {
                            const review = node('details', { class: 'mt-3' }, node('summary', {}, 'Review entry'));
                            const messageId = `${identity}-${++sequence}`;
                            const message = node('textarea', { id: messageId, class: 'form-control mt-2', rows: '2', maxlength: '1000' });
                            message.addEventListener('input', () => message.setCustomValidity(''));
                            review.append(node('label', { for: messageId, class: 'form-label mt-2' }, 'Message to applicant'), message,
                                node('p', { class: 'form-text' }, 'This message is visible to the applicant. Reviewed does not verify a credential or award credit.'));
                            const reviewActions = node('div', { class: 'd-flex flex-wrap gap-2' });
                            for (const [label, outcome] of [['Mark reviewed', 'reviewed'], ['Request changes', 'changes_requested']]) {
                                const button = node('button', { type: 'button', class: 'btn btn-sm btn-outline-primary', onclick: () => {
                                    if (pending) return;
                                    if (draft.changed(read())) {
                                        status.textContent = 'Save background changes before reviewing an institution.';
                                        save.focus(); return;
                                    }
                                    if (!message.value.trim()) {
                                        message.setCustomValidity('Enter a message for the applicant.'); message.reportValidity(); return;
                                    }
                                    task(() => write(`${endpoint}review/`, { entry_key: entry.key, review_status: outcome,
                                        version: record.version, reason: message.value }), button);
                                } }, label);
                                reviewActions.append(button);
                            }
                            review.append(reviewActions); card.append(review);
                        }
                        list.append(card);
                    });
                    if (!entries.length) list.append(node('p', { class: 'form-text' }, 'No institutions have been provided.'));
                    add.disabled = entries.length >= 20;
                    status.textContent = `${entries.length} of 20 institutions`;
                    if (focus) list.querySelector(`[data-entry="${focus}"] input`)?.focus();
                }
                add.addEventListener('click', () => {
                    if (pending || entries.length >= 20) return;
                    entries = read();
                    const key = `school_${crypto.randomUUID().replaceAll('-', '').slice(0, 26)}`;
                    entries.push({ key, review_status: 'unreviewed', feedback: '' }); paint(key);
                });
                form.addEventListener('submit', event => {
                    event.preventDefault();
                    if (pending) return;
                    // Expand entries before native validation so every invalid control can receive focus.
                    list.querySelectorAll('details[data-entry]').forEach(card => { card.open = true; });
                    if (!form.reportValidity()) return;
                    const values = read();
                    if (!draft.changed(values)) { status.textContent = 'There are no background changes to save.'; return; }
                    task(() => write(endpoint, draft.command(values, reason.value)), save);
                });
                controls.append(node('legend', { class: 'h5' }, 'Educational background'),
                    node('p', { class: 'form-text' }, 'List previous schools, colleges or universities. Dates, qualifications and GPA are optional. Leave unknown dates blank. GPA uses the original institution’s scale; no credits or grades are awarded here.'),
                    list, node('div', { class: 'mb-3' }, node('label', { for: reasonId, class: 'form-label' },
                        record.version > 0 ? 'Reason for update' : 'Reason for update (optional)'), reason),
                    status, node('div', { class: 'd-flex flex-wrap gap-2' }, add, save));
                if (requestUpdate) controls.append(node('button', { type: 'button', class: 'btn btn-outline-primary mt-3',
                    onclick: event => task(() => leave() && requestUpdate(), event.currentTarget),
                }, 'Request an education update'));
                leave = () => {
                    if (pending) { status.textContent = 'Wait for the current save before starting another task.'; return false; }
                    const changed = draft.changed(read());
                    const unfinished = [...form.querySelectorAll('textarea')].find(input => input.value.trim());
                    if (changed || unfinished) {
                        form.querySelectorAll('details').forEach(card => { card.open = true; });
                        status.textContent = 'Save or clear background changes and finish review feedback before starting another task. Your draft is still here.';
                        (changed ? save : unfinished).focus(); return false;
                    }
                    return true;
                };
                form.append(controls); content.replaceChildren(form); paint(focusKey);
            }
            toggle.addEventListener('click', () => {
                if (pending) return;
                if (expanded) close(); else task(load, toggle);
            });
            container.replaceChildren(toggle, content);
            if (open) task(load, toggle);
            return {
                beforeLeave: () => !expanded || leave(),
                async open() {
                    if (!expanded) await load();
                    if (!expanded || !container.isConnected) return;
                    (content.querySelector('summary') || content.querySelector('button') || toggle).focus();
                },
            };
        },
    });
})();
