window.createSectionContactHours = function (ui) {
    "use strict";
    const { node, request, task, openDrawer } = ui;
    const button = (label, run) =>
        node(
            "button",
            {
                type: "button",
                class: "btn btn-outline-primary",
                onclick: (event) => task(run, event.currentTarget),
            },
            label,
        );
    function details(value, recorded = true) {
        if (!recorded) return node("p", {}, "Contact hours were not recorded in this publication.");
        if (!value) return node("p", {}, "Weekly instructional contact time is unknown.");
        return node(
            "div",
            {},
            node(
                "p",
                {},
                `${(value.weekly_contact_milli / 1000).toFixed(3)} weekly contact hours · ${value.weekly_instruction_minutes} instructional minutes per typical teaching week`,
            ),
            node(
                "p",
                { class: "form-text" },
                `${value.unit_minutes} instructional minutes per contact hour; rounding ${value.rounding} to a thousandth. ${value.policy_version ? `Institutional policy ${value.policy_version}.` : "Default policy: 50 minutes per contact hour."}`,
            ),
        );
    }
    async function open(section, back, editable = true) {
        const body = openDrawer("Section contact hours", "TEACHING WORKLOAD");
        body.append(node("p", { role: "status" }, "Loading section contact time…"));
        const data = await request(`/sis/sections/${section.id}/contact-hours/`);
        if (!body.isConnected) return;
        body.replaceChildren(
            node("h3", {}, data.section_code),
            node(
                "p",
                {},
                "Contact hours describe direct instruction during a typical teaching week. They are separate from academic credits, elapsed timesheet hours, preparation and office hours. They do not calculate pay.",
            ),
            node("h4", { class: "h6" }, "Working calculation"),
            details(data.calculation),
            node("h4", { class: "h6" }, "Selected offering publication"),
            details(data.selected, data.selected_recorded),
        );
        if (editable) {
            const minutes = node("input", {
                type: "number",
                id: "section-contact-minutes",
                class: "form-control",
                min: 0,
                max: 10080,
                step: 1,
                value: data.weekly_minutes ?? "",
            });
            const error = node("div", { role: "alert", class: "text-danger" });
            const submit = node(
                "button",
                { type: "submit", class: "btn btn-primary" },
                "Save working contact time",
            );
            const form = node(
                "form",
                { class: "record-form", "aria-label": "Section contact time" },
                node(
                    "label",
                    { for: minutes.id, class: "form-label" },
                    "Weekly instructional minutes (optional)",
                ),
                minutes,
                node(
                    "p",
                    { class: "form-text" },
                    "Blank means unknown; zero is an explicit value. Enter the typical instructional week, including eligible lecture/lab contact under institutional policy. The system does not infer it from course credits or cancelled/special meeting weeks.",
                ),
                error,
                submit,
            );
            form.addEventListener("submit", (event) => {
                event.preventDefault();
                task(async () => {
                    try {
                        await request(`/sis/sections/${section.id}/contact-hours/`, "POST", {
                            weekly_minutes: minutes.value === "" ? null : Number(minutes.value),
                            version: data.version,
                        });
                        if (body.isConnected) await open(section, back, editable);
                    } catch (failure) {
                        if (form.isConnected) error.textContent = failure.message;
                    }
                }, submit);
            });
            body.append(
                form,
                node(
                    "p",
                    { class: "form-text" },
                    `Current conversion: ${data.policy.unit_minutes} instructional minutes per contact hour, rounding ${data.policy.rounding}. Save the draft, then review and publish section details to retain the calculation. Earlier offerings stay unchanged.`,
                ),
            );
        }
        if (back) body.append(button("Back to section", back));
    }
    return { open, details };
};
