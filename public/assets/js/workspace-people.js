/* Connected people, account maintenance and admissions review. No private browser storage. */
window.createWorkspacePeople = function (ui) {
    "use strict";
    const {
        node,
        request,
        task,
        openDrawer,
        buildForm,
        formData,
        notify,
        can,
        openPerson,
        badge,
        flow,
        historyPanel,
        recordSummary,
        actionMenu,
    } = ui;
    const button = (label, callback, style = "btn-outline-primary") =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (event) => task(callback, event.currentTarget),
            },
            label,
        );
    const personId = (person) => person.id.value;

    async function editProfile(person) {
        const id = personId(person);
        const record = await request(`/sis/ui/people/${id}/profile/`);
        const current = record.person;
        const fields = [
            ["given_name", "Given name"],
            ["family_name", "Family name"],
            [
                "directory_restricted",
                "Restrict public directory disclosure",
                "checkbox",
            ],
        ];
        await flow(
            "Save person profile",
            fields,
            `/sis/ui/people/${id}/profile/`,
            (data) => ({ ...data, revision: record.revision }),
            () => openPerson(id),
            {
                given_name: current.givenName,
                family_name: current.familyName,
                directory_restricted: current.directoryRestricted,
            },
            "Names stay connected across person, student, employment and sign-in records. Directory restriction does not prevent authorized staff from doing their work.",
            { layout: "people" },
        );
    }
    async function account(person) {
        const pendingView = openDrawer("Sign-in account", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const id = personId(person);
        const record = await request(`/sis/ui/people/${id}/account/`);
        if (!pendingView.isConnected) return;
        const body = openDrawer("Sign-in account", "CONNECTED TO THIS PERSON");
        body.append(button("Back to person", () => openPerson(id), "btn-link"));
        body.append(
            node(
                "dl",
                { class: "detail-list" },
                node(
                    "div",
                    {},
                    node("dt", {}, "Username"),
                    node("dd", {}, record.username),
                ),
                node(
                    "div",
                    {},
                    node("dt", {}, "Email address"),
                    node("dd", {}, record.email),
                ),
                node("div", {}, node("dt", {}, "Sign-in access"),
                    node("dd", {}, record.sign_in_enabled ? "Enabled" : "Disabled")),
            ),
        );
        body.append(
            button("Change sign-in email", () =>
                flow(
                    "Save sign-in email",
                    [["email", "Email address", "email"]],
                    `/sis/ui/people/${id}/account/email/`,
                    (data) => ({ ...data, version: record.version }),
                    () => account(person),
                    record,
                    "Changing the address preserves this person’s institutional ID and effective roles.",
                ),
            ),
        );
        body.append(
            button(
                record.is_self ? "Change my password" : "Reset password",
                () => password(person, record),
            ),
        );
        if (!record.is_self) {
            body.append(button(record.sign_in_enabled ? "Disable sign-in" : "Enable sign-in",
                () => signIn(person, record)));
        }
        if (can("admin:access:manage"))
            body.append(
                node(
                    "p",
                    { class: "form-text mt-4" },
                    "Assign effective roles and extra permissions from this person’s Access tab. A sign-in profile does not assign roles.",
                ),
            );
    }
    async function signIn(person, record) {
        const pendingView = openDrawer("Review sign-in access", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const id = personId(person);
        const enabled = !record.sign_in_enabled;
        const operation = (await request("/sis/ui/operation-id/")).id;
        if (!pendingView.isConnected) return;
        const body = openDrawer(enabled ? "Enable sign-in" : "Disable sign-in", "ACCOUNT ACCESS");
        body.append(button("Back to account", () => account(person), "btn-link"));
        body.append(node("p", {}, enabled
            ? "This person will be able to sign in again with their current password. Earlier sessions and setup links stay invalid."
            : "This blocks new sign-ins and invalidates existing etSIS sessions and linked Moodle launches on their next check. Their person, academic and employment records and roles remain intact."));
        body.append(node("p", { class: "form-text" },
            "This does not change academic standing or independent Moodle password/SSO access."));
        const fields = [["reason", "Reason for sign-in change", "textarea"]];
        const form = await buildForm(fields);
        form.elements.reason.maxLength = 1000;
        const save = node("button", { type: "submit", class: "btn btn-primary" },
            enabled ? "Confirm enable sign-in" : "Confirm disable sign-in");
        form.append(save);
        form.addEventListener("submit", event => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                await request(`/sis/ui/people/${id}/account/sign-in/`, "POST", {
                    ...formData(form, fields), sign_in_enabled: enabled,
                    version: record.version, operation_id: operation,
                });
                notify("Sign-in decision saved.");
                await account(person);
            }, save);
        });
        body.append(form);
    }
    async function history(person) {
        const id = personId(person);
        const body = openDrawer("Profile history", `${person.givenName} ${person.familyName}`);
        body.append(button("Back to person", () => openPerson(id), "btn-link"));
        await historyPanel({
            body, endpoint: `/sis/ui/people/${id}/history/`, label: "Recorded profile changes",
            description: "Review recorded changes, newest first. Earlier personal values, private reasons and sign-in credentials are excluded. Recorder names are current account names; original account attribution is retained.",
            unknownText: "Earlier profile history is unknown. Existing details were first observed when tracking began; this does not establish when those details originally became valid.",
            emptyText: "No profile changes have been recorded yet. Existing details remain in the person record; earlier changes are unknown.",
            render: item => item.earlier_details_unknown
                ? node("p", { class: "form-text" }, "Existing details were observed here. Earlier changes and their dates are unknown.")
                : node("p", {}, `Fields: ${item.fields.join(", ")}`),
        });
    }
    async function contact(person) {
        const pendingView = openDrawer("Contact information", "CONNECTED WORKSPACE");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading record details…"));
        const id = personId(person);
        let record = await request(`/sis/ui/people/${id}/contact/`);
        if (!pendingView.isConnected) return;
        const body = openDrawer(
            "Contact information",
            `${person.givenName} ${person.familyName}`,
        );
        body.append(button("Back to person", () => openPerson(id), "btn-link"));
        const fields = [
            ["preferred_name", "Preferred name", "text", "", false],
            ["phone", "Phone number", "tel", "", false],
            ["address", "Mailing address", "textarea", "", false],
        ];
        const form = await buildForm(fields, record);
        for (const [key, length, autocomplete] of [
            ["preferred_name", 80, "nickname"],
            ["phone", 40, "tel"],
            ["address", 500, "street-address"],
        ]) {
            form.elements[key].maxLength = length;
            form.elements[key].autocomplete = autocomplete;
        }
        form.elements.address.readOnly = !!record.mailing_managed;
        const addressHelp = node("p", { class: "form-text", hidden: !record.mailing_managed },
            "This mailing address comes from dated addresses. Use Manage addresses to change it.");
        form.append(addressHelp);
        form.append(
            node(
                "p",
                { class: "form-text" },
                "These details stay connected to this person throughout their studies and employment. Use Manage sign-in account to change their sign-in email.",
            ),
        );
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Save contact information",
        );
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const submitted = {
                    preferred_name: "",
                    phone: "",
                    address: "",
                    ...formData(form, fields),
                    version: record.version,
                };
                await request(`/sis/ui/people/${id}/contact/`, "POST", submitted);
                const saved = await request(`/sis/ui/people/${id}/contact/`);
                if (!form.isConnected) return;
                record = saved;
                form.elements.address.readOnly = !!saved.mailing_managed;
                addressHelp.hidden = !saved.mailing_managed;
                for (const key of ["preferred_name", "phone", "address"]) {
                    if (form.elements[key].value === submitted[key]) form.elements[key].value = saved[key];
                }
                notify("Contact information saved.");
            }, save);
        });
        const contactPanel = (title, description, glyph, content) => node(
            "section",
            { class: "record-contact-panel", "aria-label": title },
            node("div", { class: "record-task-heading" },
                node("span", { class: "icon-soft", "aria-hidden": "true" },
                    node("i", { class: `bi bi-${glyph}` })),
                node("h3", {}, title)),
            node("p", { class: "form-text" }, description),
            content,
        );
        body.append(contactPanel("Contact details", "Maintain the current contact details for this person.", "person-lines-fill", form));
        const addresses = node("div");
        addresses.append(button("Manage addresses", () => window.createPersonAddresses({ node, request, task, notify })
            .open(addresses, `/sis/ui/people/${id}/addresses/`, saved => {
                const revision = saved.contact_revision;
                if (revision && revision.preferred_name === record.preferred_name && revision.phone === record.phone) {
                    record.version = revision.version;
                }
                if (saved.mailing_managed) {
                    record.address = saved.mailing_address;
                    record.mailing_managed = true;
                    form.elements.address.value = saved.mailing_address;
                    form.elements.address.readOnly = true;
                    addressHelp.hidden = false;
                }
            })));
        body.append(contactPanel("Addresses", "Maintain dated mailing, home and other addresses without changing the contact draft above.", "geo-alt", addresses));
        const emergency = node("div");
        emergency.append(button("Emergency contacts", () =>
            window.createPersonEmergencyContacts({ node, request, task, notify })
                .open(emergency, `/sis/ui/people/${id}/emergency-contacts/`)));
        body.append(contactPanel("Emergency contacts", "Maintain the people to contact in an emergency.", "telephone", emergency));
        let contactBody = body;
        const requests = node("div");
        body.append(contactPanel("Profile update requests", "Request missing information and review the person's replies. Profile edits are saved separately.", "chat-left-text", requests));
        window.createPersonProfileRequests({ node, request, task, notify }).mount(
            requests, `/sis/ui/people/${id}/update-requests/`, { staff: true, showHeading: false, openEditor: area => {
                if (area === 'education') {
                    const content = [...contactBody.childNodes];
                    return education(person, () => {
                        contactBody = openDrawer('Contact information', `${person.givenName} ${person.familyName}`);
                        contactBody.append(button('Back to person', () => openPerson(id), 'btn-link'), ...content);
                    });
                }
                if (area === 'contact') form.elements.preferred_name.focus();
                else (area === 'addresses' ? addresses : emergency).querySelector('button')?.click();
            } },
        );


    }
    function educationWorkflow(body, person, open = false, beforeNavigate = () => true) {
        const id = personId(person);
        const area = node('div', { class: 'record-section mt-3' });
        const requests = node('section', { class: 'mt-4', 'aria-label': 'Profile update requests' });
        body.append(area, requests);
        let editor, followUp = null;
        function openRequests() {
            if (followUp) return followUp;
            requests.replaceChildren();
            followUp = window.createPersonProfileRequests({ node, request, task, notify }).mount(
                requests, `/sis/ui/people/${id}/update-requests/`, { staff: true, area: 'education', openEditor: requestedArea => {
                    if (requestedArea === 'education') return editor.open();
                    if (beforeNavigate() && editor.beforeLeave() && followUp.beforeLeave()) return contact(person);
                } },
            );
            return followUp;
        }
        requests.append(button('Education update requests', openRequests));
        editor = window.createPersonEducation({ node, request, task, notify }).mount(
            area, `/sis/ui/people/${id}/education/`, {
                canReview: can('sis:admissions:manage'), open,
                requestUpdate: () => openRequests().compose('education'),
            },
        );
        return { beforeLeave: () => editor.beforeLeave() && (followUp?.beforeLeave() ?? true) };
    }
    function education(person, back = null) {
        const body = openDrawer('Educational background', 'PERSON');
        let workflow;
        body.append(button(back ? 'Back to contact information' : 'Back to person', () => {
            if (workflow.beforeLeave()) return back ? back() : openPerson(personId(person));
        }, 'btn-link'));
        workflow = educationWorkflow(body, person, true);
    }
    async function password(person, record) {
        const id = personId(person);
        const body = openDrawer(
            record.is_self ? "Change my password" : "Reset sign-in password",
            "ACCOUNT SECURITY",
        );
        body.append(
            button("Back to account", () => account(person), "btn-link"),
        );
        body.append(
            node(
                "p",
                { class: "form-text" },
                "Saving invalidates existing sign-in sessions for this account. Use your institution’s approved process to share a reset password; this screen does not email it.",
            ),
        );
        const fields = [
            ...(record.is_self
                ? [["current_password", "Current password", "password"]]
                : []),
            [
                "password",
                `New password (at least ${record.minimum_password_length} characters)`,
                "password",
            ],
            ["confirm_password", "Confirm new password", "password"],
        ];
        const form = await buildForm(fields);
        for (const key of ["password", "confirm_password"]) {
            form.elements[key].minLength = record.minimum_password_length;
            form.elements[key].maxLength = 4096;
            form.elements[key].autocomplete = "new-password";
        }
        if (record.is_self)
            form.elements.current_password.autocomplete = "current-password";
        const save = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Save new password",
        );
        form.append(save);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                form.elements.confirm_password.setCustomValidity(
                    form.elements.password.value ===
                        form.elements.confirm_password.value
                        ? ""
                        : "The passwords must match.",
                );
                if (!form.reportValidity()) return;
                await request(
                    `/sis/ui/people/${id}/account/password/`,
                    "POST",
                    { ...formData(form, fields), version: record.version },
                );
                form.reset();
                notify(
                    "Password updated. Previous sign-in sessions are invalid.",
                );
                if (record.is_self) location.assign(ui.loginUrl);
                else await account(person);
            }, save);
        });
        form.elements.confirm_password.addEventListener("input", () =>
            form.elements.confirm_password.setCustomValidity(""),
        );
        body.append(form);
    }
    async function application(row) {
        const pendingView = openDrawer("Application review", "ADMISSIONS RECORD");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading application review…"));
        const review = await request(`/sis/applications/${row.id}/review/`);
        const app = review.application;
        const person = review.person;
        if (!pendingView.isConnected) return;
        const body = openDrawer(
            `${person.givenName} ${person.familyName}`,
            "APPLICATION REVIEW",
            {record:{key:`application:${row.id}`,label:`${person.givenName} ${person.familyName}`,run:()=>application(row)}},
        );
        body.append(
            recordSummary([
                ["Institutional ID", person.institutionalId.value],
                ["Application status", badge(app.status)],
                [
                    "Program",
                    review.labels
                        ? `${review.labels.program_code} · ${review.labels.program_title}`
                        : "Not available",
                ],
                ["Entry term", review.labels?.term_code ?? "Not available"],
            ]),
        );
        const actions = node("div", {
            class: "inline-actions",
            role: "group",
            "aria-label": "Application tasks",
        });
        const extra = [button("Application history", () => mayLeave() && decisionHistory(row))];
        if (can("sis:person:manage"))
            extra.push(button("Open person record", () => mayLeave() && openPerson(person.id.value)));
        if (app.status === "submitted")
            actions.append(
                button("Accept application", () => mayLeave() && decide(row, true), "btn-primary"),
                button("Reject application", () => mayLeave() && decide(row, false), "btn-outline-danger"),
            );
        if (app.status === "accepted")
            actions.append(button("Matriculate student", () => mayLeave() && matriculate(row), "btn-primary"));
        if (
            ["accepted", "rejected"].includes(app.status) &&
            can("sis:admissions:manage") &&
            can("sis:admissions:correct")
        )
            extra.push(button("Correct admissions decision", () => mayLeave() && correctDecision(row, app.status)));
        if (can('sis:admissions:withdraw')) {
            if (['submitted', 'accepted', 'rejected'].includes(app.status)) {
                extra.push(button('Withdraw application', () => mayLeave() && reviewState(row, true)));
            } else if (app.status === 'withdrawn') {
                actions.append(button('Return to review', () => mayLeave() && reviewState(row, false), 'btn-primary'));
            }
        }
        if (app.status === 'accepted' && can('sis:admissions:defer'))
            extra.push(button('Entry term', () => mayLeave() && entryTerm(row)));
        if (app.status === "matriculated")
            extra.push(button("Matriculation history", () => mayLeave() && matriculationHistory(row)));
        actions.append(
            extra.length === 1
                ? extra[0]
                : actionMenu("More", extra, { context: "More application actions" }),
        );
        body.append(actions);
        const submission = review.submission;
        const casework = node('div', { class: 'record-section mt-3' });
        const workbench = window.createAdmissionCasework({ node, request, task, notify }).mount(casework, row.id,
            { managePresets: can('sis:forms:manage') });
        const documents = node('div', { class: 'record-section mt-3' });
        const documentEditor = window.createAdmissionDocuments({ node, request, task, notify, holdLabels: ui.documentHoldLabels, retentionLabels: ui.documentRetentionLabels }).mount(documents, row.id, { staff: true });
        body.append(documents);
        const offerArea = node('div'), withdrawalArea = node('div'); body.append(offerArea, withdrawalArea);
        let background = null, withdrawalEditor = null, answerEditor = null;
        const otherForms = () => workbench.beforeLeave() && documentEditor.beforeLeave() && (background?.beforeLeave() ?? true);
        const offerEditor = window.createAdmissionOffers({ node, request, task, notify }).mount(offerArea, row.id,
            { staff: true, canIssue: can('sis:admissions:offers'), canPolicy: can('sis:admissions:policy'),
                beforeChange: () => otherForms() && (withdrawalEditor?.beforeLeave() ?? true) });
        withdrawalEditor = window.createAdmissionWithdrawals({ node, request, task, notify }).mount(withdrawalArea, row.id,
            { staff: true, canResolve: can('sis:admissions:withdraw'), initiallyOpen: row.openWithdrawal === true,
                beforeChange: () => otherForms() && offerEditor.beforeLeave(),
                onOutcome: async result => {
                    if (result.application_status !== app.status && body.isConnected && mayLeave()) await application(row);
                    else { try { await offerEditor.refresh(); } catch { notify('Request saved. Reopen the offer to refresh its status.'); } }
                } });
        function mayLeave() {
            return otherForms() && offerEditor.beforeLeave() && (withdrawalEditor?.beforeLeave() ?? true) && (answerEditor?.beforeLeave() ?? true);
        }
        if (typeof row.followUpBack === "function") body.prepend(
            button("Back to follow-up queue", () => mayLeave() && row.followUpBack()),
        );
        if (typeof row.reportBack === "function") body.prepend(
            button("Back to report", () => mayLeave() && row.reportBack()),
        );
        if (can('sis:communications:manage') && can('sis:person:manage')) actions.prepend(
            button('Message applicant', () => ui.messageRecord('application', row.id, () => application(row), body,
                mayLeave)),
        );
        body.append(window.createReviewGuidance({ node }, window.capturedReviewGuidance.admissions(review), {
            checklist: { label: 'Go to checklist', find: () => casework.querySelector('button') },
            documents: { label: 'Go to documents', find: () => documents.querySelector('button') },
            offer: { label: 'Go to admission offer', find: () => offerArea.querySelector('button') },
        }));
        body.append(casework);
        if (can('sis:person:manage')) {
            background = educationWorkflow(body, person, false, mayLeave);
        }
        if (submission) {
            const section = node('section', { class: 'record-section' });
            body.append(section);
            answerEditor = window.createApplicationRevisions({ node, request, task, notify }).mount(section, row.id,
                { staff: true, canAmend: can('sis:admissions:amend'), initiallyOpen: true,
                    beforeChange: () => otherForms() && offerEditor.beforeLeave() && (withdrawalEditor?.beforeLeave() ?? true) });
        } else
            body.append(
                node(
                    "p",
                    { class: "record-section" },
                    "This application was entered directly. No application-form answers are attached.",
                ),
            );
    }
    function entryTerm(row) {
        const body = openDrawer('Entry term', 'ADMISSIONS APPROVAL');
        let editor = null;
        body.append(button('Back to application', () => (editor?.beforeLeave() ?? true) && application(row)));
        editor = window.createAdmissionEntry({ node, request, task, notify }).mount(body, row.id,
            { onComplete: () => application(row) });
    }
    async function decide(row, accept, preset = {}) {
        const title = accept ? "Accept application" : "Reject application";
        const body = openDrawer(title, "ADMISSIONS DECISION");
        body.append(
            button("Back to application", () => application(row)),
            node(
                "p",
                {},
                "Review the submitted answers in the application, then enter the reason for this decision. Confirmation retains the application’s program, term and submitted form revision.",
            ),
        );
        const fields = [
            ["reason", "Reason for admissions decision", "textarea"],
        ];
        const form = await buildForm(fields, preset, { layout: "decision_reason" });
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review admissions decision",
        );
        const evidenceHost = node("section", { class: "record-section" });
        const evidenceEditor = window.createAdmissionDocuments({ node, request, task, notify });
        const evidence = evidenceEditor.evidence(evidenceHost, row.id, preset.documents || []);
        form.append(evidenceHost, review);
        const controls = node("fieldset", {}, node("legend", { class: "visually-hidden" }, "Admissions decision details"));
        controls.append(...form.childNodes);
        form.append(controls);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (controls.disabled || !form.reportValidity()) return;
                const data = { ...formData(form, fields), documents: evidence.selection() };
                controls.disabled = true;
                try {
                    const preview = await request(
                        `/sis/applications/${row.id}/decision/review/`,
                        "POST",
                        { ...data, decision: accept ? "accept" : "reject" },
                    );
                    if (!form.isConnected) return;
                    const confirmation = openDrawer(
                        accept ? "Confirm acceptance" : "Confirm rejection",
                        "REVIEWED DECISION",
                    );
                    const source = preview.source;
                    confirmation.append(
                        button("Revise admissions decision", () =>
                            decide(row, accept, data),
                        ),
                        node(
                            "h3",
                            {},
                            `${preview.person.given_name} ${preview.person.family_name} · ${preview.status}`,
                        ),
                        node(
                            "p",
                            {},
                            `Institutional ID: ${preview.person.institutional_id}`,
                        ),
                        node(
                            "p",
                            {},
                            `${source.program_code} · ${source.program_title} · ${source.entry_term_code}`,
                        ),
                        node(
                            "p",
                            {},
                            source.submission
                                ? `${source.submission.form_name} · submitted form revision ${source.submission.form_version}`
                                : "This application was entered directly; no application-form submission is attached.",
                        ),
                        node(
                            "p",
                            {},
                            `Effective on confirmation: ${preview.effective_on}. ${accept ? "Matriculation remains a separate reviewed action." : "This records a rejection; it does not create a student record."}`,
                        ),
                        button(
                            accept ? "Confirm acceptance" : "Confirm rejection",
                            async () => {
                                await request(
                                    `/sis/applications/${row.id}/decision/commit/`,
                                    "POST",
                                    { review_token: preview.review_token },
                                );
                                notify("Admissions decision recorded.");
                                if (heading.isConnected) await application(row);
                            },
                            "btn-primary",
                        ),
                    );
                    if (preview.answers_review) {
                        const retained = preview.answers_review;
                        confirmation.insertBefore(node('section', { class: 'record-section' },
                            node('h4', { class: 'h6' }, 'Current revised answers'),
                            node('p', { class: 'form-text' }, `Resubmitted: ${retained.submitted_at}. Review these answers before confirming.`),
                            window.createApplicationAnswerReview({ fields: retained.form.fields,
                                layout: retained.form.layout || [], answers: retained.answers })), confirmation.lastElementChild);
                    }
                    if (preview.checklist) confirmation.insertBefore(
                        window.createAdmissionReadiness({ node }).staff(preview.checklist), confirmation.lastElementChild);
                    const evidenceSummary = node("div");
                    evidenceEditor.summary(evidenceSummary, preview.evidence ?? null);
                    confirmation.insertBefore(evidenceSummary, confirmation.lastElementChild);
                    const heading = confirmation.querySelector("h3");
                    heading.tabIndex = -1;
                    heading.focus();
                } finally { controls.disabled = false; }
            }, review);
        });
        body.append(form);
    }
    async function correctDecision(row, currentStatus, preset = {}) {
        const target = currentStatus === "accepted" ? "rejected" : "accepted";
        const body = openDrawer(
            "Correct admissions decision",
            "PRE-MATRICULATION CORRECTION",
        );
        body.append(
            button("Back to application", () => application(row)),
            node(
                "p",
                {},
                `Change ${currentStatus} to ${target}. The earlier decision stays in history. This action does not reverse matriculation or change student records.`,
            ),
        );
        const fields = [
            ["reason", "Reason for admissions correction", "textarea"],
        ];
        const form = await buildForm(fields, preset, { layout: "decision_reason" });
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review admissions correction",
        );
        const evidenceHost = node("section", { class: "record-section" });
        const evidenceEditor = window.createAdmissionDocuments({ node, request, task, notify });
        const evidence = evidenceEditor.evidence(evidenceHost, row.id, preset.documents || []);
        form.append(evidenceHost, review);
        const controls = node("fieldset", {}, node("legend", { class: "visually-hidden" }, "Admissions decision details"));
        controls.append(...form.childNodes);
        form.append(controls);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (controls.disabled || !form.reportValidity()) return;
                const data = { ...formData(form, fields), documents: evidence.selection() };
                controls.disabled = true;
                try {
                    const preview = await request(
                        `/sis/applications/${row.id}/decision/correction/review/`,
                        "POST",
                        {
                            ...data,
                            decision: target === "accepted" ? "accept" : "reject",
                        },
                    );
                    if (!form.isConnected) return;
                    const confirmation = openDrawer(
                        "Confirm admissions correction",
                        "REVIEWED CORRECTION",
                    );
                    confirmation.append(
                        button("Revise admissions correction", () =>
                            correctDecision(row, currentStatus, data),
                        ),
                        node(
                            "h3",
                            {},
                            `${preview.before.status} → ${preview.status}`,
                        ),
                        node(
                            "p",
                            {},
                            `${preview.person.given_name} ${preview.person.family_name} · ${preview.person.institutional_id}`,
                        ),
                        node(
                            "p",
                            {},
                            `${preview.source.program_code} · ${preview.source.program_title} · ${preview.source.entry_term_code}`,
                        ),
                        node(
                            "p",
                            {},
                            preview.before.history_recorded
                                ? "The previous recorded outcome remains in history. This correction takes effect on confirmation."
                                : "The earlier decision was not recorded. Its current status will be observed before this correction; no earlier decision date will be invented.",
                        ),
                        node(
                            "p",
                            {},
                            `Effective on confirmation: ${preview.effective_on}`,
                        ),
                        button(
                            "Confirm admissions correction",
                            async () => {
                                await request(
                                    `/sis/applications/${row.id}/decision/correction/commit/`,
                                    "POST",
                                    { review_token: preview.review_token },
                                );
                                notify("Admissions correction recorded.");
                                if (heading.isConnected) await application(row);
                            },
                            "btn-primary",
                        ),
                    );
                    if (preview.answers_review) {
                        const retained = preview.answers_review;
                        confirmation.insertBefore(node('section', { class: 'record-section' },
                            node('h4', { class: 'h6' }, 'Current revised answers'),
                            node('p', { class: 'form-text' }, `Resubmitted: ${retained.submitted_at}. Review these answers before confirming.`),
                            window.createApplicationAnswerReview({ fields: retained.form.fields,
                                layout: retained.form.layout || [], answers: retained.answers })), confirmation.lastElementChild);
                    }
                    if (preview.checklist) confirmation.insertBefore(
                        window.createAdmissionReadiness({ node }).staff(preview.checklist), confirmation.lastElementChild);
                    const evidenceSummary = node("div");
                    evidenceEditor.summary(evidenceSummary, preview.evidence ?? null);
                    confirmation.insertBefore(evidenceSummary, confirmation.lastElementChild);
                    const heading = confirmation.querySelector("h3");
                    heading.tabIndex = -1;
                    heading.focus();
                } finally { controls.disabled = false; }
            }, review);
        });
        body.append(form);
    }
    async function reviewState(row, withdraw, preset = {}) {
        const title = withdraw ? 'Withdraw application' : 'Return to review';
        const description = withdraw
            ? 'Close this application before matriculation. Earlier decisions and documents remain in history.'
            : 'Return this withdrawn application for a fresh decision. This does not restore an earlier acceptance or reopen submitted answers.';
        const body = openDrawer(title, 'ADMISSIONS REVIEW');
        body.append(button('Back to application', () => application(row)), node('p', {}, description));
        const fields = [['reason', 'Reason for this change', 'textarea']];
        const form = await buildForm(fields, preset, { layout: 'decision_reason' });
        form.elements.reason.required = true; form.elements.reason.maxLength = 10000;
        const controls = node('fieldset', {}, node('legend', { class: 'visually-hidden' }, title));
        controls.append(...form.childNodes); form.append(controls);
        const review = node('button', { type: 'submit', class: 'btn btn-primary' }, 'Review change');
        controls.append(review); body.append(form);
        form.addEventListener('submit', event => { event.preventDefault(); task(async () => {
            if (controls.disabled || !form.reportValidity()) return;
            const data = formData(form, fields); controls.disabled = true;
            try {
                const preview = await request(`/sis/applications/${row.id}/review-state/review/`, 'POST', { ...data, withdraw });
                if (!form.isConnected) return;
                const confirmation = openDrawer(withdraw ? 'Confirm withdrawal' : 'Confirm return to review', 'REVIEWED CHANGE');
                const heading = node('h3', { tabindex: '-1' }, `${preview.person.given_name} ${preview.person.family_name}`);
                confirmation.append(button('Revise change', () => reviewState(row, withdraw, data)), heading,
                    node('p', {}, `Institutional ID: ${preview.person.institutional_id}`),
                    node('p', {}, `${preview.source.program_code} · ${preview.source.program_title} · ${preview.source.entry_term_code}`),
                    node('p', {}, `${preview.previous_status} → ${preview.status}. Effective on confirmation: ${preview.effective_on}.`),
                    node('p', {}, description),
                    node('p', { class: 'form-text' }, 'The person account and any separate student record remain unchanged.'),
                    button(withdraw ? 'Confirm withdrawal' : 'Confirm return to review', async () => {
                        await request(`/sis/applications/${row.id}/review-state/commit/`, 'POST', { review_token: preview.review_token });
                        notify(withdraw ? 'Application withdrawn.' : 'Application returned for a fresh review.');
                        if (heading.isConnected) await application(row);
                    }, withdraw ? 'btn-danger' : 'btn-primary'));
                heading.focus();
            } finally { controls.disabled = false; }
        }, review); });
    }
    async function decisionHistory(row, cursor = null, previous = []) {
        const pendingView = openDrawer("Application history", "ADMISSIONS RECORD");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading application history…"));
        const record =
            cursor === null
                ? await request(`/sis/applications/${row.id}/decision/history/`)
                : await request(
                      `/sis/applications/${row.id}/decision/history/page/`,
                      "POST",
                      { cursor },
                  );
        if (!pendingView.isConnected) return;
        const body = openDrawer("Application history", "ADMISSIONS RECORD");
        body.append(button("Back to application", () => application(row)));
        if (record.submission) {
            const birth = record.submission;
            const original = birth.source;
            body.append(
                node(
                    "section",
                    { class: "record-section" },
                    node("h3", {}, "Application submitted"),
                    node(
                        "p",
                        {},
                        `${original.program_code} · ${original.program_title} · ${original.entry_term_code}`,
                    ),
                    node(
                        "p",
                        {},
                        original.submission
                            ? `${original.submission.form_name} · submitted form revision ${original.submission.form_version}`
                            : "Direct application entry; no form answers were attached.",
                    ),
                    node(
                        "p",
                        {},
                        `Submitted: ${birth.effective_at}. Recorded: ${birth.recorded_at}.`,
                    ),
                    node(
                        "p",
                        {},
                        birth.authorization === "owner"
                            ? "Submitted by the applicant’s account."
                            : "Entered by authorized admissions staff for the applicant.",
                    ),
                ),
            );
        }
        if (!record.history_recorded) {
            body.append(
                node(
                    "p",
                    {},
                    record.submission
                        ? "No acceptance or rejection has been recorded yet."
                        : "No acceptance or rejection has been recorded in the protected decision history. Earlier decisions and original submission timing remain unknown.",
                ),
            );
            if (!record.items?.some(item => item.kind !== 'submission' && item.kind !== 'observation')) return;
        }
        if (Array.isArray(record.items)) {
            body.append(
                node(
                    "p",
                    {},
                    `Current application status: ${record.current_status}`,
                ),
            );
            for (const item of record.items.filter(
                (item) => item.kind !== "submission",
            )) {
                const observed = item.kind === "observation";
                const reviewState = ['withdrawal', 'review_resumed'].includes(item.kind);
                const offer = item.kind.startsWith('offer_');
                const entry = item.kind.startsWith('entry_');
                const resubmission = item.kind === 'resubmission';
                const section = node(
                    "section",
                    { class: "record-section" },
                    node(
                        "h3",
                        {},
                        resubmission ? 'Application answers resubmitted' : entry ? (item.kind === 'entry_defer' ? 'Entry deferred' : 'Updated intake approved')
                        : offer ? `Admission offer: ${item.status}` : observed
                            ? "Observed current application"
                            : reviewState ? (item.kind === 'withdrawal' ? (item.withdrawal_channel === 'applicant' ? 'Applicant withdrew application' : 'Application withdrawn') : 'Returned to review')
                            : item.kind === "correction"
                              ? `Decision corrected: ${item.previous_status} → ${item.status}`
                              : item.status === "accepted"
                                ? "Application accepted"
                                : "Application rejected",
                    ),
                );
                if (item.source)
                    section.append(
                        node(
                            "p",
                            {},
                            `${item.source.program_code} · ${item.source.program_title} · ${item.source.entry_term_code}`,
                        ),
                    );
                section.append(
                    node(
                        "p",
                        {},
                        observed
                            ? `Status seen: ${item.status}. Earlier decision timing is unknown. Observed: ${item.recorded_at}.`
                            : `Effective: ${item.effective_at}. Recorded: ${item.recorded_at}.`,
                    ),
                );
                if (item.checklist) section.append(window.createAdmissionReadiness({ node }).history(item.checklist));
                else if (['decision', 'correction'].includes(item.kind)) section.append(
                    node('p', { class: 'form-text' }, 'The checklist used for this earlier decision was not recorded.'));
                if (offer) {
                    section.append(node('h4', { class: 'h6 text-break' }, item.offer.title),
                        node('p', { class: 'private-text text-break', style: 'white-space: pre-wrap' }, item.offer.message));
                }
                if (entry) section.append(node('p', { class: 'text-break' },
                    `${item.entry.before.code} → ${item.entry.after.code} · ${item.entry.after.year_code}`),
                    node('p', { class: 'form-text' }, `${item.entry.after.starts_on} through ${item.entry.after.ends_on}`));
                if (!observed && !reviewState && !offer && !entry && !resubmission) window.createAdmissionDocuments({ node, request, task, notify }).summary(section, item.evidence ?? null);
                body.append(section);
            }
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Decisions are listed in recording order. Original outcomes stay in history. Protected reasons, actor details, submission fingerprints and answers are excluded.",
                ),
            );
            const controls = node("div", { class: "inline-actions" });
            if (previous.length)
                controls.append(
                    button("Previous application history page", () =>
                        decisionHistory(
                            row,
                            previous[previous.length - 1],
                            previous.slice(0, -1),
                        ),
                    ),
                );
            if (record.next_cursor)
                controls.append(
                    button("Next application history page", () =>
                        decisionHistory(row, record.next_cursor, [
                            ...previous,
                            cursor,
                        ]),
                    ),
                );
            body.append(controls);
            return;
        }
        const source = record.source;
        body.append(
            node(
                "h3",
                {},
                record.decision.status === "accepted"
                    ? "Application accepted"
                    : "Application rejected",
            ),
            node(
                "p",
                {},
                `${source.program_code} · ${source.program_title} · ${source.entry_term_code}`,
            ),
            node(
                "p",
                {},
                source.submission
                    ? `${source.submission.form_name} · submitted form revision ${source.submission.form_version}`
                    : "Directly entered application; no application-form submission was attached.",
            ),
            node(
                "p",
                {},
                `Effective: ${record.decision.effective_at}. Recorded: ${record.recorded_at}.`,
            ),
            node(
                "p",
                {},
                `Current application status: ${record.current_status}`,
            ),
            node(
                "p",
                { class: "form-text" },
                record.earlier_history_unknown === false
                    ? "This history starts with the actual submission above. Decision labels are retained as recorded. Protected reasons, actor details, submission fingerprints and answers are excluded."
                    : "The earlier submitted application was observed when tracking this decision began. Its original submission time is unknown. These labels are retained as recorded. Protected reasons, actor details, submission fingerprints and answers are excluded.",
            ),
        );
    }
    async function matriculate(row, preset = {}) {
        const body = openDrawer("Matriculate student", "ACCEPTED APPLICATION");
        body.append(
            button("Back to application", () => application(row)),
            node(
                "p",
                {},
                "Review the student’s first program, entry term and cohort before creating their record. The person and institutional ID stay connected.",
            ),
        );
        const fields = [["reason", "Reason for matriculation", "textarea"]];
        const form = await buildForm(fields, preset, { layout: "decision_reason" });
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review matriculation",
        );
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const preview = await request(
                    `/sis/applications/${row.id}/matriculate/review/`,
                    "POST",
                    data,
                );
                const confirmation = openDrawer(
                    "Confirm matriculation",
                    "REVIEWED DECISION",
                );
                const source = preview.source;
                confirmation.append(
                    button("Revise matriculation", () =>
                        matriculate(row, data),
                    ),
                    node(
                        "h3",
                        {},
                        `${preview.person.given_name} ${preview.person.family_name}`,
                    ),
                    node(
                        "p",
                        {},
                        `Institutional ID: ${preview.person.institutional_id}`,
                    ),
                    node(
                        "p",
                        {},
                        `${source.program_code} · ${source.program_title} · ${source.entry_term_code}`,
                    ),
                    node(
                        "p",
                        {},
                        source.cohort
                            ? `Cohort: ${source.cohort.year_code}. Curriculum revision ${source.cohort.revision}${source.cohort.catalog_id ? " is available." : " will be created on confirmation."}`
                            : "This term has no linked academic year. No cohort will be assigned; review calendar setup separately.",
                    ),
                    node(
                        "p",
                        {},
                        `The student becomes active on ${preview.effective_on}. This records the first program and primary selection together. Degree-audit requirements are assigned separately.`,
                    ),
                    button(
                        "Confirm matriculation",
                        async () => {
                            await request(
                                `/sis/applications/${row.id}/matriculate/commit/`,
                                "POST",
                                { review_token: preview.review_token },
                            );
                            notify(
                                "Student record created. Initial program and activity history recorded.",
                            );
                            if (heading.isConnected) await application(row);
                        },
                        "btn-primary",
                    ),
                );
                const heading = confirmation.querySelector("h3");
                heading.tabIndex = -1;
                heading.focus();
            }, review);
        });
        body.append(form);
    }
    async function matriculationHistory(row) {
        const pendingView = openDrawer("Matriculation history", "ADMISSIONS RECORD");
        pendingView.append(node("p", { role: "status", class: "form-text" }, "Loading matriculation history…"));
        const record = await request(
            `/sis/applications/${row.id}/matriculate/history/`,
        );
        if (!pendingView.isConnected) return;
        const body = openDrawer("Matriculation history", "ADMISSIONS RECORD");
        body.append(button("Back to application", () => application(row)));
        if (!record.history_recorded) {
            body.append(
                node(
                    "p",
                    {},
                    "This application’s earlier matriculation was not recorded in the event history. No creation date or first program is inferred from current records.",
                ),
            );
            return;
        }
        const source = record.source;
        body.append(
            node("h3", {}, "Initial matriculation"),
            node(
                "p",
                {},
                `${source.program_code} · ${source.program_title} · ${source.entry_term_code}`,
            ),
            node(
                "p",
                {},
                source.cohort
                    ? `Original cohort: ${source.cohort.year_code} · curriculum revision ${source.cohort.revision}`
                    : "No cohort was assigned at matriculation.",
            ),
            node("p", {}, `Confirmed: ${record.matriculation.effective_at}`),
            node(
                "p",
                { class: "form-text" },
                record.acceptance_history_unknown === false
                    ? "These are the original creation facts. Acceptance is retained in Application history. Later program and activity decisions remain in their respective histories. Protected reasons and actor details are excluded."
                    : "These are the original creation facts. Later program and activity decisions remain in their respective histories. The preceding acceptance decision is not yet tracked as an academic event. Protected reasons and actor details are excluded.",
            ),
        );
    }
    function documentRetention() {
        const labels = ui.documentRetentionLabels || {};
        const body = openDrawer(labels['Document retention'] || 'Document retention', labels['ADMISSIONS SETTINGS'] || 'ADMISSIONS SETTINGS');
        let editor = null;
        body.append(button(labels['Back to Admissions'] || 'Back to Admissions', () => (editor?.beforeLeave() ?? true) && ui.closeDrawer(), 'btn-link'));
        editor = window.createAdmissionRetention({ node, request, task, notify, labels }).mountPolicy(body);
    }
    function admissionPolicy() {
        const body = openDrawer('Admissions policy', 'INSTITUTIONAL SETUP');
        let editor = null;
        body.append(button('Back to Admissions', () => (editor?.beforeLeave() ?? true) && ui.closeDrawer(), 'btn-link'));
        editor = window.createAdmissionPolicy({ node, request, task, notify }).mount(body);
    }
    function withdrawalQueue() {
        const body = openDrawer('Withdrawal requests', 'ADMISSIONS WORKLIST');
        window.createAdmissionWithdrawals({ node, request, task, notify }).queue(body, row => application(row));
    }
    function caseQueue(state = null) {
        const body = openDrawer('Admissions follow-up', 'APPLICATION WORKBENCH');
        body.append(button('Back to Admissions', ui.closeDrawer, 'btn-link'));
        window.createAdmissionCasework({ node, request, task, notify }).queue(body,
            (row, accepted) => application({ ...row, followUpBack: () => caseQueue(accepted) }), state);
    }
    return { editProfile, account, contact, education, history, application, caseQueue, admissionPolicy, documentRetention, withdrawalQueue };
};
