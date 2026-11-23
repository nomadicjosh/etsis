/* Bootstrap owns toggling; nested menus escape clipping ancestors while open. */
(() => {
    'use strict';
    const records = new Map(), owners = new WeakMap();
    const menuFor = toggle => toggle.parentElement.querySelector(':scope > .dropdown-menu');
    const children = menu => [...menu.querySelectorAll(':scope > li > a, :scope > li > button, :scope > li > .site-navigation-heading > a, :scope > li > .site-navigation-heading > button')]
        .filter(control => !control.disabled && control.getClientRects().length);
    window.initializeSiteDropdowns = function (root = document) {
        if (!window.bootstrap?.Dropdown) return;
        for (const toggle of root.querySelectorAll('[data-bs-toggle="dropdown"]')) {
            if (records.has(toggle)) continue;
            const menu = menuFor(toggle); if (!menu) continue;
            const parentMenu = toggle.closest('.dropdown-menu');
            const record = { menu, parentMenu, placeholder: null };
            records.set(toggle, record); owners.set(menu, toggle);
            if (!menu.id) menu.id = 'site-popup-' + crypto.randomUUID();
            toggle.setAttribute('aria-controls', menu.id);
            const instance = bootstrap.Dropdown.getOrCreateInstance(toggle, { boundary: 'viewport',
                popperConfig: defaults => ({ ...defaults, strategy: 'fixed', placement: parentMenu ? 'right-start' : defaults.placement,
                    modifiers: [...(defaults.modifiers || []), { name: 'flip', options: {
                        fallbackPlacements: parentMenu ? ['left-start', 'bottom-start'] : ['top-start', 'bottom-end', 'top-end'] } },
                    { name: 'preventOverflow', options: { boundary: 'viewport', rootBoundary: 'viewport', padding: 12 } },
                    { name: 'applyStyles', enabled: true }] }) });
            toggle.addEventListener('show.bs.dropdown', () => {
                if (record.placeholder) return;
                record.placeholder = document.createComment('Nested Bootstrap menu');
                menu.before(record.placeholder); menu.classList.add('site-navigation-portal'); document.body.append(menu);
            });
            toggle.addEventListener('hide.bs.dropdown', () => {
                for (const [child, details] of records) {
                    if (details.parentMenu === menu) bootstrap.Dropdown.getInstance(child)?.hide();
                }
            });
            toggle.addEventListener('hidden.bs.dropdown', () => {
                if (record.placeholder) {
                    record.placeholder.replaceWith(menu); record.placeholder = null; menu.classList.remove('site-navigation-portal');
                }
            });
            toggle.addEventListener('keydown', event => {
                if (!['ArrowRight', 'ArrowDown', 'ArrowUp'].includes(event.key)) return;
                event.preventDefault(); event.stopPropagation(); instance.show();
                const controls = children(menu); (event.key === 'ArrowUp' ? controls.at(-1) : controls[0])?.focus();
            });
            menu.addEventListener('click', event => {
                event.stopPropagation();
                const child = event.target.closest('[data-bs-toggle="dropdown"]');
                if (child && records.has(child)) {
                    event.preventDefault(); bootstrap.Dropdown.getOrCreateInstance(child).toggle();
                }
            });
        }
        for (const [toggle, record] of records) {
            if (toggle.isConnected) continue;
            bootstrap.Dropdown.getInstance(toggle)?.dispose(); record.menu.remove(); record.placeholder?.remove(); records.delete(toggle);
        }
    };
    document.addEventListener('keydown', event => {
        const menu = event.target.closest('.dropdown-menu.show'), toggle = menu && owners.get(menu);
        if (!toggle || !['ArrowDown', 'ArrowUp', 'Home', 'End', 'ArrowLeft', 'Escape'].includes(event.key)) return;
        event.preventDefault(); event.stopPropagation();
        if (['Escape', 'ArrowLeft'].includes(event.key)) {
            bootstrap.Dropdown.getOrCreateInstance(toggle).hide(); toggle.focus(); return;
        }
        const controls = children(menu), current = controls.indexOf(document.activeElement);
        const index = event.key === 'Home' ? 0 : event.key === 'End' ? controls.length - 1
            : event.key === 'ArrowDown' ? (current + 1) % controls.length : (current - 1 + controls.length) % controls.length;
        controls[index]?.focus();
    }, true);
    document.addEventListener('DOMContentLoaded', () => window.initializeSiteDropdowns());
    window.addEventListener('pagehide', () => {
        for (const [toggle, record] of records) {
            bootstrap.Dropdown.getInstance(toggle)?.dispose(); if (record.placeholder) record.menu.remove();
        }
        records.clear();
    });
})();
