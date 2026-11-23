/* Audited institutional settings; issued offers retain their accepted policy. */
(() => {
    "use strict";
    let sequence = 0;
    window.createAdmissionPolicy = ({ node, request, task, notify }) => ({
        mount(container) {
            const status = node("p", { role: "status", "aria-live": "polite", class: "form-text" });
            const body = node("div", { class: "admission-policy-stack" });
            container.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Set the institution’s rules for checklist completion, applicant answers, offer acceptance and withdrawal. Save each policy separately. Earlier decisions and issued offers keep their recorded policy.",
                ),
                status,
                body,
            );
            let busy = false,
                generation = 0;
            const dirty = new Set(),
                buttons = [];
            const definitions = [
                [
                    "checklist",
                    "Acceptance checklist",
                    "Require checklist completion before acceptance",
                    "Needed items must be Complete or Not needed. If no items are needed, save casework as Done to record that review. Earlier admissions decisions remain unchanged.",
                    "Save checklist policy",
                ],
                [
                    "answers",
                    "Application revisions",
                    "When applicants may revise answers",
                    "Each resubmission retains earlier answers and uses the original form. Accepted, rejected, withdrawn and matriculated applications cannot be edited.",
                    "Save revision policy",
                ],
                [
                    "offers",
                    "Offer acceptance",
                    "Require applicant acceptance before matriculation",
                    "New offers retain this policy. Issued letters and completed matriculations stay unchanged.",
                    "Save offer policy",
                ],
                [
                    "withdrawals",
                    "Applicant withdrawal",
                    "How applicants may withdraw",
                    "Applies before matriculation. Pending requests pause matriculation and offer responses. Applicants may cancel an existing request even if the policy changes.",
                    "Save withdrawal policy",
                ],
            ];
            const controls = () =>
                buttons.forEach((button) => {
                    button.disabled = busy;
                });
            async function load() {
                const ticket = ++generation;
                busy = true;
                controls();
                status.textContent = "Loading admissions policies…";
                try {
                    const data = await request("/sis/admission-policy/");
                    if (!container.isConnected || ticket !== generation) return;
                    body.replaceChildren();
                    buttons.length = 0;
                    for (const [area, title, label, help, saveLabel] of definitions) {
                        const identity = `admission-policy-${++sequence}`;
                        const form = node("form", { class: "record-form admission-policy-form" });
                        const group = node(
                            "fieldset",
                            { class: "record-form-section" },
                            node("legend", {}, title),
                        );
                        const checkbox = ["offers", "checklist"].includes(area);
                        const read = (input) =>
                            area === "offers"
                                ? { require_acceptance: input.checked }
                                : area === "checklist"
                                  ? { required: input.checked }
                                  : { mode: input.value };
                        let input;
                        if (checkbox) {
                            input = node("input", {
                                type: "checkbox",
                                id: identity,
                                class: "form-check-input",
                                checked:
                                    area === "offers"
                                        ? data.offers.require_acceptance
                                        : data.checklist.required,
                            });
                            group.append(
                                node(
                                    "div",
                                    { class: "form-check mb-3" },
                                    input,
                                    node(
                                        "label",
                                        { for: identity, class: "form-check-label" },
                                        label,
                                    ),
                                ),
                            );
                        } else {
                            const choices =
                                area === "answers"
                                    ? [
                                          ["request", "Only after staff request corrections"],
                                          [
                                              "before_decision",
                                              "Anytime before an admissions decision",
                                          ],
                                      ]
                                    : [
                                          ["request", "Staff approval required"],
                                          ["direct", "Applicants may withdraw directly"],
                                          ["staff_only", "Staff-only withdrawal"],
                                      ];
                            input = node(
                                "select",
                                { id: identity, class: "form-select" },
                                ...choices.map(([value, label]) =>
                                    node(
                                        "option",
                                        { value, selected: data[area].mode === value },
                                        label,
                                    ),
                                ),
                            );
                            group.append(
                                node("label", { for: identity, class: "form-label" }, label),
                                input,
                            );
                        }
                        input.setAttribute("aria-describedby", `${identity}-help`);
                        group.append(
                            node("p", { id: `${identity}-help`, class: "form-text" }, help),
                        );
                        const state = node("p", {
                            role: "status",
                            class: "form-text admission-policy-state",
                            "aria-live": "polite",
                        });
                        input.addEventListener("change", () => {
                            dirty.add(area);
                            state.textContent = "Unsaved changes.";
                        });
                        const save = node(
                            "button",
                            { type: "submit", class: "btn btn-primary" },
                            saveLabel,
                        );
                        buttons.push(save);
                        group.append(
                            node("div", { class: "admission-policy-footer" }, save, state),
                        );
                        form.append(group);
                        body.append(form);
                        form.addEventListener("submit", (event) => {
                            event.preventDefault();
                            if (busy) return;
                            busy = true;
                            controls();
                            const value = read(input);
                            state.textContent = "Saving policy…";
                            status.textContent = "";
                            task(async () => {
                                try {
                                    const saved = await request("/sis/admission-policy/", "POST", {
                                        area,
                                        ...value,
                                        revision: data[area].revision,
                                    });
                                    if (!container.isConnected || ticket !== generation) return;
                                    data[area] = saved;
                                    const newerDraft =
                                        JSON.stringify(read(input)) !== JSON.stringify(value);
                                    if (!newerDraft) dirty.delete(area);
                                    state.textContent = newerDraft
                                        ? "Policy saved. Your newer changes are still unsaved."
                                        : `${title} policy saved.`;
                                    notify(`${title} policy saved.`);
                                } catch (error) {
                                    if (!container.isConnected || ticket !== generation) return;
                                    if (error.status === 403) {
                                        body.replaceChildren();
                                        status.textContent =
                                            "Admissions policies are no longer available with your current access.";
                                    } else
                                        state.textContent =
                                            "The policy could not be saved. Your choice is still shown. Reopen Policies if someone else changed it.";
                                    throw error;
                                } finally {
                                    if (ticket === generation) {
                                        busy = false;
                                        controls();
                                    }
                                }
                            });
                        });
                    }
                    dirty.clear();
                    status.textContent = "";
                } catch (error) {
                    if (!container.isConnected || ticket !== generation) return;
                    if (error.status === 403) body.replaceChildren();
                    status.textContent =
                        error.status === 403
                            ? "Admissions policies are no longer available with your current access."
                            : "Could not load admissions policies. Close and reopen Policies to try again.";
                    throw error;
                } finally {
                    if (ticket === generation) {
                        busy = false;
                        controls();
                    }
                }
            }
            task(load);
            return {
                beforeLeave: () =>
                    !container.isConnected ||
                    (!busy &&
                        (dirty.size === 0 || window.confirm("Leave the unsaved policy changes?"))),
            };
        },
    });
})();
