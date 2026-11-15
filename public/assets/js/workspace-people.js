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
        );
    }
    async function account(person) {
        const id = personId(person);
        const record = await request(`/sis/ui/people/${id}/account/`);
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
        const id = personId(person);
        const enabled = !record.sign_in_enabled;
        const operation = (await request("/sis/ui/operation-id/")).id;
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
        const panel = node("div");
        body.append(panel);
        panel.append(button("Back to person", () => openPerson(id), "btn-link"));
        panel.append(node("p", { class: "form-text" },
            "Review recorded changes, newest first. Earlier personal values, private reasons and sign-in credentials are excluded. Recorder names are current account names; original account attribution is retained."));
        const notice = node("p", { class: "alert alert-info", hidden: true },
            "Earlier profile history is unknown. Existing details were first observed when tracking began; this does not establish when those details originally became valid.");
        const status = node("p", { role: "status", class: "form-text" }, "Loading recorded changes…");
        const list = node("ul", { class: "list-unstyled", "aria-label": "Recorded profile changes" });
        let after = 0;
        let through = null;
        let shown = 0;
        let epoch = 0;
        const time = (label, value) => node("p", { class: "small mb-1" }, `${label}: `,
            node("time", { datetime: value }, new Date(value).toLocaleString(undefined,
                { dateStyle: "medium", timeStyle: "long" })));
        const load = async (older = false) => {
            const expectedEpoch = ++epoch;
            const data = await request(`/sis/ui/people/${id}/history/`, "POST",
                older ? { after, through_version: through } : { after: 0 });
            if (!panel.isConnected || epoch !== expectedEpoch) return;
            if (!Array.isArray(data.items)) throw new Error("The history could not be loaded. Try refreshing it.");
            if (!older) { list.replaceChildren(); shown = 0; }
            through = data.through_version;
            after = data.next_after;
            notice.hidden = !data.earlier_history_unknown;
            for (const item of data.items) {
                const card = node("article", { class: "record-section" },
                    node("h3", {}, `${item.category} · ${item.action}`),
                    time("Recorded", item.recorded_at),
                    node("p", { class: "small mb-2 private-text" }, `Recorded by: ${item.recorded_by}`));
                if (item.earlier_details_unknown) {
                    card.append(node("p", { class: "form-text" },
                        "Existing details were observed here. Earlier changes and their dates are unknown."));
                } else {
                    card.append(node("p", {}, `Fields: ${item.fields.join(", ")}`));
                    if (item.effective_at) card.append(time("Effective", item.effective_at));
                }
                list.append(node("li", {}, card));
                shown++;
            }
            more.hidden = after === null;
            status.textContent = through === 0
                ? "No profile changes have been recorded yet. Existing details remain in the person record; earlier changes are unknown."
                : `Showing ${shown} of ${through} recorded changes.`;
        };
        const refresh = button("Refresh history", () => load());
        const more = button("Load older changes", () => load(true), "btn-outline-secondary");
        more.hidden = true;
        panel.append(refresh, notice, status, list, more);
        await load();
    }
    async function contact(person) {
        const id = personId(person);
        let record = await request(`/sis/ui/people/${id}/contact/`);
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
                for (const key of ["preferred_name", "phone", "address"]) {
                    if (form.elements[key].value === submitted[key]) form.elements[key].value = saved[key];
                }
                notify("Contact information saved.");
            }, save);
        });
        body.append(form);
        const emergency = node("div", { class: "mt-4" });
        emergency.append(button("Emergency contacts", () =>
            window.createPersonEmergencyContacts({ node, request, task, notify })
                .open(emergency, `/sis/ui/people/${id}/emergency-contacts/`)));
        body.append(emergency);

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
        const review = await request(`/sis/applications/${row.id}/review/`);
        const app = review.application;
        const person = review.person;
        const body = openDrawer(
            `${person.givenName} ${person.familyName}`,
            "APPLICATION REVIEW",
        );
        body.append(
            badge(app.status),
            node(
                "p",
                { class: "mt-3" },
                `Institutional ID: ${person.institutionalId.value}`,
            ),
        );
        if (review.labels)
            body.append(
                node(
                    "p",
                    {},
                    `${review.labels.program_code} · ${review.labels.program_title} · ${review.labels.term_code}`,
                ),
            );
        if (can("sis:person:manage"))
            body.append(
                button("Open person record", () => openPerson(person.id.value)),
            );
        const submission = review.submission;
        if (submission) {
            const section = node(
                "section",
                { class: "record-section" },
                node("h3", {}, submission.form.name),
                node(
                    "p",
                    { class: "form-text" },
                    "These answers use the form revision submitted by the applicant. Later form changes do not alter this application.",
                ),
            );
            const answers = node("dl", { class: "detail-list" });
            for (const field of submission.form.fields) {
                const value = submission.answers[field.key];
                answers.append(
                    node(
                        "div",
                        {},
                        node("dt", {}, field.label),
                        node(
                            "dd",
                            { class: "message-preview" },
                            typeof value === "boolean"
                                ? value
                                    ? "Yes"
                                    : "No"
                                : value === null ||
                                    value === undefined ||
                                    value === ""
                                  ? "No response"
                                  : String(value),
                        ),
                    ),
                );
            }
            section.append(answers);
            body.append(section);
        } else
            body.append(
                node(
                    "p",
                    { class: "record-section" },
                    "This application was entered directly. No application-form answers are attached.",
                ),
            );
        const actions = node("div", { class: "inline-actions" });
        body.append(actions);
        actions.append(
            button("Application history", () => decisionHistory(row)),
        );
        if (app.status === "submitted") {
            actions.append(
                button(
                    "Accept application",
                    () => decide(row, true),
                    "btn-primary",
                ),
                button("Reject application", () => decide(row, false)),
            );
        }
        if (app.status === "accepted")
            actions.append(
                button(
                    "Matriculate student",
                    () => matriculate(row),
                    "btn-primary",
                ),
            );
        if (
            ["accepted", "rejected"].includes(app.status) &&
            can("sis:admissions:manage") &&
            can("sis:admissions:correct")
        )
            actions.append(
                button("Correct admissions decision", () =>
                    correctDecision(row, app.status),
                ),
            );
        if (app.status === "matriculated")
            actions.append(
                button("Matriculation history", () =>
                    matriculationHistory(row),
                ),
            );
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
        const form = await buildForm(fields, preset);
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review admissions decision",
        );
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const preview = await request(
                    `/sis/applications/${row.id}/decision/review/`,
                    "POST",
                    { ...data, decision: accept ? "accept" : "reject" },
                );
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
                            await application(row);
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
        const form = await buildForm(fields, preset);
        form.elements.reason.required = true;
        form.elements.reason.maxLength = 10000;
        const review = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review admissions correction",
        );
        form.append(review);
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            task(async () => {
                if (!form.reportValidity()) return;
                const data = formData(form, fields);
                const preview = await request(
                    `/sis/applications/${row.id}/decision/correction/review/`,
                    "POST",
                    {
                        ...data,
                        decision: target === "accepted" ? "accept" : "reject",
                    },
                );
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
                            await application(row);
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
    async function decisionHistory(row, cursor = null, previous = []) {
        const record =
            cursor === null
                ? await request(`/sis/applications/${row.id}/decision/history/`)
                : await request(
                      `/sis/applications/${row.id}/decision/history/page/`,
                      "POST",
                      { cursor },
                  );
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
            return;
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
                const section = node(
                    "section",
                    { class: "record-section" },
                    node(
                        "h3",
                        {},
                        observed
                            ? "Observed current application"
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
        const form = await buildForm(fields, preset);
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
                            await application(row);
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
        const record = await request(
            `/sis/applications/${row.id}/matriculate/history/`,
        );
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
    return { editProfile, account, contact, history, application };
};
