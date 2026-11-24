/* Explicit, bounded support observations. No polling, payload display or browser persistence. */
'use strict';
window.createWorkspaceHistoryChecks = ({ node, request, task, openDrawer, labels = {} }) => {
    const fallbackLabels = {
        recorded_progress: "Recorded degree progress",
        progress_components: "Accepted progress observation, protected history and projection",
        progress_count: "Recorded progress observations checked",
        progress_help: "Compare this saved progress observation with its protected history and projection. This check does not recalculate progress, apply current policies or certify graduation.",
        program_standing: "Program standing",
        retained_load: "Retained enrollment load",
        advisor_assignments: "Advisor assignments",
        degree_selection: "Selected degree requirements",
        advisor_components: "Retained advisor assignments and projection",
        requirement_components: "Degree selection, projection and retained definitions",
        advisors_empty: "No recorded advisor assignment observed",
        advisors_empty_help: "No advisor decision was found for this pursuit. Earlier advising remains unknown.",
        requirements_empty: "No recorded degree selection observed",
        requirements_empty_help: "No degree selection was found for this pursuit. This does not assign requirements or establish earlier history.",
        advisor_count: "Retained advisor periods checked",
        requirement_count: "Selected requirement baselines checked",
        curriculum_help: "Compare retained advisor assignments or the selected degree baseline with history, using a 200-event allowance. Current advisor eligibility and degree completion need their own reviews; this check changes neither access nor academic requirements.",
        pursuit_identity: "Program pursuit identity",
        standing_components: "Standing periods and history",
        load_components: "Retained load decision and projection",
        standing_empty: "No recorded standing observed",
        standing_empty_help: "No standing decision was found for this pursuit. Earlier standing remains unknown.",
        standing_count: "Standing periods checked",
        load_count: "Retained determinations checked",
        application: "Admissions application",
        application_components: "Application facts and history",
        intake_component: "Application intake projection",
        receipt_saved: "Business records were not changed. A restricted support receipt was recorded.",
        check_help: "Run one selected-record observation with a 200-event allowance and bounded replay. It does not repair data, approve admissions, academic or financial decisions, verify external settlement, or certify archive completeness or restore readiness.",
        history_help: "Previous results describe their recorded observation, not a continuing guarantee about the current record. No event payloads, application answers, private profile values, financial amounts or credentials are displayed.",
    };
    const text = key => labels[key] || fallbackLabels[key];
    const kinds = { progress: text('recorded_progress'), advisors: text('advisor_assignments'), requirements: text('degree_selection'), person: 'Person profile', student: 'Student academic records', account: 'Student account', application: text('application'), standing: text('program_standing'), load: text('retained_load'), primary: text('primary_position'), position: text('position'), time: text('time_entry') };
    const employmentKinds = ['primary', 'position', 'time'];
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
        progress_record: text('progress_components'), advisors: text('advisor_components'), requirements: text('requirement_components'), application: text('application_components'), intake: text('intake_component'), pursuit_identity: text('pursuit_identity'), standing: text('standing_components'), load_decision: text('load_components'), primary_position: text('primary_components'), position: text('position_components'), time_decision: text('time_components'), earlier_decision: text('earlier_time') };
    function observation(kind, state) {
        if (kind === 'time' && state === 'history_gaps') return [text('earlier_time'), text('earlier_time_help')];
        if (state === 'observed_empty') {
            if (kind === 'standing') return [text('standing_empty'), text('standing_empty_help')];
            if (['advisors', 'requirements'].includes(kind)) return [text(`${kind}_empty`), text(`${kind}_empty_help`)];
            if (kind === 'primary') return [text('primary_empty'), text('primary_empty_help')];
            if (kind === 'time') return [text('time_empty'), text('time_empty_help')];
        }
        return statuses[state] || statuses.unverified;
    }
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
            const [label, description] = observation(kind, check.status);
            result.replaceChildren(node('h3', { class: 'h5' }, label), node('p', {}, description),
                node('p', { class: 'text-break' }, `Support reference: ${receipt.reference}`),
                node('p', {}, `Observed ${receipt.checked_at} · ${check.events_checked} events checked (maximum ${data.event_limit}).`));
            if (check.records_checked != null) result.append(node('p', {},
                `${text(employmentKinds.includes(kind) ? 'hr_count' : kind === 'standing' ? 'standing_count' : kind === 'advisors' ? 'advisor_count' : kind === 'requirements' ? 'requirement_count' : kind === 'progress' ? 'progress_count' : 'load_count')}: ${check.records_checked}.`));
            if (check.position != null) result.append(node('p', {}, `Observed stream position: ${check.position}.`));
            if (check.current_records != null) result.append(node('p', {}, `Current academic records checked: ${check.current_records}.`));
            if (check.expected_postings != null) result.append(node('p', {}, `Expected postings checked: ${check.expected_postings}.`));
            const components = (check.issues || []).filter(key => Object.hasOwn(issues, key)).map(key => key === 'identity' && employmentKinds.includes(kind) ? text('hr_identity') : issues[key]);
            if (components.length) result.append(node('p', {}, `Components for review: ${components.join(', ')}.`));
            after = null; stack = [];
            await load();
            if (!body.isConnected) return;
            status.textContent = `${label}. ${text('receipt_saved')}`;
        });
        const previous = button('Previous checks page', async () => { after = stack.pop(); await load(); });
        const older = button('Older checks', async () => { stack.push(after); after = next; await load(); });
        previous.hidden = older.hidden = true;
        controls.append(run, button('Refresh previous checks', () => load()));
        if (back) controls.append(node('button', { type: 'button', class: 'btn btn-link', onclick: event => task(back, event.currentTarget) }, 'Back to record'));
        body.append(node('h3', { class: 'h5' }, kinds[kind]),
            node('p', {}, text(employmentKinds.includes(kind) ? 'hr_help' :
                kind === 'progress' ? 'progress_help' : ['advisors', 'requirements'].includes(kind) ? 'curriculum_help' : 'check_help')),
            node('p', { class: 'form-text' }, text(employmentKinds.includes(kind) ? 'hr_history_help' : 'history_help')),
            controls, status, result, history, node('div', { class: 'd-flex gap-2 flex-wrap' }, previous, older));
        async function load() {
            const page = await request(base + 'history/', 'POST', { after });
            if (!body.isConnected) return;
            next = page.next_after; older.hidden = !next; previous.hidden = !stack.length;
            history.replaceChildren(node('h3', { class: 'h5' }, 'Previous checks'));
            for (const item of page.items) history.append(node('article', { class: 'border rounded bg-white p-3 mb-3 text-break' },
                node('h4', { class: 'h6' }, observation(kind, item.status)[0]),
                node('p', {}, `Observed ${item.checked_at} · ${item.events_checked} events checked.`),
                node('p', {}, `Support reference: ${item.reference}`)));
            if (!page.items.length) history.append(node('p', {}, 'No previous checks for the current record routing on this page.'));
        }
        work(load);
    }
    return { open };
};
