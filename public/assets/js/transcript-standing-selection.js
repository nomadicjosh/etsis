/* Institution reference choices only. Drafts and pending lookup state stay in this drawer. */
'use strict';
window.createTranscriptStandingSelection = ({ node, request, task, labels = {}, container, saved, enabled, changed, denied }) => {
    const fallbackLabels = {
        remove: "Remove",
        all_definitions: "All recorded standing definitions",
        selected_definitions: "Selected standing definitions",
        unavailable_definition: "Unavailable definition",
        find_definitions: "Find definitions",
        more_definitions: "More definitions",
        previous_definitions: "Previous definitions",
        available_definitions: "Available standing definitions",
        find_definition: "Find a standing definition",
        include_standings: "Standings to include",
        selection_required: "Choose at least one definition or include all standings.",
        remove_selection: "Remove selection",
        loading_definitions: "Loading standing definitions…",
        retired_definition: "Retired definition",
        selection_hint: "Choose the definitions to include. Other recorded standings will be omitted.",
        no_definitions: "No standing definitions match this search.",
        lookup_failed: "Standing definitions could not be loaded. Your choices are kept; try again.",
    };
    const text = key => labels[key] || fallbackLabels[key];
    let next = null, applied = '', sequence = 0, loading = false, loaded = false, cursor = null, stack = [];
    const selected = new Map((saved.standing_choices || []).map(item => [item.id, item]));
    for (const id of saved.standing_definitions || []) {
        if (!selected.has(id)) selected.set(id, { id, code: text('unavailable_definition'), title: '' });
    }
    const mode = node('select', { id: 'transcript-standing-mode', class: 'form-select' },
        node('option', { value: 'all' }, text('all_definitions')),
        node('option', { value: 'selected' }, text('selected_definitions')));
    mode.value = saved.standing_definitions === null || saved.standing_definitions === undefined ? 'all' : 'selected';
    const search = node('input', { id: 'transcript-standing-search', type: 'search', class: 'form-control', maxlength: '80' });
    const searchButton = node('button', { type: 'button', class: 'btn btn-outline-primary' }, text('find_definitions'));
    const more = node('button', { type: 'button', class: 'btn btn-outline-primary' }, text('more_definitions'));
    const previous = node('button', { type: 'button', class: 'btn btn-outline-primary' }, text('previous_definitions'));
    const status = node('p', { class: 'form-text', role: 'status' });
    const choices = node('div', { 'aria-label': text('available_definitions'), role: 'group' });
    const picked = node('div', { 'aria-label': text('selected_definitions'), role: 'group' });
    const picker = node('div', { class: 'mt-3' }, picked,
        node('div', { class: 'record-field-group mt-3' },
            node('label', { for: search.id, class: 'form-label' }, text('find_definition')),
            search, node('div', { class: 'd-flex gap-2 flex-wrap mt-2' }, searchButton)), status, choices,
        node('div', { class: 'd-flex gap-2 flex-wrap' }, previous, more));
    container.append(node('label', { for: mode.id, class: 'form-label' }, text('include_standings')), mode,
        node('p', { class: 'form-text' }, (labels.selection_help || 'Choose up to 25 standing definitions. Retired definitions can still identify earlier recorded periods. Transcripts keep the original recorded labels.')),
        picker);
    function alive() { return container.isConnected; }
    function value() { return mode.value === 'all' ? null : [...selected.keys()].sort(); }
    function valid() { return mode.value === 'all' || selected.size > 0; }
    function refresh() {
        container.hidden = !enabled();
        picker.hidden = mode.value !== 'selected';
        more.hidden = !next;
        previous.hidden = !stack.length;
        searchButton.disabled = more.disabled = previous.disabled = loading;
        for (const input of choices.querySelectorAll('input[type="checkbox"]')) {
            input.checked = selected.has(input.value);
            input.disabled = !input.checked && selected.size >= 25;
        }
        changed();
    }
    function renderSelected() {
        picked.replaceChildren(node('h4', { class: 'h6' }, text('selected_definitions')));
        if (!selected.size) picked.append(node('p', { class: 'form-text' }, text('selection_required')));
        for (const item of selected.values()) {
            const remove = node('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary',
                'aria-label': `${text('remove_selection')} ${item.code}`, onclick: () => {
                    selected.delete(item.id); renderSelected(); refresh();
                    (document.getElementById(`transcript-standing-choice-${item.id}`) || mode).focus();
                } }, node('i', { class: 'bi bi-x-lg me-1', 'aria-hidden': 'true' }), text('remove'));
            picked.append(node('div', { class: 'd-flex flex-wrap gap-2 align-items-start justify-content-between border rounded p-2 mb-2' },
                node('span', { class: 'transcript-standing-picked-label text-break' },
                    [item.code, item.title].filter(Boolean).join(' · ')), remove));
        }
    }
    async function load(term, after = null, trail = []) {
        const ticket = ++sequence;
        loading = true; status.textContent = text('loading_definitions'); refresh();
        try {
            const query = new URLSearchParams({ search: term, ...(after ? { after } : {}) });
            const page = await request(`/sis/transcript-policy/standings/?${query}`);
            if (!alive() || ticket !== sequence || mode.value !== 'selected') return;
            choices.replaceChildren();
            applied = term; next = page.next_after; cursor = after; stack = trail; loaded = true;
            for (const item of page.items) {
                if (selected.has(item.id)) selected.set(item.id, item);
                const input = node('input', { id: `transcript-standing-choice-${item.id}`, type: 'checkbox',
                    class: 'form-check-input', value: item.id, checked: selected.has(item.id), onchange: () => {
                        if (input.checked && selected.size < 25) selected.set(item.id, item);
                        else selected.delete(item.id);
                        renderSelected(); refresh();
                    } });
                choices.append(node('div', { class: 'form-check mb-3 text-break' }, input,
                    node('label', { for: input.id, class: 'form-check-label' }, `${item.code} · ${item.title}`,
                        item.active ? '' : ` · ${text('retired_definition')}`)));
            }
            status.textContent = page.items.length ? text('selection_hint')
                : text('no_definitions');
            renderSelected();
        } catch (error) {
            if (alive() && ticket === sequence) {
                status.textContent = text('lookup_failed');
                if ([401, 403].includes(error.status)) denied();
                throw error;
            }
        } finally {
            if (alive() && ticket === sequence) { loading = false; refresh(); }
        }
    }
    searchButton.addEventListener('click', event => task(() => load(search.value.trim()), event.currentTarget));
    search.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); task(() => load(search.value.trim()), searchButton); }
    });
    more.addEventListener('click', event => task(() => load(applied, next, [...stack, cursor]), event.currentTarget));
    previous.addEventListener('click', event => task(() => load(applied, stack.at(-1), stack.slice(0, -1)), event.currentTarget));
    mode.addEventListener('change', () => {
        sequence++; loading = false; refresh();
        if (mode.value === 'selected' && !loaded) task(() => load(search.value.trim()));
    });
    renderSelected();
    return { value, valid, refresh, pending: () => loading };
};
