/* One institutional policy editor. Settings are audited CRUD; issued offers retain their own policy. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdmissionPolicy = ({ node, request, task, notify }) => ({
        mount(container) {
            const status = node('p', { role: 'status', 'aria-live': 'polite', class: 'form-text' });
            const body = node('div'); container.append(status, body);
            let busy = false, generation = 0; const dirty = new Set();
            async function load() {
                const ticket = ++generation; busy = true; status.textContent = 'Loading admissions policies…';
                try {
                    const data = await request('/sis/admission-policy/');
                    if (!container.isConnected || ticket !== generation) return;
                    body.replaceChildren();
                    for (const area of ['offers', 'withdrawals', 'answers']) {
                        const form = node('form', { class: 'record-section mb-3' });
                        const identity = `admission-policy-${++sequence}`;
                        const title = area === 'offers' ? 'Offer acceptance' : area === 'answers' ? 'Application revisions' : 'Applicant withdrawal';
                        form.append(node('h3', { class: 'h5' }, title));
                        let input;
                        if (area === 'offers') {
                            input = node('input', { type: 'checkbox', id: identity, class: 'form-check-input', checked: data.offers.require_acceptance });
                            form.append(node('div', { class: 'form-check mb-3' }, input,
                                node('label', { for: identity, class: 'form-check-label' }, 'Require applicant acceptance before matriculation')),
                            node('p', { class: 'form-text' }, 'New offers retain this policy. Issued letters and completed matriculations stay unchanged.'));
                        } else if (area === 'answers') {
                            input = node('select', { id: identity, class: 'form-select mb-3' },
                                ...[['request', 'Only after staff request corrections'], ['before_decision', 'Anytime before an admissions decision']]
                                    .map(([value, label]) => node('option', { value, selected: data.answers.mode === value }, label)));
                            form.append(node('label', { for: identity, class: 'form-label' }, 'When applicants may revise answers'), input,
                                node('p', { class: 'form-text' }, 'Each resubmission retains earlier answers and uses the original form. Accepted, rejected, withdrawn and matriculated applications cannot be edited.'));
                        } else {
                            input = node('select', { id: identity, class: 'form-select mb-3' },
                                ...[['request', 'Staff approval required'], ['direct', 'Applicants may withdraw directly'], ['staff_only', 'Staff-only withdrawal']]
                                    .map(([value, label]) => node('option', { value, selected: data.withdrawals.mode === value }, label)));
                            form.append(node('label', { for: identity, class: 'form-label' }, 'How applicants may withdraw'), input,
                                node('p', { class: 'form-text' }, 'Applies before matriculation. Pending requests pause matriculation and offer responses. Applicants may cancel an existing request even if the policy changes.'));
                        }
                        input.addEventListener('change', () => { dirty.add(area); });
                        const save = node('button', { type: 'submit', class: 'btn btn-primary' }, area === 'offers' ? 'Save offer policy' : area === 'answers' ? 'Save revision policy' : 'Save withdrawal policy');
                        form.append(save); body.append(form);
                        form.addEventListener('submit', event => {
                            event.preventDefault(); if (busy) return;
                            // Keep another policy's unsaved choice instead of discarding it on refresh.
                            busy = true; save.disabled = true; status.textContent = 'Saving policy…';
                            const value = area === 'offers' ? { require_acceptance: input.checked } : { mode: input.value };
                            task(async () => {
                                try {
                                    const saved = await request('/sis/admission-policy/', 'POST', { area, ...value, revision: data[area].revision });
                                    if (!container.isConnected || ticket !== generation) return;
                                    data[area] = saved;
                                    if (area === 'offers' ? input.checked === value.require_acceptance : input.value === value.mode) dirty.delete(area); status.textContent = `${title} policy saved.`; notify(`${title} policy saved.`);
                                } catch (error) {
                                    if (container.isConnected && ticket === generation) status.textContent = 'The policy could not be saved. Your choice is still shown. Reload if someone else changed it.';
                                    throw error;
                                } finally { if (ticket === generation) { busy = false; save.disabled = false; } }
                            });
                        });
                    }
                    dirty.clear(); status.textContent = '';
                } catch (error) {
                    if (container.isConnected && ticket === generation) status.textContent = 'Could not load admissions policies. Close and reopen setup to try again.';
                    throw error;
                } finally { if (ticket === generation) busy = false; }
            }
            task(load);
            return { beforeLeave: () => !container.isConnected || (!busy && (dirty.size === 0 || window.confirm('Leave the unsaved policy changes?'))) };
        },
    });
})();
