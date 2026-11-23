/* Explicit, bounded support observations. No polling, payload display or browser persistence. */
'use strict';
window.createWorkspaceHistoryChecks = ({ node, request, task, openDrawer, labels = {} }) => {
    const text = value => labels[value] || value;
    const kinds = { person: 'Person profile', student: 'Student academic records', account: 'Student account', application: text('Admissions application') };
    const statuses = {
        verified: ['Verified within this check', 'The selected recorded sources matched their projections at the observed point.'],
        history_gaps: ['Earlier history is incomplete', 'Available evidence matched, but earlier observations or unrecorded components prevent a complete-history conclusion.'],
        observed_empty: ['No current academic records observed', 'An empty current observation does not establish a complete archive.'],
        unverified: ['Unable to verify', 'A selected component needs an authorized operator review. No repair was attempted.'],
        changed: ['Records changed during the check', 'This is an incomplete observation. Review the record and deliberately run a new check.'],
        limited: ['Check limit reached', 'This is an incomplete observation. Ask a trusted operator for a larger bounded command-line review.'],
    };
    const issues = { journal: 'Protected history', identity: 'Person identity', account_link: 'Account link', contact: 'Contact projection',
        addresses: 'Address projection', emergency_contacts: 'Emergency-contact projection', education: 'Education projection',
        unavailable: 'Verification source unavailable', student_identity: 'Student identity', academic_sources: 'Academic sources',
        account_identity: 'Account identity', balance: 'Balance projection', postings: 'Posting projection',
        application: text('Application facts and history'), intake: text('Application intake projection') };
    function open(kind, id, back) {
        if (!Object.hasOwn(kinds, kind)) throw new Error('Choose a supported history check.');
        const body = openDrawer('Record history check', kinds[kind].toUpperCase());
        const base = `/sis/history-checks/${kind}/${encodeURIComponent(id)}/`;
        const status = node('p', { role: 'status', 'aria-live': 'polite', class: 'form-text text-break', tabindex: '-1' });
        const result = node('section', { 'aria-label': 'Current check result', class: 'mb-4' });
        const history = node('section', { 'aria-label': 'Previous history checks' });
        const controls = node('div', { class: 'd-flex flex-wrap gap-2 mb-3' });
        let busy = false, after = null, next = null, stack = [];
        const managed = [];
        const button = (label, action) => {
            const control = node('button', { type: 'button', class: 'btn btn-outline-primary', onclick: () => work(action) }, label);
            managed.push(control);
            return control;
        };
        async function work(action) {
            if (busy || !body.isConnected) return;
            busy = true; managed.forEach(button => { button.disabled = true; }); status.textContent = '';
            try { await action(); }
            catch (error) {
                if (!body.isConnected) return;
                if ([401, 403].includes(error.status)) { result.replaceChildren(); history.replaceChildren(); }
                status.textContent = error.message || 'This check is unavailable. Reopen the record or contact an authorized support operator.';
                status.focus();
            } finally {
                busy = false; managed.forEach(button => { button.disabled = false; });
            }
        }
        const run = button('Run history check', async () => {
            result.replaceChildren();
            status.textContent = 'Checking selected sources…';
            const data = await request(base + 'run/', 'POST', {});
            if (!body.isConnected) return;
            const check = data.result, receipt = data.receipt;
            const [label, description] = statuses[check.status] || statuses.unverified;
            result.replaceChildren(node('h3', { class: 'h5' }, label), node('p', {}, description),
                node('p', { class: 'text-break' }, `Support reference: ${receipt.reference}`),
                node('p', {}, `Observed ${receipt.checked_at} · ${check.events_checked} events checked (maximum ${data.event_limit}).`));
            if (check.position != null) result.append(node('p', {}, `Observed stream position: ${check.position}.`));
            if (check.current_records != null) result.append(node('p', {}, `Current academic records checked: ${check.current_records}.`));
            if (check.expected_postings != null) result.append(node('p', {}, `Expected postings checked: ${check.expected_postings}.`));
            const components = (check.issues || []).filter(key => Object.hasOwn(issues, key)).map(key => issues[key]);
            if (components.length) result.append(node('p', {}, `Components for review: ${components.join(', ')}.`));
            after = null; stack = [];
            await load();
            if (!body.isConnected) return;
            status.textContent = `${label}. ${text('Business records were not changed. A restricted support receipt was recorded.')}`;
        });
        const previous = button('Previous checks page', async () => { after = stack.pop(); await load(); });
        const older = button('Older checks', async () => { stack.push(after); after = next; await load(); });
        previous.hidden = older.hidden = true;
        controls.append(run, button('Refresh previous checks', () => load()));
        if (back) controls.append(node('button', { type: 'button', class: 'btn btn-link', onclick: event => task(back, event.currentTarget) }, 'Back to record'));
        body.append(node('h3', { class: 'h5' }, kinds[kind]),
            node('p', {}, text('Run one selected-record observation with a 200-event allowance and bounded replay. It does not repair data, approve admissions, academic or financial decisions, verify external settlement, or certify archive completeness or restore readiness.')),
            node('p', { class: 'form-text' }, text('Previous results describe their recorded observation, not a continuing guarantee about the current record. No event payloads, application answers, private profile values, financial amounts or credentials are displayed.')),
            controls, status, result, history, node('div', { class: 'd-flex gap-2 flex-wrap' }, previous, older));
        async function load() {
            const page = await request(base + 'history/', 'POST', { after });
            if (!body.isConnected) return;
            next = page.next_after; older.hidden = !next; previous.hidden = !stack.length;
            history.replaceChildren(node('h3', { class: 'h5' }, 'Previous checks'));
            for (const item of page.items) history.append(node('article', { class: 'border rounded p-3 mb-3 text-break' },
                node('h4', { class: 'h6' }, (statuses[item.status] || statuses.unverified)[0]),
                node('p', {}, `Observed ${item.checked_at} · ${item.events_checked} events checked.`),
                node('p', {}, `Support reference: ${item.reference}`)));
            if (!page.items.length) history.append(node('p', {}, 'No previous checks for the current record routing on this page.'));
        }
        work(load);
    }
    return { open };
};
