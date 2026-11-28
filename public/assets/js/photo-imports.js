'use strict';
window.createPhotoImports = ({ node, request, labels: L, openDrawer, task, jobs }) => {
    const button = (text, icon, run, primary = false) => node('button', { type: 'button',
        class: `btn btn-${primary ? 'primary' : 'outline-secondary'}`, onclick: event => task(run, event.currentTarget) },
        node('i', { class: `bi bi-${icon} me-2`, 'aria-hidden': 'true' }), text);
    async function open() {
        const body = openDrawer(L.imports);
        const form = node('fieldset', { class: 'card border bg-white' });
        const inner = node('div', { class: 'card-body' }, node('p', { class: 'form-text' }, L.import_help),
            node('p', { class: 'form-text' }, L.import_limits));
        form.append(inner);
        const file = node('input', { id: 'photo-import-file', class: 'form-control mb-3', type: 'file', accept: '.png,.jpg,.zip' });
        const match = node('select', { id: 'photo-import-match', class: 'form-select mb-3' },
            node('option', { value: 'id' }, L.match_id), node('option', { value: 'name' }, L.match_name));
        const reason = node('textarea', { id: 'photo-import-reason', class: 'form-control mb-3', required: true, maxlength: '500' });
        const preview = node('div', { 'aria-live': 'polite' });
        const status = node('p', { role: 'status' });
        let selected = null, revision = 0, proof = null;
        const invalidate = () => { revision++; proof = null; preview.replaceChildren(); };
        const dispose = () => { selected = null; proof = null; revision++; file.value = ''; reason.value = '';
            preview.replaceChildren(); observer.disconnect(); window.removeEventListener('pagehide', dispose); };
        const observer = new MutationObserver(() => { if (!body.isConnected) dispose(); });
        observer.observe(document.body, { subtree: true, childList: true });
        window.addEventListener('pagehide', dispose);
        file.addEventListener('change', () => { selected = file.files[0]; invalidate(); });
        form.addEventListener('input', invalidate);
        form.addEventListener('change', invalidate);
        inner.addEventListener('dragover', event => event.preventDefault());
        inner.addEventListener('drop', event => { event.preventDefault(); if (event.dataTransfer.files.length === 1) {
            selected = event.dataTransfer.files[0]; invalidate(); status.textContent = selected.name;
        } });
        const privateRequest = async (path, data) => {
            try { return await request(path, 'POST', data); }
            catch (error) {
                proof = null;
                if (body.isConnected && [401,403].includes(error.status)) body.replaceChildren(
                    node('p', { class: 'alert alert-warning', role: 'status' }, L.denied));
                throw error;
            }
        };
        const review = button(L.import_review, 'eye', async () => {
            if (!selected || selected.size > 10485760 || !/\.(png|jpg|zip)$/i.test(selected.name)) throw new Error(L.import_limits);
            if (!reason.reportValidity()) return;
            invalidate(); const requested = revision;
            const data = new FormData(); data.append('photo', selected, selected.name);
            data.append('_workspace_json', JSON.stringify({ match: match.value, reason: reason.value }));
            const result = await privateRequest('/sis/people/photos/import/review/', data);
            if (!body.isConnected || requested !== revision) return;
            proof = result.review_token;
            const summary = node('p', { class: `alert alert-${result.ready ? 'success' : 'warning'}`, role: 'status' },
                result.ready ? (result.plan.length === 1 ? L.import_ready_one : L.import_ready.replace('{count}', result.plan.length)) : L.import_blocked);
            const list = node('div');
            function page(offset = 0) {
                const table = node('table', { class: 'table align-middle mb-0' },
                    node('caption', { class: 'visually-hidden' }, L.import_review),
                    node('thead', {}, node('tr', {}, ...[L.filename,L.person,L.match].map(label => node('th', { scope: 'col' }, label)))));
                const rows = node('tbody');
                for (const item of result.plan.slice(offset, offset + 20)) rows.append(node('tr', {},
                    node('td', { style: 'overflow-wrap:anywhere;' }, item.filename),
                    node('td', { style: 'overflow-wrap:anywhere;' }, item.basis?.person_label || '—'),
                    node('td', {}, item.issue ? L[item.issue] : L.matched)));
                table.append(rows);
                list.replaceChildren(node('div', { class: 'table-responsive border rounded bg-white' }, table));
                const paging = node('div', { class: 'd-flex flex-wrap gap-2 my-3' });
                if (offset) paging.append(button(L.previous, 'arrow-left', () => page(offset - 20)));
                if (offset + 20 < result.plan.length) paging.append(button(L.next, 'arrow-right', () => page(offset + 20)));
                list.append(paging);
            }
            preview.replaceChildren(summary, list); page();
            if (result.ready) preview.append(button(L.import_queue, 'cloud-upload', async () => {
                if (!proof) return;
                form.disabled = true;
                try {
                    const queued = await privateRequest('/sis/imports/commit/', { review_token: proof });
                    if (!body.isConnected) return;
                    proof = null; selected = null; file.value = '';
                    preview.replaceChildren(node('p', { class: 'alert alert-info', role: 'status' }, L.import_saved),
                        button(L.activity, 'list-check', () => jobs.detail(queued.id || result.job_id)));
                } catch (error) { proof = null; preview.replaceChildren(); throw error; }
                finally { form.disabled = false; }
            }, true));
        }, true);
        inner.append(node('label', { for: 'photo-import-file', class: 'form-label' }, L.import_file), file,
            node('label', { for: 'photo-import-match', class: 'form-label' }, L.match), match,
            node('p', { class: 'form-text' }, L.match_help),
            node('label', { for: 'photo-import-reason', class: 'form-label' }, L.import_reason), reason, review,
            button(L.import_discard, 'x-circle', () => { selected = null; file.value = ''; invalidate(); }));
        body.append(form, status, preview);
        return body;
    }
    return { open };
};
