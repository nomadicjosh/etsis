/* Private creation-request intake. No credentials, remote account writes or browser persistence. */
window.createWorkspaceMoodleRequests = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, openPerson, can } = ui;
    async function open(id) {
        if (!can('sis:moodle:create-person') || !can('sis:person:manage')) return;
        const body = openDrawer('Create a Moodle account', 'REVIEWED ACCOUNT REQUEST');
        body.append(node('p', { role: 'status' }, 'Loading account request status…'));
        const data = await request(`/sis/people/${id}/moodle/account-request/`);
        if (!body.isConnected) return;
        let busy = false, pending = null;
        const refresh = node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: () => { if (!busy) task(() => open(id)); } }, 'Refresh request status');
        body.replaceChildren(node('h3', {}, data.person.name),
            node('p', { class: 'muted-text' }, `Institutional ID ${data.person.institutional_id}`),
            node('div', { class: 'd-flex flex-wrap gap-2 mb-3' },
                node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: () => task(() => openPerson(id)) }, 'Back to person'), refresh));
        const status = node('p', { role: 'status', 'aria-live': 'polite', tabindex: '-1', 'data-account-request-status': '' });
        body.append(status);
        if (data.link && !data.request) {
            status.textContent = `A Moodle account is already linked (ID ${data.link.moodle_user_id}). Another account request is not needed.`;
            return;
        }
        function queued(value, processing = data.processing_available) {
            status.textContent = `Account creation request saved. Status: ${value.state}.`;
            if (data.link) body.append(node('p', {}, `Moodle account linked (ID ${data.link.moodle_user_id}).`));
            if (value.issue) body.append(node('p', { role: 'status', 'data-account-request-issue': '' }, value.issue));
            if (!processing) body.append(node('p', { class: 'muted-text' }, 'Automatic processing is disabled. Saving a request does not create an account or send an email.'));
            else if (value.state === 'queued' || value.state === 'processing') body.append(node('p', { class: 'muted-text' }, 'The background worker will verify and process this request. Refresh here to check progress.'));
        }
        if (data.request) {
            queued(data.request);
            if (data.link && data.request.state === 'review_required' && data.request.issue_code === 'email_delivery') {
                resolution();
            }
            return;
        }
        if (!data.enabled || !data.installation_approved) {
            status.textContent = !data.enabled ? 'Account request intake is disabled. Ask an administrator to review the institutional setup.'
                : 'Ask a Moodle administrator to approve the installation before requesting an account.';
            return;
        }
        status.textContent = data.processing_available ? 'Review this account request before the background worker creates the account and retains its Moodle link.' : 'You can save a reviewed creation request. Automatic processing is disabled; saving will not create an account or send an email.';
        const username = node('input', { id: 'moodle-create-username', name: 'username', class: 'form-control', type: 'text', maxlength: 100, required: true, autocomplete: 'off', value: data.defaults.username });
        const email = node('input', { id: 'moodle-create-email', name: 'email', class: 'form-control', type: 'email', maxlength: 100, required: true, autocomplete: 'off', value: data.defaults.email });
        const reason = node('input', { id: 'moodle-create-reason', name: 'reason', class: 'form-control', type: 'text', maxlength: 500, required: true, autocomplete: 'off' });
        const sso = node('input', { id: 'moodle-create-sso', name: 'sign_in', type: 'radio', class: 'form-check-input', value: 'sso', required: true });
        const password = node('input', { id: 'moodle-create-password-email', name: 'sign_in', type: 'radio', class: 'form-check-input', value: 'password_email', required: true });
        const review = node('button', { type: 'submit', class: 'btn btn-outline-primary' }, 'Review account request');
        const form = node('form', {},
            node('label', { class: 'form-label', for: username.id }, 'Moodle username'), username,
            node('p', { class: 'form-text' }, 'Use 1–100 lowercase letters, digits or . _ @ - characters.'),
            node('label', { class: 'form-label', for: email.id }, 'Account email address'), email,
            node('fieldset', { class: 'my-3' }, node('legend', { class: 'h6' }, 'Sign-in workflow'),
                node('div', { class: 'form-check' }, sso, node('label', { for: sso.id, class: 'form-check-label' }, 'Institutional SSO')),
                node('div', { class: 'form-check' }, password, node('label', { for: password.id, class: 'form-check-label' }, 'Moodle password-setup email'))),
            node('label', { class: 'form-label', for: reason.id }, 'Reason for account creation'), reason,
            node('p', { class: 'form-text' }, 'Use an administrative reason without passwords or academic details.'), review);
        const preview = node('div', { class: 'mt-4', hidden: true });
        body.append(form, preview);
        const acknowledge = node('input', { type: 'checkbox', id: 'moodle-create-ack', class: 'form-check-input' });
        const confirm = node('button', { type: 'button', class: 'btn btn-primary', disabled: true }, 'Save reviewed creation request');
        const cancel = node('button', { type: 'button', class: 'btn btn-outline-secondary' }, 'Cancel account review');
        function controls() {
            refresh.disabled = busy;
            for (const field of [username, email, reason, sso, password]) field.disabled = busy || pending !== null;
            review.disabled = busy || pending !== null;
            acknowledge.disabled = busy;
            confirm.disabled = busy || pending === null || !acknowledge.checked;
            cancel.disabled = busy;
        }
        function clear() { pending = null; acknowledge.checked = false; preview.replaceChildren(); preview.hidden = true; controls(); }
        async function act(button, action) {
            if (busy || !body.isConnected) return;
            busy = true; controls(); button.setAttribute('aria-busy', 'true');
            try { await action(); }
            catch (error) {
                if (!body.isConnected) return;
                clear();
                status.textContent = 'The request could not be confirmed in this screen. Refresh request status before trying again.';
                throw error;
            } finally { busy = false; button.removeAttribute('aria-busy'); controls(); }
        }
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (pending !== null || !form.reportValidity()) return;
            task(() => act(review, async () => {
                status.textContent = 'Verifying the approved installation and preparing your review…';
                const result = await request(`/sis/people/${id}/moodle/account-request/review/`, 'POST', {
                    username: username.value, email: email.value, sign_in: sso.checked ? 'sso' : 'password_email', reason: reason.value,
                });
                if (!body.isConnected) return;
                pending = result;
                preview.replaceChildren(node('h4', { tabindex: '-1' }, 'Review account creation request'),
                    node('dl', { class: 'detail-list' },
                        node('div', {}, node('dt', {}, 'Person'), node('dd', {}, result.person_name)),
                        node('div', {}, node('dt', {}, 'Username'), node('dd', { class: 'text-break' }, result.username)),
                        node('div', {}, node('dt', {}, 'Email'), node('dd', { class: 'text-break' }, result.email)),
                        node('div', {}, node('dt', {}, 'Sign-in'), node('dd', {}, result.sign_in === 'sso' ? 'Institutional SSO' : 'Moodle password-setup email'))),
                    node('p', {}, data.processing_available ? 'This review expires after 20 minutes. Saving queues account creation and the selected sign-in setup for the background worker.' : 'This review expires after 20 minutes. Saving records a private request; processing is disabled, so no account or email is created.'),
                    node('div', { class: 'form-check mb-3' }, acknowledge, node('label', { for: acknowledge.id, class: 'form-check-label' }, 'I reviewed this person, destination address and sign-in choice and authorize this account request.')),
                    node('div', { class: 'd-flex flex-wrap gap-2' }, confirm, cancel));
                preview.hidden = false;
                status.textContent = 'Review ready. Confirm to save the request and its processing reference.';
                preview.querySelector('h4').focus();
            }));
        });
        acknowledge.addEventListener('change', controls);
        cancel.addEventListener('click', () => { if (!busy) { clear(); status.textContent = 'Review canceled. No request was saved.'; username.focus(); } });
        confirm.addEventListener('click', () => {
            if (pending === null || !acknowledge.checked) return;
            const token = pending.review_token;
            task(() => act(confirm, async () => {
                status.textContent = 'Rechecking and saving the private account request…';
                const result = await request(`/sis/people/${id}/moodle/account-request/confirm/`, 'POST', { review_token: token });
                if (!body.isConnected) return;
                clear(); form.remove(); preview.remove(); queued(result.request, result.processing_available); status.focus();
            }));
        });

        function resolution() {
            const section = node('section', { class: 'mt-4', 'aria-labelledby': 'moodle-resolution-heading' });
            const reason = node('input', { id: 'moodle-resolution-reason', class: 'form-control', type: 'text', maxlength: 500, required: true, autocomplete: 'off', 'aria-describedby': 'moodle-resolution-help' });
            const review = node('button', { type: 'submit', class: 'btn btn-outline-primary mt-3' }, 'Review access resolution');
            const form = node('form', {},
                node('label', { for: reason.id, class: 'form-label' }, 'How was account access resolved?'), reason,
                node('p', { id: 'moodle-resolution-help', class: 'form-text' }, 'Record the administrative outcome only. Do not include passwords, reset links or academic details.'), review);
            const preview = node('div', { hidden: true, class: 'mt-3' });
            const acknowledge = node('input', { id: 'moodle-resolution-ack', type: 'checkbox', class: 'form-check-input' });
            const confirm = node('button', { type: 'button', class: 'btn btn-primary', disabled: true }, 'Save access resolution');
            const cancel = node('button', { type: 'button', class: 'btn btn-outline-secondary' }, 'Cancel resolution review');
            section.append(node('h4', { id: 'moodle-resolution-heading' }, 'Resolve setup-email review'),
                node('p', {}, 'First resolve access to this existing Moodle account through your institution’s verified support process or Moodle password recovery. Then record the outcome here. This does not verify email delivery, resend a message, change a password or create another account.'), form, preview);
            body.append(section);
            function controls() {
                refresh.disabled = busy;
                reason.disabled = busy || pending !== null;
                review.disabled = busy || pending !== null;
                acknowledge.disabled = busy;
                confirm.disabled = busy || pending === null || !acknowledge.checked;
                cancel.disabled = busy;
            }
            function clear() {
                pending = null; acknowledge.checked = false;
                preview.replaceChildren(); preview.hidden = true; controls();
            }
            async function act(button, action) {
                if (busy || !body.isConnected) return;
                busy = true; controls(); button.setAttribute('aria-busy', 'true');
                try { await action(); }
                catch (error) {
                    if (!body.isConnected) return;
                    clear();
                    status.textContent = 'The resolution could not be confirmed here. Refresh request status before trying again.';
                    status.focus();
                    throw error;
                } finally { busy = false; button.removeAttribute('aria-busy'); controls(); }
            }
            form.addEventListener('submit', event => {
                event.preventDefault();
                if (pending !== null || !form.reportValidity()) return;
                task(() => act(review, async () => {
                    const result = await request(`/sis/people/${id}/moodle/account-request/resolution/review/`, 'POST', { reason: reason.value });
                    if (!body.isConnected) return;
                    pending = result;
                    preview.replaceChildren(node('h5', { tabindex: '-1' }, 'Review existing-account access resolution'),
                        node('p', {}, `${result.person_name} · Moodle account ${result.moodle_user_id}`),
                        node('p', { class: 'text-break' }, result.reason),
                        node('p', {}, 'This review expires after 20 minutes. Saving records your statement and closes this setup-email review. The original creation request and linked account are retained.'),
                        node('div', { class: 'form-check mb-3' }, acknowledge,
                            node('label', { for: acknowledge.id, class: 'form-check-label' }, 'I verified that access to this existing account was resolved and reviewed this outcome.')),
                        node('div', { class: 'd-flex flex-wrap gap-2' }, confirm, cancel));
                    preview.hidden = false;
                    status.textContent = 'Resolution ready for your confirmation.';
                    preview.querySelector('h5').focus();
                }));
            });
            acknowledge.addEventListener('change', controls);
            cancel.addEventListener('click', () => {
                if (!busy) { clear(); status.textContent = 'Resolution review canceled. No change was saved.'; reason.focus(); }
            });
            confirm.addEventListener('click', () => {
                if (pending === null || !acknowledge.checked) return;
                const token = pending.review_token;
                task(() => act(confirm, async () => {
                    await request(`/sis/people/${id}/moodle/account-request/resolution/confirm/`, 'POST', { review_token: token });
                    if (!body.isConnected) return;
                    clear(); section.remove();
                    body.querySelector('[data-account-request-issue]')?.remove();
                    status.textContent = 'Access resolution saved. The existing Moodle account and original creation request were retained. No email was resent.';
                    status.focus();
                }));
            });
        }
    }
    return { open };
};
