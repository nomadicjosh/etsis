/* Private metadata dashboard. No credentials, record lookups, configuration changes or automatic checks. */
window.createWorkspaceMoodle = function (ui) {
    "use strict";
    const { node, request, task, can } = ui;
    const panel = document.querySelector('[data-panel="moodle"]');
    const status = document.querySelector('#moodle-status');
    const check = document.querySelector('#moodle-check');
    const refresh = document.querySelector('#moodle-refresh');
    const results = document.querySelector('#moodle-results');
    let version = 0, enabled = false, busy = false;
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
        check.disabled = !enabled || busy;
        status.textContent = enabled
            ? 'Enabled by your institution. No connection check has run in this screen yet.'
            : 'Moodle integration is disabled. Normal SIS workflows remain available.';
    }
    async function load() {
        const current = ++version;
        results.hidden = true;
        results.replaceChildren();
        enabled = false;
        check.disabled = true;
        if (!can('sis:moodle:manage')) {
            status.textContent = 'You do not have access to Moodle administration.';
            return;
        }
        status.textContent = 'Loading institutional status…';
        const response = await request('/sis/moodle/status/');
        if (version !== current || panel.hidden) return;
        state(response.enabled);
    }
    refresh.addEventListener('click', event => task(load, event.currentTarget));
    check.addEventListener('click', event => task(async () => {
        if (busy || !enabled || !can('sis:moodle:manage')) return;
        const current = version;
        busy = true;
        refresh.disabled = true;
        check.disabled = true;
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
            refresh.disabled = false;
            check.disabled = !enabled;
            check.removeAttribute("aria-busy");
        }
    }));
    return { load };
};
