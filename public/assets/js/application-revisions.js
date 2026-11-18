/* Shared retained-answer review and requested corrections. Private state stays in this page only. */
(() => {
    'use strict';
    let sequence = 0;
    window.createApplicationRevisions = ({ node, request, task, notify }) => ({
        mount(container, application, { staff = false, canAmend = false, initiallyOpen = false,
            beforeChange = () => true, onSubmitted = async () => {} } = {}) {
            const base = `${staff ? '/sis' : '/portal/api'}/applications/${application}/answers/`;
            const regionId = `answer-revisions-${++sequence}`;
            const status = node('p', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true', class: 'form-text' });
            const panel = node('div', { id: regionId, hidden: !initiallyOpen });
            let busy = false, dirty = false, generation = 0;
            const alive = ticket => container.isConnected && ticket === generation;
            const leave = () => !busy && (!dirty || window.confirm('Leave the unfinished answer changes?'));
            const button = (label, action, primary = false) => {
                const b = node('button', { type: 'button', class: `btn ${primary ? 'btn-primary' : 'btn-outline-secondary'}` }, label);
                b.addEventListener('click', () => { if (!busy) task(action); }); return b;
            };
            const open = button('Application answers', async () => {
                if (!leave() || !beforeChange()) return;
                panel.hidden = false; open.setAttribute('aria-expanded', 'true'); await load();
            });
            open.setAttribute('aria-controls', regionId); open.setAttribute('aria-expanded', String(initiallyOpen));
            container.append(open, status, panel);
            async function load(query = '') {
                const ticket = ++generation; busy = true; status.textContent = 'Loading retained answers…';
                try {
                    const data = await request(`${base}${query}`);
                    if (!alive(ticket)) return;
                    dirty = false; render(data, query); status.textContent = '';
                } catch (error) {
                    if (alive(ticket)) status.textContent = 'Could not load the answers. Try Application answers again.';
                    throw error;
                } finally { if (alive(ticket)) busy = false; }
            }
            function render(data, query) {
                panel.replaceChildren(node('h3', { class: 'h5 mt-3' }, data.form.name),
                    node('p', { class: 'form-text' }, data.submitted_term_code ? `Submitted intake: ${data.submitted_term_code}. Earlier answers remain in history. The original form and its conditions are used for revisions.` : 'The original submission was not recorded. These retained answers are available for reading; contact admissions about corrections.'));
                const selectorId = `answer-version-${++sequence}`;
                const selector = node('select', { id: selectorId, class: 'form-select mb-3' },
                    node('option', { value: '', selected: query === '' }, 'Current answers'),
                    node('option', { value: '?original=1', selected: query === '?original=1' }, 'Original submission'),
                    ...(data.versions || []).map((version, index) => node('option', { value: `?revision_id=${encodeURIComponent(version.id)}`,
                        selected: query === `?revision_id=${encodeURIComponent(version.id)}` }, `Revision ${index + 1} · ${version.submitted_at}`)));
                selector.addEventListener('change', () => { if (leave() && beforeChange()) task(() => load(selector.value)); else selector.value = query; });
                panel.append(node('label', { for: selectorId, class: 'form-label' }, 'Answer version'), selector,
                    window.createApplicationAnswerReview({ fields: data.form.fields, layout: data.form.layout || [], answers: data.answers }));
                if (data.request) panel.append(node('div', { class: 'alert alert-info mt-3' },
                    node('h4', { class: 'h6' }, 'Corrections requested'),
                    node('p', { class: 'private-text text-break mb-0', style: 'white-space: pre-wrap' }, data.request.instructions)));
                if (data.request_blocked) panel.append(node('p', { class: 'alert alert-info' }, 'Contact admissions to review the correction request for your current account.'));
                const editor = node('div'), actions = node('div', { class: 'd-flex flex-wrap gap-2 mt-3' });
                panel.append(actions, editor);
                if (query === '' && data.can_revise && (!staff || canAmend)) actions.append(button('Revise answers', () => edit('submit'), true));
                if (staff && canAmend && data.can_request && query === '')
                    actions.append(button('Request corrections', () => edit('request')));
                if (staff && canAmend && data.request_pending && query === '') actions.append(button('Close request', () => edit('cancel')));
                if (!data.can_revise && data.application_status === 'submitted' && !data.request && query === '') panel.append(
                    node('p', { class: 'form-text' }, data.withdrawal_pending ? 'Resolve the pending withdrawal request before changing application answers.' : data.revision_limit_reached ? 'This application has reached the online revision limit. Contact admissions to review further corrections.' : 'Admissions must request corrections before you can revise these answers. Contact admissions if something needs changing.'));
                function edit(action) {
                    if (!leave() || !beforeChange()) return;
                    dirty = false; const ticket = ++generation; editor.replaceChildren();
                    const instance = action === 'submit' ? window.createApplicationForm({ fields: data.form.fields, layout: data.form.layout || [] }) : null;
                    if (instance) instance.fill(data.answers);
                    const form = instance?.form || node('form'), preview = node('div', { class: 'mt-3' });
                    const identity = `answer-reason-${++sequence}`;
                    const reason = node('textarea', { id: identity, rows: 3, maxlength: 2000, required: true, class: 'form-control mb-3' });
                    form.append(node('label', { for: identity, class: 'form-label' }, action === 'request' ? 'Instructions for the applicant' : 'Reason for this change'), reason,
                        node('p', { class: 'form-text' }, action === 'submit' ? 'Submit only the corrections you intend. Earlier answers remain available; your person, program and entry term stay the same.'
                            : action === 'request' ? 'The applicant will see these instructions. Admissions decisions pause until the answers are resubmitted or you close the request.'
                            : 'Closing this request leaves all submitted answers unchanged.'),
                        node('button', { type: 'submit', class: 'btn btn-primary' }, 'Review changes'));
                    form.addEventListener('input', () => { dirty = true; });
                    form.addEventListener('change', () => { dirty = true; });
                    editor.append(form, preview);
                    form.addEventListener('submit', event => {
                        event.preventDefault();
                        if (busy || form.hidden || !form.reportValidity() || (instance && !instance.reportValidity())) return;
                        dirty = true; busy = true; status.textContent = 'Preparing your review…';
                        const intent = action === 'request' ? { instructions: reason.value }
                            : { reason: reason.value, ...(instance ? { answers: instance.answers() } : {}) };
                        task(async () => {
                            try {
                                const review = await request(`${base}review/`, 'POST', { action, ...intent });
                                if (!alive(ticket)) return;
                                form.hidden = true; preview.replaceChildren(node('h4', { class: 'h6', tabindex: '-1' }, 'Review before confirming'));
                                if (review.answers) preview.append(window.createApplicationAnswerReview({ fields: data.form.fields,
                                    layout: data.form.layout || [], answers: review.answers }));
                                preview.append(node('p', { class: 'private-text text-break', style: 'white-space: pre-wrap' },
                                    action === 'request' ? review.instructions : review.reason));
                                const revise = button('Edit changes', () => { preview.replaceChildren(); form.hidden = false; reason.focus(); });
                                const confirm = button(action === 'submit' ? 'Resubmit answers' : action === 'request' ? 'Send request' : 'Close request', async () => {
                                    if (!alive(ticket)) return;
                                    busy = true; confirm.disabled = true; revise.disabled = true; status.textContent = 'Saving your changes…';
                                    try {
                                        const result = await request(`${base}commit/`, 'POST', { review_token: review.review_token });
                                        if (!alive(ticket)) return;
                                        dirty = false; notify(action === 'submit' ? 'Revised answers submitted.' : 'Correction request updated.');
                                        if (action === 'submit') await onSubmitted(result);
                                        if (alive(ticket)) await load();
                                    } catch (error) {
                                        if (alive(ticket)) status.textContent = 'Could not finish the confirmation. Retry this confirmation or edit your changes.';
                                        throw error;
                                    } finally { if (alive(ticket)) { busy = false; confirm.disabled = false; revise.disabled = false; } }
                                }, true);
                                preview.append(node('div', { class: 'd-flex flex-wrap gap-2' }, confirm, revise));
                                status.textContent = 'Review ready. Confirm to save.'; confirm.focus();
                            } catch (error) {
                                if (alive(ticket)) { form.hidden = false; status.textContent = 'Could not prepare the review. Your changes are still shown.'; instance?.showErrors(error.fields); }
                                throw error;
                            } finally { if (alive(ticket)) busy = false; }
                        });
                    });
                    reason.focus();
                }
            }
            if (initiallyOpen) task(() => load());
            return { beforeLeave: leave };
        },
    });
})();
