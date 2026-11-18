/* Shared private class worksheets: bounded reads, guarded edits and explicit partial-save results. */
window.createTeachingWorksheet = function (ui) {
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
    async function open(id, section, back, attendance = false) {
        const title = attendance ? "Class attendance" : "Grade assignment";
        const body = openDrawer(title, `${section.code} · COURSEWORK`);
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
            activeState = "all",
            activeDate = null;
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
            ...(attendance
                ? [
                      ["all", "All students"],
                      ["unmarked", "Not marked"],
                      ["present", "Present"],
                      ["absent", "Absent"],
                      ["late", "Late"],
                      ["excused", "Excused"],
                  ]
                : [
                      ["all", "All students"],
                      ["pending", "Awaiting score"],
                      ["scored", "Scored"],
                      ["excused", "Excused"],
                  ]
            ).map(([value, label]) => node("option", { value }, label)),
        );
        state.value = "all";
        const date = node("input", {
            id: "worksheet-date",
            name: "held_on",
            type: "date",
            required: true,
            class: "form-control",
            "aria-describedby": "worksheet-date-help",
        });
        const find = node(
            "button",
            { type: "submit", class: "btn btn-outline-primary" },
            attendance ? "Load register" : "Find students",
        );
        const filters = node(
            "form",
            { class: "row g-2 mb-3" },
            attendance
                ? node(
                      "div",
                      { class: "col-12" },
                      node("label", { for: date.id, class: "form-label" }, "Class date"),
                      date,
                      node(
                          "p",
                          { id: "worksheet-date-help", class: "form-text" },
                          "Choose Load register to apply the date and filters. Only currently active students appear; this is not a reconstructed past roster.",
                      ),
                  )
                : null,
            node(
                "div",
                { class: "col-sm-6" },
                node("label", { for: search.id, class: "form-label" }, "Find student"),
                search,
            ),
            node(
                "div",
                { class: "col-sm-6" },
                node(
                    "label",
                    { for: state.id, class: "form-label" },
                    attendance ? "Attendance status" : "Score status",
                ),
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
            "aria-label": attendance ? "Saving attendance marks" : "Saving assignment scores",
            hidden: true,
        });
        const fill = action("Fill unmarked as present", () => {
            for (const row of session.rows) row.fill?.();
            edited();
        });
        const content = node("div");
        const paging = node("p", { class: "form-text" });
        const form = node(
            "form",
            {},
            node(
                "div",
                { class: "inline-actions mb-3" },
                save,
                discard,
                refresh,
                backButton,
                attendance ? fill : null,
            ),
            progress,
            content,
        );
        body.append(
            heading,
            limits,
            node(
                "p",
                { class: "form-text" },
                attendance
                    ? "Choose a date and load the register. Select each attendance mark, then save. Fill unmarked as present changes only unmarked students on this page and requires Save changes. Recorded marks are never filled over. Official grades and assignment excusals stay separate."
                    : "Enter marks for this assignment, then save your changes. Blank clears a mark; zero is a recorded score. Excusing work keeps its mark and omits it from the average. Official final grades stay unchanged.",
            ),
            filters,
            form,
            paging,
            node("div", { class: "inline-actions" }, previous, next),
        );
        function update() {
            const dirty = session.rows.filter((row) => row.dirty()).length;
            const datePending = attendance && (date.value || null) !== activeDate;
            save.disabled = session.busy || session.blocked || datePending || !dirty;
            date.disabled = session.busy || dirty > 0;
            fill.disabled = session.busy || session.blocked || datePending || !session.rows.length;
            discard.disabled = session.busy || !dirty;
            refresh.disabled = session.busy || dirty > 0;
            search.disabled = state.disabled = find.disabled = session.busy;
            previous.disabled = session.busy || !history.length;
            next.disabled = session.busy || !nextCursor;
            backButton.disabled = session.busy;
            for (const row of session.rows) row.disable(session.busy || datePending);
        }
        function edited() {
            update();
            const dirty = session.rows.filter((row) => row.dirty()).length;
            announcement.textContent = session.blocked
                ? "Discard unsaved changes and refresh before continuing."
                : `${dirty} ${dirty === 1 ? "student has" : "students have"} unsaved changes.`;
        }
        function scoreEditor(item, key) {
            let savedMark = item.earned_hundredths == null ? "" : String(item.earned_hundredths / 100),
                savedExcused = item.excused,
                version = item.version;
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
            return {
                controls: [mark, excused],
                fields: [
                    node(
                        "div",
                        {},
                        node(
                            "label",
                            { for: mark.id, class: "visually-hidden" },
                            `Points earned for ${item.given_name} ${item.family_name}`,
                        ),
                        mark,
                    ),
                    node(
                        "div",
                        { class: "form-check" },
                        excused,
                        node(
                            "label",
                            { for: excused.id, class: "form-check-label" },
                            node(
                                "span",
                                { class: "visually-hidden" },
                                `${item.given_name} ${item.family_name}: `,
                            ),
                            "Excused",
                        ),
                    ),
                ],
                dirty: () => mark.value !== savedMark || excused.checked !== savedExcused,
                reset() {
                    mark.value = savedMark;
                    excused.checked = savedExcused;
                },
                feedback: () =>
                    excused.checked ? "Excused" : mark.value === "" ? "Awaiting score" : "Scored",
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
                    return {
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
                },
            };
        }
        function attendanceEditor(item, key) {
            let saved = item.status || "",
                revision = item.revision;
            const empty = node("option", { value: "" }, "Not marked");
            const mark = node(
                "select",
                { id: `${key}-mark`, class: "form-select", "aria-describedby": `${key}-status` },
                empty,
                ...["present", "absent", "late", "excused"].map((value) =>
                    node("option", { value }, value[0].toUpperCase() + value.slice(1)),
                ),
            );
            const synchronize = () => {
                empty.disabled = saved !== "";
            };
            synchronize();
            return {
                controls: [mark],
                fields: [
                    node(
                        "div",
                        {},
                        node(
                            "label",
                            { for: mark.id, class: "visually-hidden" },
                            `Attendance for ${item.given_name} ${item.family_name}`,
                        ),
                        mark,
                    ),
                ],
                dirty: () => mark.value !== saved,
                reset() {
                    mark.value = saved;
                    synchronize();
                },
                feedback: () =>
                    mark.value ? mark.value[0].toUpperCase() + mark.value.slice(1) : "Not marked",
                fill() {
                    if (saved === "" && mark.value === "") mark.value = "present";
                },
                payload() {
                    const valid = ["present", "absent", "late", "excused"].includes(mark.value);
                    mark.setCustomValidity(
                        valid ? "" : "Choose an attendance mark. Recorded marks cannot be cleared here.",
                    );
                    if (!valid) {
                        mark.setAttribute("aria-invalid", "true");
                        mark.reportValidity();
                        return null;
                    }
                    return { status: mark.value, held_on: activeDate, revision };
                },
                accepted(response) {
                    if (typeof response?.revision !== "string" || !response.revision)
                        throw new Error("Attendance confirmation is incomplete.");
                    saved = mark.value;
                    revision = response.revision;
                    synchronize();
                },
            };
        }
        function rosterRow(item) {
            const key = `worksheet-${item.student_id}`;
            const result = node("p", {
                id: `${key}-status`,
                class: "form-text mb-0",
                role: "status",
                "aria-live": "polite",
            });
            const editor = attendance ? attendanceEditor(item, key) : scoreEditor(item, key);
            function feedback() {
                result.setAttribute("class", "form-text mb-0");
                result.textContent = editor.feedback();
            }
            function clearErrors() {
                for (const control of editor.controls) {
                    control.setCustomValidity("");
                    control.removeAttribute("aria-invalid");
                }
            }
            const row = {
                dirty: editor.dirty,
                reset() {
                    editor.reset();
                    clearErrors();
                    feedback();
                },
                disable(value) {
                    for (const control of editor.controls) control.disabled = value;
                },
                fill() {
                    editor.fill?.();
                    feedback();
                },
                payload() {
                    clearErrors();
                    const value = editor.payload();
                    return value === null
                        ? null
                        : { student_id: item.student_id, enrollment_id: item.enrollment_id, ...value };
                },
                accepted(response) {
                    editor.accepted(response);
                    result.textContent = "Saved";
                },
                failed(error) {
                    result.setAttribute("class", "form-text text-danger fw-semibold mb-0");
                    const messages = {
                        409: "This mark, assignment or registration changed. Discard unsaved changes and refresh before retrying.",
                        422: attendance
                            ? "Mark not saved. Check the date, attendance choice and active registration."
                            : "Mark not saved. Check the score limit and whether the student is still enrolled.",
                        401: "Your session expired. Sign in again before continuing.",
                        403: "Your teaching access changed. Refresh before continuing.",
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
                        node("strong", {}, `${item.given_name} ${item.family_name}`),
                        node("div", { class: "small" }, item.institutional_id),
                    ),
                    ...editor.fields,
                ],
            };
            editor.fields[0].append(result);
            for (const control of editor.controls)
                control.addEventListener(
                    attendance || control.getAttribute("type") === "checkbox" ? "change" : "input",
                    () => {
                        clearErrors();
                        feedback();
                        edited();
                    },
                );
            row.reset();
            return row;
        }
        async function load(
            after = cursor,
            appliedSearch = activeSearch,
            appliedState = activeState,
            appliedDate = activeDate,
        ) {
            const token = ++generation;
            session.busy = true;
            update();
            content.setAttribute("aria-busy", "true");
            try {
                const page = await request(
                    attendance
                        ? `/sis/sections/${section.id}/attendance-roster/`
                        : `/sis/assignments/${id}/score-roster/`,
                    "POST",
                    {
                        ...(attendance ? { held_on: appliedDate } : {}),
                        search: appliedSearch,
                        state: appliedState,
                        after,
                    },
                );
                if (!body.isConnected || token !== generation) return false;
                assignment = page.assignment;
                cursor = after;
                nextCursor = page.next_after;
                search.value = appliedSearch;
                state.value = appliedState;
                activeSearch = appliedSearch;
                activeState = appliedState;
                session.blocked = false;
                if (attendance) {
                    activeDate = page.held_on;
                    date.value = activeDate || "";
                    date.min = page.term.starts_on;
                    date.max = page.term.ends_on;
                }
                session.rows = page.items.map(rosterRow);
                heading.textContent = attendance
                    ? activeDate
                        ? `Attendance · ${activeDate}`
                        : "Choose a class date"
                    : assignment.title;
                limits.textContent = attendance
                    ? `Term: ${page.term.starts_on} through ${page.term.ends_on}. Attendance uses one mark per student and date.`
                    : `Base: ${assignment.possible_hundredths / 100} points · Bonus limit: ${assignment.bonus_hundredths / 100} · Maximum earned: ${assignment.score_limit_hundredths / 100} points · Due ${assignment.due_on}`;
                content.replaceChildren(
                    session.rows.length
                        ? node(
                              "div",
                              {
                                  class: "table-responsive",
                                  role: "region",
                                  tabindex: "0",
                                  "aria-label": title,
                              },
                              table(
                                  attendance
                                      ? ["Student", "Attendance"]
                                      : ["Student", "Points earned", "Inclusion"],
                                  session.rows.map((row) => row.cells),
                                  title,
                              ),
                          )
                        : node("p", {}, "No active students match these filters."),
                );
                paging.textContent = `${page.items.length} students on this page.${nextCursor ? " More are available." : ""} Filters apply to saved marks; saved rows remain here until you refresh.`;
                announcement.textContent =
                    attendance && !activeDate
                        ? "Choose a class date and load the register."
                        : "Worksheet ready. Only changed rows will be saved.";
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
                if (await load("", search.value.trim(), state.value, attendance ? date.value : null))
                    history = [];
                update();
            });
        });
        form.addEventListener("submit", (event) => {
            event.preventDefault();
            if (session.busy || session.blocked) return;
            if (attendance && (date.value || null) !== activeDate) return;
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
                            const response = await request(
                                attendance
                                    ? `/sis/sections/${section.id}/attendance/`
                                    : `/sis/assignments/${id}/scores/`,
                                "POST",
                                payload,
                            );
                            row.accepted(response);
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
        date.addEventListener("change", () => {
            update();
            if ((date.value || null) !== activeDate)
                announcement.textContent = "Load the selected date before editing marks.";
        });
        await load();
    }
    return {
        assignment: (id, section, back) => open(id, section, back),
        attendance: (section, back) => open(null, section, back, true),
        canLeave,
    };
};
