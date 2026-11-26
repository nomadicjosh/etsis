/* Private person preferences, shared by workspace and portal. No browser persistence. */
'use strict';
window.createPersonNotificationPreferences = ({ node, request, labels, player }) => {
    const text = key => labels[key] || key;
    const icon = name => node('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const sounds = { tone: 'sound_tone', message: 'sound_message', warm: 'sound_warm', bird: 'sound_bird', piano: 'sound_piano' };
    let generation = 0;
    async function mount(host, endpoint, { expanded = true } = {}) {
        const open = node('button', { type: 'button', class: 'btn btn-outline-primary' }, icon('sliders'), text('preferences_edit'));
        open.addEventListener('click', () => show());
        const collapse = () => { generation++; player?.stopPreview(); host.replaceChildren(open); open.focus(); };
        async function show() {
            const turn = ++generation;
            const status = node('p', { class: 'form-text', role: 'status', tabindex: '-1' }, text('preferences_loading'));
            host.replaceChildren(status);
            try {
                let state = await request(endpoint);
                if (!host.isConnected || turn !== generation) return;
                const sound = node('input', { type: 'checkbox', class: 'form-check-input', id: 'person-notification-sound', checked: state.preferences.sound });
                const keep = node('input', { type: 'checkbox', class: 'form-check-input', id: 'person-notification-keep', checked: state.preferences.keep_visible });
                const choice = node('select', { class: 'form-select', id: 'person-notification-choice' },
                    Object.entries(sounds).map(([value, label]) => node('option', { value }, text(label))));
                choice.value = state.preferences.sound_id;
                const test = node('button', { type: 'button', class: 'btn btn-outline-secondary' }, icon('volume-up'), text('test_sound'));
                const save = node('button', { type: 'submit', class: 'btn btn-primary' }, icon('check2'), text('preferences_save'));
                const cancel = node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: collapse }, text('preferences_discard'));
                const form = node('form', {}, node('fieldset', { class: 'record-form-section' },
                    node('legend', {}, text('preferences')),
                    node('p', { class: 'form-text' }, text('preferences_help')),
                    node('div', { class: 'form-check mb-3' }, sound, node('label', { for: sound.id, class: 'form-check-label' }, text('sound'))),
                    node('div', { class: 'mb-3' }, node('label', { for: choice.id, class: 'form-label' }, text('sound_choice')), choice),
                    node('div', { class: 'form-check mb-3' }, keep, node('label', { for: keep.id, class: 'form-check-label' }, text('keep_visible')))),
                    node('div', { class: 'd-flex flex-wrap gap-2' }, test, save, cancel));
                const previewStatus = node('p', { class: 'form-text', role: 'status' });
                let previewTurn = 0;
                const stop = () => { previewTurn++; player?.stopPreview(); previewStatus.textContent = ''; test.disabled = false; };
                choice.addEventListener('change', stop); sound.addEventListener('change', stop);
                test.addEventListener('click', async () => {
                    const preview = ++previewTurn;
                    previewStatus.textContent = text('sound_loading'); test.disabled = true;
                    const played = await player?.previewSound(choice.value, () => host.isConnected && turn === generation && form.isConnected);
                    if (host.isConnected && turn === generation && preview === previewTurn) {
                        previewStatus.textContent = text(played ? 'sound_played' : 'sound_unavailable'); test.disabled = false;
                    }
                });
                form.addEventListener('submit', async event => {
                    event.preventDefault(); stop();
                    if (!form.reportValidity()) return;
                    form.querySelectorAll('button,input,select').forEach(control => { control.disabled = true; });
                    status.setAttribute('role', 'status'); status.textContent = text('preferences_saving');
                    try {
                        const result = await request(endpoint, 'POST', { revision: state.revision, preferences: {
                            sound: sound.checked, sound_id: choice.value, keep_visible: keep.checked,
                        } });
                        if (!host.isConnected || turn !== generation) return;
                        state = result;
                        if (result.own) player?.applyPreferences(result.preferences);
                        status.textContent = text('preferences_saved'); status.focus();
                    } catch (error) {
                        if (!host.isConnected || turn !== generation) return;
                        status.setAttribute('role', 'alert');
                        status.textContent = text(error.status === 409 ? 'preferences_changed' : 'preferences_failed');
                        if ([401, 403].includes(error.status)) form.remove();
                        status.focus();
                    } finally {
                        if (host.isConnected && turn === generation) form.querySelectorAll('button,input,select').forEach(control => { control.disabled = false; });
                    }
                });
                status.textContent = '';
                host.replaceChildren(form, previewStatus, status);
            } catch {
                if (host.isConnected && turn === generation) {
                    status.setAttribute('role', 'alert'); status.textContent = text('preferences_failed'); host.append(open);
                }
            }
        }
        if (expanded) await show(); else host.replaceChildren(open);
    }
    return { mount };
};
