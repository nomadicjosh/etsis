"use strict";
const assert = require("node:assert/strict");
const term = { starts_on: "2026-08-01", ends_on: "2026-12-31" };
const fs = require("node:fs"),
    vm = require("node:vm");
const { Element, node, walk } = require("./elements.cjs");
Element.prototype.removeAttribute = function (key) {
    delete this.attributes[key];
};
Element.prototype.setCustomValidity = function (value) {
    this.validation = value;
};
const event = () => ({ preventDefault() {} });
const assignment = {
    id: "task",
    title: "Fictional quiz <script>",
    possible_hundredths: 1000,
    bonus_hundredths: 200,
    score_limit_hundredths: 1200,
    due_on: "2026-09-01",
    revision: "assignment-revision",
};
const student = (id, mark = null, excused = false) => ({
    student_id: id,
    enrollment_id: `enrollment-${id}`,
    given_name: `Fictional ${id}`,
    family_name: "Student",
    institutional_id: `TEST-${id}`,
    earned_hundredths: mark,
    excused,
    version: mark === null ? 0 : 2,
});
async function fixture(items = [student("one", 800), student("two")], next = null, attendance = false) {
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/teaching-worksheet.js", "utf8"), { window });
    let body,
        pending,
        failure,
        delayed,
        delayedRead,
        release,
        pages = [],
        backs = 0;
    const calls = [],
        writes = [];
    const module = window.createTeachingWorksheet({
        node,
        table: (headers, rows, caption) => node("table", {}, node("caption", {}, caption), rows.flat()),
        task: (run) => (pending = Promise.resolve().then(run)),
        openDrawer: () => (body = node("div")),
        request: async (path, method, data) => {
            calls.push({ path, method, data: JSON.parse(JSON.stringify(data)) });
            if (path.endsWith("score-roster/") || path.endsWith("attendance-roster/")) {
                const page =
                    pages.shift() ||
                    (attendance
                        ? {
                              term,
                              held_on: data.held_on ?? null,
                              items: data.held_on ? items : [],
                              next_after: data.held_on ? next : null,
                          }
                        : { assignment, items, next_after: next });
                if (delayedRead)
                    return new Promise((resolve) => {
                        release = () => resolve(page);
                    });
                return page;
            }
            writes.push(JSON.parse(JSON.stringify(data)));
            if (failure && writes.length === failure.at)
                throw Object.assign(new Error("Private fixture failure"), { status: failure.status });
            if (delayed)
                return new Promise((resolve) => {
                    release = resolve;
                });
            return attendance
                ? { status: "recorded", revision: `saved-${writes.length}` }
                : { status: "graded" };
        },
    });
    if (attendance)
        await module.attendance({ id: "section", code: "TEST.101" }, () => {
            backs++;
        });
    else
        await module.assignment("task", { code: "TEST.101" }, () => {
            backs++;
        });
    const get = (id) => walk(body).find((e) => e.id === id);
    const control = (label) => walk(body).find((e) => e.tag === "button" && e.textContent === label);
    const click = async (label) => {
        const b = control(label);
        assert.ok(b, label);
        b.attributes.onclick(event());
        await pending;
    };
    const change = (id, value, check = false) => {
        const input = get(id);
        assert.ok(input, id);
        if (check) input.checked = value;
        else input.value = value;
        input.events[
            check || input.tag === "select" || input.attributes.type === "date" ? "change" : "input"
        ](event());
    };
    const submit = async (index = 1) => {
        walk(body)
            .filter((e) => e.tag === "form")
            [index].events.submit(event());
        await pending;
    };
    return {
        module,
        get,
        body: () => body,
        calls,
        writes,
        click,
        control,
        change,
        submit,
        backs: () => backs,
        fail: (at, status) => {
            failure = { at, status };
        },
        queue: (page) => pages.push(page),
        delay: () => {
            delayed = true;
        },
        delayRead: () => {
            delayedRead = true;
        },
        release: () => release(),
        close: () => {
            for (const e of walk(body)) e.isConnected = false;
        },
    };
}
module.exports = { fixture, student, assignment, term };
