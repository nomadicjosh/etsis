/* Bounded, page-local action feedback. Browser storage contains preferences only. */
window.createWorkspaceNotifications = function (ui) {
    'use strict';
    const { node, stack, trigger, bootstrap, labels = {} } = ui;
    const text = (key, fallback) => labels[key] || fallback;
    const preferenceKey = 'etsis.workspace.notification-preferences';
    let preferences = { sound: false, keep_visible: false };
    try {
        const saved = JSON.parse(localStorage.getItem(preferenceKey));
        if (saved && Object.keys(saved).length === 2 && typeof saved.sound === 'boolean'
            && typeof saved.keep_visible === 'boolean') preferences = saved;
    } catch (_) { /* Storage may be disabled; this page still works. */ }
    const history = [], visible = new Map();
    let dialog = null, list = null, empty = null, audio = null, sequence = 0, lastSound = -Infinity;
    let disposed = false;
    const icons = { success: 'check-circle', info: 'info-circle', warning: 'exclamation-triangle' };
    const titles = { success: 'Completed', info: 'Update', warning: 'Needs attention' };
    const glyph = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const badge = trigger.querySelector('[data-notification-count]');
    function savePreferences() {
        try { localStorage.setItem(preferenceKey, JSON.stringify(preferences)); }
        catch (_) { /* Keep the same preferences in memory for this page. */ }
    }
    async function sound(preview = false) {
        if (!preferences.sound || document.hidden || disposed) return false;
        const now = performance.now();
        if (!preview && now - lastSound < 2000) return false;
        try {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (!Context) return false;
            audio ??= new Context();
            const current = audio;
            if (current.state === 'suspended') await current.resume();
            if (!preferences.sound || document.hidden || disposed || audio !== current || current.state !== 'running') return false;
            if (!preview && performance.now() - lastSound < 2000) return false;
            const start = current.currentTime, tone = current.createOscillator(), volume = current.createGain();
            tone.type = 'sine';
            tone.frequency.setValueAtTime(660, start);
            volume.gain.setValueAtTime(0, start);
            volume.gain.linearRampToValueAtTime(0.035, start + 0.01);
            volume.gain.exponentialRampToValueAtTime(0.0001, start + 0.13);
            tone.connect(volume); volume.connect(current.destination);
            tone.onended = () => { tone.disconnect(); volume.disconnect(); };
            tone.start(start); tone.stop(start + 0.14);
            lastSound = performance.now();
            return true;
        } catch (_) { return false; }
    }
    function stopSound() {
        const previous = audio; audio = null;
        if (previous && previous.state !== 'closed') Promise.resolve(previous.close()).catch(() => {});
    }
    function unlockSound() {
        if (!preferences.sound || audio || disposed) return;
        try {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (Context) { audio = new Context(); Promise.resolve(audio.resume()).catch(() => {}); }
        } catch (_) { /* Sound never blocks a campus action. */ }
    }
    function host() {
        const dialogs = [...document.querySelectorAll('dialog[open]')];
        return dialogs.at(-1) || document.querySelector('.modal.show')
            || document.querySelector('.offcanvas.show') || document.body;
    }
    function mount() {
        if (!visible.size || disposed) return;
        const parent = host();
        if (stack.parentElement !== parent) parent.append(stack);
    }
    const observer = new MutationObserver(mount);
    function remove(id) {
        const item = visible.get(id);
        if (!item) return;
        const focused = item.element.contains(document.activeElement);
        visible.delete(id);
        item.instance.dispose(); item.element.remove();
        if (!visible.size) observer.disconnect();
        if (focused) {
            const parent = host();
            const next = [...visible.values()].find(value => value.element.isConnected)?.element.querySelector('button');
            const previous = item.opener?.isConnected && item.opener !== document.body
                && !item.opener.disabled && parent.contains(item.opener)
                ? item.opener : null;
            (next || previous || (parent === document.body ? trigger : parent.querySelector('h2, [tabindex="-1"]')))?.focus();
        }
    }
    function configure(item) {
        item.instance?.dispose();
        item.instance = new bootstrap.Toast(item.element, {
            autohide: !preferences.keep_visible, delay: 8000,
            animation: false,
        });
        item.instance.show();
    }
    function renderHistory() {
        if (badge) { badge.textContent = String(history.length); badge.hidden = !history.length; }
        if (!list) return;
        empty.hidden = history.length > 0;
        list.replaceChildren(...history.map(item => node('li', { class: 'workspace-notification-entry' },
            node('div', { class: 'workspace-notification-entry-heading' },
                glyph(icons[item.tone]), node('strong', {}, text(item.tone, titles[item.tone])),
                node('time', { datetime: item.at.toISOString() }, item.at.toLocaleTimeString())),
            node('p', { class: 'mb-0 text-break' }, item.message))));
    }
    function notify(message, options = {}) {
        if (disposed || typeof message !== 'string' || !message.trim()) return;
        const tone = Object.hasOwn(icons, options?.tone) ? options.tone : 'success';
        const item = { id: ++sequence, message: message.slice(0, 1000), tone, at: new Date() };
        history.unshift(item); history.length = Math.min(history.length, 20);
        while (visible.size >= 5) remove([...visible.keys()].find(id =>
            !visible.get(id).element.contains(document.activeElement)) ?? visible.keys().next().value);
        const close = node('button', { type: 'button', class: 'btn-close',
            'aria-label': text('dismiss', 'Dismiss notification'), onclick: () => remove(item.id) });
        const element = node('section', { class: `toast workspace-notification-toast notification-${tone}`,
            'aria-label': text('notification', 'Workspace notification') },
            node('div', { class: 'toast-header' }, glyph(icons[tone]),
                node('strong', { class: 'me-auto' }, text(tone, titles[tone])), close),
            node('div', { class: 'toast-body text-break' }, item.message));
        const entry = { element, instance: null, opener: document.activeElement };
        element.addEventListener('hidden.bs.toast', () => remove(item.id), { once: true });
        visible.set(item.id, entry);
        mount(); stack.append(element); configure(entry);
        observer.observe(document.body, { childList: true });
        renderHistory();
        void sound();
    }
    function closeHistory() {
        if (!dialog) return;
        const previous = dialog;
        dialog = list = empty = null;
        previous.close(); previous.remove();
        stack.hidden = false;
        document.body.append(stack);
        stack.hidden = false;
        trigger.setAttribute('aria-expanded', 'false');
        if (trigger.isConnected && !document.querySelector('dialog[open], .modal.show, .offcanvas.show')) trigger.focus();
        mount();
    }
    function openHistory() {
        if (dialog || disposed) return;
        const heading = node('h2', { id: 'workspace-notifications-heading', class: 'h5 mb-0' },
            text('title', 'Notifications'));
        const close = node('button', { type: 'button', class: 'btn-close',
            'aria-label': text('close', 'Close notifications'), onclick: closeHistory });
        const soundControl = node('input', { type: 'checkbox', id: 'workspace-notification-sound',
            class: 'form-check-input', checked: preferences.sound });
        const keep = node('input', { type: 'checkbox', id: 'workspace-notification-keep',
            class: 'form-check-input', checked: preferences.keep_visible });
        const audioStatus = node('p', { class: 'form-text mb-0', role: 'status' });
        soundControl.addEventListener('change', () => {
            preferences.sound = soundControl.checked; savePreferences();
            if (preferences.sound) unlockSound(); else stopSound();
        });
        keep.addEventListener('change', () => {
            preferences.keep_visible = keep.checked; savePreferences();
            visible.forEach(configure);
        });
        const test = node('button', { type: 'button', class: 'btn btn-outline-primary btn-sm', onclick: async () => {
            if (!soundControl.checked) { soundControl.checked = preferences.sound = true; savePreferences(); }
            const played = await sound(true);
            if (audioStatus.isConnected) audioStatus.textContent = played ? '' :
                text('sound_unavailable', 'Sound is unavailable. Visual notifications will still appear.');
        } }, glyph('volume-up'), text('test_sound', 'Test notification sound'));
        list = node('ol', { class: 'workspace-notification-history list-unstyled mb-0' });
        empty = node('p', { class: 'text-secondary' },
            text('empty', 'There are no notifications from this workspace session.'));
        dialog = node('dialog', { class: 'workspace-notification-dialog',
            'aria-label': text('recent', 'Recent workspace notifications'),
            'aria-describedby': 'workspace-notification-help' },
            node('header', { class: 'workspace-notification-dialog-heading' }, heading, close),
            node('div', { class: 'workspace-notification-dialog-body' },
                node('p', { id: 'workspace-notification-help', class: 'text-secondary' }, text('session_help',
                    'Recent action feedback stays only in this page. Reloading or signing out clears it. Recorded decisions and background job status remain in their own workspaces.')),
                node('div', { class: 'workspace-notification-preferences' },
                    node('div', { class: 'form-check' }, soundControl,
                        node('label', { for: soundControl.id, class: 'form-check-label' },
                            text('sound', 'Play notification sounds'))),
                    node('div', { class: 'form-check' }, keep,
                        node('label', { for: keep.id, class: 'form-check-label' },
                            text('keep_visible', 'Keep notifications on screen until dismissed'))),
                    node('p', { class: 'form-text' }, text('preference_help',
                        'Only these display and sound preferences are saved in this browser. Notification messages and campus records are never saved here.')),
                    test, audioStatus),
                empty, list,
                node('button', { type: 'button', class: 'btn btn-outline-secondary mt-3', onclick: () => clear(false) },
                    glyph('trash'), text('clear', 'Clear recent notifications'))));
        dialog.addEventListener('cancel', event => { event.preventDefault(); closeHistory(); });
        const currentDialog = dialog;
        dialog.addEventListener('close', () => { if (dialog === currentDialog) closeHistory(); });
        stack.hidden = true;
        document.body.append(dialog); renderHistory(); dialog.showModal();
        stack.hidden = true;
        trigger.setAttribute('aria-expanded', 'true');
        close.focus(); mount();
    }
    function clear(close = true) {
        [...visible.keys()].forEach(remove); history.length = 0; renderHistory();
        stopSound();
        if (close) closeHistory();
    }
    function onPageHide() { clear(); stopSound(); }
    function destroy() {
        clear(); disposed = true; stopSound(); observer.disconnect();
        trigger.removeEventListener('click', openHistory);
        document.removeEventListener('shown.bs.offcanvas', mount);
        document.removeEventListener('hidden.bs.offcanvas', mount);
        document.removeEventListener('shown.bs.modal', mount);
        document.removeEventListener('hidden.bs.modal', mount);
        document.removeEventListener('close', mount, true);
        window.removeEventListener('pointerdown', unlockSound);
        window.removeEventListener('keydown', unlockSound);
        window.removeEventListener('pagehide', onPageHide);
    }
    trigger.addEventListener('click', openHistory);
    for (const event of ['shown.bs.offcanvas', 'hidden.bs.offcanvas', 'shown.bs.modal', 'hidden.bs.modal'])
        document.addEventListener(event, mount);
    document.addEventListener('close', mount, true);
    window.addEventListener('pointerdown', unlockSound);
    window.addEventListener('keydown', unlockSound);
    window.addEventListener('pagehide', onPageHide);
    renderHistory();
    return { notify, clear, destroy };
};
