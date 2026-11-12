/* Explicit person reference: no automatic matching, provisioning, profile output or sign-in grant. */
window.createWorkspaceMoodlePeople = function (ui) {
    'use strict';
    const { node, request, task, openDrawer, openPerson, can } = ui;
    async function open(id) {
        if (!can('sis:moodle:link-person') || !can('sis:person:manage')) return;
        const body = openDrawer('Moodle account link', 'REVIEWED PERSON REFERENCE');
        let busy = false;
        body.append(node('p', { role: 'status' }, 'Loading saved link…'));
        const data = await request(`/sis/people/${id}/moodle/`);
        if (!body.isConnected) return;
        body.replaceChildren(node('h3', {}, data.person.name),
            node('p', { class: 'muted-text' }, `Institutional ID ${data.person.institutional_id}`),
            node('p', {}, 'This reference does not enable sign-in, assign roles, enroll the person or transfer grades.'));
        const refresh = node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: () => { if (!busy) task(() => open(id)); } }, 'Refresh saved link');
        body.append(node('div', { class: 'd-flex flex-wrap gap-2 mb-3' }, node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: () => task(() => openPerson(id)) }, 'Back to person'), refresh));
        const status = node('p', { role: 'status', 'aria-live': 'polite', tabindex: '-1', 'data-moodle-link-status': '' });
        body.append(status);
        function saved(link) {
            status.textContent = 'Moodle person link saved. Single sign-on and synchronization remain inactive.';
            body.append(node('dl', { class: 'detail-list' },
                node('div', {}, node('dt', {}, 'Moodle user ID'), node('dd', { class: 'text-break font-monospace' }, link.moodle_user_id)),
                node('div', {}, node('dt', {}, 'Approved'), node('dd', {}, link.approved_at))),
                node('p', { class: 'muted-text' }, 'Saved details are read locally. This is not a live account-status check. Replacement and unlinking require a separate reviewed correction workflow.'));
        }
        if (data.link) { saved(data.link); return; }
        if (!data.enabled || !data.installation_approved) {
            status.textContent = !data.enabled ? 'Moodle integration is disabled.'
                : 'Ask a Moodle administrator to approve the institutional installation before linking people.';
            return;
        }
        status.textContent = 'Enter the exact Moodle user ID verified independently for this person.';
        const external = node('input', { id: 'moodle-person-external', name: 'moodle_user_id', class: 'form-control', type: 'text', inputmode: 'numeric', pattern: '[1-9][0-9]{0,18}', maxlength: 19, required: true, 'aria-describedby': 'moodle-person-help' });
        const reason = node('textarea', { id: 'moodle-person-reason', name: 'reason', class: 'form-control', rows: 2, maxlength: 500, required: true });
        const review = node('button', { type: 'submit', class: 'btn btn-outline-primary' }, 'Review Moodle account link');
        const form = node('form', {}, node('label', { class: 'form-label', for: external.id }, 'Moodle user ID'), external,
            node('p', { id: 'moodle-person-help', class: 'form-text' }, 'Use Moodle’s numeric user ID, not an email address, username or institutional ID. No automatic matching is performed.'),
            node('label', { class: 'form-label', for: reason.id }, 'Reason for linking'), reason,
            node('p', { class: 'form-text' }, 'Use an administrative reason without credentials or student academic details.'), review);
        const preview = node('div', { class: 'mt-4', hidden: true });
        body.append(form, preview);
        let pending = null;
        const acknowledge = node('input', { type: 'checkbox', id: 'moodle-person-ack', class: 'form-check-input' });
        const confirm = node('button', { type: 'button', class: 'btn btn-primary', disabled: true }, 'Approve reviewed person link');
        const cancel = node('button', { type: 'button', class: 'btn btn-outline-secondary' }, 'Cancel link review');
        function controls() {
            external.disabled = busy;
            refresh.disabled = busy;
            reason.disabled = busy;
            external.readOnly = pending !== null;
            reason.readOnly = pending !== null;
            review.disabled = busy || pending !== null;
            acknowledge.disabled = busy;
            confirm.disabled = busy || pending === null || !acknowledge.checked;
            cancel.disabled = busy;
        }
        function clear() {
            pending = null;
            acknowledge.checked = false;
            preview.replaceChildren();
            preview.hidden = true;
            controls();
        }
        async function act(button, callback) {
            if (busy || !body.isConnected) return;
            busy = true;
            controls();
            button.setAttribute('aria-busy', 'true');
            try { await callback(); }
            catch (error) {
                if (!body.isConnected) return;
                clear();
                status.textContent = 'The link was not confirmed in this screen. Reload saved details before reviewing again.';
                throw error;
            } finally {
                busy = false;
                button.removeAttribute('aria-busy');
                controls();
            }
        }
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (pending !== null || !form.reportValidity()) return;
            task(() => act(review, async () => {
                status.textContent = 'Checking one account against the approved Moodle installation…';
                const result = await request(`/sis/people/${id}/moodle/review/`, 'POST', { moodle_user_id: external.value, reason: reason.value });
                if (!body.isConnected) return;
                pending = result;
                const flags = node('dl', { class: 'detail-list' });
                for (const [key, label] of [['suspended', 'Suspended'], ['deleted', 'Deleted']]) {
                    const value = result.status[key];
                    flags.append(node('div', {}, node('dt', {}, label), node('dd', {}, value === null ? 'Not supplied by Moodle' : value === true ? 'Yes' : 'No')));
                }
                preview.replaceChildren(node('h4', { tabindex: '-1' }, 'Review the person and Moodle reference'),
                    node('p', { class: 'text-break' }, `${data.person.name} · ${data.person.institutional_id} → Moodle user ${result.moodle_user_id}`), flags,
                    node('p', {}, 'No Moodle profile was returned to this screen. Check the ID independently. Unknown flags do not establish account eligibility. This review expires after 20 minutes.'),
                    node('div', { class: 'form-check mb-3' }, acknowledge, node('label', { class: 'form-check-label', for: acknowledge.id }, 'I independently verified that this Moodle user ID belongs to this person.')),
                    node('div', { class: 'd-flex flex-wrap gap-2' }, confirm, cancel));
                preview.hidden = false;
                status.textContent = 'Review ready. Approval rechecks the installation, account and person record.';
                preview.querySelector('h4').focus();
            }));
        });
        acknowledge.addEventListener('change', controls);
        cancel.addEventListener('click', () => {
            if (busy) return;
            clear(); status.textContent = 'Review canceled. No person link was saved.'; external.focus();
        });
        confirm.addEventListener('click', () => {
            if (pending === null || !acknowledge.checked) return;
            const token = pending.review_token;
            task(() => act(confirm, async () => {
                status.textContent = 'Rechecking and approving this person link…';
                const result = await request(`/sis/people/${id}/moodle/confirm/`, 'POST', { review_token: token });
                if (!body.isConnected) return;
                clear(); form.remove(); preview.remove(); saved(result.link); status.focus();
            }));
        });
    }
    return { open };
};
