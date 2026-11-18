/* Private admission letters and responses. One editor for staff and applicant routes; no browser storage. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdmissionOffers = ({ node, request, task, notify }) => ({
        mount(container, application, { staff = false, canIssue = false, canPolicy = false, toggleTarget = null, label = '', beforeChange = () => true } = {}) {
            const base = `${staff ? '/sis' : '/portal/api'}/applications/${application}/offer/`;
            const id = `admission-offer-${++sequence}`;
            const content = node('section', { id, hidden: true, class: 'record-section mt-3', 'aria-label': 'Admission offer' });
            const status = node('p', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true', class: 'form-text mt-2' });
            const body = node('div'); content.append(status, body);
            const toggle = node('button', { type: 'button', class: 'btn btn-outline-secondary',
                'aria-expanded': 'false', 'aria-controls': id, ...(label ? { 'aria-label': `Admission offer for ${label}` } : {}) }, 'Admission offer');
            (toggleTarget || container).append(toggle); container.append(content);
            let expanded = false, busy = false, dirty = false, generation = 0, policyPanel = null;
            const alive = ticket => ticket === generation && container.isConnected;
            const leave = () => !busy && (!dirty || window.confirm('Leave the unfinished admission offer form?')) && (policyPanel?.beforeLeave() ?? true);
            const button = (text, action, style = 'btn-outline-secondary') => {
                const b = node('button', { type: 'button', class: `btn ${style}` }, text);
                b.addEventListener('click', () => { if (!busy) task(action); }); return b;
            };
            const field = (form, name, text, { textarea = false, value = '', ...attributes } = {}) => {
                const identity = `offer-field-${++sequence}`;
                const input = node(textarea ? 'textarea' : 'input', { id: identity, name, class: 'form-control', ...attributes });
                input.value = value; input.addEventListener('input', () => { dirty = true; });
                form.append(node('div', { class: 'mb-3' }, node('label', { for: identity, class: 'form-label' }, text), input)); return input;
            };
            const summary = (target, offer) => {
                target.append(node('h3', { class: 'h5 text-break' }, offer.title),
                    node('p', { class: 'badge text-bg-light' }, `Offer ${offer.status}`),
                    node('p', { class: 'private-text text-break', style: 'white-space: pre-wrap' }, offer.message),
                    node('p', { class: 'form-text' }, offer.require_acceptance
                        ? 'Applicant acceptance is required before matriculation.' : 'Applicant acceptance is optional under this offer’s policy.'));
                if (offer.respond_by) target.append(node('p', { class: 'form-text' }, `Respond by: ${offer.respond_by}`));
            };
            async function load() {
                const restoreFocus = !!body.querySelector(':focus');
                const ticket = ++generation; busy = true; toggle.disabled = true;
                status.textContent = 'Loading admission offer…';
                try {
                    const data = await request(base);
                    if (!alive(ticket)) return;
                    render(data); status.textContent = '';
                    if (restoreFocus) body.querySelector('h2')?.focus();
                } catch (error) {
                    if (alive(ticket)) status.textContent = 'The offer could not be loaded. Close and reopen it to try again.';
                    throw error;
                } finally { if (alive(ticket)) { busy = false; toggle.disabled = false; } }
            }
            function render(data) {
                dirty = false; policyPanel = null; body.replaceChildren(node('h2', { class: 'h5', tabindex: '-1' }, 'Admission offer'));
                const current = node('div'); body.append(current);
                if (data.offer) summary(current, data.offer);
                else current.append(node('p', {}, data.application_status === 'accepted'
                    ? 'No current offer has been issued.' : 'An offer becomes available after the admissions decision.'));
                if (data.entry_review_required) current.append(node('p', { class: 'alert alert-info' }, 'Admissions must review the changed intake before this offer can be used. Contact admissions for help.'));
                if (data.withdrawal_pending) current.append(node('p', { class: 'alert alert-info' }, 'A withdrawal request is awaiting review. Resolve it before issuing or responding to an offer.'));
                if (data.offer?.status === 'pending' && !data.response_open && !data.withdrawal_pending && !data.entry_review_required) current.append(
                    node('p', { class: 'form-text' }, 'This offer is no longer open for a response. Contact admissions for help.'));
                const editor = node('div', { class: 'mt-3' }), actions = node('div', { class: 'd-flex flex-wrap gap-2 mt-3' });
                body.append(actions, editor);
                const start = action => {
                    if (!beforeChange() || !leave()) return;
                    dirty = false; editor.replaceChildren(); status.textContent = '';
                    const form = node('form'), controls = node('fieldset'), preview = node('div', { class: 'mt-3' });
                    controls.append(node('legend', { class: 'h6' }, ({ issue: 'Issue admission offer', accept: 'Accept offer', decline: 'Decline offer', cancel: 'Cancel offer' })[action]));
                    const fields = {};
                    if (action === 'issue') {
                        fields.title = field(controls, 'title', 'Letter title', { value: data.offer?.title || 'Admission offer', required: true, maxlength: 120 });
                        fields.message = field(controls, 'message', 'Offer letter', { textarea: true, value: data.offer?.message || '', rows: 8, required: true, maxlength: 10000 });
                        fields.respond_by = field(controls, 'respond_by', 'Response deadline', { type: 'date' });
                        controls.append(node('p', { class: 'form-text' }, 'The deadline is optional and includes the full day in the institution’s timezone. A replacement keeps the earlier letter and responses in history.'),
                            node('p', { class: 'form-text' }, 'The review shows the acceptance policy for this offer.'));
                    }
                    if (staff) {
                        fields.reason = field(controls, 'reason', action === 'accept' || action === 'decline' ? 'Documented response and reason' : 'Reason',
                            { textarea: true, rows: 3, required: true, maxlength: 10000 });
                        if (action === 'accept' || action === 'decline') controls.append(node('p', { class: 'form-text' }, 'Record an applicant’s response received outside the portal. History identifies this as a staff-recorded response.'));
                    } else controls.append(node('p', {}, 'Review your response before confirming it. This does not withdraw your application or create a student record.'));
                    const review = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Review');
                    controls.append(review); form.append(controls, preview); editor.append(form);
                    form.addEventListener('submit', event => {
                        event.preventDefault(); if (busy || controls.disabled || !form.reportValidity()) return;
                        const ticket = generation, values = { action };
                        Object.entries(fields).forEach(([key, input]) => { values[key] = input.value; });
                        busy = true; dirty = true; controls.disabled = true; status.textContent = 'Preparing your review…';
                        task(async () => {
                            try {
                                const reviewed = await request(`${base}review/`, 'POST', values);
                                if (!alive(ticket) || !form.isConnected) return;
                                preview.replaceChildren(node('h3', { class: 'h6' }, 'Review before confirming'));
                                summary(preview, reviewed.offer);
                                preview.append(node('p', {}, `Action: ${reviewed.action}.`));
                                const revise = button('Revise', () => { preview.replaceChildren(); controls.disabled = false; review.focus(); });
                                const confirm = button('Confirm', async () => {
                                    if (!alive(ticket) || !form.isConnected) return;
                                    busy = true; confirm.disabled = true; revise.disabled = true;
                                    status.textContent = 'Saving your confirmation…';
                                    try {
                                        await request(`${base}commit/`, 'POST', { review_token: reviewed.review_token });
                                        if (!alive(ticket) || !form.isConnected) return;
                                        dirty = false; notify('Admission offer updated.'); await load();
                                    } catch (error) {
                                        if (alive(ticket)) status.textContent = 'Confirmation could not be completed. You can retry or revise this review.';
                                        throw error;
                                    } finally { if (alive(ticket)) { busy = false; confirm.disabled = false; revise.disabled = false; } }
                                }, 'btn-primary');
                                preview.append(node('div', { class: 'd-flex flex-wrap gap-2' }, confirm, revise));
                                status.textContent = 'Review ready. Confirm to save.'; confirm.focus();
                            } catch (error) {
                                if (alive(ticket)) { controls.disabled = false; status.textContent = 'Review could not be completed. Check the details and try again.'; }
                                throw error;
                            } finally { if (alive(ticket)) busy = false; }
                        });
                    });
                };
                if (data.application_status === 'accepted') {
                    if (staff && canIssue) actions.append(button(data.offer ? 'Replace offer' : 'Issue offer', () => start('issue'), 'btn-primary'));
                    if (data.response_open && (!staff || canIssue)) {
                        actions.append(button(staff ? 'Record acceptance' : 'Accept offer', () => start('accept'), 'btn-primary'),
                            button(staff ? 'Record decline' : 'Decline offer', () => start('decline')));
                    }
                    if (staff && canIssue && ['pending', 'accepted'].includes(data.offer?.status)) actions.append(button('Cancel offer', () => start('cancel')));
                }
                if (staff && canPolicy) actions.append(button('Admissions policy', () => {
                    if (!beforeChange() || !leave()) return;
                    dirty = false; editor.replaceChildren();
                    policyPanel = window.createAdmissionPolicy({ node, request, task, notify }).mount(editor);
                }));
            }
            toggle.addEventListener('click', () => {
                if (!leave()) return;
                if (expanded) { generation++; dirty = false; expanded = false; body.replaceChildren(); content.hidden = true; toggle.setAttribute('aria-expanded', 'false'); return; }
                expanded = true; content.hidden = false; toggle.setAttribute('aria-expanded', 'true'); task(load);
            });
            return { beforeLeave: leave, refresh: () => expanded && !busy && !dirty && !policyPanel ? load() : Promise.resolve() };
        },
    });
})();
