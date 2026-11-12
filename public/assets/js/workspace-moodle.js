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
        core_webservice_get_site_info: 'Service information',
        core_user_get_users_by_field: 'Targeted user lookup',
        core_course_get_courses_by_field: 'Targeted course lookup',
        core_course_create_courses: 'Course creation',
        core_course_update_courses: 'Course updates',
        enrol_manual_enrol_users: 'Manual enrollment',
        enrol_manual_unenrol_users: 'Manual unenrollment',
        gradereport_user_get_grade_items: 'User grade items',
    };
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
            status.textContent = 'Site URL and REST connection verified. SSO and synchronization remain inactive.';
            const list = node('dl', { class: 'row mb-0' });
            for (const [name, label] of Object.entries(labels)) {
                list.append(node('dt', { class: 'col-8 mb-3' }, label,
                    node('span', { class: 'd-block fw-normal small text-break' }, name)),
                    node('dd', { class: 'col-4 mb-3' }, response.capabilities[name] === true ? 'Advertised' : 'Not advertised'));
            }
            results.append(node('h3', { class: 'h5', tabindex: '-1' }, 'Available service functions'),
                node('p', {}, 'Not advertised can be expected for a restricted service. Do not add permissions simply to make every function available. Discovery does not prove identity mappings or contextual access.'), list);
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
            installationStatus.textContent = failure;
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
            installationStatus.textContent = 'Installation approved. SSO and synchronization remain inactive.';
            installationStatus.focus();
        }, 'Approval could not be confirmed. Refresh saved details before reviewing again.'));
    });
    verify.addEventListener('click', () => task(() => installationAction(verify, async current => {
        installationStatus.textContent = 'Checking Moodle against the saved approval…';
        await request('/sis/moodle/installation/verify/', 'POST', {});
        if (current !== version || panel.hidden) return;
        installationStatus.textContent = 'The configured Moodle installation matches the saved approval. SSO and synchronization remain inactive.';
    }, 'The approved installation could not be verified. Check the institutional configuration; the saved approval has not been replaced.')));
    function leave() {
        version++;
        clearReview();
        reason.value = '';
    }
    window.addEventListener('pagehide', leave);
    return { load, leave };
};
