/* Staff intake approval: one application, original answers, immutable before/after terms and a fresh offer. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdmissionEntry = ({ node, request, task, notify }) => ({
        mount(container, application, { onComplete = async () => {} } = {}) {
            const base = `/sis/applications/${application}/entry/`;
            const status = node('p', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true', class: 'form-text' });
            const body = node('div'); container.append(status, body);
            let busy = true, searching = false, dirty = false, generation = 0;
            const alive = ticket => ticket === generation && container.isConnected;
            const button = (text, action, style = 'btn-outline-secondary') => {
                const b = node('button', { type: 'button', class: `btn ${style}` }, text);
                b.addEventListener('click', () => { if (!busy && !searching) task(action); }); return b;
            };
            const summary = (target, term, title) => {
                target.append(node('h3', { class: 'h6' }, title), node('p', { class: 'fw-semibold text-break' }, `${term.code} · ${term.name}`),
                    node('p', {}, `${term.starts_on} through ${term.ends_on}`),
                    node('p', { class: 'form-text' }, term.year_code ? `Academic year ${term.year_code}` : 'Academic year not linked'));
            };
            async function load() {
                const ticket = ++generation; busy = true; status.textContent = 'Loading approved intake…';
                try {
                    const data = await request(base);
                    if (!alive(ticket)) return;
                    render(data); status.textContent = '';
                } catch (error) {
                    if (alive(ticket)) status.textContent = 'The intake could not be loaded. Return to the application and try again.';
                    throw error;
                } finally { if (alive(ticket)) busy = false; }
            }
            function render(data) {
                const person = data.person;
                body.replaceChildren(node('h2', { class: 'h5' }, `${person.given_name} ${person.family_name}`),
                    node('p', { class: 'form-text' }, `Institutional ID: ${person.institutional_id}`));
                summary(body, data.approved || data.current, data.approved ? 'Previously approved intake' : 'Current entry term');
                body.append(node('p', { class: 'form-text' }, 'Keep the same application and submitted answers. A new intake approval requires a fresh offer. Its acceptance follows institutional policy. Program and degree requirements are selected at matriculation.'));
                if (data.application_status !== 'accepted' || data.withdrawal_pending) {
                    body.append(node('p', { class: 'alert alert-info' }, data.withdrawal_pending
                        ? 'Resolve the pending withdrawal request before approving an intake.' : 'Only accepted, unmatriculated applications can receive an intake approval.'));
                    return;
                }
                const editor = node('div'); body.append(editor);
                if (data.needs_review) {
                    body.append(node('p', { class: 'alert alert-warning' }, 'The calendar changed after the earlier approval. Review the updated intake before issuing an offer or matriculating.'));
                    body.append(button('Review current intake', () => edit('reconfirm'), 'btn-outline-primary'));
                }
                const defer = button('Choose later intake', () => edit('defer'), 'btn-primary'); body.append(defer);
                function edit(kind) {
                    if (dirty && !window.confirm('Replace the unfinished intake review?')) return;
                    generation++; dirty = false; editor.replaceChildren(); status.textContent = '';
                    const form = node('form'), controls = node('fieldset'), preview = node('div', { class: 'mt-3' });
                    controls.append(node('legend', { class: 'h6' }, kind === 'defer' ? 'Choose a later intake' : 'Review updated intake'));
                    let selected = kind === 'reconfirm' ? data.current : null;
                    const chosen = node('p', { role: 'status', 'aria-atomic': 'true', class: 'form-text' });
                    if (kind === 'defer') {
                        const identity = `intake-search-${++sequence}`;
                        const search = node('input', { type: 'search', id: identity, class: 'form-control', maxlength: 80, placeholder: 'Term code or name' });
                        const results = node('fieldset', { class: 'mt-3' }, node('legend', { class: 'h6' }, 'Available intakes'));
                        const choices = node('div'), pages = node('nav', { class: 'd-flex flex-wrap gap-2 mt-2', 'aria-label': 'Intake search pages' }); results.append(choices, pages);
                        let searchGeneration = 0;
                        const find = button('Find terms', () => list());
                        controls.append(node('label', { class: 'form-label', for: identity }, 'Find a later intake'),
                            node('div', { class: 'd-flex gap-2' }, search, find), results);
                        search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); if (!busy && !searching) task(() => list()); } });
                        async function list(after = null) {
                            const ticket = generation, serial = ++searchGeneration; searching = true; find.disabled = true;
                            const text = search.value, query = new URLSearchParams({ search: text }); if (after) query.set('after', after);
                            chosen.textContent = 'Finding later intakes…';
                            try {
                                const packet = await request(`${base}terms/?${query}`);
                                if (!alive(ticket) || !form.isConnected || serial !== searchGeneration) return;
                                if (text !== search.value) { chosen.textContent = 'Search changed. Choose Find terms.'; return; }
                                choices.replaceChildren(); pages.replaceChildren();
                                for (const term of packet.items) {
                                    const identity = `intake-choice-${++sequence}`;
                                    const radio = node('input', { id: identity, type: 'radio', name: 'target_term', value: term.id, class: 'form-check-input me-2', checked: selected?.id === term.id });
                                    radio.addEventListener('change', () => { selected = term; dirty = true; chosen.textContent = `Selected intake: ${term.code} · ${term.year_code}`; });
                                    choices.append(node('div', { class: 'form-check border rounded p-3 ps-5 mb-2' }, radio,
                                        node('label', { for: identity, class: 'form-check-label text-break' },
                                            node('strong', {}, `${term.code} · ${term.name}`), node('span', { class: 'd-block form-text' }, `${term.starts_on} through ${term.ends_on} · ${term.year_code}`))));
                                }
                                if (after) pages.append(button('First results', () => list()));
                                if (packet.next) pages.append(button('More terms', () => list(packet.next)));
                                chosen.textContent = selected ? `Selected intake: ${selected.code}` : packet.items.length ? 'Choose an intake, then add the approval reason.' : 'No matching later intakes. Review academic year and term setup with the catalog office.';
                            } catch (error) {
                                if (alive(ticket)) chosen.textContent = 'Could not find terms. Your reason and selected intake are unchanged.';
                                throw error;
                            } finally { if (alive(ticket)) { searching = false; find.disabled = false; } }
                        }
                        task(() => list());
                    } else summary(controls, data.current, 'Updated calendar definition');
                    const reasonId = `intake-reason-${++sequence}`;
                    const reason = node('textarea', { id: reasonId, class: 'form-control mb-3', rows: 3, maxlength: 2000, required: true });
                    reason.addEventListener('input', () => { dirty = true; });
                    const review = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Review intake');
                    controls.append(chosen, node('label', { class: 'form-label', for: reasonId }, 'Reason for approval'), reason, review);
                    form.append(controls, preview); editor.append(form);
                    form.addEventListener('submit', event => {
                        event.preventDefault(); if (busy || searching || controls.disabled || !form.reportValidity()) return;
                        if (!selected) { chosen.textContent = 'Choose a later intake first.'; return; }
                        const ticket = generation; busy = true; dirty = true; controls.disabled = true;
                        status.textContent = 'Preparing intake review…';
                        task(async () => {
                            try {
                                const reviewed = await request(`${base}review/`, 'POST', { target_term: selected.id, kind, reason: reason.value });
                                if (!alive(ticket) || !form.isConnected) return;
                                preview.replaceChildren(node('h3', { class: 'h6' }, 'Review before confirming'));
                                preview.append(node('p', { class: 'fw-semibold' }, `${reviewed.person.given_name} ${reviewed.person.family_name} · ${reviewed.person.institutional_id}`));
                                summary(preview, reviewed.before, 'Before approval'); summary(preview, reviewed.after, 'After approval');
                                preview.append(node('p', { class: 'private-text text-break', style: 'white-space: pre-wrap' }, reason.value),
                                    node('p', { class: 'form-text' }, 'Earlier terms, answers and offers stay in history. Issue a fresh offer for this approved intake. No student record is created here.'));
                                const revise = button('Revise', () => { preview.replaceChildren(); controls.disabled = false; reason.focus(); });
                                const confirm = button('Approve intake', async () => {
                                    if (!alive(ticket) || !form.isConnected) return;
                                    busy = true; confirm.disabled = true; revise.disabled = true;
                                    try {
                                        const result = await request(`${base}commit/`, 'POST', { review_token: reviewed.review_token });
                                        if (!alive(ticket) || !form.isConnected) return;
                                        dirty = false; notify('Intake approved. Issue a fresh offer.'); await onComplete(result);
                                    } catch (error) {
                                        if (alive(ticket)) status.textContent = 'Approval could not be completed. Retry this confirmation or revise the review.';
                                        throw error;
                                    } finally { if (alive(ticket)) { busy = false; confirm.disabled = false; revise.disabled = false; } }
                                }, 'btn-primary');
                                preview.append(node('div', { class: 'd-flex flex-wrap gap-2' }, confirm, revise)); status.textContent = 'Review ready. Confirm to save.'; confirm.focus();
                            } catch (error) {
                                if (alive(ticket)) { controls.disabled = false; status.textContent = 'Review could not be completed. Your reason and choice are still shown.'; }
                                throw error;
                            } finally { if (alive(ticket)) busy = false; }
                        });
                    });
                }
            }
            task(load);
            return { beforeLeave: () => !busy && !searching && (!dirty || window.confirm('Leave the unfinished intake approval?')) };
        },
    });
})();
