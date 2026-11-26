/* Bounded action feedback with person-owned server preferences. No notification data enters browser storage. */
window.createWorkspaceNotifications = function (ui) {
    'use strict';
    const { node, stack, trigger, bootstrap, labels = {}, importLabels = {}, request } = ui;
    const text = (key, fallback) => labels[key] || fallback;
    const sounds = Object.freeze({
        tone: null,
        message: '/assets/sounds/message-notification-4.wav',
        warm: '/assets/sounds/warm-success-notification.wav',
        bird: '/assets/sounds/bird_notification.mp3',
        piano: '/assets/sounds/piano-notification-5b.mp3',
    });
    let preferences = { sound: false, keep_visible: false, sound_id: 'tone' };
    try { localStorage.removeItem('etsis.workspace.notification-preferences'); } catch (_) { /* No browser preference store. */ }
    const history = [], visible = new Map();
    let dialog = null, list = null, empty = null, audio = null, sequence = 0, lastSound = -Infinity;
    let disposed = false;
    let announcements = null, registrationUpdates = null, advisingUpdates = null, academicUpdates = null, transcriptUpdates = null, standingUpdates = null, importUpdates = null;
    let persistentUnread = 0, importTimer = null, importLoading = false, importStopped = false, identityKnown = false;
    let refreshAgain = false;
    let activeSound = null, pendingSound = null, decodedSound = null, soundGeneration = 0;
    const icons = { success: 'check-circle', info: 'info-circle', warning: 'exclamation-triangle' };
    const titles = { success: 'Completed', info: 'Update', warning: 'Needs attention' };
    const glyph = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const badge = trigger.querySelector('[data-notification-count]');
    let preferenceGeneration = 0;
    function applyPreferences(values) {
        if (!values || typeof values.sound !== 'boolean' || typeof values.keep_visible !== 'boolean'
            || !Object.hasOwn(sounds, values.sound_id)) return false;
        preferenceGeneration++; stopSound();
        preferences = { sound: values.sound, sound_id: values.sound_id, keep_visible: values.keep_visible };
        visible.forEach(configure); return true;
    }
    async function loadPreferences() {
        if (!request || disposed) return;
        identityKnown = true; void refreshPrivateCounts();
        const turn = ++preferenceGeneration;
        try {
            const data = await request('/portal/api/notification-preferences/');
            if (!disposed && !importStopped && turn === preferenceGeneration) applyPreferences(data.preferences);
        } catch (_) { /* Defaults remain quiet if this private preference read is unavailable. */ }
    }
    async function sound(preview = false, selected = null, canPlay = () => true) {
        if ((!preview && !preferences.sound) || document.hidden || disposed || !canPlay()) return false;
        const now = performance.now();
        if (!preview && (activeSound || now - lastSound < 2000)) return false;
        if (preview) { ++soundGeneration; stopPlayback(); }
        const generation = soundGeneration, selection = preview ? selected : preferences.sound_id;
        if (!Object.hasOwn(sounds, selection)) return false;
        const currentRequest = () => (preview || preferences.sound) && !document.hidden && !disposed && canPlay()
            && soundGeneration === generation && (preview || preferences.sound_id === selection);
        try {
            const Context = window.AudioContext || window.webkitAudioContext;
            if (!Context) return false;
            audio ??= new Context();
            const current = audio;
            if (current.state === 'suspended') await current.resume();
            if (!currentRequest() || audio !== current || current.state !== 'running') return false;
            const buffer = selection === 'tone' ? null : await loadSound(current, selection);
            if (!currentRequest() || audio !== current || current.state !== 'running') return false;
            if (!preview && (activeSound || performance.now() - lastSound < 2000)) return false;
            const start = current.currentTime, volume = current.createGain();
            const tone = buffer ? current.createBufferSource() : current.createOscillator();
            if (buffer) {
                tone.buffer = buffer;
                volume.gain.setValueAtTime(0.3, start);
            } else {
                tone.type = 'sine';
                tone.frequency.setValueAtTime(660, start);
                volume.gain.setValueAtTime(0, start);
                volume.gain.linearRampToValueAtTime(0.035, start + 0.01);
                volume.gain.exponentialRampToValueAtTime(0.0001, start + 0.13);
            }
            tone.connect(volume); volume.connect(current.destination);
            activeSound = tone;
            tone.onended = () => {
                tone.disconnect(); volume.disconnect();
                if (activeSound === tone) activeSound = null;
            };
            tone.start(start); tone.stop(start + (buffer ? Math.min(buffer.duration, 8) : 0.14));
            lastSound = performance.now();
            return true;
        } catch (_) { return false; }
    }
    async function loadSound(current, selection) {
        if (decodedSound?.context === current && decodedSound.selection === selection) return decodedSound.buffer;
        if (pendingSound?.context === current && pendingSound.selection === selection) return pendingSound.promise;
        pendingSound?.controller.abort();
        const controller = new AbortController();
        const pending = { context: current, selection, controller, promise: null };
        const timeout = setTimeout(() => controller.abort(), 8000);
        pending.promise = (async () => {
            try {
                const response = await fetch(sounds[selection], {
                    credentials: 'omit', redirect: 'error', signal: controller.signal,
                });
                if (!response.ok) throw new Error('Notification sound unavailable.');
                const bytes = await response.arrayBuffer();
                if (bytes.byteLength > 2 * 1024 * 1024 || controller.signal.aborted) throw new Error('Invalid sound.');
                const buffer = await current.decodeAudioData(bytes);
                if (controller.signal.aborted || audio !== current) throw new Error('Sound cancelled.');
                decodedSound = { context: current, selection, buffer };
                return buffer;
            } finally {
                clearTimeout(timeout);
                if (pendingSound === pending) pendingSound = null;
            }
        })();
        pendingSound = pending;
        return pending.promise;
    }
    function stopPlayback() {
        const previous = activeSound; activeSound = null;
        try { previous?.stop(); } catch (_) { /* Already ended. */ }
    }
    function stopSound() {
        ++soundGeneration; stopPlayback();
        pendingSound?.controller.abort(); pendingSound = decodedSound = null;
        const previous = audio; audio = null;
        if (previous && previous.state !== 'closed') Promise.resolve(previous.close()).catch(() => {});
    }
    function onVisibilityChange() {
        if (document.hidden) { stopSound(); clearTimeout(importTimer); }
        else refreshPrivateCounts();
    }
    function updateBadge() {
        const count = history.length + persistentUnread;
        if (badge) { badge.textContent = String(count); badge.hidden = !count; }
    }
    async function refreshPrivateCounts() {
        clearTimeout(importTimer);
        if (!request || !identityKnown || disposed || importStopped || document.hidden) return;
        if (importLoading) { refreshAgain = true; return; }
        importLoading = true;
        try {
            let count = 0;
            const endpoints = ['/portal/api/academic-updates/', '/portal/api/transcript-updates/', '/portal/api/standing-updates/', ...(importLabels.notices ? ['/sis/imports/notices/'] : [])];
            for (const endpoint of endpoints) {
                try {
                    const result = await request(endpoint);
                    if (Array.isArray(result.items)) count += result.items.length;
                } catch (_) { /* One unavailable source does not hide another source's notices. */ }
            }
            if (!disposed && !importStopped) { persistentUnread = count; updateBadge(); }
        } catch (_) { /* Existing private notifications remain available when the server recovers. */ }
        finally {
            importLoading = false;
            if (refreshAgain) { refreshAgain = false; void refreshPrivateCounts(); }
            else if (!disposed && !importStopped && !document.hidden) importTimer = setTimeout(refreshPrivateCounts, 60000);
        }
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
            const target = next || previous || (parent === document.body ? trigger : parent.querySelector('h2, [tabindex="-1"]'));
            if (target?.matches('h2') && !target.hasAttribute('tabindex')) target.tabIndex = -1;
            target?.focus();
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
        updateBadge();
        if (!list) return;
        empty.hidden = history.length > 0;
        list.replaceChildren(...history.map(item => node('li', { class: 'workspace-notification-entry' },
            node('div', { class: 'workspace-notification-entry-heading' },
                glyph(icons[item.tone]), node('strong', {}, text(item.tone, titles[item.tone])),
                node('time', { datetime: item.at.toISOString() }, item.at.toLocaleTimeString())),
            node('p', { class: 'mb-0 text-break' }, item.message),
            node('button', { type: 'button', class: 'btn btn-outline-danger btn-sm mt-2', onclick: () => {
                remove(item.id); const index = history.findIndex(row => row.id === item.id);
                if (index >= 0) history.splice(index, 1); renderHistory();
                (list.querySelector('button') || dialog?.querySelector('.btn-close'))?.focus();
            } }, glyph('trash'), text('delete_notification', 'Delete notification')))));
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
        announcements?.destroy(); announcements = null;
        registrationUpdates?.destroy(); registrationUpdates = null;
        advisingUpdates?.destroy(); advisingUpdates = null;
        academicUpdates?.destroy(); academicUpdates = null;
        transcriptUpdates?.destroy(); transcriptUpdates = null;
        standingUpdates?.destroy(); standingUpdates = null;
        importUpdates?.destroy(); importUpdates = null;
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
                empty, list,
                node('button', { type: 'button', class: 'btn btn-outline-secondary mt-3', onclick: () => clear(false) },
                    glyph('trash'), text('clear', 'Clear recent notifications'))));
        dialog.addEventListener('cancel', event => { event.preventDefault(); closeHistory(); });
        const currentDialog = dialog;
        if (request) {
            const body = node('div', { class: 'campus-announcements-body' });
            const section = node('details', { class: 'campus-announcements' },
                node('summary', {}, glyph('megaphone'), text('announcements', 'Campus announcements')), body);
            section.addEventListener('toggle', () => {
                if (dialog !== currentDialog) return;
                announcements?.destroy(); announcements = null;
                if (section.open && dialog === currentDialog) {
                    announcements = window.createCampusAnnouncements({ node, request, labels, notify, notificationsOnly: true });
                    announcements.mount(body);
                }
            });
            currentDialog.querySelector('.workspace-notification-dialog-body').prepend(section);
            if (window.createImportUpdates && importLabels.notices) {
                const importBody = node('div', { class: 'campus-announcements-body' });
                const importSection = node('details', { class: 'campus-announcements' },
                    node('summary', {}, glyph('cloud-check'), importLabels.notices), importBody);
                importSection.addEventListener('toggle', () => {
                    if (dialog !== currentDialog) return;
                    importUpdates?.destroy(); importUpdates = null;
                    if (importSection.open) {
                        importUpdates = window.createImportUpdates({ node, request, labels: importLabels, changed: refreshPrivateCounts, notificationsOnly: true });
                        importUpdates.mount(importBody);
                    }
                });
                currentDialog.querySelector('.workspace-notification-dialog-body').prepend(importSection);
            }
            if (window.createStudentUpdates) {
                const transcriptBody = node('div', { class: 'campus-announcements-body' });
                const transcriptSection = node('details', { class: 'campus-announcements' },
                    node('summary', {}, glyph('file-earmark-text'), text('transcript_updates', 'Transcript updates')), transcriptBody);
                transcriptSection.addEventListener('toggle', () => {
                    if (dialog !== currentDialog) return;
                    transcriptUpdates?.destroy(); transcriptUpdates = null;
                    if (transcriptSection.open) {
                        transcriptUpdates = window.createStudentUpdates({ node, request, labels, notify,
                            kind: 'transcript', notificationsOnly: true, changed: refreshPrivateCounts });
                        transcriptUpdates.mount(transcriptBody);
                    }
                });
                currentDialog.querySelector('.workspace-notification-dialog-body').prepend(transcriptSection);
                const academicBody = node('div', { class: 'campus-announcements-body' });
                const standingBody = node('div', { class: 'campus-announcements-body' });
                const standingSection = node('details', { class: 'campus-announcements' },
                    node('summary', {}, glyph('award'), text('standing_updates')), standingBody);
                standingSection.addEventListener('toggle', () => {
                    if (dialog !== currentDialog) return;
                    standingUpdates?.destroy(); standingUpdates = null;
                    if (standingSection.open) {
                        standingUpdates = window.createStudentUpdates({ node, request, labels, notify,
                            kind: 'standing', notificationsOnly: true, changed: refreshPrivateCounts });
                        standingUpdates.mount(standingBody);
                    }
                });
                currentDialog.querySelector('.workspace-notification-dialog-body').prepend(standingSection);
                const academicSection = node('details', { class: 'campus-announcements' },
                    node('summary', {}, glyph('mortarboard'), text('academic_updates', 'Academic updates')), academicBody);
                academicSection.addEventListener('toggle', () => {
                    if (dialog !== currentDialog) return;
                    academicUpdates?.destroy(); academicUpdates = null;
                    if (academicSection.open) {
                        academicUpdates = window.createStudentUpdates({ node, request, labels, notify,
                            kind: 'academic', notificationsOnly: true, changed: refreshPrivateCounts });
                        academicUpdates.mount(academicBody);
                    }
                });
                currentDialog.querySelector('.workspace-notification-dialog-body').prepend(academicSection);

                const updatesBody = node('div', { class: 'campus-announcements-body' });
                const updatesSection = node('details', { class: 'campus-announcements' },
                    node('summary', {}, glyph('person-check'), text('registration_updates', 'Registration updates')), updatesBody);
                updatesSection.addEventListener('toggle', () => {
                    if (dialog !== currentDialog) return;
                    registrationUpdates?.destroy(); registrationUpdates = null;
                    if (updatesSection.open) {
                        registrationUpdates = window.createStudentUpdates({ node, request, labels, notify, notificationsOnly: true });
                        registrationUpdates.mount(updatesBody);
                    }
                });
                currentDialog.querySelector('.workspace-notification-dialog-body').prepend(updatesSection);
                const advisingBody = node('div', { class: 'campus-announcements-body' });
                const advisingSection = node('details', { class: 'campus-announcements' },
                    node('summary', {}, glyph('chat-left-text'), text('advising_updates', 'Advising updates')), advisingBody);
                advisingSection.addEventListener('toggle', () => {
                    if (dialog !== currentDialog) return;
                    advisingUpdates?.destroy(); advisingUpdates = null;
                    if (advisingSection.open) {
                        advisingUpdates = window.createStudentUpdates({ node, request, labels, notify, kind: 'advising', notificationsOnly: true });
                        advisingUpdates.mount(advisingBody);
                    }
                });
                currentDialog.querySelector('.workspace-notification-dialog-body').prepend(advisingSection);
            }

        }
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
    function onPageShow() { importStopped = false; refreshPrivateCounts(); }
    function onPageHide() { importStopped = true; clearTimeout(importTimer); clear(); stopSound(); }
    function destroy() {
        clearTimeout(importTimer); clear(); disposed = true; stopSound(); observer.disconnect();
        trigger.removeEventListener('click', openHistory);
        document.removeEventListener('shown.bs.offcanvas', mount);
        document.removeEventListener('hidden.bs.offcanvas', mount);
        document.removeEventListener('shown.bs.modal', mount);
        document.removeEventListener('hidden.bs.modal', mount);
        document.removeEventListener('close', mount, true);
        window.removeEventListener('pointerdown', unlockSound);
        window.removeEventListener('keydown', unlockSound);
        window.removeEventListener('pagehide', onPageHide);
        window.removeEventListener('pageshow', onPageShow);
        document.removeEventListener('visibilitychange', onVisibilityChange);
        document.removeEventListener('hide.bs.offcanvas', stopSound);
        document.removeEventListener('hide.bs.modal', stopSound);
    }
    refreshPrivateCounts();
    trigger.addEventListener('click', openHistory);
    for (const event of ['shown.bs.offcanvas', 'hidden.bs.offcanvas', 'shown.bs.modal', 'hidden.bs.modal'])
        document.addEventListener(event, mount);
    document.addEventListener('close', mount, true);
    window.addEventListener('pointerdown', unlockSound);
    window.addEventListener('keydown', unlockSound);
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', onPageShow);
    document.addEventListener('visibilitychange', onVisibilityChange);
    document.addEventListener('hide.bs.offcanvas', stopSound);
    document.addEventListener('hide.bs.modal', stopSound);
    renderHistory();
    return { notify, clear, destroy, applyPreferences, loadPreferences,
        previewSound: (selection, canPlay) => sound(true, selection, canPlay), stopPreview: stopSound };
};
