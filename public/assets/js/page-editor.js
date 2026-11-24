/* Explain Vihzhuo's native edit boundaries; do not change component permissions or serialization. */
(() => {
    'use strict';
    const header = document.getElementById('page-editor-header');
    if (!header) return;
    const labels = JSON.parse(document.getElementById('page-editor-labels').textContent);
    const guidance = document.getElementById('page-editor-guidance');
    document.title = `${labels.page} · ${document.querySelector('#page-editor-header h1 span').textContent}`;
    const t = key => labels[key] || key;
    function initialize() {
        const editor = window.editor;
        if (!editor || header.dataset.ready) return;
        header.dataset.ready = 'true';
        const actions = document.getElementById('page-editor-actions');
        const save = document.getElementById('save-page');
        if (save) {
            const glyph = document.createElement('i');
            glyph.className = 'bi bi-check2'; glyph.setAttribute('aria-hidden', 'true');
            const spinner = save.querySelector('.spinner-border');
            save.replaceChildren(...(spinner ? [spinner] : []), glyph, document.createTextNode(` ${t('save')}`));
            actions.append(save);
        }
        const publicLink = document.getElementById('view-page');
        if (publicLink) {
            publicLink.rel = 'noopener noreferrer';
            publicLink.setAttribute('aria-label', t('view'));
        }
        document.getElementById('go-back')?.remove();
        const sizes = document.getElementById('sidebar-top-device');
        sizes?.setAttribute('role', 'group'); sizes?.setAttribute('aria-label', t('devices'));
        for (const [id, key] of [['set-dekstop-view', 'desktop'], ['set-tablet-view', 'tablet'], ['set-mobile-view', 'mobile']]) {
            const control = document.getElementById(id);
            if (!control) continue;
            control.setAttribute('role', 'button'); control.tabIndex = 0;
            control.setAttribute('aria-label', t(key)); control.title = t(key);
            control.addEventListener('keydown', event => {
                if (['Enter', ' '].includes(event.key)) { event.preventDefault(); control.click(); }
            });
        }
        const toggle = document.getElementById('toggle-sidebar');
        toggle?.setAttribute('aria-label', t('sidebar'));
        const search = document.querySelector('#block-search input');
        search?.setAttribute('aria-label', t('search'));
        function explain(component) {
            if (!component) { guidance.textContent = t('choose'); return; }
            let current = component;
            for (let depth = 0; current && depth < 100; depth++, current = current.parent()) {
                const attributes = current.getAttributes();
                if (Object.hasOwn(attributes, 'phpb-content-container') || Object.hasOwn(attributes, 'phpb-blocks-container')) {
                    guidance.textContent = t('container'); return;
                }
                if (current.get('block-slug')) {
                    guidance.textContent = t(current.get('is-html') === 'true' ? 'html' : 'dynamic'); return;
                }
            }
            guidance.textContent = t('choose');
        }
        editor.on('component:selected', explain);
        editor.on('component:deselected', () => explain(editor.getSelected()));
        let frame = null;
        const resize = () => {
            if (frame !== null) return;
            frame = requestAnimationFrame(() => {
                frame = null;
                document.documentElement.style.setProperty('--page-editor-header-height', `${header.offsetHeight}px`);
                editor.trigger('change:canvasOffset canvasScroll');
            });
        };
        new ResizeObserver(resize).observe(header);
        resize(); explain(editor.getSelected());
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
