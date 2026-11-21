/* Registrar-only authoring. Drafts and private history remain in page memory. */
window.createWorkspacePrerequisitePermissions = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, can } = ui;
    let sequence = 0;
    const button = (text, run, style = "btn-outline-primary") => node("button", {
        type: "button", class: `btn ${style}`, onclick: event => task(run, event.currentTarget),
    }, text);
    const field = (label, input) => node("div", { class: "mb-3" }, node("label", { for: input.id, class: "form-label" }, label), input);
    const summary = approval => approval ? `Approved until ${new Date(approval.expires_at).toLocaleString(undefined, { timeZoneName: "short" })}` : "No prerequisite exception";
    const localTime = stamp => {
        if (!stamp) return "";
        const date = new Date(stamp), pad = n => String(n).padStart(2, "0");
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    };
    const allowed = () => can("sis:registration:prerequisite-exception") && can("sis:student:read");
    async function open(student, pursuit, search = "", after = null, stack = []) {
        if (!allowed()) return;
        const base = `/sis/students/${student}/programs/${pursuit}/prerequisite-exceptions/`;
        const body = openDrawer("Prerequisite exceptions", "REGISTRAR");
        const page = await request(`${base}sections/`, "POST", { search, after });
        if (!body.isConnected) return;
        const input = node("input", { id: `exception-search-${++sequence}`, class: "form-control", type: "search", maxlength: "80", value: search });
        const form = node("form", {}, field("Find a section, course or term", input), node("button", { type: "submit", class: "btn btn-primary" }, "Search sections"));
        form.addEventListener("submit", event => { event.preventDefault(); task(() => open(student, pursuit, input.value)); });
        body.append(node("p", { class: "form-text" }, "Approve registration despite a prerequisite gap for this program pursuit and one section. Holds, seats, timetable rules and co-requisites still apply. No grades or credits are awarded."), form);
        for (const item of page.items) body.append(node("div", { class: "my-2" }, button(`${item.code} · ${item.course_title} · ${item.term_code}`, () => edit(base, item.id, () => open(student, pursuit, search, after, stack)))));
        if (!page.items.length) body.append(node("p", { role: "status" }, "No sections match."));
        const navigation = node("div", { class: "d-flex flex-wrap gap-2 mt-3" });
        if (stack.length) navigation.append(button("Previous sections", () => open(student, pursuit, search, stack.at(-1), stack.slice(0, -1))));
        if (page.next_after) navigation.append(button("More sections", () => open(student, pursuit, search, page.next_after, [...stack, after])));
        body.append(navigation);
    }
    async function edit(base, section, back) {
        const waiting = openDrawer("Prerequisite exception", "REGISTRAR");
        const saved = await request(`${base}${section}/view/`, "POST", {});
        if (!waiting.isConnected) return;
        let reason = "", expiry = localTime(saved.state?.approval?.expires_at);
        const title = `${saved.section.code} · ${saved.section.course_title} · ${saved.section.term_code}`;
        function render() {
            const body = openDrawer("Prerequisite exception", title);
            const prefix = `exception-${++sequence}`;
            const date = node("input", { id: `${prefix}-expiry`, class: "form-control", type: "datetime-local", step: "60", value: expiry, "aria-describedby": `${prefix}-help` });
            const text = node("textarea", { id: `${prefix}-reason`, class: "form-control", maxlength: "1000", required: true }, reason);
            date.addEventListener("input", () => { expiry = date.value; });
            text.addEventListener("input", () => { reason = text.value; });
            body.append(node("p", { role: "status", class: "fw-semibold" }, `${summary(saved.state?.approval)}${saved.state?.approval && !saved.active ? " · No longer applicable" : ""}`),
                node("p", { class: "form-text" }, "This exception applies only to this pursuit, section and published course definition. A later revocation does not remove an already accepted registration."));
            const form = node("form", {}, field("Expires at", date), node("p", { id: `${prefix}-help`, class: "form-text" },
                `Time is shown in your browser's time zone: ${Intl.DateTimeFormat().resolvedOptions().timeZone}. Choose a future time no later than the end of the section's term.`), field("Reason", text));
            async function review(revoke) {
                if (revoke ? !text.reportValidity() : !form.reportValidity()) return;
                if (!revoke && !expiry.trim()) { date.setCustomValidity("Choose an expiry date and time."); date.reportValidity(); date.setCustomValidity(""); return; }
                const reviewed = await request(`${base}${section}/review/`, "POST", { expires_at: revoke ? null : new Date(expiry).toISOString(), reason, revision: saved.revision });
                if (!body.isConnected) return;
                const confirmation = openDrawer("Review exception", title);
                confirmation.append(node("dl", {}, node("dt", {}, "Before"), node("dd", {}, summary(reviewed.before)),
                    node("dt", {}, "After"), node("dd", {}, summary(reviewed.after)), node("dt", {}, "Reason"), node("dd", {}, reviewed.reason)),
                    node("p", { class: "form-text" }, "Only the prerequisite requirement is excepted. Every other registration check still applies."),
                    node("div", { class: "d-flex flex-wrap gap-2" }, button("Confirm exception", async () => {
                        await request(`${base}${section}/commit/`, "POST", { stream_id: reviewed.stream_id, operation_id: reviewed.operation_id,
                            approval: reviewed.approval, revision: reviewed.revision, reason: reviewed.reason, review_token: reviewed.review_token });
                        if (confirmation.isConnected) await edit(base, section, back);
                    }, "btn-primary"), button("Back to edit", render)));
            }
            form.addEventListener("submit", event => { event.preventDefault(); task(() => review(false)); });
            if (saved.editable) {
                form.append(node("button", { type: "submit", class: "btn btn-primary" }, saved.state?.approval ? "Review renewal" : "Review approval"));
                if (saved.state?.approval) form.append(button("Review revocation", () => review(true), "btn-outline-danger ms-2"));
                body.append(form);
            } else body.append(node("p", { role: "status" }, "Exceptions can be changed only for the current primary active program pursuit."));
            body.append(node("div", { class: "d-flex flex-wrap gap-2 mt-3" }, button("History", () => history(base, section, title, render)), button("Change section", back)));
        }
        render();
    }
    async function history(base, section, title, back, cursor = null, stack = []) {
        const body = openDrawer("Exception history", title);
        const page = await request(`${base}${section}/history/`, "POST", { cursor });
        if (!body.isConnected) return;
        const list = node("ol", { class: "list-unstyled" });
        for (const item of page.items) list.append(node("li", { class: "border-bottom py-3" }, node("strong", {}, summary(item.approval)),
            node("p", { class: "mb-1" }, new Date(item.effective_at).toLocaleString()), node("p", { class: "mb-0" }, item.reason)));
        body.append(list);
        if (!page.items.length) body.append(node("p", { role: "status" }, "No exception decisions recorded."));
        const controls = node("div", { class: "d-flex flex-wrap gap-2" }, button("Back to exception", back));
        if (stack.length) controls.append(button("Previous decisions", () => history(base, section, title, back, stack.at(-1), stack.slice(0, -1))));
        if (page.next_cursor) controls.append(button("More decisions", () => history(base, section, title, back, page.next_cursor, [...stack, cursor])));
        body.append(controls);
    }
    return { open };
};
