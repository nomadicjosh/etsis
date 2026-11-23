/* Reviewed per-section queues. Browser timestamps and preview IDs do not establish accepted priority. */
window.createSectionWaitlist = function (ui) {
    "use strict";
    const { node, request, table } = ui;
    const portal = !!ui.portal;
    const open = ui.open || ((title) => ui.openDrawer(title, "SECTION WAITLIST"));
    const action = (label, run, primary = false) =>
        ui.button
            ? ui.button(label, run, primary)
            : node(
                  "button",
                  {
                      type: "button",
                      class: `btn ${primary ? "btn-primary" : "btn-outline-primary"}`,
                      onclick: (event) => ui.task(run, event.currentTarget),
                  },
                  label,
              );
    const notice = (text) =>
        node("p", { role: "status", class: portal ? "campus-notice" : "alert alert-info" }, text);
    async function send(body, ...args) {
        try {
            return await request(...args);
        } catch (error) {
            if (error.status === 403 && body.isConnected)
                body.replaceChildren(
                    notice(
                        "Access to this waitlist changed. Reopen the task after checking your access.",
                    ),
                );
            throw error;
        }
    }
    const explanation = () =>
        node(
            "p",
            {},
            "Places are ordered by accepted server decisions. Joining records interest without enrollment or charges. Registration requires your turn, an available seat and the normal eligibility-and-fee confirmation. Existing places keep their priority when new requests are disabled.",
        );
    async function policy(section, back) {
        const body = open("Section waitlist policy");
        body.append(notice("Loading this section’s policy…"));
        const current = await send(body, `/sis/sections/${section.id}/waitlist/policy/`);
        if (!body.isConnected) return;
        const enabled = node("input", {
            type: "checkbox",
            id: "section-waitlist-enabled",
            class: "form-check-input",
            checked: current.policy.enabled,
        });
        const limit = node("input", {
            type: "number",
            id: "section-waitlist-limit",
            class: "form-control",
            min: 1,
            max: 100,
            step: 1,
            required: true,
            value: current.policy.maximum_active,
        });
        const reason = node("textarea", {
            id: "section-waitlist-reason",
            class: "form-control",
            maxlength: 1000,
            required: true,
        });
        const error = node("div", { role: "alert", class: "text-danger" });
        const availability = node(
            "fieldset",
            { class: "record-form-section" },
            node("legend", { class: "h6" }, "Section availability"),
            node(
                "div",
                { class: "form-check" },
                enabled,
                node(
                    "label",
                    { for: enabled.id, class: "form-check-label" },
                    "Allow waitlisting for this section",
                ),
            ),
            node("label", { for: limit.id, class: "form-label" }, "Maximum active requests"),
            limit,
        );
        const publication = node(
            "fieldset",
            { class: "record-form-section" },
            node("legend", { class: "h6" }, "Policy decision"),
            node(
                "label",
                { for: reason.id, class: "form-label" },
                "Reason for this section policy",
            ),
            reason,
        );
        const form = node(
            "form",
            { class: "record-form", "aria-label": "Section waitlist policy" },
            availability,
            publication,
            error,
        );
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review waitlist policy",
        );
        form.append(node("div", { class: "d-flex flex-wrap gap-2 my-3" }, submit));
        const edit = () =>
            body.replaceChildren(
                explanation(),
                notice(
                    `${current.waiting_count} existing requests. This setting belongs to this section. Other sections keep their own settings.`,
                ),
                form,
                back ? action("Back to section", back) : null,
            );
        edit();
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            ui.task(async () => {
                error.textContent = "";
                try {
                    const review = await send(
                        body,
                        `/sis/sections/${section.id}/waitlist/policy/review/`,
                        "POST",
                        {
                            policy: {
                                enabled: enabled.checked,
                                maximum_active: Number(limit.value),
                                ordering: "accepted_fifo",
                                allocation: "oldest_may_register",
                            },
                            version: current.version,
                            reason: reason.value,
                        },
                    );
                    if (!body.isConnected) return;
                    const failure = node("div", { role: "alert", class: "text-danger" });
                    body.replaceChildren(
                        node("h3", {}, "Review section waitlist policy"),
                        explanation(),
                        notice(
                            `${review.policy.enabled ? "Allow" : "Disable new"} waitlist requests · limit ${review.policy.maximum_active} · first accepted, first served. Existing requests retain their priority.`,
                        ),
                        node("p", {}, review.reason),
                        node("p", {}, "This review expires in twenty minutes."),
                        failure,
                        action(
                            "Confirm waitlist policy",
                            async () => {
                                try {
                                    await send(
                                        body,
                                        `/sis/sections/${section.id}/waitlist/policy/confirm/`,
                                        "POST",
                                        review,
                                    );
                                    if (body.isConnected)
                                        body.replaceChildren(
                                            notice("Section waitlist policy recorded."),
                                            action("View policy", () => policy(section, back)),
                                            back ? action("Back to section", back) : null,
                                        );
                                } catch (e) {
                                    if (body.isConnected) {
                                        failure.textContent = e.message;
                                        if (e.status !== 403)
                                            failure.append(
                                                action("Reload section policy", () =>
                                                    policy(section, back),
                                                ),
                                            );
                                    }
                                }
                            },
                            true,
                        ),
                        action("Revise policy", edit),
                    );
                } catch (e) {
                    if (form.isConnected) error.textContent = e.message;
                }
            }, submit);
        });
    }
    async function place(student, section, back, owner = false) {
        const body = open("Waitlist options");
        body.append(notice("Checking your waitlist request…"));
        const base = owner
            ? `/portal/api/waitlists/${student}/sections/${section.id}/`
            : `/sis/sections/${section.id}/waitlist/`;
        const result = owner
            ? await send(body, base)
            : await send(body, `${base}context/`, "POST", { student_id: student });
        if (!body.isConnected) return;
        body.replaceChildren(
            node("h3", {}, `${result.course_code} · ${result.section_code}`),
            explanation(),
            notice(
                result.rank
                    ? `Current place ${result.rank} of ${result.waiting_count}. ${result.seats_remaining} seats currently available.`
                    : `${result.policy.enabled ? "Waitlisting is allowed for this section." : "New waitlist requests are disabled for this section."} ${result.waiting_count} requests are waiting.`,
            ),
        );
        if (result.entry_id)
            body.append(
                action("Withdraw waitlist request", () =>
                    change(
                        body,
                        base,
                        student,
                        "cancelled",
                        () => place(student, section, back, owner),
                        owner,
                        null,
                        result.entry_id,
                    ),
                ),
            );
        else if (
            result.policy.enabled &&
            result.waiting_count < result.policy.maximum_active &&
            (result.waiting_count > 0 || result.seats_remaining === 0)
        )
            body.append(
                action(
                    "Join section waitlist",
                    () =>
                        change(
                            body,
                            base,
                            student,
                            "requested",
                            () => place(student, section, back, owner),
                            owner,
                        ),
                    true,
                ),
            );
        else if (result.policy.enabled && result.waiting_count >= result.policy.maximum_active)
            body.append(
                notice(
                    "This section’s waitlist has reached its request limit. Refresh later or contact the registrar.",
                ),
            );
        else if (!result.entry_id && result.seats_remaining > 0 && !result.waiting_count)
            body.append(
                notice(
                    "A seat is available. Use the normal registration review to check eligibility and fees.",
                ),
            );
        if (
            ui.register &&
            result.seats_remaining > 0 &&
            (result.rank === 1 || (!result.waiting_count && !result.entry_id))
        )
            body.append(
                action(
                    "Review registration and fees",
                    () =>
                        ui.register(student, section.id, () =>
                            place(student, section, back, owner),
                        ),
                    true,
                ),
            );
        body.append(
            action("Refresh waitlist", () => place(student, section, back, owner)),
            back ? action("Back to registration", back) : null,
        );
    }
    async function change(
        body,
        base,
        student,
        actionName,
        back,
        owner = false,
        reason = null,
        entry = null,
    ) {
        const review = await send(body, `${base}review/`, "POST", {
            student_id: student,
            action: actionName,
            entry_id: entry,
            ...(reason ? { reason } : {}),
        });
        if (!body.isConnected) return;
        const error = node("div", {
            role: "alert",
            class: portal ? "campus-notice" : "text-danger",
        });
        body.replaceChildren(
            node(
                "h3",
                {},
                actionName === "requested"
                    ? "Review waitlist request"
                    : actionName === "removed"
                      ? "Review waitlist removal"
                      : "Review waitlist withdrawal",
            ),
            explanation(),
            node(
                "p",
                {},
                actionName === "requested"
                    ? "Your place will be assigned when confirmation is accepted. A browser click or this preview does not reserve a place."
                    : "This closes the selected waiting request and preserves its history.",
            ),
            reason ? node("p", {}, reason) : null,
            error,
            action(
                "Confirm waitlist change",
                async () => {
                    try {
                        await send(body, `${base}confirm/`, "POST", {
                            ...review,
                            student_id: student,
                        });
                        if (!body.isConnected) return;
                        body.replaceChildren(
                            notice("Waitlist change recorded."),
                            action("View current waitlist", back),
                        );
                    } catch (e) {
                        if (!body.isConnected) return;
                        error.textContent = e.message;
                        error.append(action("Refresh and review again", back));
                    }
                },
                true,
            ),
            action("Back to waitlist", back),
        );
    }
    async function staff(section, back, after = 0, previous = []) {
        const body = open("Section waitlist");
        body.append(notice("Loading accepted requests…"));
        const data = await send(
            body,
            `/sis/sections/${section.id}/waitlist/${after ? `?after=${after}` : ""}`,
        );
        if (!body.isConnected) return;
        const rows = data.items.map((item) => [
            String(item.rank),
            item.current_name,
            item.current_institutional_id,
            node(
                "div",
                { class: "inline-actions" },
                item.rank === 1 && data.seats_remaining > 0
                    ? action(
                          "Review registration for oldest request",
                          () =>
                              ui.register(item.student_id, section.id, () => staff(section, back)),
                          true,
                      )
                    : node(
                          "span",
                          {},
                          item.rank === 1 ? "Waiting for a seat" : "Earlier requests have priority",
                      ),
                action("Review removal", () =>
                    remove(body, section, item, () => staff(section, back)),
                ),
            ),
        ]);
        body.replaceChildren(
            node("h3", {}, data.section_code),
            explanation(),
            notice(
                `${data.policy.enabled ? "New requests allowed" : "New requests disabled"} · ${data.waiting_count} waiting · ${data.seats_remaining} available seats. Names and institutional IDs are current.`,
            ),
            node(
                "div",
                {
                    class: "table-responsive",
                    tabindex: "0",
                    role: "region",
                    "aria-label": "Accepted waitlist order",
                },
                table(
                    ["Place", "Student (current)", "Institutional ID (current)", "Action"],
                    rows,
                    "Accepted waitlist order",
                ),
            ),
            !data.items.length ? node("p", {}, "No active waitlist requests.") : null,
            previous.length
                ? action("Previous waitlist page", () =>
                      staff(section, back, previous.at(-1), previous.slice(0, -1)),
                  )
                : null,
            data.next_after
                ? action("Next waitlist page", () =>
                      staff(section, back, data.next_after, [...previous, after]),
                  )
                : null,
            ui.can?.("sis:registration:policy")
                ? action("Waitlist policy", () => policy(section, () => staff(section, back)))
                : null,
            back ? action("Back to section", back) : null,
        );
    }
    function remove(body, section, item, back) {
        const reason = node("textarea", {
            id: "waitlist-removal-reason",
            class: "form-control",
            required: true,
            maxlength: 1000,
        });
        const form = node(
            "form",
            { class: "record-form", "aria-label": "Remove waitlist request" },
            node(
                "label",
                { for: reason.id, class: "form-label" },
                "Reason for removing this request",
            ),
            reason,
        );
        const submit = node(
            "button",
            { type: "submit", class: "btn btn-primary" },
            "Review waitlist removal",
        );
        form.append(submit);
        body.replaceChildren(
            node("h3", {}, `Remove request for ${item.current_name}`),
            form,
            action("Back to queue", back),
        );
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            ui.task(
                () =>
                    change(
                        body,
                        `/sis/sections/${section.id}/waitlist/`,
                        item.student_id,
                        "removed",
                        back,
                        false,
                        reason.value,
                        item.entry_id,
                    ),
                submit,
            );
        });
    }
    async function mine(student, back, after = null, previous = []) {
        const body = open("Your waitlist requests");
        body.append(notice("Loading your requests…"));
        const page = await send(
            body,
            `/portal/api/waitlists/${student}/${after ? `?after=${encodeURIComponent(after)}` : ""}`,
        );
        if (!body.isConnected) return;
        body.replaceChildren(
            explanation(),
            ...page.items.map((item) =>
                node(
                    "section",
                    { class: "campus-surface" },
                    node("h3", {}, `${item.course_code} · ${item.section_code}`),
                    node(
                        "p",
                        {},
                        item.status === "active"
                            ? `Waiting · current place ${item.rank}`
                            : {
                                  cancelled: "Withdrawn from waitlist",
                                  removed: "Removed by registrar review",
                                  registered: "Completed by registration",
                              }[item.status],
                    ),
                    item.status === "active"
                        ? action("Waitlist options", () =>
                              place(
                                  student,
                                  { id: item.section_id },
                                  () => mine(student, back, after, previous),
                                  true,
                              ),
                          )
                        : null,
                ),
            ),
            !page.items.length ? node("p", {}, "You have no recorded waitlist requests.") : null,
            previous.length
                ? action("Previous request page", () =>
                      mine(student, back, previous.at(-1), previous.slice(0, -1)),
                  )
                : null,
            page.next_after
                ? action("Next request page", () =>
                      mine(student, back, page.next_after, [...previous, after]),
                  )
                : null,
            back ? action("Back to registration", back) : null,
        );
    }
    return { policy, place, staff, mine };
};
