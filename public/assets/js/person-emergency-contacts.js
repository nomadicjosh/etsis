/* Shared person-contact editor. Private values stay in the current form only. */
(() => {
    'use strict';
    let sequence = 0;
    window.createPersonEmergencyContacts = ({ node, request, task, notify }) => ({
        async open(container, endpoint) {
            container.replaceChildren(node('p', { role: 'status' }, 'Loading emergency contacts…'));
            let record = await request(endpoint);
            if (!container.isConnected) return;
            let contacts = record.contacts.map(contact => ({ ...contact }));
            const form = node('form', { class: 'record-form' });
            const list = node('div', { 'data-emergency-list': '' });
            const status = node('p', { class: 'form-text', role: 'status', 'aria-live': 'polite' });
            const add = node('button', { type: 'button', class: 'btn btn-outline-primary' }, 'Add emergency contact');
            const save = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Save emergency contacts');
            const read = () => [...list.querySelectorAll('fieldset')].map(card => Object.fromEntries(
                ['name', 'relationship', 'phone', 'email'].map(key => [key, card.querySelector(`[data-field="${key}"]`).value]),
            ));
            const draw = (focus = null) => {
                list.replaceChildren();
                contacts.forEach((contact, index) => {
                    const card = node('fieldset', { class: 'border rounded p-3 mb-3' },
                        node('legend', { class: 'h6' }, `Contact ${index + 1}`));
                    for (const [key, label, type, maximum, required] of [
                        ['name', 'Name', 'text', 191, true],
                        ['relationship', 'Relationship', 'text', 80, false],
                        ['phone', 'Phone number', 'tel', 60, true],
                        ['email', 'Email address', 'email', 191, false],
                    ]) {
                        const id = `emergency-field-${++sequence}`;
                        const input = node('input', { id, type, class: 'form-control', required, maxlength: maximum,
                            autocomplete: 'off', name: `contacts.${index}.${key}`, 'data-field': key, value: contact[key] || '' });
                        card.append(node('div', { class: 'mb-3' }, node('label', { for: id, class: 'form-label' }, label), input));
                    }
                    const actions = node('div', { class: 'd-flex flex-wrap gap-2' });
                    for (const [label, offset] of [['Move earlier', -1], ['Move later', 1]]) {
                        actions.append(node('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary',
                            disabled: index + offset < 0 || index + offset >= contacts.length,
                            'aria-label': `${label}: contact ${index + 1}`, onclick: () => {
                                contacts = read();
                                [contacts[index], contacts[index + offset]] = [contacts[index + offset], contacts[index]];
                                draw(index + offset);
                            } }, label));
                    }
                    actions.append(node('button', { type: 'button', class: 'btn btn-sm btn-outline-danger',
                        'aria-label': `Remove contact ${index + 1}`, onclick: () => {
                            contacts = read(); contacts.splice(index, 1); draw(Math.min(index, contacts.length - 1));
                        } }, 'Remove'));
                    card.append(actions); list.append(card);
                });
                if (!contacts.length) list.append(node('p', { class: 'form-text' }, 'No emergency contacts provided.'));
                add.disabled = contacts.length >= 5;
                status.textContent = `${contacts.length} of 5 emergency contacts`;
                if (focus !== null) {
                    const input = list.querySelectorAll('fieldset')[focus]?.querySelector('input');
                    (input || add).focus();
                }
            };
            add.addEventListener('click', () => {
                contacts = read();
                if (contacts.length >= 5) return;
                contacts.push({ name: '', relationship: '', phone: '', email: '' });
                draw(contacts.length - 1);
            });
            form.addEventListener('submit', event => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity()) return;
                    contacts = read();
                    await request(endpoint, 'POST', { contacts, version: record.version });
                    record = await request(endpoint);
                    if (!container.isConnected) return;
                    contacts = record.contacts.map(contact => ({ ...contact }));
                    draw();
                    notify('Emergency contacts saved.');
                }, save);
            });
            form.append(node('p', { class: 'form-text' },
                'Add up to five people your institution may contact in an emergency. Name and phone are required; relationship and email are optional. Put your first contact at the top.'),
                list, status, node('div', { class: 'd-flex flex-wrap gap-2' }, add, save));
            container.replaceChildren(form); draw();
        },
    });
})();
