/* Application withdrawal only. One owner/staff request panel; formal outcomes keep their existing journal. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdmissionWithdrawals = ({ node, request, task, notify }) => ({
        mount(container, application, { staff = false, canResolve = false, toggleTarget = null, label = '', onOutcome = async () => {}, beforeChange = () => true, initiallyOpen = false } = {}) {
            const base = `${staff ? '/sis' : '/portal/api'}/applications/${application}/withdrawal/`;
            const id = `withdrawal-${++sequence}`, content = node('section', { id, hidden: true, class: 'record-section mt-3', 'aria-label': 'Application withdrawal' });
            const toggle = node('button', { type: 'button', class: 'btn btn-outline-secondary', 'aria-controls': id, 'aria-expanded': 'false',
                ...(label ? { 'aria-label': `Withdrawal for ${label}` } : {}) }, 'Withdrawal');
            const status = node('p', { role: 'status', 'aria-live': 'polite', 'aria-atomic': 'true', class: 'form-text' }), body = node('div');
            content.append(status, body); (toggleTarget || container).append(toggle); container.append(content);
            let expanded = false, busy = false, dirty = false, generation = 0, page = null;
            const alive = ticket => ticket === generation && container.isConnected;
            const leave = () => !busy && (!dirty || window.confirm('Leave the unfinished withdrawal form?'));
            const button = (text, action, style = 'btn-outline-secondary') => {
                const b = node('button', { type: 'button', class: `btn ${style}` }, text);
                b.addEventListener('click', () => { if (!busy) task(action); }); return b;
            };
            const names = { request: 'Request withdrawal', direct: 'Withdraw application', cancel: 'Cancel request', approve: 'Approve withdrawal', decline: 'Close request' };
            const outcomes = { pending: 'Awaiting staff review', approved: 'Withdrawal approved', declined: 'Request closed', cancelled: 'Request cancelled', withdrawn: 'Application withdrawn' };
            async function load(after = null) {
                const restoreFocus = !!body.querySelector(':focus');
                const ticket = ++generation; busy = true; toggle.disabled = true; status.textContent = 'Loading withdrawal requests…';
                try {
                    const data = await request(base + (after ? `?after=${encodeURIComponent(after)}` : ''));
                    if (!alive(ticket)) return;
                    dirty = false; page = after; render(data); status.textContent = '';
                    if (restoreFocus) body.querySelector('h2')?.focus();
                } catch (error) {
                    if (alive(ticket)) status.textContent = 'Could not load withdrawal requests. Close and reopen this panel to try again.';
                    throw error;
                } finally { if (alive(ticket)) { busy = false; toggle.disabled = false; } }
            }
            function render(data) {
                body.replaceChildren(node('h2', { class: 'h5', tabindex: '-1' }, 'Application withdrawal'),
                    node('p', { class: 'form-text' }, 'This concerns this application only. Your person account and any existing student or employment records remain intact.'),
                    node('p', { class: 'form-text' }, `Current application status: ${data.application_status}`));
                const actions = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' }), editor = node('div'); body.append(actions, editor);
                if (!staff && !data.has_pending && ['submitted', 'accepted'].includes(data.application_status)) {
                    if (data.policy.mode === 'staff_only') body.append(node('p', {}, 'Contact admissions if you would like to withdraw this application.'));
                    else {
                        const action = data.policy.mode === 'direct' ? 'direct' : 'request';
                        actions.append(button(names[action], () => edit(action), action === 'direct' ? 'btn-outline-danger' : 'btn-outline-primary'));
                        body.append(node('p', { class: 'form-text' }, action === 'direct'
                            ? 'Institutional policy allows you to withdraw directly after review and confirmation.'
                            : 'Admissions staff must approve your request. The application stays open while they review it.'));
                    }
                }
                if (data.has_pending) body.append(node('p', { class: 'alert alert-info' }, 'A withdrawal request is awaiting review. Matriculation and offer responses are paused until it is resolved.'));
                if (!staff && data.pending_id) actions.append(button('Cancel request', () => edit('cancel', data.pending_id)));
                if (staff && canResolve && data.pending_id) {
                    if (['submitted', 'accepted', 'rejected'].includes(data.application_status)) actions.append(button('Approve withdrawal', () => edit('approve', data.pending_id), 'btn-primary'));
                    actions.append(button('Close request', () => edit('decline', data.pending_id)));
                }
                for (const item of data.items) {
                    const details = node('details', { class: 'border rounded p-3 mb-3' },
                        node('summary', { class: 'fw-semibold' }, `${outcomes[item.status] || item.status} · ${item.requested_at}`),
                        node('p', { class: 'private-text text-break mt-3', style: 'white-space: pre-wrap' }, item.reason));
                    if (item.response) details.append(node('h3', { class: 'h6' }, 'Response'),
                        node('p', { class: 'private-text text-break', style: 'white-space: pre-wrap' }, item.response));
                    body.append(details);
                }
                if (!data.items.length) body.append(node('p', {}, 'No withdrawal requests on this page.'));
                const pages = node('nav', { class: 'd-flex flex-wrap gap-2', 'aria-label': 'Withdrawal request pages' });
                if (page) pages.append(button('Latest requests', () => leave() && load()));
                if (data.next) pages.append(button('Earlier requests', () => leave() && load(data.next)));
                body.append(pages);
                function edit(action, requestId = null) {
                    if (!beforeChange() || !leave()) return;
                    dirty = false; editor.replaceChildren(); status.textContent = '';
                    const form = node('form'), controls = node('fieldset'), preview = node('div');
                    controls.append(node('legend', { class: 'h6' }, names[action]));
                    let reason = null;
                    if (action !== 'cancel') {
                        const identity = `withdrawal-reason-${++sequence}`;
                        reason = node('textarea', { id: identity, class: 'form-control mb-3', rows: 4, required: true, maxlength: 2000 });
                        reason.addEventListener('input', () => { dirty = true; });
                        controls.append(node('label', { class: 'form-label', for: identity }, staff ? 'Response to applicant' : 'Reason for withdrawal'), reason);
                    }
                    controls.append(node('p', { class: 'form-text' }, staff
                        ? 'This response is visible to the applicant. Closing a request leaves the application’s current status unchanged.'
                        : action === 'cancel' ? 'Cancel the pending request. This does not restore a previously withdrawn application.'
                        : action === 'request' ? 'Submitting this request does not withdraw the application until staff approve it.'
                        : 'Confirmation withdraws this application. Admissions must review any later request to return it to review.'));
                    const review = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Review'); controls.append(review);
                    form.append(controls, preview); editor.append(form); reason?.focus();
                    form.addEventListener('submit', event => {
                        event.preventDefault(); if (busy || controls.disabled || !form.reportValidity()) return;
                        const ticket = generation; busy = true; dirty = true; controls.disabled = true;
                        status.textContent = 'Preparing your review…';
                        task(async () => {
                            try {
                                const reviewed = await request(`${base}review/`, 'POST', { action, request_id: requestId, reason: reason?.value || '' });
                                if (!alive(ticket) || !form.isConnected) return;
                                preview.replaceChildren(node('h3', { class: 'h6' }, 'Review before confirming'),
                                    node('p', {}, names[reviewed.action]), node('p', { class: 'private-text text-break', style: 'white-space: pre-wrap' }, reviewed.reason));
                                if (reviewed.request) preview.append(node('p', { class: 'private-text text-break' }, `Applicant request: ${reviewed.request.reason}`));
                                const revise = button('Revise', () => { preview.replaceChildren(); controls.disabled = false; reason?.focus(); });
                                const confirm = button('Confirm', async () => {
                                    if (!alive(ticket) || !form.isConnected || !beforeChange()) return;
                                    busy = true; confirm.disabled = true; revise.disabled = true; status.textContent = 'Saving confirmation…';
                                    try {
                                        const result = await request(`${base}commit/`, 'POST', { review_token: reviewed.review_token });
                                        if (!alive(ticket) || !form.isConnected) return;
                                        dirty = false; notify(outcomes[result.status] || 'Application request updated.');
                                        await load(); busy = false; await onOutcome(result);
                                    } catch (error) {
                                        if (alive(ticket)) status.textContent = 'Confirmation could not be completed. Retry this confirmation or revise your review.';
                                        throw error;
                                    } finally { if (alive(ticket)) { busy = false; confirm.disabled = false; revise.disabled = false; } }
                                }, action === 'direct' || action === 'approve' ? 'btn-danger' : 'btn-primary');
                                preview.append(node('div', { class: 'd-flex flex-wrap gap-2 mt-3' }, confirm, revise));
                                status.textContent = 'Review ready. Confirm to save.'; confirm.focus();
                            } catch (error) {
                                if (alive(ticket)) { controls.disabled = false; status.textContent = 'Review could not be completed. Check the details and try again.'; }
                                throw error;
                            } finally { if (alive(ticket)) busy = false; }
                        });
                    });
                }
            }
            toggle.addEventListener('click', () => {
                if (!leave()) return;
                if (expanded) { generation++; expanded = false; dirty = false; content.hidden = true; body.replaceChildren(); toggle.setAttribute('aria-expanded', 'false'); }
                else { expanded = true; content.hidden = false; toggle.setAttribute('aria-expanded', 'true'); task(() => load()); }
            });
            if (initiallyOpen) {
                expanded = true; content.hidden = false; toggle.setAttribute('aria-expanded', 'true'); task(() => load());
            }
            return { beforeLeave: leave };
        },
        queue(container, openApplication) {
            const list = node('div'), status = node('p', { role: 'status', class: 'form-text' }); 
            const refresh = node('button', { type: 'button', class: 'btn btn-outline-primary mb-3' }, 'Refresh requests');
            refresh.addEventListener('click', () => task(() => load())); container.append(refresh, status, list);
            let generation = 0;
            async function load(after = null) {
                const ticket = ++generation; status.textContent = 'Loading withdrawal requests…';
                const data = await request('/sis/admissions/withdrawal-requests/' + (after ? `?after=${encodeURIComponent(after)}` : '')).catch(error => {
                    if (container.isConnected && ticket === generation) status.textContent = 'Could not load requests. Choose Refresh requests to try again.';
                    throw error;
                });
                if (!container.isConnected || ticket !== generation) return;
                list.replaceChildren();
                for (const item of data.items) {
                    const open = node('button', { type: 'button', class: 'btn btn-outline-primary' }, 'Review application');
                    open.addEventListener('click', () => task(() => openApplication({ id: item.application_id, openWithdrawal: true })));
                    list.append(node('article', { class: 'record-section mb-3' },
                        node('h3', { class: 'h5' }, `${item.given_name} ${item.family_name}`),
                        node('p', {}, `Application status: ${item.application_status}`), open));
                }
                const pages = node('div', { class: 'd-flex flex-wrap gap-2' });
                if (after) { const latest = node('button', { type: 'button', class: 'btn btn-outline-secondary' }, 'First page'); latest.addEventListener('click', () => task(() => load())); pages.append(latest); }
                if (data.next) { const next = node('button', { type: 'button', class: 'btn btn-outline-secondary' }, 'Next requests'); next.addEventListener('click', () => task(() => load(data.next))); pages.append(next); }
                list.append(pages); status.textContent = data.items.length ? `${data.items.length} pending requests on this page.` : 'No pending withdrawal requests.';
            }
            task(() => load());
        },
    });
})();
