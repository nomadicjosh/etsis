/* Shared presentation of saved/current checklist readiness; it never grants admission. */
(() => {
    'use strict';
    window.createAdmissionReadiness = ({ node }) => {
        const count = data => `${data.complete} complete · ${data.not_needed} not needed · ${data.needed} needed items total`;
        return {
            staff(data) {
                const section = node('section', { class: `alert ${data.required && !data.ready ? 'alert-warning' : 'alert-info'} mt-3`, 'aria-label': 'Acceptance checklist' });
                section.append(node('h4', { class: 'h6' }, data.required ? 'Required before acceptance' : 'Advisory checklist'),
                    node('p', { class: 'mb-2' }, data.ready ? 'The saved checklist is ready for an admissions decision.' : data.needs_case_review
                        ? 'Review and save the checklist. If no items are needed, mark casework Done.' : 'Needed items remain pending or received. Complete them or mark them Not needed where appropriate.'),
                    node('p', { class: 'small mb-0' }, count(data)));
                if (data.pending?.length) section.append(node('ul', { class: 'mb-0 mt-2' }, ...data.pending.map(label => node('li', {}, label))));
                if (!data.required) section.append(node('p', { class: 'small mb-0 mt-2' }, 'Current policy permits a decision while checklist items remain.'));
                return section;
            },
            history(data) {
                return node('p', { class: 'form-text' }, `Checklist at this decision: ${data.required ? 'required' : 'advisory'} · ${data.ready ? 'ready' : 'not ready'} · ${count(data)}.`);
            },
        };
    };
})();
