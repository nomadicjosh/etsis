/* Private Moodle administration. Explicit verification and approval; no credentials or student lookups. */
window.createWorkspaceMoodle = function (ui) {
    "use strict";
    const { node, request, task, can } = ui;
    const panel = document.querySelector('[data-panel="moodle"]');
    const status = document.querySelector('#moodle-status');
    const check = document.querySelector('#moodle-check');
    const refresh = document.querySelector('#moodle-refresh');
    const results = document.querySelector('#moodle-results');
    const installationStatus = document.querySelector('#moodle-installation-status');
    const saved = document.querySelector('#moodle-installation-saved');
    const form = document.querySelector('#moodle-installation-form');
    const reason = document.querySelector('#moodle-installation-reason');
    const review = document.querySelector('#moodle-installation-review');
    const preview = document.querySelector('#moodle-installation-preview');
    const approval = document.querySelector('#moodle-installation-approval');
    const acknowledge = document.querySelector('#moodle-installation-acknowledge');
    const confirm = document.querySelector('#moodle-installation-confirm');
    const cancel = document.querySelector('#moodle-installation-cancel');
    const verify = document.querySelector('#moodle-installation-verify');
    let version = 0, enabled = false, busy = false, bindingLoaded = false, binding = null, pending = null;
    function controls() {
        check.disabled = !enabled || busy;
        refresh.disabled = busy;
        reason.disabled = !enabled || busy || !bindingLoaded;
        reason.readOnly = pending !== null;
        review.disabled = !enabled || busy || !bindingLoaded || binding !== null || pending !== null;
        acknowledge.disabled = busy;
        confirm.disabled = !enabled || busy || pending === null || !acknowledge.checked;
        cancel.disabled = busy;
        verify.disabled = !enabled || busy || binding === null;
    }
    function clearReview() {
        pending = null;
        acknowledge.checked = false;
        preview.replaceChildren();
        preview.hidden = true;
        approval.hidden = true;
        controls();
    }
    function fingerprints(value) {
        return node('dl', { class: 'mb-0' },
            node('dt', {}, 'Installation fingerprint (SHA-256)'),
            node('dd', { class: 'font-monospace text-break' }, value.instance_fingerprint),
            node('dt', {}, 'Site-address fingerprint (SHA-256)'),
            node('dd', { class: 'font-monospace text-break' }, value.site_fingerprint));
    }
    function showBinding(value) {
        binding = value;
        bindingLoaded = true;
        clearReview();
        saved.replaceChildren();
        saved.hidden = binding === null;
        form.hidden = binding !== null;
        verify.hidden = binding === null;
        if (binding !== null) {
            reason.value = '';
            saved.append(fingerprints(binding), node('p', { class: 'small mb-0' }, `Approved ${binding.approved_at}.`));
            installationStatus.textContent = 'Approval saved. No live installation verification has run in this screen yet.';
        } else {
            installationStatus.textContent = enabled
                ? 'No installation approved. Review the institution’s configured Moodle installation to begin.'
                : 'Moodle integration is disabled. Enable it in protected server settings before reviewing an installation.';
        }
        controls();
    }
    const labels = {
        local_etsis_get_instance_info: "Installation identity plugin",
        local_etsis_get_account_policy: 'Account sign-in policy',
        local_etsis_provision_account: 'Reviewed account creation',
        local_etsis_get_account_receipt: 'Original account receipt inspection',
        local_etsis_get_roster_policy: 'Section course-role policy',
        local_etsis_sync_roster_member: 'Managed student and instructor access',
        local_etsis_provision_course: 'Section course creation',
        core_webservice_get_site_info: 'Service information',
        core_user_get_users_by_field: 'Targeted user lookup',
        core_course_get_courses_by_field: 'Targeted course lookup',
        core_course_create_courses: 'Course creation',
        core_course_update_courses: 'Course updates',
        enrol_manual_enrol_users: 'Manual enrollment',
        enrol_manual_unenrol_users: 'Manual unenrollment',
        gradereport_user_get_grade_items: 'User grade items',
    };
    const additionalFunctions = new Set([
        'core_course_create_courses', 'core_course_update_courses',
        'enrol_manual_enrol_users', 'enrol_manual_unenrol_users', 'gradereport_user_get_grade_items',
    ]);
    function state(value) {
        enabled = value === true;
        controls();
        status.textContent = enabled
            ? 'Enabled by your institution. No connection check has run in this screen yet.'
            : 'Moodle integration is disabled. Normal SIS workflows remain available.';
    }
    async function load() {
        const current = ++version;
        clearReview();
        bindingLoaded = false;
        binding = null;
        saved.hidden = true;
        form.hidden = false;
        verify.hidden = true;
        results.hidden = true;
        results.replaceChildren();
        enabled = false;
        controls();
        if (!can('sis:moodle:manage')) {
            status.textContent = 'You do not have access to Moodle administration.';
            return;
        }
        status.textContent = 'Loading institutional status…';
        const response = await request('/sis/moodle/status/');
        if (version !== current || panel.hidden) return;
        state(response.enabled);
        installationStatus.textContent = 'Loading saved approval…';
        const installation = await request('/sis/moodle/installation/');
        if (version !== current || panel.hidden) return;
        showBinding(installation.binding);
    }
    refresh.addEventListener('click', event => task(load, event.currentTarget));
    check.addEventListener('click', event => task(async () => {
        if (busy || !enabled || !can('sis:moodle:manage')) return;
        const current = version;
        busy = true;
        controls();
        check.setAttribute("aria-busy", "true");
        results.hidden = true;
        results.replaceChildren();
        status.textContent = 'Checking Moodle service metadata…';
        try {
            const response = await request('/sis/moodle/check/', 'POST', {});
            if (version !== current || panel.hidden) return;
            if (!response.enabled) { state(false); return; }
            status.textContent = 'Site URL and REST connection verified. This verification does not change launch or synchronization settings.';
            const list = node('dl', { class: 'row mb-0', 'data-moodle-connector-functions': '' });
            const additionalList = node('dl', { class: 'row mb-0' });
            let advertised = 0;
            for (const [name, label] of Object.entries(labels)) {
                const additional = additionalFunctions.has(name);
                const available = response.capabilities[name] === true;
                if (!additional && available) advertised++;
                (additional ? additionalList : list).append(node('dt', { class: 'col-8 mb-3' }, label,
                    node('span', { class: 'd-block fw-normal small text-break' }, name)),
                    node('dd', { class: 'col-4 mb-3' }, available ? 'Advertised' : 'Not advertised'));
            }
            const total = Object.keys(labels).length - additionalFunctions.size;
            results.append(node('h3', { class: 'h5', tabindex: '-1' }, 'Connector service functions'),
                node('p', {}, `${advertised} of ${total} connector functions advertised. The etSIS section synchronization service includes all ${total}.`),
                node('p', {}, 'Functions needed depend on the features your institution enables. Advertisement confirms service availability; it does not verify course permissions or enable synchronization.'), list,
                node('details', { class: 'mt-3' },
                    node('summary', { class: 'fw-semibold' }, 'Additional Moodle functions — not required by this connector'),
                    node('p', { class: 'mt-2' }, 'Missing functions here are expected with the etSIS section synchronization service. Managed course access uses the connector’s own enrollment function. Section course creation uses the connector’s own creation function. Gradebook import remains future work.'), additionalList));
            results.hidden = false;
            results.querySelector('h3').focus();
        } catch (error) {
            if (version !== current || panel.hidden) return;
            status.textContent = 'Connection could not be verified. Review the institutional configuration and retry.';
            throw error;
        } finally {
            busy = false;
            controls();
            check.removeAttribute("aria-busy");
        }
    }));
    async function installationAction(button, action, failure) {
        if (busy || !enabled || !can('sis:moodle:manage')) return;
        const current = version;
        busy = true;
        controls();
        button.setAttribute('aria-busy', 'true');
        try {
            await action(current);
        } catch (error) {
            if (current !== version || panel.hidden) return;
            installationStatus.textContent = error.status === 503 && typeof error.message === 'string'
                ? `${failure} ${error.message}` : failure;
            clearReview();
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
        task(() => installationAction(review, async current => {
            installationStatus.textContent = 'Verifying the configured Moodle installation for review…';
            const result = await request('/sis/moodle/installation/review/', 'POST', { reason: reason.value });
            if (current !== version || panel.hidden) return;
            pending = result;
            acknowledge.checked = false;
            preview.replaceChildren(node('h3', { class: 'h5', tabindex: '-1' }, 'Review this installation'),
                fingerprints(result), node('p', { class: 'small' }, 'This review expires after 20 minutes. Compare the installation fingerprint with the deployment operator’s sis:moodle:instance result. Approval rechecks Moodle before saving.'));
            preview.hidden = false;
            approval.hidden = false;
            installationStatus.textContent = 'Review ready. Confirm only if this is the intended institutional Moodle installation.';
            preview.querySelector('h3').focus();
        }, 'Installation review failed. Check that the connector is installed, enabled and authorized, then review again.'));
    });
    acknowledge.addEventListener('change', controls);
    cancel.addEventListener('click', () => {
        if (busy) return;
        clearReview();
        installationStatus.textContent = 'Review canceled. No installation approval was saved.';
        reason.focus();
    });
    confirm.addEventListener('click', () => {
        if (pending === null || !acknowledge.checked) return;
        const token = pending.review_token;
        task(() => installationAction(confirm, async current => {
            installationStatus.textContent = 'Rechecking Moodle and saving your approval…';
            const result = await request('/sis/moodle/installation/confirm/', 'POST', { review_token: token });
            if (current !== version || panel.hidden) return;
            showBinding(result.binding);
            installationStatus.textContent = 'Installation approved. This verification does not change launch or synchronization settings.';
            installationStatus.focus();
        }, 'Approval could not be confirmed. Refresh saved details before reviewing again.'));
    });
    verify.addEventListener('click', () => task(() => installationAction(verify, async current => {
        installationStatus.textContent = 'Checking Moodle against the saved approval…';
        await request('/sis/moodle/installation/verify/', 'POST', {});
        if (current !== version || panel.hidden) return;
        installationStatus.textContent = 'The configured Moodle installation matches the saved approval. This verification does not change launch or synchronization settings.';
    }, 'The approved installation could not be verified. Check the institutional configuration; the saved approval has not been replaced.')));
    function leave() {
        version++;
        clearReview();
        reason.value = '';
    }
    const sectionArea = document.querySelector('#moodle-section-area');
    if (sectionArea && can('sis:catalog:manage') && can('sis:moodle:manage')) {
        sectionArea.hidden = false;
        const searchForm = document.querySelector('#moodle-section-search-form');
        const search = document.querySelector('#moodle-section-search');
        const sectionStatus = document.querySelector('#moodle-section-status');
        const sectionResults = document.querySelector('#moodle-section-results');
        let sectionBusy = false;
        searchForm.addEventListener('submit', event => {
            event.preventDefault();
            if (sectionBusy || !searchForm.reportValidity()) return;
            task(async () => {
                sectionBusy = true;
                const current = version;
                try {
                    sectionStatus.textContent = 'Searching section setup…';
                    const data = await request('/sis/moodle/sections/?search=' + encodeURIComponent(search.value));
                    if (current !== version || panel.hidden) return;
                    sectionResults.replaceChildren();
                    sectionStatus.textContent = `${data.items.length} matching sections. Background section synchronization is ${data.sync_enabled ? 'enabled' : 'disabled'}.`;
                    for (const item of data.items) {
                        const card = node('section', { class: 'border rounded-3 p-3 mt-3' }, node('h3', { class: 'h5' }, `${item.term_code} · ${item.code} · ${item.title}`));
                        sectionResults.append(card);
                        if (item.moodle_course_id) {
                            const messages = { none: 'No section issue recorded.', account_setup_required: 'A Moodle account link or account setup is needed.', account_pending: 'Automatic Moodle account setup is queued or processing.',
                                externally_managed: 'Some course access is managed outside this connector.', faculty_role_required: 'The instructor needs a current faculty role.',
                                student_setup_required: 'Student sign-in or role setup is needed.', source_changed: 'SIS changes are waiting for the next run.',
                                review_required: 'Synchronization stopped for review. Check institutional settings.' };
                            card.append(node('p', {}, `Moodle course ${item.moodle_course_id}. ${messages[item.issue] || 'Review section setup.'}`));
                            if (item.members_needing_setup) card.append(node('p', { class: 'text-body-secondary' }, `${item.members_needing_setup} members still need setup. Review their account links and current SIS roles in the person workspace.`));
                            continue;
                        }
                        const course = node('input', { id: `moodle-course-${item.id}`, type: 'text', inputmode: 'numeric', class: 'form-control', required: true, maxlength: 19, autocomplete: 'off' });
                        const why = node('input', { id: `moodle-why-${item.id}`, type: 'text', class: 'form-control', required: true, maxlength: 500, autocomplete: 'off' });
                        const ack = node('input', { id: `moodle-course-ack-${item.id}`, type: 'checkbox', class: 'form-check-input', required: true });
                        const save = node('button', { type: 'submit', class: 'btn btn-primary mt-3' }, 'Confirm Moodle course link');
                        const linkForm = node('form', {}, node('label', { for: course.id, class: 'form-label' }, 'Moodle course ID'), course,
                            node('label', { for: why.id, class: 'form-label mt-2' }, 'Administrative reason'), why,
                            node('p', { class: 'form-text' }, 'Publish and select the section offering first. Enter no credentials or student details. The saved course link cannot be replaced here.'),
                            node('div', { class: 'form-check mt-2' }, ack, node('label', { for: ack.id, class: 'form-check-label' }, 'I independently verified that this Moodle course belongs to this SIS section.')), save);
                        card.append(linkForm);
                        linkForm.addEventListener('submit', event => {
                            event.preventDefault(); if (sectionBusy || !linkForm.reportValidity()) return;
                            task(async () => {
                                sectionBusy = true; save.disabled = true;
                                try {
                                    await request(`/sis/moodle/sections/${item.id}/link/`, 'POST', { course: course.value, reason: why.value, acknowledged: ack.checked });
                                    if (current !== version || panel.hidden) return;
                                    linkForm.remove(); card.append(node('p', { role: 'status' }, 'Course link saved. The worker will reconcile access when institutional synchronization is enabled.'));
                                } finally { sectionBusy = false; save.disabled = false; }
                            });
                        });
                    }
                } finally { sectionBusy = false; }
            });
        });
    }
    window.addEventListener('pagehide', leave);
    return { load, leave };
};
