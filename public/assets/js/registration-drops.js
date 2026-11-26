/* Shared owner/staff selection and one reviewed atomic drop confirmation. */
window.createRegistrationDrops = function (ui) {
    "use strict";
    const { node, request, task, open, prefix } = ui;
    const creditNotice = summary => summary && node('p', { class: 'alert alert-info' }, summary.minor > 0
        ? (ui.creditLabels?.review || 'Scheduled account credit: {amount}. This reduces registration charges; it does not issue a cash refund.')
            .replace('{amount}', ui.money(summary.minor, summary.currency))
        : (ui.creditLabels?.none || 'No additional registration-charge credit is due under this schedule.'));
    const active = ui.active || (body => body.isConnected && !!body.closest('.show,.showing'));
    const button = (label, run, style = 'btn-outline-primary') => node('button', {
        type: 'button', class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
    }, label);
    async function start(student, back, seed = []) {
        if (new Set(seed).size > 10) throw new Error('These linked courses exceed the ten-course limit. Ask the registrar to review the full set.');
        const selected = new Map(seed.map(id => [id, { id, code: "Linked course", course_code: "Selected registration" }]));
        const fingerprint = () => [...selected.keys()].sort().join('|');
        let query = '';
        async function choose(after = null, stack = []) {
            const body = open('Drop selected courses');
            const search = node('input', { type: 'search', id: 'drop-plan-search', maxlength: 80,
                value: query, class: 'form-control' });
            const form = node('form', { class: 'mb-3' }, node('label', { for: search.id, class: 'form-label' }, 'Find a registered course'),
                search, node('button', { type: 'submit', class: 'btn btn-outline-primary mt-2' }, 'Search courses'));
            form.addEventListener('submit', event => { event.preventDefault(); query = search.value.trim(); task(() => choose()); });
            const selection = node('div', { class: 'mb-3', 'aria-label': 'Selected courses' });
            const status = node('p', { class: 'form-text', role: 'status' });
            let notice = '';
            const review = node('button', { type: 'button', class: 'btn btn-primary' }, 'Review selected drops');
            review.addEventListener('click', () => { task(async () => {
                const submitted = fingerprint();
                const plan = await request(prefix + 'review/', 'POST', { student_id: student, section_ids: [...selected.keys()] });
                if (!active(body)) return;
                if (fingerprint() !== submitted) { notice = 'Your selection changed. Review the selected courses again.'; return; }
                confirm(plan);
            }, review).finally(() => { if (active(body)) update(); }); });
            const results = node('div');
            let page = [];
            function update() {
                status.textContent = notice || `${selected.size} of 10 courses selected. Nothing changes until confirmation.`;
                review.disabled = selected.size < 1;
                selection.replaceChildren(...[...selected.values()].map(row => node('div', { class: 'd-flex flex-wrap gap-2 align-items-center mb-2' },
                    node('span', { class: 'text-break' }, `${row.course_code} · ${row.code}`),
                    button('Remove', () => { selected.delete(row.id); draw(); }, 'btn-link btn-sm'))));
            }
            function draw() {
                update();
                results.replaceChildren(...page.map(row => {
                    const input = node('input', { type: 'checkbox', class: 'form-check-input', id: `drop-course-${row.id}`,
                        checked: selected.has(row.id), disabled: selected.size >= 10 && !selected.has(row.id) });
                    input.addEventListener('change', () => {
                        notice = '';
                        if (input.checked && selected.size < 10) selected.set(row.id, row);
                        else selected.delete(row.id);
                        draw();
                        results.querySelector(`#drop-course-${row.id}`)?.focus({ preventScroll: true });
                    });
                    return node('div', { class: 'form-check border rounded p-3 mb-2' }, input,
                        node('label', { for: input.id, class: 'form-check-label text-break' }, `${row.course_code} · ${row.course_title} · ${row.code} · ${row.term_code}`));
                }));
                if (!page.length) results.append(node('p', {}, 'No ungraded active registrations match. Posted grades need registrar review.'));
            }
            body.append(node('p', {}, 'Select up to ten courses. Review each drop/add deadline, transcript effect and charge treatment before changing anything.'),
                form, selection, status, results, node('div', { class: 'inline-actions d-flex flex-wrap gap-2' }, review, button('Back', back)));
            update();
            const data = await request(prefix + 'choices/', 'POST', { student_id: student, search: query, ...(after ? { after } : {}) });
            if (!active(body)) return;
            page = data.items;
            for (const row of page) if (selected.has(row.id)) selected.set(row.id, row);
            draw();
            body.append(node('div', { class: 'inline-actions d-flex flex-wrap gap-2 mt-3' },
                ...(stack.length ? [button('Previous courses', () => choose(stack.at(-1), stack.slice(0, -1)))] : []),
                ...(data.next_after ? [button('More courses', () => choose(data.next_after, [...stack, after]))] : [])));
        }
        function confirm(plan) {
            const body = open('Review selected drops');
            const required = [...new Set(plan.courses.flatMap(row => row.corequisites?.required_sections || []))];
            const blocked = plan.courses.some(row => row.corequisites?.blocked);
            const warnings = plan.courses.some(row => row.corequisites?.warning);
            const confirmButton = button('Confirm selected drops', async () => {
                const saved = await request(prefix + 'commit/', 'POST', command);
                if (active(body)) receipt(saved);
            }, 'btn-danger');
            confirmButton.disabled = blocked;
            const include = button('Include linked courses', async () => {
                for (const id of required) if (!selected.has(id)) selected.set(id,
                    { id, course_code: 'Selected registration', code: 'Linked course' });
                await choose();
            }, 'btn-primary');
            include.disabled = new Set([...selected.keys(), ...required]).size > 10;
            const command = { student_id: student, section_ids: plan.courses.map(row => row.section_id),
                operation_id: plan.operation_id, revision: plan.revision, review_token: plan.review_token };
            body.append(plan.student ? node('h3', { class: 'h5' }, `${plan.student.given_name} ${plan.student.family_name} · ${plan.student.institutional_id}`) : null,
                node('p', { class: 'alert alert-info' }, 'All selected drops are saved together. If any course cannot be changed, none of these drops are saved. No cash refund is issued.'),
                warnings ? node('div', { role: 'status', class: blocked ? 'alert alert-danger' : 'alert alert-warning' },
                    node('p', {}, blocked ? 'A selected drop would leave a required co-requisite unsupported. Include the linked courses, then review all drops together.' :
                        'This leaves a required co-requisite unsupported. Institutional policy permits proceeding with this warning.'),
                    blocked ? include : null, blocked && include.disabled ? node('p', {}, 'This exceeds the ten-course selection limit. Ask the registrar to review these dependencies.') : null) : null,
                ...plan.courses.map(row => node('section', { class: 'border rounded p-3 mb-3', 'aria-label': row.section_code },
                    node('h3', { class: 'h5' }, `${row.section_code} · ${row.outcome.term_code}`),
                    node('p', {}, `Effective ${row.effective_on}. Drop/add ends ${row.outcome.grace_ends_on}.`),
                    node('p', {}, row.outcome.delete_records
                        ? 'Remove the current enrollment and academic credit. Reverse the original course charge if present.'
                        : `Keep the attempted course with grade ${row.outcome.grade_code} and no earned credit. Keep its charge. GPA: ${row.outcome.counts_gpa ? 'included' : 'excluded'}.`),
                    ...(row.withdrawal_credit ? [creditNotice(row.withdrawal_credit)] : []),
                    ...(row.has_earned_ceus === true ? [node('p', { class: 'alert alert-info' },
                        ui.ceuLabels?.withdrawal_preserves || 'This academic withdrawal preserves the independently recorded CEUs. Any CEU correction or revocation requires a separate reviewed decision.')] : []))),
                node('p', { class: 'form-text' }, 'Reviews expire after 20 minutes. Any account credit is considered separately for a refund; other institutional fees stay unchanged.'),
                node('div', { class: 'inline-actions d-flex flex-wrap gap-2' },
                    button('Change selection', () => choose()),
                    confirmButton,
                    button('Check saved drops', async () => {
                        const saved = await request(prefix + `receipts/${plan.operation_id}/`);
                        if (active(body)) receipt(saved);
                    })));
            function receipt(saved) {
                const result = open('Course drops completed');
                result.append(node('p', { role: 'status' }, `${saved.removed} removed during drop/add; ${saved.withdrawn} withdrawn with retained grades and charges.`),
                    node('p', {}, 'Reference: ', node('code', {}, saved.drop_plan_id)), button('Return to registrations', back, 'btn-primary'));
            }
        }
        await choose();
    }
    return { start };
};
