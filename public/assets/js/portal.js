/* Campus portal: private data lives only in this page's memory. */
(() => {
    'use strict';
    const one = (selector, root = document) => root.querySelector(selector);
    const all = (selector, root = document) => [
        ...root.querySelectorAll(selector),
    ];
    const config = one('#campus-config')
        ? JSON.parse(one('#campus-config').textContent)
        : {};
    let me = null;
    let dialogOpener = null;
    let pageLeaving = false;
    let controlSequence = 0;
    let catalogAfter = '';
    let catalogCount = 0;
    const icon = (name) =>
        el('i', { class: `bi bi-${name}`, 'aria-hidden': 'true' });
    const amount = (minor, currency) => {
        const formatter = new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
        });
        return formatter.format(
            Number(minor) /
                10 ** formatter.resolvedOptions().maximumFractionDigits,
        );
    };

    function el(tag, attributes = {}, ...children) {
        const element = document.createElement(tag);
        for (const [key, value] of Object.entries(attributes)) {
            if (value === null || value === undefined || value === false)
                continue;
            if (key.startsWith('on'))
                element.addEventListener(key.slice(2), value);
            else if (key === 'class') element.className = value;
            else if (key === 'value') element.value = value;
            else if (key === 'checked') element.checked = value;
            else element.setAttribute(key, value === true ? '' : String(value));
        }
        for (const child of children.flat())
            if (child !== null && child !== undefined)
                element.append(
                    child instanceof Node
                        ? child
                        : document.createTextNode(String(child)),
                );
        return element;
    }

    async function request(path, method = 'GET', data) {
        const token = one('#campus-csrf input')?.value;
        const response = await fetch(path, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
                ...(method === 'GET'
                    ? {}
                    : {
                          'Content-Type':
                              'application/x-www-form-urlencoded;charset=UTF-8',
                          'X-CSRF-Token': token || '',
                      }),
            },
            body:
                method === 'GET'
                    ? undefined
                    : new URLSearchParams({
                          _workspace_json: JSON.stringify(data || {}),
                      }).toString(),
        });
        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error(
                'The server sent an unreadable response. Check whether your change was saved before trying again.',
            );
        }
        if (!response.ok) {
            const error = new Error(
                result.error ||
                    'We could not complete that request. Please try again.',
            );
            error.fields = result.errors || [];
            if (response.status === 403)
                error.message =
                    'This service needs a valid sign-in or additional access. Please sign in again if your session has expired.';
            throw error;
        }
        return result;
    }

    function showError(error) {
        let box = one('#campus-error');
        if (one('#campus-dialog')?.classList.contains('show')) {
            box = one('#campus-dialog-error');
            if (!box) {
                box = el('div', {
                    id: 'campus-dialog-error',
                    class: 'alert alert-danger',
                    role: 'alert',
                    tabindex: '-1',
                });
                one('#campus-dialog-body').prepend(box);
            }
        }
        if (!box) {
            box = el('div', {
                id: 'campus-error',
                class: 'alert alert-danger campus-container',
                role: 'alert',
                tabindex: '-1',
            });
            one('#campus-main').prepend(box);
        }
        box.replaceChildren(
            el('strong', {}, error.message || 'Please reload and try again.'),
        );
        if (error.fields?.length) {
            const list = el('ul', { class: 'mb-0 mt-2' });
            for (const item of error.fields) {
                const field = all(`[name="${CSS.escape(item.field)}"]`).find(
                    (control) => control.getClientRects().length,
                );
                const label =
                    field?.labels?.[0]?.textContent
                        .replace(/\s*\*$/, '')
                        .trim() || item.field;
                list.append(el('li', {}, `${label}: ${item.message}`));
                field?.setAttribute('aria-invalid', 'true');
            }
            box.append(list);
        }
        box.hidden = false;
        box.focus();
    }

    function notice(message) {
        const box = one('#campus-notice');
        if (!box) return;
        box.textContent = message;
        box.hidden = false;
        setTimeout(() => {
            box.hidden = true;
        }, 7000);
    }

    async function task(callback, button) {
        if (button) {
            const modal = one('#campus-dialog');
            if (
                modal &&
                !modal.classList.contains('show') &&
                !modal.contains(button)
            )
                dialogOpener = button;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
        }
        if (one('#campus-error')) one('#campus-error').hidden = true;
        try {
            return await callback();
        } catch (error) {
            showError(error);
        } finally {
            if (button) {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        }
    }

    function button(label, callback, primary = false) {
        return el(
            'button',
            {
                class: `btn ${primary ? 'campus-button' : 'campus-button-outline'}`,
                type: 'button',
                onclick: (event) => task(callback, event.currentTarget),
            },
            label,
        );
    }

    function empty(title, description) {
        return el(
            'div',
            { class: 'campus-empty' },
            icon('compass'),
            el('h2', {}, title),
            el('p', {}, description),
        );
    }

    function surface(title, ...children) {
        return el(
            'section',
            { class: 'campus-surface' },
            el('h2', { class: 'mb-4' }, title),
            ...children,
        );
    }

    function table(headers, rows, caption) {
        return el(
            'div',
            {
                class: 'table-responsive',
                tabindex: '0',
                role: 'region',
                'aria-label': caption,
            },
            el(
                'table',
                { class: 'campus-table' },
                el('caption', { class: 'visually-hidden' }, caption),
                el(
                    'thead',
                    {},
                    el(
                        'tr',
                        {},
                        headers.map((header) =>
                            el('th', { scope: 'col' }, header),
                        ),
                    ),
                ),
                el(
                    'tbody',
                    {},
                    rows.map((row) =>
                        el(
                            'tr',
                            {},
                            row.map((value) =>
                                el('td', {}, value == null ? '—' : value),
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    function chip(value) {
        const good = [
            'active',
            'accepted',
            'matriculated',
            'fulfilled',
            'ready',
            'registered',
        ].includes(String(value).toLowerCase());
        return el(
            'span',
            { class: `campus-chip${good ? ' success' : ''}` },
            String(value).replaceAll('_', ' '),
        );
    }

    function dialog(title, ...children) {
        if (pageLeaving) return;
        if (!dialogOpener?.isConnected) dialogOpener = document.activeElement;
        one('#campus-dialog-title').textContent = title;
        one('#campus-dialog-body').replaceChildren(...children);
        bootstrap.Modal.getOrCreateInstance(one('#campus-dialog')).show();
    }

    one('#campus-dialog')?.addEventListener('hidden.bs.modal', () => {
        const destination = dialogOpener?.isConnected
            ? dialogOpener
            : one('#campus-main');
        destination?.focus();
        dialogOpener = null;
    });

    function control(form, key, label, type = 'text', options = {}) {
        const id = `portal-${key}-${++controlSequence}`;
        const input = el(
            type === 'textarea'
                ? 'textarea'
                : type === 'select'
                  ? 'select'
                  : 'input',
            {
                class:
                    type === 'checkbox'
                        ? 'form-check-input'
                        : type === 'select'
                          ? 'form-select'
                          : 'form-control',
                id,
                name: key,
                ...(type === 'textarea'
                    ? { rows: 4 }
                    : type === 'select'
                      ? {}
                      : { type }),
                ...options,
            },
        );
        const labelElement = el(
            'label',
            {
                class: type === 'checkbox' ? 'form-check-label' : 'form-label',
                for: id,
            },
            label,
        );
        const wrapper =
            type === 'checkbox'
                ? el('div', { class: 'form-check mb-4' }, input, labelElement)
                : el('div', { class: 'mb-4' }, labelElement, input);
        form.append(wrapper);
        input.addEventListener('input', () =>
            input.removeAttribute('aria-invalid'),
        );
        return input;
    }

    function selectOptions(select, options, placeholder = 'Choose…') {
        select.replaceChildren(el('option', { value: '' }, placeholder));
        options.forEach((option) =>
            select.append(el('option', { value: option.id }, option.label)),
        );
    }

    function termName(id) {
        return (
            config.terms?.find((term) => term.id === id)?.code ||
            'Academic term'
        );
    }
    function programName(id) {
        return (
            config.programs?.find((program) => program.id === id)?.title ||
            'Academic program'
        );
    }

    function courseCard(course, registration = false) {
        const seats = Math.max(
            0,
            Number(course.capacity) - Number(course.enrolled),
        );
        const card = el(
            'article',
            { class: 'campus-course-card' },
            el(
                'div',
                {
                    class: 'd-flex justify-content-between align-items-center gap-2',
                },
                el(
                    'strong',
                    { class: 'campus-program-code' },
                    course.course_code,
                ),
                chip(course.term_code),
            ),
            el('h3', {}, course.title),
            el(
                'p',
                {},
                `Section ${course.section_code} · ${Number(course.credits_milli) / 1000} credits`,
            ),
            el('p', {}, `${seats} of ${course.capacity} seats available`),
            el(
                'p',
                {},
                `Section fee: ${amount(course.fee_minor, course.currency)}`,
            ),
        );
        if (
            registration &&
            me?.students.length &&
            Number(course.web_registration) &&
            seats > 0
        ) {
            card.append(
                button(
                    'Review & register',
                    () => confirmRegistration(course),
                    true,
                ),
            );
        } else if (!Number(course.web_registration))
            card.append(
                el(
                    'p',
                    { class: 'campus-muted' },
                    'Contact the registrar to register for this section.',
                ),
            );
        else
            card.append(
                el(
                    'a',
                    {
                        class: 'btn campus-button-outline',
                        href: `/portal/student/registration/?section=${encodeURIComponent(course.id)}`,
                    },
                    seats ? 'View registration options' : 'Section full',
                ),
            );
        return card;
    }

    async function loadPublicCatalog(append = false) {
        const form = one('#campus-course-search');
        const query = new URLSearchParams(new FormData(form));
        if (append && catalogAfter) query.set('after', catalogAfter);
        const result = await request(`/portal/api/courses/?${query}`);
        const container = one('#campus-course-results');
        if (!append) {
            container.replaceChildren();
            catalogCount = 0;
        }
        catalogAfter = result.next_after || '';
        catalogCount += result.items.length;
        result.items.forEach((course) => container.append(courseCard(course)));
        if (!catalogCount)
            container.append(
                empty(
                    'A little room for possibility.',
                    'No course sections match these filters. Try another term or search.',
                ),
            );
        one('#campus-course-count').textContent =
            `${catalogCount} course ${catalogCount === 1 ? 'section' : 'sections'} shown${catalogAfter ? '. More are available.' : '.'}`;
        one('#campus-course-more').hidden = !catalogAfter;
    }

    async function refreshMe() {
        const result = await request('/portal/api/me/');
        if (pageLeaving) throw new Error('This page is closing.');
        me = result;
        return me;
    }

    function overview(container) {
        const firstName = me.contact.preferred_name || me.person.given_name;
        one('#campus-service-title').textContent =
            `Welcome back, ${firstName}.`;
        one('#campus-service-intro').textContent =
            'Your next steps, your important details, and a little breathing room.';
        container.append(
            surface(
                'Your campus identity',
                el(
                    'dl',
                    { class: 'campus-detail-list' },
                    el(
                        'div',
                        {},
                        el('dt', {}, 'Name'),
                        el(
                            'dd',
                            {},
                            `${me.person.given_name} ${me.person.family_name}`,
                        ),
                    ),
                    el(
                        'div',
                        {},
                        el('dt', {}, 'Institutional ID'),
                        el('dd', {}, me.person.institutional_id),
                    ),
                ),
            ),
        );
        const links = [
            [
                'applications',
                'Your next chapter',
                'See application progress or apply to another program.',
                'send',
            ],
            [
                'registration',
                'Build your semester',
                'Find available course sections and manage your registration.',
                'calendar2-plus',
            ],
            [
                'grades',
                'See how you’re doing',
                'Grades, academic progress, and your current enrollment.',
                'mortarboard',
            ],
            [
                'accounts',
                'Keep things in balance',
                'Your statements, payments, and account history.',
                'wallet2',
            ],
        ];
        container.append(
            el(
                'div',
                { class: 'campus-service-grid' },
                links.map(([path, title, detail, name]) =>
                    el(
                        'a',
                        {
                            class: 'campus-service-card',
                            href: `/portal/student/${path}/`,
                        },
                        icon(name),
                        el('h2', {}, title),
                        el('p', {}, detail),
                    ),
                ),
            ),
        );
        const updates = me.news.slice(0, 3);
        if (updates.length)
            container.append(
                surface(
                    'Your campus is talking.',
                    updates.map((post) =>
                        button(post.title, () =>
                            dialog(
                                post.title,
                                el(
                                    'div',
                                    {
                                        class: 'campus-article-body',
                                        style: 'white-space:pre-wrap',
                                    },
                                    post.content,
                                ),
                            ),
                        ),
                    ),
                ),
            );
    }

    function profile(container) {
        container.append(
            surface(
                'Your institutional record',
                el(
                    'dl',
                    { class: 'campus-detail-list' },
                    el(
                        'div',
                        {},
                        el('dt', {}, 'Legal name'),
                        el(
                            'dd',
                            {},
                            `${me.person.given_name} ${me.person.family_name}`,
                        ),
                    ),
                    el(
                        'div',
                        {},
                        el('dt', {}, 'Institutional ID'),
                        el('dd', {}, me.person.institutional_id),
                    ),
                ),
                el(
                    'p',
                    { class: 'campus-muted mt-4 mb-0' },
                    'Contact the registrar for changes to your legal name or assigned institutional ID.',
                ),
            ),
        );
        const form = el('form');
        control(
            form,
            'preferred_name',
            'What would you like us to call you?',
            'text',
            {
                maxlength: 80,
                value: me.contact.preferred_name,
                autocomplete: 'given-name',
            },
        );
        control(form, 'phone', 'Phone number', 'tel', {
            maxlength: 40,
            value: me.contact.phone,
            autocomplete: 'tel',
        });
        const address = control(
            form,
            'address',
            'Mailing address',
            'textarea',
            { maxlength: 500, autocomplete: 'street-address' },
        );
        address.value = me.contact.address;
        form.append(
            el(
                'button',
                { class: 'btn campus-button', type: 'submit' },
                'Save personal information',
            ),
        );
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            task(
                async () => {
                    if (!form.reportValidity()) return;
                    await request('/portal/api/contact/', 'POST', {
                        ...Object.fromEntries(new FormData(form)),
                        version: me.contact.version,
                    });
                    await refreshMe();
                    notice('Your contact information is up to date.');
                },
                one('[type="submit"]', form),
            );
        });
        container.append(surface('Stay connected', form));
        container.append(
            el(
                'p',
                { class: 'campus-muted' },
                'Your contact information is encrypted and stays attached to your person record.',
            ),
        );
    }

    function matches(group, facts) {
        const conditions = group.rules.map((rule) => {
            if (rule.condition) return matches(rule, facts);
            const actual = facts[rule.id];
            if (actual === undefined || actual === null || actual === '')
                return false;
            switch (rule.operator) {
                case 'equal':
                    return actual === rule.value;
                case 'not_equal':
                    return actual !== rule.value;
                case 'in':
                    return rule.value.includes(actual);
                case 'not_in':
                    return !rule.value.includes(actual);
                case 'less':
                    return Number.isInteger(actual) && actual < rule.value;
                case 'greater':
                    return Number.isInteger(actual) && actual > rule.value;
                default:
                    return false;
            }
        });
        return group.condition === 'AND'
            ? conditions.every(Boolean)
            : conditions.some(Boolean);
    }

    function applicationForm(schema) {
        const form = el('form');
        for (const field of schema.fields) {
            const input = control(
                form,
                field.key,
                `${field.label}${field.required ? ' *' : ''}`,
                field.type,
                {
                    required: field.required,
                    ...(field.type === 'textarea'
                        ? { maxlength: 4000 }
                        : ['text', 'email'].includes(field.type)
                          ? { maxlength: 191 }
                          : {}),
                    ...(field.type === 'number'
                        ? { step: 1, min: -999999999, max: 999999999 }
                        : {}),
                },
            );
            if (field.type === 'select')
                selectOptions(
                    input,
                    field.options.map((option) => ({
                        id: option,
                        label: option,
                    })),
                );
            if (field.help) {
                const help = el(
                    'div',
                    { class: 'form-text', id: `${input.id}-help` },
                    field.help,
                );
                input.after(help);
                input.setAttribute('aria-describedby', help.id);
            }
        }
        const answers = () => {
            const facts = {};
            for (const field of schema.fields) {
                const input = form.elements.namedItem(field.key);
                const visible =
                    !field.show_when || matches(field.show_when, facts);
                input.closest('.mb-4').hidden = !visible;
                input.disabled = !visible;
                if (visible) {
                    if (field.type === 'checkbox')
                        facts[field.key] = input.checked;
                    else if (input.value !== '')
                        facts[field.key] =
                            field.type === 'number'
                                ? Number(input.value)
                                : input.value;
                }
            }
            return facts;
        };
        form.addEventListener('input', answers);
        form.addEventListener('change', answers);
        answers();
        return { form, answers };
    }

    function applications(container) {
        if (me?.applications.length)
            container.append(
                surface(
                    'Your applications',
                    table(
                        ['Program', 'Entry term', 'Progress'],
                        me.applications.map((application) => [
                            programName(application.program_id),
                            termName(application.entry_term_id),
                            chip(application.status),
                        ]),
                        'Your admissions applications',
                    ),
                ),
            );
        const form = el('form');
        const program = control(
            form,
            'program_id',
            'Choose your academic program',
            'select',
            { required: true },
        );
        selectOptions(
            program,
            config.programs.map((item) => ({
                id: item.id,
                label: `${item.title}${item.form_id ? '' : ' — application not yet available'}`,
            })),
        );
        const requested = new URLSearchParams(location.search).get('program');
        if (requested) program.value = requested;
        const term = control(
            form,
            'entry_term_id',
            'When would you like to begin?',
            'select',
            { required: true },
        );
        selectOptions(
            term,
            config.terms.map((item) => ({ id: item.id, label: item.code })),
        );
        form.append(
            el(
                'button',
                { class: 'btn campus-button', type: 'submit' },
                config.signedIn
                    ? 'Continue to your application'
                    : 'See your next step',
                icon('arrow-right'),
            ),
        );
        const next = el('div', { class: 'mt-4' });
        const finder = programFinder(program, next);
        program.addEventListener('change', () => next.replaceChildren());
        term.addEventListener('change', () => next.replaceChildren());
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            task(
                async () => {
                    if (!form.reportValidity()) return;
                    const selected = config.programs.find(
                        (item) => item.id === program.value,
                    );
                    next.replaceChildren();
                    if (!selected?.form_id) {
                        next.append(
                            empty(
                                'This application isn’t open just yet.',
                                'Please contact admissions for the next application opportunity for this program.',
                            ),
                        );
                        return;
                    }
                    if (!config.signedIn) {
                        next.append(
                            surface(
                                'A small first step. A world of possibility.',
                                el(
                                    'p',
                                    {},
                                    'Create a secure account or sign in to complete your program’s application. Your answers stay private.',
                                ),
                                el(
                                    'div',
                                    { class: 'campus-actions' },
                                    el(
                                        'a',
                                        {
                                            class: 'btn campus-button',
                                            href: '/register/',
                                        },
                                        'Create your account',
                                    ),
                                    el(
                                        'a',
                                        {
                                            class: 'btn campus-button-outline',
                                            href: `${config.loginUrl || '/login/'}?continue=apply`,
                                        },
                                        'Sign in to apply',
                                    ),
                                ),
                            ),
                        );
                        return;
                    }
                    const schema = await request(
                        `/sis/forms/programs/${program.value}/active/`,
                    );
                    const { form: answersForm, answers } =
                        applicationForm(schema);
                    answersForm.append(
                        el(
                            'p',
                            { class: 'campus-muted' },
                            'Required questions are marked with an asterisk. Your answers are private and encrypted.',
                        ),
                        el(
                            'button',
                            { class: 'btn campus-button', type: 'submit' },
                            'Submit application',
                            icon('send'),
                        ),
                    );
                    const programId = program.value;
                    const termId = term.value;
                    answersForm.addEventListener('submit', (event) => {
                        event.preventDefault();
                        task(
                            async () => {
                                const data = answers();
                                if (!answersForm.reportValidity()) return;
                                await request(
                                    `/sis/forms/${schema.id}/submissions/`,
                                    'POST',
                                    {
                                        submission_id: schema.submission_id,
                                        person_id: me.person.id,
                                        entry_term_id: termId,
                                        answers: data,
                                    },
                                );
                                answersForm.reset();
                                next.replaceChildren(
                                    empty(
                                        'You’re one step closer.',
                                        `Your application to ${programName(programId)} has been submitted. Admissions will review your next steps.`,
                                    ),
                                );
                                await refreshMe();
                                notice(
                                    'Application submitted. Here’s to your next chapter.',
                                );
                            },
                            one('[type="submit"]', answersForm),
                        );
                    });
                    next.append(
                        surface(
                            schema.name,
                            el(
                                'p',
                                { class: 'campus-muted' },
                                `${programName(programId)} · ${termName(termId)}`,
                            ),
                            answersForm,
                        ),
                    );
                    next.tabIndex = -1;
                    next.focus();
                },
                one('[type="submit"]', form),
            );
        });
        container.append(
            surface(
                'Make this path your own',
                el(
                    'ol',
                    { class: 'campus-step-list mb-4' },
                    el('li', {}, 'Choose a program and entry term.'),
                    el('li', {}, 'Complete the questions for your program.'),
                    el('li', {}, 'Submit, then follow your progress here.'),
                ),
                finder,
                form,
                next,
            ),
        );
    }

    function programFinder(program, next) {
        const details = el(
            'details',
            { class: 'mb-4' },
            el(
                'summary',
                { class: 'campus-text-link mb-3' },
                'Search all academic programs',
            ),
        );
        const form = el('form');
        const search = control(
            form,
            'program_search',
            'Program code or title',
            'search',
            { maxlength: 80 },
        );
        const status = el('p', { class: 'campus-muted mt-3', role: 'status' });
        let after = '';
        const more = button('Show more programs', () => load(true));
        more.hidden = config.programs.length < 100;
        if (!more.hidden)
            after = config.programs[config.programs.length - 1].id;
        const load = async (append = false) => {
            const params = new URLSearchParams({ q: search.value });
            if (append && after) params.set('after', after);
            const result = await request(`/portal/api/programs/?${params}`);
            const known = new Map(
                config.programs.map((item) => [item.id, item]),
            );
            result.items.forEach((item) => known.set(item.id, item));
            config.programs = [...known.values()];
            if (!append)
                program.replaceChildren(el('option', { value: '' }, 'Choose…'));
            const shown = new Set(
                [...program.options].map((option) => option.value),
            );
            result.items.forEach((item) => {
                if (!shown.has(item.id))
                    program.append(
                        el(
                            'option',
                            { value: item.id },
                            `${item.title}${item.form_id ? '' : ' — application not yet available'}`,
                        ),
                    );
            });
            after = result.next_after || '';
            more.hidden = !after;
            next.replaceChildren();
            status.textContent = `${program.options.length - 1} matching programs loaded. Choose one below.`;
        };
        form.append(
            el(
                'button',
                { class: 'btn campus-button-outline', type: 'submit' },
                'Find programs',
            ),
        );
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            task(() => load(), one('[type="submit"]', form));
        });
        details.append(form, status, more);
        const requested = new URLSearchParams(location.search).get('program');
        if (
            requested &&
            ![...program.options].some((option) => option.value === requested)
        ) {
            search.value = requested;
            task(async () => {
                await load();
                program.value = requested;
            });
        }
        return details;
    }

    async function grades(container) {
        if (!me.students.length) {
            container.append(
                empty(
                    'Your academic story starts here.',
                    'Grades and academic status will appear after you have a student record.',
                ),
            );
            return;
        }
        for (const student of me.students) {
            const credits = await request(
                `/portal/api/students/${student.id}/transcript/`,
            );
            const holds = me.restrictions.filter(
                (item) =>
                    item.starts_on <= new Date().toISOString().slice(0, 10) &&
                    (!item.ends_on ||
                        item.ends_on >= new Date().toISOString().slice(0, 10)),
            );
            const gpaCredits = credits.items
                .filter((item) => item.counts_gpa)
                .reduce(
                    (total, item) => total + Number(item.attempted_milli),
                    0,
                );
            const gradePoints = credits.items
                .filter((item) => item.counts_gpa)
                .reduce(
                    (total, item) => total + Number(item.grade_points_milli),
                    0,
                );
            container.append(
                surface(
                    programName(student.primary_program_id),
                    el(
                        'div',
                        { class: 'd-flex flex-wrap gap-3 mb-4' },
                        chip(Number(student.active) ? 'Active' : 'Inactive'),
                        chip(`Entry term ${termName(student.entry_term_id)}`),
                    ),
                    el(
                        'p',
                        { class: 'campus-muted' },
                        gpaCredits
                            ? `Unofficial GPA from recorded GPA-bearing credits: ${(gradePoints / gpaCredits).toFixed(3)}`
                            : 'GPA will appear when GPA-bearing final grades have been recorded.',
                    ),
                    holds.length
                        ? el(
                              'div',
                              { class: 'alert alert-warning' },
                              'Your record has current restrictions. Contact the relevant campus office before registration.',
                              el(
                                  'ul',
                                  {},
                                  holds.map((item) => el('li', {}, item.code)),
                              ),
                          )
                        : el(
                              'p',
                              { class: 'campus-muted' },
                              'No current restrictions are listed on your record.',
                          ),
                    table(
                        [
                            'Course',
                            'Title',
                            'Attempted credits',
                            'Earned credits',
                            'Grade',
                        ],
                        credits.items.map((item) => [
                            item.course_code,
                            item.course_title,
                            Number(item.attempted_milli) / 1000,
                            Number(item.completed_milli) / 1000,
                            item.grade_code || 'Not yet graded',
                        ]),
                        'Your unofficial academic record',
                    ),
                ),
            );
            const enrolled = student.enrollments.filter((item) =>
                ['A', 'N'].includes(item.status),
            );
            if (enrolled.length)
                container.append(
                    surface(
                        'A closer look at your coursework',
                        enrolled.map((item) =>
                            button(
                                `${item.course_code} · ${item.section_code}: assignment scores`,
                                async () => {
                                    const scores = await request(
                                        `/portal/api/students/${student.id}/sections/${item.section_id}/scores/`,
                                    );
                                    dialog(
                                        `${item.course_code} — assignment scores`,
                                        scores.items.length
                                            ? table(
                                                  [
                                                      'Assignment',
                                                      'Earned',
                                                      'Possible',
                                                  ],
                                                  scores.items.map((score) => [
                                                      score.title,
                                                      score.earned_hundredths ==
                                                      null
                                                          ? 'Not yet graded'
                                                          : Number(
                                                                score.earned_hundredths,
                                                            ) / 100,
                                                      Number(
                                                          score.possible_hundredths,
                                                      ) / 100,
                                                  ]),
                                                  'Your assignment scores',
                                              )
                                            : empty(
                                                  'Nothing graded yet.',
                                                  'Assignment scores will appear when your instructor records them.',
                                              ),
                                    );
                                },
                            ),
                        ),
                    ),
                );
        }
    }

    async function registration(container) {
        if (!me.students.length) {
            container.append(
                empty(
                    'One step at a time.',
                    'Online course registration becomes available after admissions creates your student record.',
                ),
            );
            return;
        }
        for (const student of me.students) {
            container.append(
                surface(
                    'Your registered sections',
                    student.enrollments.length
                        ? table(
                              ['Course', 'Section', 'Status', 'Actions'],
                              student.enrollments.map((item) => [
                                  item.course_code,
                                  item.section_code,
                                  chip(
                                      {
                                          A: 'Registered',
                                          N: 'Registered',
                                          D: 'Dropped',
                                          W: 'Withdrawn',
                                          C: 'Cancelled',
                                      }[item.status] || item.status,
                                  ),
                                  ['A', 'N'].includes(item.status)
                                      ? button('Drop / withdraw', async () => {
                                            dialog(
                                                'Review your registration change',
                                                el(
                                                    'p',
                                                    {},
                                                    `You’re changing ${item.course_code}, section ${item.section_code}. The term’s add/drop deadline determines whether this is recorded as a drop or withdrawal. Fee adjustments follow your institution’s billing policy.`,
                                                ),
                                                button(
                                                    'Confirm drop / withdrawal',
                                                    async () => {
                                                        await request(
                                                            '/portal/api/drop/',
                                                            'POST',
                                                            {
                                                                student_id:
                                                                    student.id,
                                                                section_id:
                                                                    item.section_id,
                                                            },
                                                        );
                                                        bootstrap.Modal.getInstance(
                                                            one(
                                                                '#campus-dialog',
                                                            ),
                                                        ).hide();
                                                        notice(
                                                            'Your registration has been updated.',
                                                        );
                                                        await renderService();
                                                    },
                                                    true,
                                                ),
                                            );
                                        })
                                      : '—',
                              ]),
                              'Your course registrations',
                          )
                        : empty(
                              'Let’s build your semester.',
                              'Find an available section below and review its registration details.',
                          ),
                ),
            );
        }
        const form = el('form', { class: 'campus-catalog-filters' });
        const term = control(form, 'term', 'Academic term', 'select');
        selectOptions(
            term,
            config.terms.map((item) => ({ id: item.id, label: item.code })),
            'All terms',
        );
        const query = control(form, 'q', 'Course code or title', 'search', {
            maxlength: 80,
            placeholder: 'Find a course',
        });
        form.append(
            el(
                'button',
                { class: 'btn campus-button', type: 'submit' },
                'Find sections',
            ),
        );
        const grid = el('div', { class: 'campus-course-grid' });
        const more = button('Show more sections', () => load(true));
        more.hidden = true;
        let after = '';
        let total = 0;
        const load = async (append = false) => {
            const result = await request(
                `/portal/api/courses/?${new URLSearchParams({ term: term.value, q: query.value, after: append ? after : '' })}`,
            );
            if (!append) {
                grid.replaceChildren();
                total = 0;
            }
            result.items.forEach((item) => grid.append(courseCard(item, true)));
            total += result.items.length;
            after = result.next_after || '';
            more.hidden = !after;
            if (!total)
                grid.append(
                    empty(
                        'No matching sections just yet.',
                        'Try another term or a different course code.',
                    ),
                );
        };
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            task(() => load(), one('[type="submit"]', form));
        });
        container.append(
            el('h2', { class: 'mt-5 mb-4' }, 'Find your next course'),
            form,
            grid,
            more,
        );
        await load();
    }

    function confirmRegistration(course) {
        const form = el('form');
        const student = control(
            form,
            'student_id',
            'Student program',
            'select',
            { required: true },
        );
        selectOptions(
            student,
            me.students.map((item) => ({
                id: item.id,
                label: programName(item.primary_program_id),
            })),
        );
        if (me.students.length === 1) student.value = me.students[0].id;
        form.append(
            el(
                'p',
                {},
                `${course.course_code} · ${course.title} · Section ${course.section_code}`,
            ),
            el(
                'p',
                {},
                `Section fee: ${amount(course.fee_minor, course.currency)}`,
            ),
            el(
                'p',
                { class: 'campus-muted' },
                'Registration may add course and institutional fees to your account. Review your institution’s billing policy before continuing. Eligibility and seat availability are checked when you register.',
            ),
            el(
                'button',
                { class: 'btn campus-button', type: 'submit' },
                'Confirm registration',
            ),
        );
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            task(
                async () => {
                    if (!form.reportValidity()) return;
                    await request('/portal/api/register/', 'POST', {
                        student_id: student.value,
                        section_id: course.id,
                    });
                    bootstrap.Modal.getInstance(one('#campus-dialog')).hide();
                    notice('You’re registered. Your semester is taking shape.');
                    await renderService();
                },
                one('[type="submit"]', form),
            );
        });
        dialog('A new possibility, on your schedule.', form);
    }

    function transcripts(container) {
        if (!me.students.length) {
            container.append(
                empty(
                    'Your record will grow with you.',
                    'Transcript services become available once you have a student record.',
                ),
            );
            return;
        }
        container.append(
            surface(
                'Your academic record',
                el(
                    'p',
                    { class: 'campus-muted' },
                    'View an unofficial record here, or request an official transcript for registrar review.',
                ),
                me.students.map((student) =>
                    button(
                        `View unofficial record · ${programName(student.primary_program_id)}`,
                        async () => {
                            const result = await request(
                                `/portal/api/students/${student.id}/transcript/`,
                            );
                            dialog(
                                'Unofficial academic record',
                                el(
                                    'p',
                                    {},
                                    `${me.person.given_name} ${me.person.family_name} · ${me.person.institutional_id}`,
                                ),
                                table(
                                    [
                                        'Course',
                                        'Title',
                                        'Credits earned',
                                        'Grade',
                                    ],
                                    result.items.map((item) => [
                                        item.course_code,
                                        item.course_title,
                                        Number(item.completed_milli) / 1000,
                                        item.grade_code || 'Not yet graded',
                                    ]),
                                    'Unofficial transcript',
                                ),
                                el(
                                    'p',
                                    { class: 'campus-muted mt-3' },
                                    'For personal reference. Official transcripts are issued by the registrar.',
                                ),
                                button('Print this record', () =>
                                    window.print(),
                                ),
                            );
                        },
                    ),
                ),
            ),
        );
        const form = el('form');
        const student = control(
            form,
            'student_id',
            'Student program',
            'select',
            { required: true },
        );
        selectOptions(
            student,
            me.students.map((item) => ({
                id: item.id,
                label: programName(item.primary_program_id),
            })),
        );
        if (me.students.length === 1) student.value = me.students[0].id;
        const delivery = control(
            form,
            'delivery',
            'Delivery preference',
            'select',
            { required: true },
        );
        selectOptions(delivery, [
            { id: 'pickup', label: 'Collect from the registrar' },
            {
                id: 'electronic',
                label: 'Electronic delivery — registrar review',
            },
        ]);
        delivery.value = 'pickup';
        const recipient = control(
            form,
            'recipient',
            'Recipient email address',
            'email',
            { maxlength: 191 },
        );
        recipient.closest('.mb-4').hidden = true;
        recipient.disabled = true;
        delivery.addEventListener('change', () => {
            const electronic = delivery.value === 'electronic';
            recipient.closest('.mb-4').hidden = !electronic;
            recipient.disabled = !electronic;
            recipient.required = electronic;
        });
        form.append(
            el(
                'p',
                { class: 'campus-muted' },
                'The registrar will review your request and arrange delivery. Submitting this form does not automatically send a transcript or charge a fee.',
            ),
            el(
                'button',
                { class: 'btn campus-button', type: 'submit' },
                'Request official transcript',
            ),
        );
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            task(
                async () => {
                    if (!form.reportValidity()) return;
                    await request('/portal/api/transcripts/', 'POST', {
                        request_id: me.request_id,
                        student_id: student.value,
                        delivery: delivery.value,
                        recipient: recipient.disabled ? '' : recipient.value,
                    });
                    notice(
                        'Your request is with the registrar. Follow its progress below.',
                    );
                    await renderService();
                },
                one('[type="submit"]', form),
            );
        });
        container.append(surface('Request an official transcript', form));
        if (me.transcript_requests.length)
            container.append(
                surface(
                    'Your requests',
                    table(
                        ['Requested', 'Delivery', 'Status', 'Actions'],
                        me.transcript_requests.map((item) => [
                            item.requested_at.slice(0, 10),
                            item.delivery === 'pickup'
                                ? 'Registrar pickup'
                                : 'Electronic',
                            chip(item.status),
                            item.status === 'pending'
                                ? button('Cancel request', async () => {
                                      await request(
                                          `/portal/api/transcripts/${item.id}/status/`,
                                          'POST',
                                          {
                                              status: 'cancelled',
                                              version: Number(item.version),
                                          },
                                      );
                                      notice('Transcript request cancelled.');
                                      await renderService();
                                  })
                                : '—',
                        ]),
                        'Your transcript requests',
                    ),
                ),
            );
    }

    function accounts(container) {
        if (!me.accounts.length) {
            container.append(
                empty(
                    'A clear starting point.',
                    'Student account statements will appear when the institution opens an account for you.',
                ),
            );
            return;
        }
        for (const account of me.accounts) {
            const balance = Number(account.balance_minor);
            container.append(
                surface(
                    termName(account.term_id),
                    el(
                        'p',
                        { class: 'campus-muted mb-1' },
                        balance > 0
                            ? 'Amount due'
                            : balance < 0
                              ? 'Credit balance'
                              : 'Current balance',
                    ),
                    el(
                        'p',
                        { class: 'campus-amount' },
                        amount(Math.abs(balance), account.currency),
                    ),
                    el(
                        'div',
                        { class: 'campus-actions mt-3' },
                        button('View statement', async () => {
                            const statement = await request(
                                `/portal/api/accounts/${account.id}/`,
                            );
                            dialog(
                                `${termName(account.term_id)} — account statement`,
                                el(
                                    'p',
                                    { class: 'campus-amount' },
                                    amount(
                                        statement.balance_minor,
                                        statement.currency,
                                    ),
                                ),
                                table(
                                    ['Entry', 'Amount', 'Status'],
                                    statement.entries.map((entry) => [
                                        entry.type,
                                        amount(entry.minor, entry.currency),
                                        entry.reversed ? 'Reversed' : 'Posted',
                                    ]),
                                    'Your student account entries',
                                ),
                            );
                        }),
                        balance > 0
                            ? button(
                                  'Pay bill',
                                  async () => {
                                      const status = await request(
                                          '/portal/api/payments/status/',
                                      );
                                      if (!status.enabled) {
                                          dialog(
                                              'Paying your student bill',
                                              el(
                                                  'p',
                                                  {},
                                                  'Online payment setup is not yet available. Contact student accounts for the institution’s current payment options.',
                                              ),
                                              el(
                                                  'p',
                                                  { class: 'campus-muted' },
                                                  'Your balance and payment history remain available in your statement.',
                                              ),
                                          );
                                          return;
                                      }
                                      dialog(
                                          'Review your payment',
                                          el(
                                              'p',
                                              {},
                                              `${termName(account.term_id)} · ${amount(balance, account.currency)}`,
                                          ),
                                          el(
                                              'p',
                                              {},
                                              'Continue to secure hosted checkout to pay this balance. Your card details are entered with the payment provider, never stored in the SIS.',
                                          ),
                                          button(
                                              'Continue to secure checkout',
                                              async () => {
                                                  const checkout =
                                                      await request(
                                                          '/portal/api/payments/checkout/',
                                                          'POST',
                                                          {
                                                              account_id:
                                                                  account.id,
                                                              payment_id:
                                                                  me.payment_id,
                                                          },
                                                      );
                                                  const url = new URL(
                                                      checkout.url,
                                                  );
                                                  if (
                                                      url.protocol !==
                                                          'https:' ||
                                                      url.hostname !==
                                                          'checkout.stripe.com'
                                                  )
                                                      throw new Error(
                                                          'The payment provider returned an unexpected checkout destination.',
                                                      );
                                                  location.assign(url.href);
                                              },
                                              true,
                                          ),
                                      );
                                  },
                                  true,
                              )
                            : null,
                    ),
                ),
            );
        }
        container.append(
            el(
                'p',
                { class: 'campus-muted' },
                'Balances are shown separately by account and currency. A payment appears after it has been verified by the provider.',
            ),
        );
        const returned = new URLSearchParams(location.search).get('checkout');
        if (returned === 'returned')
            container.prepend(
                el(
                    'p',
                    { class: 'alert alert-info' },
                    'Welcome back. Returning from checkout does not confirm payment; your statement updates after verification.',
                ),
            );
        if (returned === 'cancelled')
            container.prepend(
                el(
                    'p',
                    { class: 'alert alert-info' },
                    'Checkout was cancelled. You can review your statement or start again when you’re ready.',
                ),
            );
    }

    function updates(container) {
        if (!me.news.length) {
            container.append(
                empty(
                    'Keep a little space for good news.',
                    'Published campus updates will appear here.',
                ),
            );
            return;
        }
        me.news.forEach((post) =>
            container.append(
                surface(
                    post.title,
                    el(
                        'p',
                        {
                            class: 'campus-article-body',
                            style: 'white-space:pre-wrap',
                        },
                        post.content,
                    ),
                ),
            ),
        );
        let after = me.news.length === 24 ? me.news[23].id : '';
        const more = button('Older campus updates', async () => {
            const result = await request(
                `/portal/api/campus-news/?after=${encodeURIComponent(after)}`,
            );
            result.items.forEach((post) =>
                more.before(
                    surface(
                        post.title,
                        el(
                            'p',
                            {
                                class: 'campus-article-body',
                                style: 'white-space:pre-wrap',
                            },
                            post.content,
                        ),
                    ),
                ),
            );
            after = result.next_after || '';
            more.hidden = !after;
        });
        more.hidden = !after;
        container.append(more);
    }

    async function renderService() {
        const container = one('#campus-service-content');
        if (!container || pageLeaving) return;
        if (!config.signedIn) {
            container.replaceChildren();
            applications(container);
            return;
        }
        await refreshMe();
        const restoreFocus = container.contains(document.activeElement);
        const content = document.createDocumentFragment();
        const actions = {
            overview,
            profile,
            applications,
            grades,
            registration,
            transcripts,
            accounts,
            updates,
        };
        await actions[config.section](content);
        if (pageLeaving) return;
        container.replaceChildren(content);
        if (restoreFocus) {
            container.tabIndex = -1;
            container.focus();
        }
    }

    window.addEventListener('pagehide', () => {
        pageLeaving = true;
        me = null;
        if (!one('#campus-service-content')) return;
        one('#campus-service-content').replaceChildren(
            el(
                'p',
                { class: 'campus-muted' },
                'Sign in to view your current records.',
            ),
        );
        one('#campus-service-title').textContent = 'My campus';
        one('#campus-dialog-body').replaceChildren();
        dialogOpener = null;
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) location.reload();
    });

    if (one('#campus-course-search')) {
        const term = new URLSearchParams(location.search).get('term');
        if (term) one('#campus-term').value = term;
        one('#campus-course-search').addEventListener('submit', (event) => {
            event.preventDefault();
            task(
                () => loadPublicCatalog(),
                one('[type="submit"]', event.currentTarget),
            );
        });
        one('#campus-course-more').addEventListener('click', (event) =>
            task(() => loadPublicCatalog(true), event.currentTarget),
        );
        task(() => loadPublicCatalog());
    }
    if (one('#campus-service-content')) task(() => renderService());
})();
