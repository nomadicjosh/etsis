/* Admissions working data: private notes never enter portal DTOs or browser storage. */
(() => {
    'use strict';
    const definitionKeys = ['key', 'label', 'instructions', 'needed', 'visible'];
    window.admissionChecklistDefinition = items => items.map(item => Object.fromEntries(definitionKeys.map(key => [key, item[key]])));
    window.mergeAdmissionChecklist = async (current, definitions, presetId) => {
        const result = current.map(item => ({ ...item }));
        const seen = new Set(result.map(item => item.key));
        for (const item of definitions) {
            const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(`${presetId}:${item.key}`));
            const key = 'tpl_' + [...new Uint8Array(digest)].map(byte => byte.toString(16).padStart(2, '0')).join('').slice(0, 32);
            if (!seen.has(key)) {
                result.push({ ...item, key, status: 'pending', due_on: '' }); seen.add(key);
            }
        }
        if (result.length > 30) throw new Error('This preset would exceed thirty checklist items. Remove unused items first.');
        return result;
    };
    let sequence = 0;
    window.createAdmissionCasework = ({ node, request, task, notify }) => {
        const button = (text, run, style = 'btn-outline-primary') => node('button', {
            type: 'button', class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
        }, text);
        const field = (form, name, label, value = '', type = 'text', maximum = 191) => {
            const id = `casework-${++sequence}`;
            const input = node(type === 'textarea' ? 'textarea' : 'input', { id, name, type: type === 'textarea' ? null : type,
                class: 'form-control', value, maxlength: maximum, ...(type === 'textarea' ? { rows: '3' } : {}) });
            form.append(node('div', { class: 'mb-3' }, node('label', { for: id, class: 'form-label' }, label), input));
            return input;
        };
        const check = (form, name, label, checked) => {
            const id = `casework-${++sequence}`;
            const input = node('input', { id, name, type: 'checkbox', class: 'form-check-input', checked });
            form.append(node('div', { class: 'form-check mb-3' }, input, node('label', { for: id, class: 'form-check-label' }, label)));
            return input;
        };
        const select = (form, name, label, selected, choices) => {
            const id = `casework-${++sequence}`;
            const input = node('select', { id, name, class: 'form-select' }, choices.map(([value, text]) => node('option', { value }, text)));
            input.value = selected;
            form.append(node('div', { class: 'mb-3' }, node('label', { for: id, class: 'form-label' }, label), input));
            return input;
        };
        function itemEditor(target, initial, definition = false, prefix = '') {
            let items = initial.map(item => ({ ...item }));
            let controls = [];
            const list = node('div');
            const status = node('p', { class: 'form-text', role: 'status' });
            const add = node('button', { type: 'button', class: 'btn btn-outline-primary', onclick: () => {
                items = read();
                if (items.length >= 30) return;
                const key = 'item_' + crypto.randomUUID().replaceAll('-', '');
                items.push({ key, label: '', instructions: '', needed: true, visible: true, status: 'pending', due_on: '' });
                draw(key);
            } }, 'Add item');
            const read = () => controls.map(row => ({ key: row.key, label: row.label.value, instructions: row.instructions.value,
                needed: row.needed.checked, visible: row.visible.checked,
                ...(!definition ? { status: row.status.value, due_on: row.due_on.value } : {}) }));
            function draw(focus = null) {
                list.replaceChildren(); controls = [];
                items.forEach((item, index) => {
                    const card = node('details', { class: 'border rounded p-3 mb-3', open: !item.label || focus === item.key });
                    const summary = node('summary', { class: 'fw-semibold mb-3' }, item.label || `Checklist item ${index + 1}`);
                    card.append(summary);
                    const base = `${prefix}items.${index}.`;
                    const row = { key: item.key };
                    row.label = field(card, base + 'label', 'Item', item.label); row.label.required = true;
                    row.label.addEventListener('input', () => { summary.textContent = row.label.value.trim() || `Checklist item ${index + 1}`; });
                    row.instructions = field(card, base + 'instructions', 'Instructions', item.instructions, 'textarea', 1000);
                    row.needed = check(card, base + 'needed', 'Needed to finish casework', item.needed);
                    row.visible = check(card, base + 'visible', 'Show item and instructions to applicant', item.visible);
                    if (!definition) {
                        row.status = select(card, base + 'status', 'Progress', item.status,
                            [['pending', 'Pending'], ['received', 'Received'], ['complete', 'Complete'], ['not_needed', 'Not needed']]);
                        row.due_on = field(card, base + 'due_on', 'Due on', item.due_on, 'date');
                    }
                    const actions = node('div', { class: 'd-flex flex-wrap gap-2' });
                    for (const [text, delta] of [['Move up', -1], ['Move down', 1]]) {
                        const move = button(text, () => {
                            items = read(); [items[index], items[index + delta]] = [items[index + delta], items[index]]; draw(item.key);
                        }, 'btn-sm btn-outline-secondary');
                        move.disabled = index + delta < 0 || index + delta >= items.length;
                        move.setAttribute('aria-label', `${text}: ${item.label || 'checklist item ' + (index + 1)}`); actions.append(move);
                    }
                    const remove = button('Remove', () => { items = read(); items.splice(index, 1); draw(); add.focus(); }, 'btn-sm btn-outline-danger');
                    remove.setAttribute('aria-label', `Remove ${item.label || 'checklist item ' + (index + 1)}`);
                    actions.append(remove); card.append(actions); list.append(card); controls.push(row);
                });
                if (!items.length) list.append(node('p', { class: 'form-text' }, 'No checklist items or follow-up tasks yet.'));
                add.disabled = items.length >= 30; status.textContent = `${items.length} of 30 items`;
                if (focus) controls.find(row => row.key === focus)?.label.focus();
            }
            target.append(list, status, add); draw();
            return { read, replace(values) { items = values.map(item => ({ ...item })); draw(); },
                expand() { list.querySelectorAll('details').forEach(card => { card.open = true; }); } };
        }
        function mount(container, applicationId, { managePresets = false } = {}) {
            const identity = `casework-${++sequence}`;
            const content = node('section', { id: identity, hidden: true, 'aria-label': 'Application checklist and follow-up' });
            let generation = 0, expanded = false, leave = () => true;
            const endpoint = `/sis/applications/${applicationId}/casework/`;
            const toggle = button('Checklist & follow-up', async () => {
                if (expanded) { generation++; expanded = false; content.replaceChildren(); content.hidden = true; toggle.setAttribute('aria-expanded', 'false'); return; }
                const ticket = ++generation; expanded = true; content.hidden = false; toggle.setAttribute('aria-expanded', 'true');
                content.replaceChildren(node('p', { role: 'status' }, 'Loading application casework…'));
                try {
                    const packet = await request(endpoint);
                    if (ticket === generation && container.isConnected) render(packet, ticket);
                } catch (error) {
                    if (ticket !== generation || !container.isConnected) return;
                    content.replaceChildren(node('p', { role: 'alert', class: 'alert alert-danger' },
                        'Casework could not be loaded. Close and reopen this panel to try again.'));
                    throw error;
                }
            });
            toggle.setAttribute('aria-controls', identity); toggle.setAttribute('aria-expanded', 'false');
            container.replaceChildren(toggle, content);
            function render(packet, ticket) {
                const alive = () => ticket === generation && container.isConnected;
                const wrapper = node('fieldset', { class: 'mt-3' }, node('legend', { class: 'h5' }, 'Checklist & follow-up'));
                const status = node('p', { role: 'status', class: 'form-text', 'aria-atomic': 'true' });
                const form = node('form');
                const value = packet.case;
                const editor = itemEditor(form, value.items);
                const details = node('details', { class: 'border-top pt-3 mt-3' }, node('summary', { class: 'fw-semibold mb-3' }, 'Routing & next steps'));
                const office = field(details, 'office', 'Office shown to applicant', value.office);
                const nextStep = field(details, 'next_step', 'Next step shown to applicant', value.next_step, 'textarea', 2000);
                const privateNotes = field(details, 'private_notes', 'Private staff notes', value.private_notes, 'textarea', 5000);
                const workStatus = select(details, 'work_status', 'Work status', value.work_status,
                    [['open', 'Open'], ['waiting', 'Waiting'], ['done', 'Done']]);
                const due = field(details, 'follow_up_on', 'Follow up on', value.follow_up_on, 'date');
                details.append(node('p', { class: 'form-text' }, `Assigned to: ${packet.assignee_name}`));
                const claim = select(details, 'claim', 'Assignment', 'keep',
                    [['keep', 'Keep current assignment'], ['me', 'Assign to me'], ['selected', 'Assign a colleague'], ['unassigned', 'Leave unassigned']]);
                const picker = node('section', { hidden: true, 'aria-label': 'Choose an admissions colleague' });
                const colleagueSearch = field(picker, 'assignee_search', 'Colleague name or institutional ID', '', 'search', 80);
                const candidates = node('div'), chosen = node('p', { role: 'status', class: 'form-text' }, 'No colleague selected.');
                let selectedAssignee = null, candidateRequest = 0, candidateSearch = '';
                async function findColleague(after = '') {
                    const queryText = colleagueSearch.value.trim();
                    if (after && queryText !== candidateSearch) return findColleague();
                    const serial = ++candidateRequest;
                    const query = new URLSearchParams({ search: queryText }); if (after) query.set('after', after);
                    const result = await request(`/sis/admissions/assignees/?${query}`);
                    if (!alive() || serial !== candidateRequest || queryText !== colleagueSearch.value.trim() || claim.value !== 'selected') return;
                    candidateSearch = queryText;
                    const group = node('fieldset', { class: 'mt-3' }, node('legend', { class: 'h6' }, 'Eligible colleagues'));
                    result.items.forEach(item => {
                        const id = `casework-colleague-${++sequence}`;
                        const label = `${item.given_name} ${item.family_name} · ${item.institutional_id}`;
                        const radio = node('input', { id, type: 'radio', name: 'assignee_id', value: item.id,
                            class: 'form-check-input', checked: selectedAssignee?.id === item.id });
                        radio.addEventListener('change', () => {
                            selectedAssignee = { id: item.id, label }; colleagueSearch.setCustomValidity('');
                            chosen.textContent = `Selected: ${label}`;
                        });
                        group.append(node('div', { class: 'form-check mb-2' }, radio,
                            node('label', { for: id, class: 'form-check-label' }, label)));
                    });
                    if (!after) candidates.replaceChildren(); candidates.querySelector('[data-more-colleagues]')?.remove();
                    if (result.items.length) candidates.append(group);
                    else if (!after) candidates.append(node('p', { role: 'status', class: 'form-text' }, result.search_required
                        ? 'Enter at least two characters to find a colleague.' : 'No eligible colleagues match this search.'));
                    if (result.next_after) {
                        const more = button('More colleagues', () => findColleague(result.next_after));
                        more.dataset.moreColleagues = ''; candidates.append(more);
                    }
                }
                const findColleagueButton = button('Find colleague', () => findColleague());
                colleagueSearch.addEventListener('keydown', event => {
                    if (event.key === 'Enter') { event.preventDefault(); findColleagueButton.click(); }
                });
                picker.append(findColleagueButton, chosen, candidates);
                claim.addEventListener('change', () => {
                    candidateRequest++; picker.hidden = claim.value !== 'selected'; colleagueSearch.setCustomValidity('');
                });
                details.append(picker);
                form.append(details);
                const save = node('button', { type: 'submit', class: 'btn btn-primary mt-3' }, 'Save casework'); form.append(save);
                const values = () => ({ office: office.value, work_status: workStatus.value, follow_up_on: due.value,
                    private_notes: privateNotes.value, next_step: nextStep.value, items: editor.read() });
                let pending = false;
                const initialValues = JSON.stringify(values());
                leave = () => {
                    if (pending) { status.textContent = 'Wait for the current save before starting a message.'; return false; }
                    if (JSON.stringify(values()) !== initialValues || claim.value !== 'keep') {
                        status.textContent = 'Save casework before starting another task. Your draft is still here.';
                        save.focus(); return false;
                    }
                    return true;
                };
                async function write(path, body, complete, kind = 'casework') {
                    if (pending) return;
                    pending = true; wrapper.disabled = true; toggle.disabled = true; let accepted = false;
                    status.textContent = 'Saving…';
                    try {
                        const result = await request(path, 'POST', body); accepted = true;
                        if (alive()) await complete(result);
                    } catch (error) {
                        if (!accepted && kind === 'casework' && error.fields?.some(field => field.field === 'assignee_id')) {
                            selectedAssignee = null; chosen.textContent = 'The selection needs review. Find and choose an eligible colleague again.';
                            candidates.querySelectorAll('input[name="assignee_id"]').forEach(input => { input.checked = false; });
                        }
                        if (alive()) status.textContent = accepted
                            ? (kind === 'preset' ? 'Preset saved. Find and edit the preset to reload it. Your casework draft is unchanged.'
                                : 'Saved. Close and reopen this panel to load the latest values before another change.')
                            : 'Your draft is unchanged. Review the error and try again.';
                        if (alive()) throw error;
                    } finally {
                        pending = false; toggle.disabled = false;
                        if (!accepted || !status.textContent.startsWith('Saved.')) wrapper.disabled = false;
                        if (accepted && alive()) toggle.focus();
                    }
                }
                form.addEventListener('submit', event => {
                    event.preventDefault(); if (pending) return;
                    editor.expand(); details.open = true;
                    if (claim.value === 'selected' && !selectedAssignee) {
                        colleagueSearch.setCustomValidity('Find and choose an eligible colleague.');
                        colleagueSearch.reportValidity(); return;
                    }
                    if (!form.reportValidity()) return;
                    task(() => write(endpoint, { version: value.version, values: values(), claim: claim.value,
                        assignee_id: claim.value === 'selected' ? selectedAssignee.id : null }, async () => {
                        const saved = await request(endpoint); if (!alive()) return;
                        render(saved, ticket); notify('Application casework saved.');
                    }), save);
                });
                const library = node('details', { class: 'border-top mt-4 pt-3' }, node('summary', { class: 'fw-semibold mb-3' }, 'Reusable checklists'));
                const searchForm = node('form');
                const search = field(searchForm, 'preset_search', 'Find a checklist preset', '', 'search', 80);
                const find = node('button', { type: 'submit', class: 'btn btn-outline-primary' }, 'Find presets'); searchForm.append(find);
                const results = node('div', { class: 'mt-3' }), presetEditor = node('section', { class: 'mt-3', 'aria-label': 'Checklist preset editor' });
                let presetRequest = 0, presetEditRequest = 0, presetSearch = '';
                async function listPresets(after = '') {
                    const searchText = search.value.trim();
                    if (after && searchText !== presetSearch) return listPresets();
                    const serial = ++presetRequest, query = new URLSearchParams({ application_id: applicationId, search: searchText });
                    if (after) query.set('after', after);
                    const data = await request(`/sis/admissions/checklists/?${query}`);
                    if (!alive() || serial !== presetRequest || searchText !== search.value.trim()) return;
                    presetSearch = searchText;
                    const cards = data.items.map(item => {
                        const card = node('div', { class: 'border rounded p-3 mb-2' }, node('h3', { class: 'h6' }, item.name),
                            node('p', { class: 'form-text' }, `${item.program_id ? 'This program' : 'All programs'} · ${Number(item.enabled) ? 'Enabled' : 'Disabled'}`));
                        if (Number(item.enabled)) card.append(button('Use checklist', async () => {
                            if (pending) return;
                            pending = true; wrapper.disabled = true; toggle.disabled = true;
                            status.textContent = 'Loading checklist…';
                            try {
                                const preset = await request(`/sis/admissions/checklists/${item.id}/`);
                                if (!alive()) return;
                                if (!preset.values.enabled || (preset.values.program_id !== null && preset.values.program_id !== packet.program_id))
                                    throw new Error('This preset changed availability. Find presets again before using it.');
                                const merged = await window.mergeAdmissionChecklist(editor.read(), preset.values.items, preset.id);
                                if (!alive()) return;
                                editor.replace(merged); status.textContent = 'Checklist added to your draft. Save casework to keep it.';
                            } catch (error) {
                                if (alive()) status.textContent = 'Checklist could not be added. Your draft is unchanged.';
                                if (alive()) throw error;
                            } finally { pending = false; wrapper.disabled = false; toggle.disabled = false; }
                        }));
                        if (managePresets) card.append(button('Edit preset', async () => {
                            const editing = ++presetEditRequest;
                            const preset = await request(`/sis/admissions/checklists/${item.id}/`);
                            if (alive() && editing === presetEditRequest && !pending) editPreset(preset);
                        }, 'btn-outline-secondary ms-2'));
                        return card;
                    });
                    if (!after) results.replaceChildren(); results.append(...cards);
                    results.querySelector('[data-more-presets]')?.remove();
                    if (!data.items.length && !after) results.append(node('p', { role: 'status' }, 'No compatible checklist presets found.'));
                    if (data.next_after) { const more = button('More presets', () => listPresets(data.next_after)); more.dataset.morePresets = ''; results.append(more); }
                }
                function editPreset(preset) {
                    const f = node('form');
                    const name = field(f, 'preset.name', 'Preset name', preset.values.name); name.required = true;
                    const global = check(f, 'preset.program_id', 'Available to all programs', preset.values.program_id === null);
                    f.append(node('p', { class: 'form-text' }, 'When unchecked, use this preset only for this application’s program.'));
                    const enabled = check(f, 'preset.enabled', 'Enabled for new casework', preset.values.enabled);
                    const items = itemEditor(f, preset.values.items, true, 'preset.');
                    const submit = node('button', { type: 'submit', class: 'btn btn-primary mt-3' }, 'Save preset');
                    f.append(node('p', { class: 'form-text' }, 'Use generic labels and instructions. Progress, dates, assignments and private case notes are not copied. Existing applications keep their own working copies.'), submit,
                        button('Close preset', () => { presetEditRequest++; presetEditor.replaceChildren(); }, 'btn-link'));
                    f.addEventListener('submit', event => {
                        event.preventDefault(); if (pending) return;
                        items.expand(); if (!f.reportValidity()) return;
                        const definition = { name: name.value, program_id: global.checked ? null : packet.program_id,
                            enabled: enabled.checked, items: window.admissionChecklistDefinition(items.read()) };
                        task(async () => {
                            try {
                                await write(`/sis/admissions/checklists/${preset.id}/`, { values: definition, version: preset.version }, async result => {
                                    packet.new_preset_id = result.new_id;
                                    const fresh = await request(`/sis/admissions/checklists/${preset.id}/`);
                                    if (!alive()) return; editPreset(fresh); status.textContent = 'Checklist preset saved.'; notify('Checklist preset saved.');
                                    await listPresets();
                                }, 'preset');
                            } catch (error) {
                                if (error.fields) error.fields = error.fields.map(field => ({ ...field, field: 'preset.' + field.field }));
                                throw error;
                            }
                        }, submit);
                    });
                    presetEditor.replaceChildren(node('h3', { class: 'h5' }, 'Checklist preset'), f); name.focus();
                }
                searchForm.addEventListener('submit', event => { event.preventDefault(); task(() => listPresets(), find); });
                library.append(searchForm, results);
                if (managePresets) library.append(button('Create preset from draft', () => { presetEditRequest++; editPreset({ id: packet.new_preset_id,
                    version: 0, values: { name: '', program_id: packet.program_id, enabled: true,
                        items: window.admissionChecklistDefinition(editor.read()) } }); }));
                library.append(presetEditor);
                wrapper.append(node('p', { class: 'form-text' }, 'Working checklists and follow-up do not change the formal admissions decision. Keep confidential notes in the staff-only field.'), form, library);
                content.replaceChildren(wrapper, status);
            }
            return { beforeLeave: () => !expanded || leave() };
        }
        function guidance(content, data) {
            const labels = { pending: 'Pending', received: 'Received', complete: 'Complete', not_needed: 'Not needed' };
            content.replaceChildren(node('h3', { class: 'h5' }, 'Your next steps'),
                node('p', {}, data.next_step || 'Admissions has not added a specific next step. Contact the office with questions.'),
                data.office ? node('p', { class: 'form-text' }, `Office: ${data.office}`) : null,
                ...data.items.map(item => node('div', { class: 'border rounded p-3 mb-2' },
                    node('h4', { class: 'h6' }, item.label), node('p', {}, labels[item.status] || 'Check with admissions'),
                    node('p', { class: 'form-text' }, item.needed ? 'Requested for review' : 'Additional item'),
                    item.instructions ? node('p', {}, item.instructions) : null,
                    item.due_on ? node('p', { class: 'form-text' }, `Due: ${item.due_on}`) : null)),
                node('p', { class: 'form-text' }, 'Checklist progress does not itself mean that admission or registration has been approved.'));
        }
        function applicant(container, applicationId, label = '', documentTarget = null) {
            const identity = `casework-applicant-${++sequence}`;
            const content = node('section', { id: identity, class: 'mt-3', hidden: true, 'aria-label': 'Application next steps' });
            let generation = 0, expanded = false;
            const toggle = button('Next steps', async () => {
                if (expanded) { generation++; expanded = false; content.replaceChildren(); content.hidden = true; toggle.setAttribute('aria-expanded', 'false'); return; }
                const ticket = ++generation;
                const data = await request(`/portal/api/applications/${applicationId}/next-steps/`);
                if (ticket !== generation || !container.isConnected) return;
                expanded = true; content.hidden = false; toggle.setAttribute('aria-expanded', 'true');
                guidance(content, data);
            });
            toggle.setAttribute('aria-controls', identity); toggle.setAttribute('aria-expanded', 'false');
            if (label) toggle.setAttribute('aria-label', `Next steps for ${label}`);
            container.replaceChildren(toggle, content);
            const documents = node('div'); (documentTarget || container).append(documents);
            const documentEditor = window.createAdmissionDocuments({ node, request, task, notify }).mount(documents, applicationId,
                { toggleTarget: documentTarget ? container : null, label });
            const offerArea = node('div'); (documentTarget || container).append(offerArea);
            const offerEditor = window.createAdmissionOffers({ node, request, task, notify }).mount(offerArea, applicationId,
                { toggleTarget: container, label });
            return { beforeLeave: () => documentEditor.beforeLeave() && offerEditor.beforeLeave() };
        }
        function queue(container, openApplication) {
            const filters = node('div', { class: 'd-flex flex-wrap gap-3 align-items-end mb-3' });
            const scope = select(filters, 'casework_scope', 'Assignment', 'all', [['all', 'All open'], ['mine', 'Assigned to me'], ['unassigned', 'Unassigned']]);
            const due = check(filters, 'casework_due', 'Due today or earlier', false);
            const status = node('p', { role: 'status', class: 'form-text' }), list = node('div');
            let generation = 0, stack = [], filterKey = '';
            async function load(after = '', previous = []) {
                const selectedScope = scope.value, selectedDue = due.checked;
                const selectedKey = JSON.stringify([selectedScope, selectedDue]);
                if (after && selectedKey !== filterKey) return load();
                const ticket = ++generation;
                status.textContent = 'Loading follow-up queue…';
                try {
                    const query = new URLSearchParams({ scope: selectedScope, due: selectedDue ? '1' : '0' });
                    if (after) query.set('after', after);
                    const data = await request(`/sis/admissions/follow-ups/?${query}`);
                    if (ticket !== generation || !container.isConnected) return;
                    if (selectedKey !== JSON.stringify([scope.value, due.checked])) {
                        status.textContent = 'Filters changed. Choose Refresh queue to load matching results.'; return;
                    }
                    filterKey = selectedKey;
                    stack = previous;
                    list.replaceChildren(...data.items.map(item => node('article', { class: 'border rounded p-3 mb-3' },
                        node('h3', { class: 'h5' }, `${item.given_name} ${item.family_name}`),
                        node('p', { class: 'form-text' }, `${item.institutional_id} · ${item.office || 'Office not set'} · ${item.assignee_name}`),
                        node('p', {}, `${item.work_status === 'waiting' ? 'Waiting' : 'Open'}${item.next_due_on ? ' · Follow up: ' + item.next_due_on : ''}`),
                        button('Open application', () => openApplication({ id: item.application_id })))));
                    if (!data.items.length) list.append(node('p', {}, 'No casework matches these filters.'));
                    const pages = node('nav', { class: 'd-flex flex-wrap gap-2', 'aria-label': 'Follow-up queue pages' });
                    if (stack.length) pages.append(button('Previous', () => { const earlier = [...stack]; return load(earlier.pop(), earlier); }));
                    if (data.next_after) pages.append(button('Next', () => load(data.next_after, [...stack, after])));
                    list.append(pages); status.textContent = `${data.items.length} casework records · Institution date: ${data.today}`;
                } catch (error) {
                    if (ticket !== generation || !container.isConnected) return;
                    status.textContent = 'Could not refresh the queue. Previous results are still shown.'; throw error;
                }
            }
            filters.append(button('Refresh queue', () => load()));
            filters.append(node('p', { class: 'form-text w-100' }, 'Follow-up is paused for withdrawn applications. Unfinished tasks return when an application returns to review.'));
            container.replaceChildren(filters, status, list); task(() => load());
        }
        function updates(target) {
            const container = node('div'); target.append(container);
            const filters = node('div', { class: 'd-flex flex-wrap gap-3 align-items-center mb-3' });
            const includeRead = check(filters, 'admission_updates_read', 'Include read updates', false);
            const status = node('p', { class: 'form-text', role: 'status', 'aria-atomic': 'true' });
            const list = node('section', { 'aria-label': 'Admissions update list' });
            const detail = node('section', { class: 'mt-4', hidden: true, 'aria-label': 'Admissions update details' });
            let pageRequest = 0, detailRequest = 0, cursor = null, stack = [], loadedFilter = false;
            const refresh = button('Refresh updates', () => load()); filters.append(refresh);
            function closeDetail() { detailRequest++; detail.hidden = true; detail.replaceChildren(); refresh.focus(); }
            async function open(item) {
                const ticket = ++detailRequest;
                const heading = node('h2', { class: 'h4', tabindex: '-1' }, `${item.program_code} · ${item.program_title} · ${item.term_code}`);
                const progress = node('p', { role: 'status', class: 'form-text' }, 'Loading your next steps…');
                const body = node('div');
                detail.hidden = false; detail.replaceChildren(heading, button('Close update', closeDetail, 'btn-link'), progress, body); heading.focus();
                let loaded = false, acknowledged = false;
                try {
                    const data = await request(`/portal/api/applications/${item.application_id}/next-steps/`);
                    if (ticket !== detailRequest || !container.isConnected) return;
                    guidance(body, data);
                    body.append(node('a', { href: '/portal/apply/', class: 'btn btn-outline-primary mt-3' }, 'View application and offer'));
                    loaded = true; progress.textContent = 'Your current next steps are ready.';
                    const result = await request(`/portal/api/admissions-updates/${item.id}/read/`, 'POST', { revision: item.revision });
                    if (ticket !== detailRequest || !container.isConnected) return;
                    acknowledged = true;
                    await load(cursor, stack, true);
                    if (ticket !== detailRequest || !container.isConnected) return;
                    progress.textContent = result.unread ? 'A newer update arrived. Refresh updates to review it.' : 'Update marked read.';
                } catch (error) {
                    if (ticket !== detailRequest || !container.isConnected) return;
                    progress.textContent = acknowledged ? 'Your read status was saved. Refresh updates to see the latest list.'
                        : loaded ? 'Your next steps are ready, but we couldn’t confirm the read status. Try again.'
                        : 'We couldn’t load your next steps. Try again.';
                    body.append(button('Try again', () => open(item)));
                    throw error;
                }
            }
            async function load(after = null, previous = [], keepDetail = false) {
                if (after && includeRead.checked !== loadedFilter) return load();
                const showRead = includeRead.checked, ticket = ++pageRequest;
                if (!keepDetail) { detailRequest++; detail.hidden = true; detail.replaceChildren(); }
                status.textContent = 'Loading admissions updates…';
                try {
                    const query = new URLSearchParams({ include_read: showRead ? '1' : '0' });
                    if (after) { query.set('after_at', after.at); query.set('after', after.id); }
                    const data = await request(`/portal/api/admissions-updates/?${query}`);
                    if (ticket !== pageRequest || !container.isConnected) return;
                    if (showRead !== includeRead.checked) { status.textContent = 'Filters changed. Choose Refresh updates.'; return; }
                    cursor = after; stack = previous; loadedFilter = showRead;
                    list.replaceChildren(...data.items.map(item => node('article', { class: 'border rounded p-3 mb-3' },
                        node('h2', { class: 'h5' }, `${item.program_code} · ${item.program_title}`),
                        node('p', { class: 'form-text' }, `${item.term_code} · ${item.unread ? 'Unread' : 'Read'}`),
                        node('p', {}, 'Admissions updated your application.'),
                        node('p', { class: 'form-text' }, `Updated: ${new Date(item.updated_at).toLocaleString()}`),
                        button('Read update', () => open(item)))));
                    if (!data.items.length) list.append(node('p', {}, showRead ? 'No admissions updates are available.' : 'You have no unread admissions updates.'));
                    const pages = node('nav', { class: 'd-flex flex-wrap gap-2', 'aria-label': 'Admissions update pages' });
                    if (stack.length) pages.append(button('Previous updates', () => { const earlier = [...stack]; return load(earlier.pop(), earlier); }));
                    if (data.next) pages.append(button('Next updates', () => load(data.next, [...stack, cursor])));
                    list.append(pages); status.textContent = `${data.unread_count} unread admissions updates`;
                } catch (error) {
                    if (ticket !== pageRequest || !container.isConnected) return;
                    status.textContent = 'Could not refresh admissions updates. Previous results are still shown.';
                    throw error;
                }
            }
            container.append(node('p', { class: 'form-text' }, 'Find updates from admissions and open an application to review your current next steps and offer.'),
                filters, status, list, detail);
            task(() => load());
        }
        return { mount, applicant, queue, updates };
    };
})();
