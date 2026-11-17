/* One private document editor for applicant and admissions routes. No file/body browser storage or inline previews. */
(() => {
    'use strict';
    let sequence = 0;
    window.createAdmissionDocuments = ({ node, request, task, notify }) => ({
        summary(container, evidence) {
            const section = node('section', { class: 'record-section' }, node('h4', { class: 'h6' }, 'Decision evidence'));
            if (evidence?.length) section.append(node('ul', {}, ...evidence.map(file => node('li', { class: 'text-break private-text' }, file.name))));
            else section.append(node('p', {}, evidence === null ? 'Earlier document evidence was not recorded.' : 'No uploaded files were selected for this decision.'));
            container.append(section);
        },
        evidence(container, application, selected = []) {
            const choices = new Map(selected.map(file => [file.id, { id: file.id, version: file.version }]));
            const content = node('div', { hidden: true, id: `decision-evidence-${++sequence}`, class: 'mt-2' });
            const status = node('p', { role: 'status', 'aria-atomic': 'true', class: 'form-text' });
            const toggle = node('button', { type: 'button', class: 'btn btn-outline-secondary',
                'aria-expanded': 'false', 'aria-controls': content.id }, 'Choose evidence');
            let generation = 0, loading = false, failed = false, files = null;
            const count = () => { status.textContent = `${choices.size} of 10 files selected. Confirmation retains these files as decision evidence.`; };
            async function load() {
                const ticket = ++generation; loading = true; failed = false; toggle.disabled = true;
                status.textContent = 'Loading evidence choices…';
                try {
                    const data = await request(`/sis/applications/${application}/documents/`);
                    if (!container.isConnected || ticket !== generation) return;
                    files = data.items;
                    const group = node('fieldset', {}, node('legend', { class: 'h6' }, 'Files used for this decision'));
                    group.append(node('p', { class: 'form-text' }, 'Choose available files you relied on, up to ten. A safety scan does not verify a document’s authenticity.'));
                    for (const file of files) {
                        const id = `evidence-choice-${++sequence}`, prior = choices.get(file.id);
                        const checkbox = node('input', { type: 'checkbox', id, class: 'form-check-input',
                            checked: !!prior, disabled: !file.can_select_evidence && !prior });
                        const row = node('div', { class: 'form-check mb-3' }, checkbox,
                            node('label', { for: id, class: 'form-check-label text-break' }, file.name));
                        if (!file.can_select_evidence) row.append(node('p', { class: 'form-text' }, 'Not available for a new decision.'));
                        if (prior && prior.version !== file.version) row.append(node('p', { class: 'form-text' }, 'File controls changed. Clear and reselect this file to review it again.'));
                        if (file.can_download) row.append(node('a', { href: `/sis/applications/${application}/documents/${file.id}/download/`,
                            class: 'btn btn-link btn-sm', download: '', 'aria-label': `Download ${file.name}` }, 'Download'));
                        checkbox.addEventListener('change', () => {
                            if (!checkbox.checked) choices.delete(file.id);
                            else if (file.can_select_evidence && choices.size < 10) choices.set(file.id, { id: file.id, version: file.version });
                            else { checkbox.checked = false; status.textContent = 'Choose up to ten available files.'; return; }
                            count();
                        });
                        group.append(row);
                    }
                    if (!files.length) group.append(node('p', {}, 'No uploaded documents are available.'));
                    if ([...choices.keys()].some(id => !files.some(file => file.id === id))) group.append(node('p', { class: 'alert alert-warning' }, 'An earlier selected file is no longer available. Clear the selection and choose again.'));
                    group.append(node('button', { type: 'button', class: 'btn btn-link', onclick: () => {
                        choices.clear(); group.querySelectorAll('input').forEach(input => { input.checked = false; }); count();
                    } }, 'Clear selection'));
                    content.replaceChildren(group); count();
                } catch (error) { failed = true; status.textContent = 'Evidence choices could not be loaded. Choose evidence to retry.'; throw error; }
                finally { loading = false; toggle.disabled = false; }
            }
            toggle.addEventListener('click', () => {
                if (loading) return;
                if (!content.hidden && !failed) { content.hidden = true; toggle.setAttribute('aria-expanded', 'false'); return; }
                content.hidden = false; toggle.setAttribute('aria-expanded', 'true'); task(load, toggle);
            });
            container.append(toggle, content, status); count();
            return { selection: () => {
                if (loading || failed) throw new Error('Load and review the evidence choices before continuing.');
                if (files && [...choices.values()].some(choice => !files.some(file => file.id === choice.id && file.version === choice.version && file.can_select_evidence))) {
                    throw new Error('Clear unavailable or changed evidence choices and select current files.');
                }
                return [...choices.values()].sort((a, b) => a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
            } };
        },
        mount(container, application, { staff = false, toggleTarget = null, label = '' } = {}) {
            const base = `${staff ? '/sis' : '/portal/api'}/applications/${application}/documents/`;
            const content = node('section', { hidden: true, class: 'mt-3', 'aria-label': 'Application documents' });
            const toggle = node('button', { type: 'button', class: 'btn btn-outline-primary',
                'aria-expanded': 'false', 'aria-controls': `admission-documents-${++sequence}` }, 'Documents');
            content.id = toggle.getAttribute('aria-controls');
            if (label) toggle.setAttribute('aria-label', `Documents for ${label}`);
            let serial = 0, expanded = false, pending = false, leave = () => true;
            const alive = ticket => ticket === serial && container.isConnected;
            async function load() {
                const ticket = ++serial;
                const data = await request(base);
                if (!alive(ticket)) return;
                draw(data, ticket);
            }
            function draw(data, ticket) {
                const status = node('p', { role: 'status', 'aria-atomic': 'true', class: 'form-text' });
                const controls = node('fieldset', {}, node('legend', { class: 'h5', tabindex: '-1' }, label ? `Application documents · ${label}` : 'Application documents'));
                const helpId = `document-help-${++sequence}`;
                const introduction = node('p', { id: helpId, class: 'form-text' }, 'PDF, JPEG and PNG · up to 5 MiB each. Files stay private and need a background safety scan before download. Admissions reviews the checklist separately.');
                controls.append(introduction);
                let fileInput = null; const drafts = [];
                let activeSettings = null, activeButton = null, controlEpoch = 0;
                leave = () => {
                    const changed = drafts.find(draft => draft.changed());
                    if (pending || fileInput?.files?.length || changed) {
                        status.textContent = pending ? 'Wait for the current document request to finish.' : 'Save or clear document changes before starting another task.';
                        if (changed) changed.focus(); else fileInput?.focus(); return false;
                    }
                    return true;
                };
                async function write(run) {
                    if (pending) return;
                    pending = true; controls.disabled = true; toggle.disabled = true;
                    try { await run(); } catch (error) {
                        if (alive(ticket)) status.textContent = 'We couldn’t finish this request. Your selections are still here.';
                        throw error;
                    } finally { pending = false; controls.disabled = false; toggle.disabled = false; }
                }
                if (data.enabled) {
                    const form = node('form'), id = `admission-document-file-${++sequence}`;
                    fileInput = node('input', { type: 'file', name: 'document', id, class: 'form-control',
                        accept: '.pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg', required: true, 'aria-describedby': helpId });
                    const upload = node('button', { type: 'submit', class: 'btn btn-primary mt-2' }, 'Upload document');
                    let uploadId = data.upload_id, originalFile = null, originalVisibility = null;
                    const visibilityId = `document-upload-visibility-${++sequence}`;
                    const uploadVisibility = staff && data.can_retain ? node('input', { type: 'checkbox', id: visibilityId, class: 'form-check-input' }) : null;
                    form.append(node('label', { for: id, class: 'form-label' }, 'Choose a document'), fileInput, upload,
                        node('button', { type: 'button', class: 'btn btn-link mt-2', onclick: () => { fileInput.value = ''; fileInput.focus(); } }, 'Clear selection'));
                    if (uploadVisibility) form.append(node('div', { class: 'form-check mt-2' }, uploadVisibility,
                        node('label', { for: visibilityId, class: 'form-check-label' }, 'Show uploaded file to applicant')));
                    if (staff) form.append(node('p', { class: 'form-text' }, 'Staff uploads start as staff-only. Records staff control applicant access.'));
                    form.addEventListener('submit', event => {
                        event.preventDefault();
                        const changed = drafts.find(draft => draft.changed());
                        if (changed) { status.textContent = 'Finish or clear retention changes before uploading.'; changed.focus(); return; }
                        const file = fileInput.files[0];
                        if (!file || !form.reportValidity()) return;
                        if (file.size > data.max_bytes) { status.textContent = 'Choose a document of up to 5 MiB.'; fileInput.focus(); return; }
                        task(() => write(async () => {
                            status.textContent = 'Uploading document…';
                            const visible = staff ? !!uploadVisibility?.checked : true;
                            if (originalFile && (originalFile !== file || originalVisibility !== visible)) uploadId = (await request(base)).upload_id;
                            originalFile = file; originalVisibility = visible;
                            const body = new FormData(); body.set('upload_id', uploadId); body.set('document', file); body.set('visible', visible ? '1' : '0');
                            await request(base, 'POST', body);
                            if (!alive(ticket)) return;
                            await load(); notify('Document uploaded. Scanning continues in the background.');
                        }), upload);
                    });
                    controls.append(form);
                } else controls.append(node('p', { class: 'alert alert-info' }, 'New uploads are not enabled. Follow the admissions office’s document-delivery instructions.'));
                const labels = { quarantined: 'Waiting for safety scan', scanning: 'Safety scan in progress', ready: 'Available',
                    rejected: 'Not available — contact admissions or upload a replacement', purging: 'Removal pending' };
                for (const item of data.items) {
                    const card = node('article', { class: 'border rounded p-3 mb-3' }, node('h3', { class: 'h6 private-text text-break' }, item.name),
                        node('p', {}, item.status === 'ready' && !item.can_download ? 'No longer available — contact admissions' : labels[item.status] || 'Contact admissions'),
                        node('p', { class: 'form-text' }, `Uploaded ${new Date(item.created_at).toLocaleDateString()}${item.bytes ? ' · ' + Math.ceil(item.bytes / 1024) + ' KiB' : ''}`));
                    if (item.can_download) card.append(node('a', { href: `${base}${item.id}/download/`, class: 'btn btn-outline-primary', download: '' }, 'Download'));
                    if (staff && (['ready', 'rejected'].includes(item.status) || (data.enabled && item.status === 'quarantined'))) card.append(node('button', {
                        type: 'button', class: 'btn btn-outline-secondary ms-2', onclick: event => { if (!leave()) return; task(() => write(async () => {
                            await request(`${base}${item.id}/rescan/`, 'POST', { version: item.version });
                            if (alive(ticket)) { await load(); notify('File quarantined for a fresh scan.'); }
                        }), event.currentTarget); },
                    }, item.status === 'quarantined' ? 'Retry scan' : 'Quarantine for recheck'));
                    if (staff && item.status !== 'purging') card.append(node('p', { class: 'form-text' }, item.applicant_visible ? 'Shared with applicant' : 'Staff only'));
                    if (staff && item.retained_evidence) card.append(node('p', { class: 'form-text' }, 'Used in an admissions decision. Ordinary file removal is unavailable.'));
                    if (staff) card.append(node('p', { class: 'form-text' }, `Retention ends ${item.expires_at.slice(0, 10)} UTC${item.held ? ' · Hold in place' : ''}`));
                    if (staff && item.retained_evidence) card.append(node('p', { class: 'form-text' }, 'Retained for an admissions decision. Ordinary file removal is unavailable.'));
                    if (data.can_retain && item.status !== 'purging') {
                        const settingsId = `document-controls-${++sequence}`;
                        const manage = node('button', { type: 'button', class: 'btn btn-outline-secondary ms-2',
                            'aria-expanded': 'false', 'aria-controls': settingsId, onclick: () => {
                            if (!leave()) return;
                            controlEpoch++; activeSettings?.remove(); activeButton?.setAttribute('aria-expanded', 'false');
                            drafts.splice(0); activeButton = manage; manage.setAttribute('aria-expanded', 'true');
                            const selection = controlEpoch;
                            const settings = node('details', { id: settingsId, class: 'mt-3', open: true }, node('summary', { class: 'fw-semibold' }, 'Access & retention'));
                            const form = node('form', { class: 'mt-2' });
                            const holdId = `document-hold-${++sequence}`, dateId = `document-date-${sequence}`;
                            const visibilityId = `document-share-${++sequence}`;
                            const visible = node('input', { type: 'checkbox', id: visibilityId, class: 'form-check-input', checked: item.applicant_visible });
                            const hold = node('input', { type: 'checkbox', id: holdId, class: 'form-check-input', checked: item.held });
                            const date = node('input', { type: 'date', id: dateId, class: 'form-control', min: item.expires_at.slice(0, 10) });
                            const save = node('button', { type: 'submit', class: 'btn btn-outline-primary mt-2' }, 'Save controls');
                            form.append(node('div', { class: 'form-check mb-2' }, visible, node('label', { for: visibilityId, class: 'form-check-label' }, 'Show file to applicant')),
                                node('div', { class: 'form-check mb-2' }, hold, node('label', { for: holdId, class: 'form-check-label' }, 'Keep under hold')),
                                node('label', { for: dateId, class: 'form-label' }, 'Extend through (UTC, optional)'), date, save);
                            form.addEventListener('submit', event => { event.preventDefault();
                                if (fileInput?.files?.length) { status.textContent = 'Upload or clear the selected file first.'; fileInput.focus(); return; }
                                if (!form.reportValidity()) return;
                                task(() => write(async () => { await request(`${base}${item.id}/retain/`, 'POST', {
                                    version: item.version, hold: hold.checked, until: date.value || null, visible: visible.checked });
                                    if (alive(ticket)) { await load(); notify('Document controls saved.'); }
                                }), save);
                            });
                            drafts.push({ changed: () => hold.checked !== item.held || !!date.value || visible.checked !== item.applicant_visible, focus: () => { settings.open = true; save.focus(); } });
                            settings.append(form);
                            if (item.can_purge) {
                                const disposal = node('form', { class: 'border-top mt-3 pt-3' });
                                const reasonId = `document-disposal-${++sequence}`;
                                const reason = node('textarea', { id: reasonId, class: 'form-control', required: true, maxlength: 1000, rows: 2 });
                                const review = node('button', { type: 'submit', class: 'btn btn-outline-danger mt-2' }, 'Review file removal');
                                const preview = node('section', { class: 'mt-2', 'aria-label': 'File removal review' });
                                let epoch = 0;
                                const invalidate = () => { epoch++; preview.replaceChildren(); };
                                reason.addEventListener('input', invalidate); hold.addEventListener('change', invalidate);
                                date.addEventListener('input', invalidate); visible.addEventListener('change', invalidate);
                                disposal.append(node('label', { for: reasonId, class: 'form-label' }, 'Reason for removal'), reason, review, preview);
                                disposal.addEventListener('submit', event => { event.preventDefault();
                                    if (hold.checked !== item.held || date.value || visible.checked !== item.applicant_visible || fileInput?.files?.length) {
                                        status.textContent = 'Save retention changes and upload or clear selected files before reviewing removal.'; return;
                                    }
                                    if (!disposal.reportValidity()) return;
                                    task(() => write(async () => {
                                        const captured = ++epoch;
                                        const reviewed = await request(`${base}${item.id}/purge-review/`, 'POST', { version: item.version, reason: reason.value });
                                        if (!alive(ticket) || captured !== epoch || selection !== controlEpoch) return;
                                        const confirm = node('button', { type: 'button', class: 'btn btn-danger', onclick: event => task(() => write(async () => {
                                            if (captured !== epoch || selection !== controlEpoch || fileInput?.files?.length) return;
                                            await request(`${base}${item.id}/purge/`, 'POST', { review_token: reviewed.review_token });
                                            if (alive(ticket)) { await load(); notify('File removal approved. Background cleanup will finish the removal.'); }
                                        }), event.currentTarget) }, 'Permanently remove file');
                                        preview.replaceChildren(node('p', { class: 'alert alert-warning' }, 'The expired file will become unavailable immediately. Background cleanup removes its encrypted contents. This cannot be undone.'), confirm);
                                    }), review);
                                });
                                drafts.push({ changed: () => !!reason.value.trim(), focus: () => { settings.open = true; reason.focus(); } });
                                settings.append(disposal);
                            }
                            settings.append(node('button', { type: 'button', class: 'btn btn-link mt-2', onclick: () => {
                                controlEpoch++; drafts.splice(0); settings.remove(); activeSettings = null;
                                manage.setAttribute('aria-expanded', 'false'); manage.focus();
                            } }, 'Discard changes'));
                            card.append(settings); activeSettings = settings; visible.focus();
                        } }, 'File controls');
                        card.append(manage);
                    }
                    controls.append(card);
                }
                if (!data.items.length) controls.append(node('p', {}, 'No documents have been uploaded.'));
                const refresh = node('button', { type: 'button', class: 'btn btn-outline-secondary', onclick: event => {
                    if (!leave()) return;
                    task(() => write(async () => { status.textContent = 'Refreshing documents…'; await load(); }), event.currentTarget);
                } }, 'Refresh documents');
                content.replaceChildren(controls, status, refresh);
                controls.querySelector('legend')?.focus();
            }
            toggle.addEventListener('click', () => {
                if (pending || (expanded && !leave())) return;
                expanded = !expanded; serial++; content.hidden = !expanded;
                toggle.setAttribute('aria-expanded', String(expanded));
                if (expanded) task(load, toggle); else content.replaceChildren();
            });
            if (toggleTarget) { toggleTarget.append(toggle); container.replaceChildren(content); }
            else container.replaceChildren(toggle, content);
            return { beforeLeave: () => !expanded || leave() };
        },
    });
})();
