/* Only the navigation layout enum is stored. Records and unsaved work stay in their existing owners. */
(() => {
    'use strict';
    const root = document.documentElement;
    const storageKey = 'etsis.workspace.navigation';
    const choices = ['sidebar', 'compact', 'horizontal'];
    let preference = 'sidebar';
    try {
        const saved = localStorage.getItem(storageKey);
        if (choices.includes(saved)) preference = saved;
    } catch (_) { /* Storage can be unavailable; navigation still works for this page. */ }
    root.dataset.workspaceNavigation = preference;

    function start() {
        const sidebar = document.getElementById('workspace-navigation');
        const controls = document.querySelector('.workspace-navigation-tools');
        const collapse = document.getElementById('workspace-sidebar-toggle');
        const picker = document.getElementById('workspace-layout-toggle');
        const messages = document.getElementById('workspace-navigation-labels');
        if (!sidebar || !controls || !collapse || !picker || !messages || !window.bootstrap) {
            root.dataset.workspaceNavigation = 'sidebar';
            return;
        }
        const labels = JSON.parse(messages.textContent);
        const media = matchMedia('(min-width: 992px)');
        const links = [...sidebar.querySelectorAll('.workspace-nav-link')];
        const groups = [...sidebar.querySelectorAll('.workspace-navigation-group')];
        const options = [...controls.querySelectorAll('[data-navigation-layout]')];
        const flyouts = new Map();
        let activeFlyout = null;
        let hideTimer = null;

        function hideFlyout() {
            clearTimeout(hideTimer);
            if (activeFlyout) flyouts.get(activeFlyout)?.hide();
            activeFlyout = null;
        }
        function scheduleHide(link) {
            clearTimeout(hideTimer);
            hideTimer = setTimeout(() => {
                if (activeFlyout !== link) return;
                const tip = document.getElementById(link.getAttribute('aria-describedby'));
                if (document.activeElement !== link && !link.matches(':hover') && !tip?.matches(':hover')) {
                    hideFlyout();
                }
            }, 120);
        }
        function showFlyout(link) {
            if (!flyouts.has(link)) return;
            clearTimeout(hideTimer);
            if (activeFlyout && activeFlyout !== link) flyouts.get(activeFlyout)?.hide();
            activeFlyout = link;
            flyouts.get(link).show();
        }
        function updateFlyouts() {
            hideFlyout();
            for (const tooltip of flyouts.values()) tooltip.dispose();
            flyouts.clear();
            if (preference !== 'compact' || !media.matches) return;
            for (const link of links) {
                flyouts.set(link, new bootstrap.Tooltip(link, {
                    title: link.querySelector('span')?.textContent || '',
                    trigger: 'manual', html: false, container: document.body,
                    placement: 'right', fallbackPlacements: ['right', 'left', 'bottom', 'top'],
                    customClass: 'workspace-navigation-flyout',
                    popperConfig: config => ({ ...config, strategy: 'fixed', modifiers: [
                        ...config.modifiers,
                        { name: 'preventOverflow', options: { boundary: 'viewport', rootBoundary: 'viewport', padding: 8 } }
                    ] })
                }));
            }
        }
        function updateGroups() {
            for (const group of groups) {
                const button = group.querySelector('.workspace-nav-group-toggle');
                button.classList.toggle('active', !!group.querySelector('.workspace-nav-link.active'));
            }
        }
        function updateHeight() {
            root.style.setProperty('--workspace-navigation-height', preference === 'horizontal' && media.matches
                ? `${sidebar.getBoundingClientRect().height}px` : '0px');
        }
        function apply() {
            const focused = document.activeElement;
            let restore = null;
            hideFlyout();
            for (const group of groups) {
                const button = group.querySelector('.workspace-nav-group-toggle');
                const menu = group.querySelector('.workspace-nav-group-items');
                if (button === focused || (menu.contains(focused)
                    && (!media.matches || preference === 'horizontal'))) restore = group;
                const dropdown = bootstrap.Dropdown.getInstance(button);
                dropdown?.hide();
                dropdown?.dispose();
                if (typeof menu.hidePopover === 'function' && menu.matches(':popover-open')) menu.hidePopover();
                menu.removeAttribute('popover');
                menu.removeAttribute('style');
                menu.removeAttribute('data-popper-placement');
                menu.classList.remove('workspace-floating-menu');
                menu.classList.toggle('dropdown-menu', preference === 'horizontal' && media.matches);
                if (preference === 'horizontal' && media.matches) button.dataset.bsToggle = 'dropdown';
                else button.removeAttribute('data-bs-toggle');
                for (const link of menu.querySelectorAll('.workspace-nav-link')) {
                    link.classList.toggle('dropdown-item', preference === 'horizontal' && media.matches);
                }
            }
            root.dataset.workspaceNavigation = preference;
            collapse.setAttribute('aria-expanded', String(preference !== 'compact'));
            collapse.setAttribute('aria-label', preference === 'compact' ? labels.expand : labels.collapse);
            collapse.title = preference === 'compact' ? labels.expand : labels.collapse;
            for (const option of options) {
                const selected = option.dataset.navigationLayout === preference;
                option.setAttribute('aria-checked', String(selected));
                option.querySelector('.layout-selected').hidden = !selected;
            }
            updateFlyouts();
            updateGroups();
            updateHeight();
            if (restore && (preference !== 'horizontal' || !media.matches)) {
                const target = media.matches ? restore.querySelector('.workspace-nav-link')
                    : document.querySelector('[data-bs-target="#workspace-navigation"][data-bs-toggle="offcanvas"]');
                target?.focus({ preventScroll: true });
            }
        }
        function choose(value) {
            if (!choices.includes(value)) return;
            preference = value;
            try { localStorage.setItem(storageKey, value); } catch (_) { /* Keep the page choice usable. */ }
            apply();
            document.getElementById('workspace-navigation-status').textContent = media.matches
                ? labels.selected.replace('{layout}', labels.layouts[value]) : labels.mobile;
        }
        collapse.addEventListener('click', () => choose(preference === 'compact' ? 'sidebar' : 'compact'));
        controls.addEventListener('click', event => {
            const option = event.target.closest('[data-navigation-layout]');
            if (!option) return;
            choose(option.dataset.navigationLayout);
            bootstrap.Dropdown.getInstance(picker)?.hide();
            picker.focus({ preventScroll: true });
        });
        for (const link of links) {
            link.addEventListener('pointerenter', () => showFlyout(link));
            link.addEventListener('pointerleave', () => scheduleHide(link));
            link.addEventListener('focus', () => showFlyout(link));
            link.addEventListener('blur', () => scheduleHide(link));
            link.addEventListener('click', hideFlyout);
            link.addEventListener('shown.bs.tooltip', () => {
                const tip = document.getElementById(link.getAttribute('aria-describedby'));
                tip?.addEventListener('pointerenter', () => clearTimeout(hideTimer), { once: true });
                tip?.addEventListener('pointerleave', () => scheduleHide(link), { once: true });
            });
        }
        document.addEventListener('keydown', event => { if (event.key === 'Escape') hideFlyout(); });
        for (const event of ['show.bs.offcanvas', 'show.bs.modal', 'show.bs.dropdown']) {
            document.addEventListener(event, hideFlyout);
        }
        const scrollArea = sidebar.querySelector('.workspace-menu');
        scrollArea.addEventListener('scroll', () => {
            if (!activeFlyout) return;
            const link = activeFlyout.getBoundingClientRect(), area = scrollArea.getBoundingClientRect();
            if (link.bottom <= area.top || link.top >= area.bottom) hideFlyout();
            else flyouts.get(activeFlyout)?.update();
        }, { passive: true });
        window.addEventListener('etsis:workspace-navigation', () => { hideFlyout(); updateGroups(); });
        media.addEventListener('change', apply);
        window.addEventListener('pagehide', hideFlyout);
        new ResizeObserver(updateHeight).observe(sidebar);
        collapse.hidden = false;
        controls.hidden = false;
        apply();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
