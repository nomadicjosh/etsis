/* Faculty-only, paged score entry. Working marks remain separate from final academic grades. */
window.createAssignmentWorksheet = function (ui) {
    "use strict";
    const { node, request, task, openDrawer, table } = ui;
    let current = null;
    function canLeave() {
        if (!current?.body.isConnected) {
            current = null;
            return true;
        }
        if (!current.busy && !current.rows.some((row) => row.dirty())) return true;
        current.announcement.textContent = current.busy
            ? "Saving marks. Wait for the result before leaving."
            : "You have unsaved marks. Save or discard your changes before leaving.";
        current.announcement.focus();
        return false;
    }
    async function open(id, section, back) {
        const body = openDrawer("Grade assignment", `${section.code} · COURSEWORK`);
        const announcement = node(
            "p",
            { role: "status", "aria-live": "polite", tabindex: "-1" },
            "Loading students…",
        );
        const session = { body, announcement, rows: [], busy: false, blocked: false };
        current = session;
        body.append(announcement);
        let assignment,
            cursor = "",
            nextCursor = null,
            history = [],
            generation = 0,
            activeSearch = "",
            activeState = "all";
        const action = (label, run, style = "btn-outline-secondary") =>
            node(
                "button",
                {
                    type: "button",
                    class: `btn ${style}`,
                    onclick: () => task(run),
                },
                label,
            );
        const search = node("input", {
            id: "worksheet-search",
            name: "search",
            type: "search",
            maxlength: "100",
            class: "form-control",
            placeholder: "Student name or ID",
        });
        const state = node(
            "select",
            { id: "worksheet-state", name: "state", class: "form-select" },
            ...[
                ["all", "All students"],
                ["pending", "Awaiting score"],
                ["scored", "Scored"],
                ["excused", "Excused"],
            ].map(([value, label]) => node("option", { value }, label)),
        );
        state.value = "all";
        const find = node("button", { type: "submit", class: "btn btn-outline-primary" }, "Find students");
        const filters = node(
            "form",
            { class: "row g-2 mb-3" },
            node(
                "div",
                { class: "col-sm-6" },
                node("label", { for: search.id, class: "form-label" }, "Find student"),
                search,
            ),
            node(
                "div",
                { class: "col-sm-6" },
                node("label", { for: state.id, class: "form-label" }, "Score status"),
                state,
            ),
            node("div", { class: "col-12" }, find),
        );
        const heading = node("h3", { class: "h5 text-break" });
        const limits = node("p", { id: "worksheet-limits", class: "form-text" });
        const save = node("button", { type: "submit", class: "btn btn-primary" }, "Save changes");
        const discard = action("Discard changes", () => {
            for (const row of session.rows) row.reset();
            update();
            announcement.textContent = "Unsaved changes discarded. Refresh to check current server values.";
        });
        const refresh = action("Refresh", () => {
            if (canLeave()) return load();
        });
        const previous = action("Previous page", async () => {
            if (!canLeave()) return;
            const target = history.at(-1) || "";
            if (await load(target)) history.pop();
            update();
        });
        const next = action("Next page", async () => {
            if (!canLeave() || !nextCursor) return;
            const old = cursor;
            if (await load(nextCursor)) history.push(old);
            update();
        });
        const backButton = action("Back to section", () => {
            if (canLeave()) return back();
        });
        const progress = node("progress", {
            class: "w-100",
            max: "1",
            value: "0",
            "aria-label": "Saving assignment scores",
            hidden: true,
        });
        const content = node("div");
        const paging = node("p", { class: "form-text" });
        const form = node(
            "form",
            {},
            node("div", { class: "inline-actions mb-3" }, save, discard, refresh, backButton),
            progress,
            content,
        );
        body.append(
            heading,
            limits,
            node(
                "p",
                { class: "form-text" },
                "Enter marks for this assignment, then save your changes. Blank clears a mark; zero is a recorded score. Excusing work keeps its mark and omits it from the average. Official final grades stay unchanged.",
            ),
            filters,
            form,
            paging,
            node("div", { class: "inline-actions" }, previous, next),
        );
        function update() {
            const dirty = session.rows.filter((row) => row.dirty()).length;
            save.disabled = session.busy || session.blocked || !dirty;
            discard.disabled = session.busy || !dirty;
            refresh.disabled = session.busy || dirty > 0;
            search.disabled = state.disabled = find.disabled = session.busy;
            previous.disabled = session.busy || !history.length;
            next.disabled = session.busy || !nextCursor;
            backButton.disabled = session.busy;
            for (const row of session.rows) row.disable(session.busy);
        }
        function edited() {
            update();
            const dirty = session.rows.filter((row) => row.dirty()).length;
            announcement.textContent = session.blocked
                ? "Discard unsaved changes and refresh before continuing."
                : `${dirty} ${dirty === 1 ? "student has" : "students have"} unsaved changes.`;
        }
        function scoreRow(item) {
            let savedMark = item.earned_hundredths == null ? "" : String(item.earned_hundredths / 100);
            let savedExcused = item.excused;
            let version = item.version;
            const name = `${item.given_name} ${item.family_name}`;
            const key = `worksheet-${item.student_id}`;
            const mark = node("input", {
                id: `${key}-mark`,
                type: "number",
                min: "0",
                max: String(assignment.score_limit_hundredths / 100),
                step: "0.01",
                class: "form-control",
                "aria-describedby": `worksheet-limits ${key}-status`,
            });
            const excused = node("input", {
                id: `${key}-excused`,
                type: "checkbox",
                class: "form-check-input",
                "aria-describedby": `${key}-status`,
            });
            const result = node("p", {
                id: `${key}-status`,
                class: "form-text mb-0",
                role: "status",
                "aria-live": "polite",
            });
            function feedback() {
                result.setAttribute("class", "form-text mb-0");
                result.textContent = excused.checked
                    ? "Excused"
                    : mark.value === ""
                      ? "Awaiting score"
                      : "Scored";
            }
            const dirty = () => mark.value !== savedMark || excused.checked !== savedExcused;
            const row = {
                dirty,
                reset() {
                    mark.value = savedMark;
                    excused.checked = savedExcused;
                    mark.setCustomValidity("");
                    mark.removeAttribute("aria-invalid");
                    feedback();
                },
                disable(value) {
                    mark.disabled = excused.disabled = value;
                },
                payload() {
                    const raw = mark.value.trim();
                    const valid =
                        raw === "" ||
                        (/^\d+(?:\.\d{1,2})?$/.test(raw) &&
                            Number(raw) <= assignment.score_limit_hundredths / 100);
                    mark.setCustomValidity(
                        valid
                            ? ""
                            : "Enter a non-negative mark within the limit, using up to two decimal places.",
                    );
                    if (!valid) {
                        mark.setAttribute("aria-invalid", "true");
                        mark.reportValidity();
                        return null;
                    }
                    mark.removeAttribute("aria-invalid");
                    return {
                        student_id: item.student_id,
                        enrollment_id: item.enrollment_id,
                        earned_hundredths: raw === "" ? null : Math.round(Number(raw) * 100),
                        version,
                        excused: excused.checked,
                        assignment_revision: assignment.revision,
                    };
                },
                accepted() {
                    savedMark = mark.value;
                    savedExcused = excused.checked;
                    version++;
                    result.textContent = "Saved";
                },
                failed(error) {
                    result.setAttribute("class", "form-text text-danger fw-semibold mb-0");
                    const messages = {
                        409: "This score or assignment changed. Discard unsaved changes and refresh before retrying.",
                        422: "Mark not saved. Check the score limit and whether the student is still enrolled.",
                        401: "Your session expired. Sign in again before continuing.",
                        403: "Your grading access changed. Refresh before continuing.",
                    };
                    result.textContent =
                        messages[error.status] ||
                        "The save could not be confirmed. Refresh before retrying; the server may have saved this mark.";
                    result.tabIndex = -1;
                    result.focus();
                    session.blocked = error.status !== 422;
                },
                cells: [
                    node(
                        "div",
                        { class: "text-break" },
                        node("strong", {}, name),
                        node("div", { class: "small" }, item.institutional_id),
                    ),
                    node(
                        "div",
                        {},
                        node(
                            "label",
                            { for: mark.id, class: "visually-hidden" },
                            `Points earned for ${name}`,
                        ),
                        mark,
                        result,
                    ),
                    node(
                        "div",
                        { class: "form-check" },
                        excused,
                        node(
                            "label",
                            { for: excused.id, class: "form-check-label" },
                            node("span", { class: "visually-hidden" }, `${name}: `),
                            "Excused",
                        ),
                    ),
                ],
            };
            mark.addEventListener("input", () => {
                mark.setCustomValidity("");
                mark.removeAttribute("aria-invalid");
                feedback();
                edited();
            });
            excused.addEventListener("change", () => {
                feedback();
                edited();
            });
            row.reset();
            return row;
        }
        async function load(after = cursor, appliedSearch = activeSearch, appliedState = activeState) {
            const token = ++generation;
            session.busy = true;
            update();
            content.setAttribute("aria-busy", "true");
            try {
                const page = await request(`/sis/assignments/${id}/score-roster/`, "POST", {
                    search: appliedSearch,
                    state: appliedState,
                    after,
                });
                if (!body.isConnected || token !== generation) return false;
                assignment = page.assignment;
                cursor = after;
                nextCursor = page.next_after;
                search.value = appliedSearch;
                state.value = appliedState;
                activeSearch = appliedSearch;
                activeState = appliedState;
                session.blocked = false;
                session.rows = page.items.map(scoreRow);
                heading.textContent = assignment.title;
                limits.textContent = `Base: ${assignment.possible_hundredths / 100} points · Bonus limit: ${assignment.bonus_hundredths / 100} · Maximum earned: ${assignment.score_limit_hundredths / 100} points · Due ${assignment.due_on}`;
                content.replaceChildren(
                    session.rows.length
                        ? node(
                              "div",
                              {
                                  class: "table-responsive",
                                  role: "region",
                                  tabindex: "0",
                                  "aria-label": "Assignment scores",
                              },
                              table(
                                  ["Student", "Points earned", "Inclusion"],
                                  session.rows.map((row) => row.cells),
                                  "Assignment scores",
                              ),
                          )
                        : node("p", {}, "No active students match these filters."),
                );
                paging.textContent = `${page.items.length} students on this page.${nextCursor ? " More are available." : ""} Filters apply to saved scores; saved rows remain here until you refresh.`;
                announcement.textContent = "Worksheet ready. Only changed rows will be saved.";
                return true;
            } finally {
                if (token === generation) {
                    session.busy = false;
                    content.removeAttribute("aria-busy");
                    update();
                }
            }
        }
        filters.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!canLeave() || !filters.reportValidity()) return;
            task(async () => {
                if (await load("", search.value.trim(), state.value)) history = [];
                update();
            });
        });
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (session.busy || session.blocked) return;
            const changed = session.rows.filter((row) => row.dirty());
            const entries = changed.map((row) => [row, row.payload()]);
            if (!changed.length || entries.some(([, payload]) => !payload) || !form.reportValidity()) return;
            task(async () => {
                session.busy = true;
                progress.hidden = false;
                progress.max = entries.length;
                progress.value = 0;
                update();
                let saved = 0;
                try {
                    for (const [row, payload] of entries) {
                        if (!body.isConnected) break;
                        announcement.textContent = `Saving ${saved + 1} of ${entries.length} changed rows…`;
                        try {
                            await request(`/sis/assignments/${id}/scores/`, "POST", payload);
                            row.accepted();
                            progress.value = ++saved;
                        } catch (error) {
                            row.failed(error);
                            announcement.textContent = `${saved} of ${entries.length} changes saved. Saving stopped; remaining rows were not sent. Check the highlighted row.`;
                            return;
                        }
                    }
                    announcement.textContent = `${saved} ${saved === 1 ? "change" : "changes"} saved. Official final grades are unchanged.`;
                } finally {
                    session.busy = false;
                    update();
                }
            });
        });
        await load();
    }
    return { open, canLeave };
};
