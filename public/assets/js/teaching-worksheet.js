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
        current.announcement.textContent =
            current.kind === "final grades"
                ? current.busy
                    ? "Working with final grades. Wait for the result before leaving."
                    : "You have unposted selections or an unconfirmed result. Post, retry or discard selections before leaving."
                : current.busy
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
                    : `${assignment.possible_hundredths === 0 ? "Bonus only · Optional work" : `Base: ${assignment.possible_hundredths / 100} points`} · Bonus limit: ${assignment.bonus_hundredths / 100} · Maximum earned: ${assignment.score_limit_hundredths / 100} points · Due ${assignment.due_on}`;
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
    async function finalGrades(section, back, initialSearch = "") {
        const body = openDrawer("Final-grade worksheet", `${section.code} · ACADEMIC RECORDS`);
        const announcement = node("p", { role: "status", "aria-live": "polite", tabindex: "-1" });
        const session = { body, announcement, rows: [], busy: false, kind: "final grades" };
        current = session;
        let cursor = "",
            nextCursor = null,
            previousCursors = [],
            searchValue = initialSearch,
            phase = "editing",
            batch = [],
            generation = 0;
        const alive = () => body.isConnected && current === session;
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
        const progress = node("progress", {
            class: "w-100",
            max: "1",
            value: "0",
            hidden: true,
            "aria-label": "Final-grade task progress",
        });
        const search = node("input", {
            id: "final-grade-search",
            type: "search",
            maxlength: "100",
            class: "form-control",
            value: initialSearch,
        });
        const find = node("button", { type: "submit", class: "btn btn-outline-primary" }, "Find students");
        const filters = node(
            "form",
            { class: "row g-2 my-3" },
            node(
                "div",
                { class: "col-sm-8" },
                node("label", { for: search.id, class: "form-label" }, "Student name or ID"),
                search,
            ),
            node("div", { class: "col-sm-4 align-self-end" }, find),
        );
        const content = node("div"),
            confirmation = node("section", { hidden: true, "aria-label": "Reviewed final grades" });
        const paging = node("p", { class: "form-text" });
        const prepare = action("Load grade choices", () => loadChoices(), "btn-outline-primary");
        const review = action("Review selected", () => reviewRows(), "btn-primary");
        const discard = action("Discard selections", () => {
            batch = [];
            phase = "editing";
            for (const row of session.rows) {
                if (row.saved) continue;
                row.select.value = "";
                row.proof = null;
                row.coursework = null;
                row.feedback.textContent = "Selection discarded; no new grade was posted by this action.";
            }
            announcement.textContent = "Unposted selections discarded. Confirmed grades stay posted.";
            update();
        });
        const refresh = action("Refresh", () => {
            if (canLeave()) return load();
        });
        const previous = action("Previous page", async () => {
            if (!canLeave()) return;
            const target = previousCursors.at(-1) || "";
            if (await load(target)) previousCursors.pop();
            update();
        });
        const next = action("Next page", async () => {
            if (!canLeave() || !nextCursor) return;
            const old = cursor;
            if (await load(nextCursor)) previousCursors.push(old);
            update();
        });
        const backButton = action("Back to roster", () => {
            if (canLeave()) return back();
        });
        const revise = action("Revise grades", () => {
            for (const row of batch)
                if (!row.saved) {
                    row.proof = null;
                    row.feedback.textContent = "Not posted. Review again before posting.";
                }
            batch = [];
            phase = "editing";
            update();
            session.rows.find((row) => row.dirty())?.select.focus();
        });
        const post = action("Post reviewed grades", () => commitRows(), "btn-primary");
        body.append(
            node("h3", { class: "h5" }, section.course_title || section.course_code || section.code),
            node(
                "p",
                { class: "form-text" },
                "Initial final grades only. Load choices, select grades, then review before posting. Each student is confirmed separately; a stopped run can leave some grades posted. Posted grades require the individual registrar correction workflow.",
            ),
            announcement,
            filters,
            node("div", { class: "inline-actions my-3" }, prepare, review, discard, refresh, backButton),
            progress,
            content,
            confirmation,
            paging,
            node("div", { class: "inline-actions" }, previous, next),
        );
        function update() {
            const dirty = session.rows.some((row) => row.dirty());
            const uncertain = session.rows.some((row) => row.uncertain);
            const locked = session.busy || phase !== "editing";
            prepare.disabled =
                session.busy ||
                phase !== "editing" ||
                !session.rows.some((row) => !row.context && !row.unavailable);
            review.disabled = locked || !dirty;
            discard.disabled = session.busy || uncertain || !dirty;
            refresh.disabled = session.busy || dirty;
            search.disabled = find.disabled = locked;
            previous.disabled = session.busy || !previousCursors.length;
            next.disabled = session.busy || !nextCursor;
            backButton.disabled = session.busy;
            revise.disabled = session.busy || uncertain || !batch.some((row) => !row.saved);
            post.disabled =
                session.busy ||
                !batch.some((row) => !row.saved) ||
                batch.some((row) => !row.saved && !row.proof);
            post.textContent = uncertain ? "Retry confirmation" : "Post reviewed grades";
            content.hidden = phase !== "editing";
            confirmation.hidden = phase === "editing";
            for (const row of session.rows) {
                if (phase === "editing" && row.feedback.parentNode !== row.editorStatus)
                    row.editorStatus.replaceChildren(row.feedback);
                row.select.disabled = locked || !row.context || row.unavailable || row.saved;
                row.pursuit.disabled = locked || row.saved;
                row.load.disabled = locked || row.unavailable || row.saved;
                row.suggest.disabled = locked || row.unavailable || row.saved;
            }
        }
        function chosen(row) {
            row.proof = null;
            row.coursework = null;
            row.select.removeAttribute("aria-invalid");
            row.feedback.setAttribute("class", "form-text mb-0");
            row.feedback.textContent = row.select.value ? "Selected, not posted." : "No grade selected.";
            update();
        }
        function makeRow(item) {
            const key = `final-grade-${item.student_id}`;
            const label = `${item.given_name} ${item.family_name}`;
            const feedback = node(
                "p",
                { id: `${key}-status`, class: "form-text mb-0", role: "status", "aria-live": "polite" },
                "Load this student's grading choices.",
            );
            const select = node(
                "select",
                { id: `${key}-grade`, class: "form-select", "aria-describedby": feedback.id },
                node("option", { value: "" }, "Choose a grade"),
            );
            const pursuit = node(
                "select",
                { id: `${key}-pursuit`, class: "form-select", hidden: true },
                node("option", { value: "" }, "Choose program pursuit"),
            );
            const row = {
                item,
                select,
                pursuit,
                feedback,
                context: null,
                proof: null,
                coursework: null,
                saved: false,
                uncertain: false,
                unavailable: false,
                dirty() {
                    return !row.saved && (!!select.value || row.uncertain);
                },
            };
            const load = action("Load choices", () => loadChoices([row]));
            const suggest = action(
                "Use coursework suggestion",
                () => {
                    const suggestion = row.context?.coursework;
                    if (
                        !suggestion?.available ||
                        !row.context.items.some((item) => item.id === suggestion.grade_id)
                    )
                        return;
                    select.value = suggestion.grade_id;
                    row.coursework = suggestion.revision;
                    row.proof = null;
                    feedback.textContent = `Using reviewed coursework: ${suggestion.score_display}. Not posted.`;
                    update();
                },
                "btn-link btn-sm",
            );
            suggest.setAttribute("aria-label", `Use coursework suggestion for ${label}`);
            suggest.hidden = true;
            row.load = load;
            row.suggest = suggest;
            row.loadLabel = (text) => {
                load.textContent = text;
                load.setAttribute("aria-label", `${text} for ${label}`);
            };
            row.loadLabel("Load choices");
            select.addEventListener("change", () => chosen(row));
            pursuit.addEventListener("change", () => {
                row.context = null;
                select.value = "";
                row.proof = null;
                row.coursework = null;
                suggest.hidden = true;
                row.loadLabel("Use program");
                feedback.textContent = "Load choices for the selected program before choosing a grade.";
                update();
            });
            row.editorStatus = node("div", {}, feedback);
            row.cells = [
                node(
                    "div",
                    { class: "text-break" },
                    node("strong", {}, label),
                    node("div", { class: "small" }, item.institutional_id),
                ),
                node(
                    "div",
                    {},
                    node("label", { for: select.id, class: "visually-hidden" }, `Final grade for ${label}`),
                    select,
                    node(
                        "label",
                        { for: pursuit.id, class: "visually-hidden" },
                        `Program pursuit for ${label}`,
                    ),
                    pursuit,
                    node("div", { class: "inline-actions mt-2" }, load, suggest),
                ),
                row.editorStatus,
            ];
            return row;
        }
        async function context(row) {
            const choices = await request(
                `/sis/sections/${section.id}/students/${row.item.student_id}/grade-choices/`,
                "POST",
                row.pursuit.value ? { assignment_id: row.pursuit.value } : {},
            );
            if (!alive()) return;
            row.select.value = "";
            row.proof = null;
            row.coursework = null;
            if (choices.enrollment_id !== row.item.id || choices.posted) {
                row.unavailable = true;
                row.feedback.textContent =
                    choices.enrollment_id !== row.item.id
                        ? "Registration changed. Refresh this worksheet before grading."
                        : "Already posted. Use the individual correction workflow if necessary.";
                return;
            }
            if (choices.needs_assignment) {
                row.pursuit.hidden = false;
                row.pursuit.replaceChildren(
                    node("option", { value: "" }, "Choose program pursuit"),
                    ...choices.pursuits.map((item) =>
                        node("option", { value: item.id }, `${item.code} · ${item.title}`),
                    ),
                );
                row.loadLabel("Use program");
                row.feedback.textContent = "Choose the applicable program, then load its grading choices.";
                return;
            }
            row.context = choices;
            row.select.replaceChildren(
                node("option", { value: "" }, "Choose a grade"),
                ...choices.items.map((grade) =>
                    node(
                        "option",
                        { value: grade.id },
                        `${grade.code} · ${Number(grade.points_milli) / 1000} grade points`,
                    ),
                ),
            );
            row.suggest.hidden = !choices.coursework?.available;
            row.loadLabel("Reload choices");
            row.feedback.textContent = !choices.items.length
                ? "A published grading scale is needed before this record can be graded."
                : choices.coursework?.available
                  ? `Coursework: ${choices.coursework.score_display}. Choose a grade or use the suggestion.`
                  : "Choose a grade. No official grade is posted until confirmation.";
        }
        function failure(row, error, confirming = false) {
            const status = error.status;
            row.uncertain = confirming && (row.uncertain || ![401, 403, 409, 422].includes(status));
            row.feedback.setAttribute("class", "form-text fw-semibold mb-0");
            if (status === 422) row.select.setAttribute("aria-invalid", "true");
            row.feedback.textContent = row.uncertain
                ? "The outcome is unknown. Retry this same confirmation to check its original result."
                : status === 401
                  ? "Your session expired. Sign in again before continuing."
                  : status === 403
                    ? "Grading access changed. Remaining grades were not sent."
                    : status === 409
                      ? "The record or review changed or expired. Discard unposted selections and refresh."
                      : status === 422
                        ? "This grade needs individual review. Check the selected grade and program."
                        : "Grading choices could not be loaded. Try again or use individual review.";
            row.feedback.tabIndex = -1;
            row.feedback.focus();
        }
        async function loadChoices(rows = session.rows.filter((row) => !row.context && !row.unavailable)) {
            if (session.busy || phase !== "editing") return;
            if (rows.some((row) => row.dirty())) {
                announcement.textContent = "Discard this grade selection before reloading its choices.";
                return;
            }
            session.busy = true;
            progress.hidden = false;
            progress.max = Math.max(1, rows.length);
            progress.value = 0;
            update();
            try {
                for (const row of rows) {
                    if (!alive()) return;
                    announcement.textContent = `Loading grading choices ${Number(progress.value) + 1} of ${rows.length}…`;
                    try {
                        await context(row);
                    } catch (error) {
                        failure(row, error);
                        if ([401, 403].includes(error.status)) return;
                    }
                    progress.value++;
                }
                if (alive())
                    announcement.textContent =
                        "Choices loaded. Select grades, then review. Nothing has been posted.";
            } finally {
                session.busy = false;
                if (alive()) update();
            }
        }
        async function load(after = cursor, searchText = searchValue) {
            const token = ++generation;
            session.busy = true;
            update();
            try {
                const page = await request(`/sis/ui/context/roster/${section.id}/`, "POST", {
                    search: searchText,
                    grade_status: "needs_grade",
                    after,
                });
                if (!alive() || token !== generation) return false;
                if (!page.can_grade) throw new Error("Grading access is required for this worksheet.");
                const items = page.items.slice(0, 20);
                nextCursor = page.items.length > 20 ? items.at(-1).id : page.next_after;
                cursor = after;
                searchValue = searchText;
                search.value = searchText;
                phase = "editing";
                batch = [];
                session.rows = items.map(makeRow);
                content.replaceChildren(
                    items.length
                        ? node(
                              "div",
                              {
                                  class: "table-responsive",
                                  role: "region",
                                  tabindex: "0",
                                  "aria-label": "Initial final grades",
                              },
                              table(
                                  ["Student", "Final grade", "Status"],
                                  session.rows.map((row) => row.cells),
                                  "Initial final grades",
                              ),
                          )
                        : node("p", {}, "No active ungraded students match this page."),
                );
                paging.textContent = `${items.length} students on this page.${nextCursor ? " More are available." : ""} Only selected grades will be reviewed. Refresh after posting to find remaining work.`;
                announcement.textContent = "Load grading choices on demand. Reviewing does not post grades.";
                return true;
            } finally {
                if (token === generation) {
                    session.busy = false;
                    if (alive()) update();
                }
            }
        }
        function showReview() {
            const heading = node("h3", { class: "h5", tabindex: "-1" }, "Review final grades");
            confirmation.replaceChildren(
                heading,
                batch.some((row) => row.proof.earlier_history_unknown)
                    ? node(
                          "p",
                          { class: "alert alert-info" },
                          "Some earlier academic history is unknown. This records the reviewed grades now; it does not reconstruct earlier decisions.",
                      )
                    : null,
                node(
                    "p",
                    { class: "form-text" },
                    "Check every student and grade. Posting confirms one student at a time and stops at the first failure. Keep this worksheet open until posting finishes.",
                ),
                node(
                    "div",
                    {
                        class: "table-responsive",
                        role: "region",
                        tabindex: "0",
                        "aria-label": "Final-grade confirmations",
                    },
                    table(
                        ["Student", "Grade", "Grade points", "Effective", "Status"],
                        batch.map((row) => [
                            node(
                                "div",
                                {},
                                `${row.item.given_name} ${row.item.family_name}`,
                                node("div", { class: "small" }, row.item.institutional_id),
                                row.proof.earlier_history_unknown
                                    ? node("div", { class: "form-text" }, "Earlier history incomplete")
                                    : null,
                            ),
                            row.proof.grade.code,
                            Number(row.proof.grade.points_milli) / 1000,
                            row.proof.effective_at,
                            row.feedback,
                        ]),
                        "Final-grade confirmations",
                    ),
                ),
                node("div", { class: "inline-actions" }, post, revise),
            );
            phase = "reviewed";
            update();
            heading.focus();
        }
        async function reviewRows() {
            if (session.busy || phase !== "editing") return;
            const rows = session.rows.filter((row) => row.dirty());
            if (!rows.length) return;
            session.busy = true;
            progress.hidden = false;
            progress.max = rows.length;
            progress.value = 0;
            update();
            try {
                for (const row of rows) {
                    if (!alive()) return;
                    announcement.textContent = `Reviewing ${Number(progress.value) + 1} of ${rows.length} grades…`;
                    try {
                        const proof = await request(
                            `/sis/sections/${section.id}/final-grades/review/`,
                            "POST",
                            {
                                student_id: row.item.student_id,
                                enrollment_id: row.item.id,
                                initial_only: true,
                                grade_id: row.select.value,
                                version: row.context.current_version,
                                effective_on: row.context.effective_on_default,
                                ...(row.pursuit.value ? { assignment_id: row.pursuit.value } : {}),
                                ...(row.coursework ? { coursework_revision: row.coursework } : {}),
                            },
                        );
                        if (!alive()) return;
                        if (
                            proof.amendment ||
                            proof.requires_approval ||
                            proof.student_id !== row.item.student_id ||
                            proof.enrollment_id !== row.item.id ||
                            proof.grade.id !== row.select.value
                        )
                            throw Object.assign(new Error("Review context changed"), { status: 409 });
                        row.proof = proof;
                        row.feedback.textContent = "Reviewed, not posted.";
                        progress.value++;
                    } catch (error) {
                        for (const item of rows) item.proof = null;
                        failure(row, error);
                        announcement.textContent =
                            "Review stopped. No grades were posted. Correct or clear the highlighted selection, then review again.";
                        return;
                    }
                }
                batch = rows;
                showReview();
                announcement.textContent = `${rows.length} ${rows.length === 1 ? "grade" : "grades"} reviewed. No grades posted yet.`;
            } finally {
                session.busy = false;
                if (alive()) update();
            }
        }
        async function commitRows() {
            if (session.busy || phase !== "reviewed") return;
            if (batch.some((row) => !row.saved && !row.proof)) return;
            session.busy = true;
            progress.hidden = false;
            progress.max = batch.length;
            progress.value = batch.filter((row) => row.saved).length;
            update();
            try {
                for (const row of batch) {
                    if (!alive()) return;
                    if (row.saved) continue;
                    announcement.textContent = `Confirming ${Number(progress.value) + 1} of ${batch.length} reviewed grades…`;
                    try {
                        const receipt = await request(
                            `/sis/sections/${section.id}/final-grades/commit/`,
                            "POST",
                            {
                                student_id: row.item.student_id,
                                review_token: row.proof.review_token,
                            },
                        );
                        if (!receipt || receipt.operation_id !== row.proof.operation_id)
                            throw Object.assign(new Error("Unconfirmed result"), { status: 0 });
                        row.saved = true;
                        row.uncertain = false;
                        row.feedback.textContent = "Posted";
                        progress.value++;
                    } catch (error) {
                        failure(row, error, true);
                        if (!row.uncertain) row.proof = null;
                        announcement.textContent = `${batch.filter((row) => row.saved).length} of ${batch.length} grades confirmed posted. Posting stopped; later rows were not sent. Check the highlighted row.`;
                        return;
                    }
                }
                announcement.textContent = `${batch.length} ${batch.length === 1 ? "grade" : "grades"} confirmed posted. Refresh the roster to check for other ungraded students.`;
            } finally {
                session.busy = false;
                if (alive()) update();
            }
        }
        filters.addEventListener("submit", (event) => {
            event.preventDefault();
            if (!canLeave()) return;
            task(async () => {
                if (await load("", search.value.trim())) previousCursors = [];
                update();
            });
        });
        await load();
    }
    return {
        assignment: (id, section, back) => open(id, section, back),
        attendance: (section, back) => open(null, section, back, true),
        finalGrades,
        canLeave,
    };
};
