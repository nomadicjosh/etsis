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
        if (data.link) {
            status.textContent = `A Moodle account is already linked (ID ${data.link.moodle_user_id}). Another account request is not needed.`;
            return;
        }
        function queued(value) {
            status.textContent = `Account creation request saved. Status: ${value.state}. No Moodle account has been created by this request intake.`;
            body.append(node('p', { class: 'muted-text' }, 'Automatic processing is not available yet. The saved request remains private and can be checked here.'));
        }
        if (data.request) { queued(data.request); return; }
        if (!data.enabled || !data.installation_approved) {
            status.textContent = !data.enabled ? 'Account request intake is disabled. Ask an administrator to review the institutional setup.'
                : 'Ask a Moodle administrator to approve the installation before requesting an account.';
            return;
        }
        status.textContent = 'You can save a reviewed creation request. Automatic processing is not available yet; saving will not create an account or send an email.';
        const username = node('input', { id: 'moodle-create-username', name: 'username', class: 'form-control', type: 'text', maxlength: 100, required: true, autocomplete: 'off', value: data.defaults.username });
        const email = node('input', { id: 'moodle-create-email', name: 'email', class: 'form-control', type: 'email', maxlength: 254, required: true, autocomplete: 'off', value: data.defaults.email });
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
                    node('p', {}, 'This review expires after 20 minutes. Saving records a private request for future processing; it does not create an account or send an email.'),
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
                clear(); form.remove(); preview.remove(); queued(result.request); status.focus();
            }));
        });
    }
    return { open };
};
