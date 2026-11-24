/* Private, reviewed application program correction. Drafts and tokens stay in memory. */
(() => {
    'use strict';
    let serial = 0;
    window.createAdmissionProgram = ({ node, request, task, notify, labels }) => ({
        mount(container, application, { onComplete = async () => {} } = {}) {
            const text = key => labels[key];
            const base = `/sis/applications/${application}/program/`;
            const status = node('p', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true', class: 'form-text' });
            const content = node('div'); container.append(status, content);
            let busy = false, dirty = false, generation = 0, selected = null, reasonText = '', record = null;
            const alive = ticket => ticket === generation && container.isConnected && content.isConnected;
            const button = (key, run, style = 'btn-outline-secondary') => {
                const b = node('button', { type: 'button', class: `btn ${style}` }, text(key));
                b.addEventListener('click', () => { if (!busy) task(run, b); }); return b;
            };
            const summary = (program, key) => node('section', { class: 'record-form-section mb-3' },
                node('h3', { class: 'h6' }, text(key)), node('p', { class: 'fw-semibold text-break mb-0' }, `${program.code} · ${program.title}`));
            function failure(error, key) {
                if ([401, 403].includes(error.status)) {
                    generation++; content.replaceChildren(); selected = null; reasonText = ''; record = null; dirty = false;
                    status.textContent = text('denied');
                } else status.textContent = text(key);
            }
            async function load() {
                const ticket = ++generation; busy = true; status.textContent = text('loading');
                try {
                    record = await request(base);
                    if (!alive(ticket)) return;
                    edit(); status.textContent = '';
                } catch (error) { if (alive(ticket)) failure(error, 'load_failed'); throw error; }
                finally { busy = false; }
            }
            function edit() {
                generation++; content.replaceChildren();
                content.append(summary(record.current, 'current'), node('p', { class: 'form-text' }, text('help')));
                const blocked = record.status !== 'submitted' ? 'closed' : record.program_form ? 'program_form'
                    : record.answers_pending || record.withdrawal_pending ? 'pending' : null;
                if (blocked) { content.append(node('p', { class: 'alert alert-info' }, text(blocked))); return; }
                const form = node('form', { class: 'record-form-section' });
                const searchId = `program-search-${++serial}`, reasonId = `program-reason-${++serial}`;
                const search = node('input', { id: searchId, type: 'search', maxlength: 80, class: 'form-control' });
                const result = node('fieldset', { class: 'mt-3' }, node('legend', { class: 'h6' }, text('available')));
                const choices = node('div'), pages = node('nav', { class: 'd-flex flex-wrap gap-2 mt-2', 'aria-label': text('pages') });
                const chosen = node('p', { class: 'form-text text-break', 'aria-live': 'polite' }, selected ? `${selected.code} · ${selected.title}` : text('choose'));
                const find = button('find', () => list());
                result.append(choices, pages);
                const controls = node('div', { class: 'd-flex flex-wrap gap-2 align-items-end' },
                    node('div', { class: 'flex-grow-1', style: 'min-width:0' }, node('label', { class: 'form-label', for: searchId }, text('search')), search), find);
                const reason = node('textarea', { id: reasonId, rows: 3, maxlength: 2000, required: true, class: 'form-control mb-3' });
                reason.value = reasonText;
                reason.addEventListener('input', () => { reasonText = reason.value; dirty = true; });
                const review = node('button', { type: 'submit', class: 'btn btn-primary' }, text('review'));
                form.append(controls, result, chosen, node('label', { class: 'form-label', for: reasonId }, text('reason')), reason, review);
                content.append(form);
                async function list(after = null) {
                    const ticket = generation, queryText = search.value;
                    const query = new URLSearchParams({ search: queryText }); if (after) query.set('after', after);
                    busy = true; find.disabled = true; review.disabled = true; status.textContent = text('finding');
                    try {
                        const packet = await request(`${base}choices/?${query}`);
                        if (!alive(ticket) || !form.isConnected) return;
                        if (queryText !== search.value) { status.textContent = text('search_changed'); return; }
                        choices.replaceChildren(); pages.replaceChildren();
                        for (const program of packet.items) {
                            const id = `program-choice-${++serial}`;
                            const radio = node('input', { id, type: 'radio', name: 'target_program', value: program.id,
                                class: 'form-check-input me-2', checked: selected?.id === program.id });
                            radio.addEventListener('change', () => {
                                if (busy) return;
                                selected = program; dirty = true; chosen.textContent = `${program.code} · ${program.title}`;
                            });
                            choices.append(node('div', { class: 'form-check border rounded p-3 ps-5 mb-2 text-start' }, radio,
                                node('label', { for: id, class: 'form-check-label text-break' }, `${program.code} · ${program.title}`)));
                        }
                        if (after) pages.append(button('first', () => list()));
                        if (packet.next) pages.append(button('more', () => list(packet.next)));
                        status.textContent = packet.items.length ? '' : text('empty');
                    } catch (error) { if (alive(ticket)) failure(error, 'search_failed'); throw error; }
                    finally { busy = false; find.disabled = false; review.disabled = false; }
                }
                search.addEventListener('keydown', event => {
                    if (event.key === 'Enter') { event.preventDefault(); if (!busy) task(() => list(), find); }
                });
                form.addEventListener('submit', event => {
                    event.preventDefault(); if (busy) return;
                    if (!selected) { status.textContent = text('choose'); search.focus(); return; }
                    const ticket = generation; reasonText = reason.value; dirty = true; busy = true;
                    for (const field of form.querySelectorAll('input, textarea, button')) field.disabled = true;
                    status.textContent = text('preparing');
                    task(async () => {
                        try {
                            const value = await request(`${base}review/`, 'POST', { target_program: selected.id, reason: reasonText });
                            if (alive(ticket)) confirm(value);
                        } catch (error) { if (alive(ticket)) failure(error, 'review_failed'); throw error; }
                        finally {
                            busy = false;
                            if (form.isConnected) for (const field of form.querySelectorAll('input, textarea, button')) field.disabled = false;
                        }
                    }, review);
                });
            }
            function confirm(value) {
                generation++; content.replaceChildren();
                content.append(node('p', { class: 'fw-semibold text-break' },
                    `${value.person.given_name} ${value.person.family_name} · ${value.person.institutional_id}`),
                    summary(value.before, 'before'), summary(value.after, 'after'),
                    node('p', { class: 'form-text' }, text('help')), node('p', { class: 'text-break' }, reasonText));
                const ticket = generation;
                const commit = button('confirm', async () => {
                    busy = true; commit.disabled = true; revise.disabled = true; status.textContent = text('saving');
                    try {
                        await request(`${base}commit/`, 'POST', { review_token: value.review_token });
                        if (!alive(ticket)) return;
                        dirty = false; selected = null; reasonText = ''; notify(text('saved')); await onComplete();
                    } catch (error) { if (alive(ticket)) failure(error, 'commit_failed'); throw error; }
                    finally { busy = false; commit.disabled = false; revise.disabled = false; }
                }, 'btn-primary');
                const revise = button('revise', () => { edit(); status.textContent = ''; });
                content.append(node('div', { class: 'd-flex flex-wrap gap-2' }, revise, commit));
                status.textContent = text('ready'); commit.focus();
            }
            const beforeLeave = () => !busy && (!dirty || window.confirm(text('leave')));
            const drawer = container.closest?.('.offcanvas');
            const detach = () => {
                drawer?.removeEventListener('hide.bs.offcanvas', leaving);
                drawer?.removeEventListener('hidden.bs.offcanvas', hidden);
            };
            const leaving = event => {
                if (!content.isConnected) { detach(); return; }
                if (!beforeLeave()) event.preventDefault();
            };
            const hidden = () => {
                generation++; selected = null; reasonText = ''; record = null; dirty = false; detach();
            };
            drawer?.addEventListener('hide.bs.offcanvas', leaving);
            drawer?.addEventListener('hidden.bs.offcanvas', hidden);
            task(load);
            return { beforeLeave };
        }
    });
})();
