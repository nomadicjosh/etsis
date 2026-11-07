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
        if (can("admin:access:manage"))
            body.append(
                node(
                    "p",
                    { class: "form-text mt-4" },
                    "Assign effective roles and extra permissions from this person’s Access tab. A sign-in profile does not assign roles.",
                ),
            );
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
        const back = () => application(row);
        if (app.status === "submitted") {
            actions.append(
                button(
                    "Accept application",
                    () =>
                        flow(
                            "Confirm acceptance",
                            [],
                            `/sis/applications/${row.id}/decision/`,
                            () => ({ decision: "accept" }),
                            back,
                            {},
                            "Review the submitted answers and program before accepting this application.",
                        ),
                    "btn-primary",
                ),
            );
            actions.append(
                button("Reject application", () =>
                    flow(
                        "Confirm rejection",
                        [],
                        `/sis/applications/${row.id}/decision/`,
                        () => ({ decision: "reject" }),
                        back,
                        {},
                        "This records a rejection decision. Review the application before confirming.",
                    ),
                ),
            );
        }
        if (app.status === "accepted")
            actions.append(
                button(
                    "Matriculate student",
                    () =>
                        flow(
                            "Confirm matriculation",
                            [],
                            `/sis/applications/${row.id}/matriculate/`,
                            null,
                            back,
                            {},
                            "Create a student record for this accepted application using its program and entry term. The same person and institutional ID are retained.",
                        ),
                    "btn-primary",
                ),
            );
    }
    return { editProfile, account, application };
};
