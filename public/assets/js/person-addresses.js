/* One owner/staff address editor. Private values stay in the current form. */
(() => {
    'use strict';
    let sequence = 0;
    window.createPersonAddresses = ({ node, request, task, notify }) => ({
        async open(container, endpoint, afterSave = () => {}) {
            container.replaceChildren(node('p', { role: 'status' }, 'Loading addresses…'));
            let record = await request(endpoint);
            if (!container.isConnected) return;
            let addresses = record.addresses.map(address => ({ ...address }));
            let available = [...record.new_ids];
            const form = node('form', { class: 'record-form' });
            const list = node('div', { 'data-address-list': '' });
            const status = node('p', { class: 'form-text', role: 'status' });
            const add = node('button', { type: 'button', class: 'btn btn-outline-primary' }, 'Add address');
            const save = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Save addresses');
            const keys = ['purpose', 'lines', 'locality', 'region', 'postal_code', 'country_code', 'valid_from', 'valid_until'];
            const read = () => [...list.querySelectorAll('fieldset')].map(card => ({ id: card.dataset.addressId,
                ...Object.fromEntries(keys.map(key => [key, card.querySelector(`[data-field="${key}"]`).value])) }));
            const earlier = node('div', { class: 'alert alert-info', hidden: !record.earlier_mailing_address });
            earlier.append(node('strong', {}, 'Earlier mailing text'),
                node('p', { class: 'private-text mb-1' }, record.earlier_mailing_address),
                node('p', { class: 'mb-0' }, 'Adding your first dated mailing address stops using this text. Include an entry covering today if it should still apply. Dates are not inferred from earlier text.'));
            const draw = (focus = null) => {
                list.replaceChildren();
                addresses.forEach((address, index) => {
                    const card = node('fieldset', { class: 'border rounded p-3 mb-3', 'data-address-id': address.id },
                        node('legend', { class: 'h6' }, `Address ${index + 1}`));
                    for (const [key, label, type, maximum, required] of [
                        ['purpose', 'Purpose', 'select', 0, true],
                        ['lines', 'Street or postal address', 'textarea', 500, true],
                        ['locality', 'City or locality', 'text', 120, false],
                        ['region', 'State, province or region', 'text', 120, false],
                        ['postal_code', 'Postal code', 'text', 32, false],
                        ['country_code', 'Country', 'select', 0, true],
                        ['valid_from', 'From date', 'date', 0, true],
                        ['valid_until', 'Through date', 'date', 0, false],
                    ]) {
                        const id = `address-field-${++sequence}`;
                        const input = node(type === 'select' ? 'select' : type === 'textarea' ? 'textarea' : 'input',
                            { id, type: type === 'select' || type === 'textarea' ? null : type,
                                class: type === 'select' ? 'form-select' : 'form-control', required,
                                name: `addresses.${index}.${key}`, 'data-field': key, autocomplete: 'off' });
                        if (maximum) input.maxLength = maximum;
                        if (type === 'select') {
                            input.append(node('option', { value: '' }, 'Choose…'));
                            for (const [value, text] of Object.entries(key === 'purpose' ? record.purposes : record.countries)) {
                                input.append(node('option', { value }, text));
                            }
                        }
                        input.value = address[key] || '';
                        card.append(node('div', { class: 'mb-3' }, node('label', { for: id, class: 'form-label' }, label), input));
                        if (key === 'valid_until') {
                            const help = node('p', { id: `${id}-help`, class: 'form-text' },
                                'The through date is the last day this address applies. Leave it blank for no scheduled end. Dates for the same purpose cannot overlap.');
                            input.setAttribute('aria-describedby', help.id); card.append(help);
                        }
                    }
                    if (record.earlier_mailing_address && !record.mailing_managed) {
                        card.append(node('button', { type: 'button', class: 'btn btn-sm btn-link', onclick: () => {
                            card.querySelector('[data-field="lines"]').value = record.earlier_mailing_address;
                            card.querySelector('[data-field="lines"]').focus();
                        } }, 'Use earlier mailing text'));
                    }
                    card.append(node('button', { type: 'button', class: 'btn btn-sm btn-outline-danger',
                        'aria-label': `Remove address ${index + 1}`, onclick: () => {
                            addresses = read(); addresses.splice(index, 1); draw(Math.min(index, addresses.length - 1));
                        } }, 'Remove'));
                    list.append(card);
                });
                if (!addresses.length) list.append(node('p', { class: 'form-text' }, 'No dated addresses provided.'));
                add.disabled = addresses.length >= 10 || !available.length;
                status.textContent = `${addresses.length} of 10 dated addresses`;
                if (focus !== null) (list.querySelectorAll('fieldset')[focus]?.querySelector('select') || add).focus();
            };
            add.addEventListener('click', () => {
                addresses = read();
                if (addresses.length >= 10 || !available.length) return;
                addresses.push({ id: available.shift(), purpose: 'mailing', lines: '', locality: '', region: '',
                    postal_code: '', country_code: '', valid_from: record.today, valid_until: '' });
                draw(addresses.length - 1);
            });
            form.addEventListener('submit', event => {
                event.preventDefault();
                task(async () => {
                    if (!form.reportValidity()) return;
                    const submitted = read();
                    await request(endpoint, 'POST', { addresses: submitted, version: record.version });
                    const saved = await request(endpoint);
                    if (!form.isConnected) return;
                    const unchanged = JSON.stringify(read()) === JSON.stringify(submitted);
                    record = saved;
                    available = [...saved.new_ids];
                    earlier.hidden = !saved.earlier_mailing_address;
                    if (unchanged) { addresses = saved.addresses.map(address => ({ ...address })); draw(); }
                    await afterSave(saved);
                    notify('Addresses saved.');
                }, save);
            });
            form.append(node('p', { class: 'form-text' },
                'Removing an entry removes it from this address book. Earlier recorded facts remain in protected history.'), earlier, status, list, node('div', { class: 'd-flex flex-wrap gap-2' }, add, save));
            container.replaceChildren(form); draw();
        },
    });
})();
