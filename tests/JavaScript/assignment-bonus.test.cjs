"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { Element, node, walk } = require("./support/elements.cjs");
Element.prototype.removeAttribute = function (key) {
    delete this.attributes[key];
};
const originalQuery = Element.prototype.querySelector;
Element.prototype.querySelector = function (selector) {
    return walk(this).find((e) => e.tag === selector) || originalQuery.call(this, selector);
};
async function fixture(excused = false) {
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/gradebook-calculation.js", "utf8"), { window });
    vm.runInNewContext(fs.readFileSync("public/assets/js/teaching-worksheet.js", "utf8"), { window });
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-teaching.js", "utf8"), { window });
    let body, pending, lastFlow, delayed, release;
    const score = {
        assignment_id: "task",
        title: "Fictional bonus task",
        possible_hundredths: 10000,
        bonus_hundredths: 2000,
        score_limit_hundredths: 12000,
        earned_hundredths: 11500,
        version: 1,
        excused,
    };
    const summary = {
        mode: "points",
        percent_hundredths: excused ? null : 11500,
        coverage_hundredths: excused ? 0 : 10000,
        graded: excused ? 0 : 1,
        pending: 0,
        excluded: 0,
        bonus_enabled: !excused,
        bonus_earned_hundredths: excused ? 0 : 1500,
        excused: excused ? 1 : 0,
        excused_assignments: excused ? [score] : [],
    };
    const openDrawer = () => {
        if (body) for (const e of walk(body)) e.isConnected = false;
        return (body = node("div"));
    };
    const ui = {
        node,
        openDrawer,
        task: (run) => (pending = Promise.resolve().then(run)),
        table: (headers, rows) => node("table", {}, rows.flat()),
        badge: (text) => node("span", {}, text),
        recordSummary: () => node("dl"),
        flow: async (...args) => {
            const [title, fields, endpoint, map, back, preset] = args;
            const parent = openDrawer();
            const form = node("form");
            form.elements = {};
            for (const [name] of fields) {
                const input = node("input", { name });
                input.value = preset[name] ?? "";
                form.elements[name] = input;
                form.append(input);
            }
            parent.append(form);
            lastFlow = { args, form };
            return form;
        },
        request: async (path) => {
            if (path.endsWith("/attendance-roster/"))
                return {
                    term: { starts_on: "2026-08-01", ends_on: "2026-12-31" },
                    held_on: null,
                    items: [],
                    next_after: null,
                };
            if (path.endsWith("/score-roster/"))
                return {
                    assignment: {
                        id: "task",
                        title: score.title,
                        possible_hundredths: 10000,
                        bonus_hundredths: 2000,
                        score_limit_hundredths: 12000,
                        revision: "current",
                        due_on: "2026-09-01",
                    },
                    items: [
                        {
                            student_id: "student",
                            enrollment_id: "enrollment",
                            given_name: "Fictional",
                            family_name: "Student",
                            institutional_id: "TEST-42",
                            earned_hundredths: score.earned_hundredths,
                            excused,
                            version: 1,
                        },
                    ],
                    next_after: null,
                };
            if (path.includes("/roster/"))
                return {
                    items: [
                        {
                            student_id: "student",
                            status: "A",
                            given_name: "Fictional",
                            family_name: "Student",
                        },
                    ],
                    can_grade: true,
                };
            if (path.includes("/context/assignments/")) return { items: [{ id: "task", ...score }] };
            if (path.endsWith("/attendance/")) return { items: [], counts: {} };
            if (path.endsWith("/gradebook-summary/")) return summary;
            if (path.endsWith("/score-page/")) return { items: [score] };
            const record = {
                id: "task",
                section_id: "section",
                title: score.title,
                possible_hundredths: 10000,
                bonus_hundredths: 2000,
                due_on: "2026-09-01",
                revision: "current",
            };
            if (delayed)
                return new Promise((resolve) => {
                    release = () => resolve(record);
                });
            return record;
        },
    };
    const component = window.createWorkspaceTeaching(ui);
    const section = node("div");
    await component.section(section, { id: "section", code: "TEST.101" }, "teaching");
    const click = async (container, label) => {
        const button = walk(container).find((e) => e.tag === "button" && e.textContent === label);
        assert.ok(button, label);
        button.attributes.onclick({ currentTarget: button });
        await pending;
    };
    return {
        section,
        click,
        body: () => body,
        flow: () => lastFlow,
        defer: () => {
            delayed = true;
        },
        release: () => release(),
        close: openDrawer,
        canLeave: component.canLeave,
    };
}
test("assignment creation exposes an optional default-zero bonus limit", async () => {
    const f = await fixture();
    await f.click(f.section, "Add assignment");
    const flow = f.flow();
    const field = flow.args[1].find(([name]) => name === "bonus_hundredths");
    assert.equal(field[2], "hundredths");
    assert.equal(field[4], false);
    assert.equal(flow.args[5].bonus_hundredths, 0);
    assert.match(flow.args[6], /without adding possible points to the average/);
});
test("the assignment list opens the integrated grading worksheet", async () => {
    const f = await fixture(true);
    await f.click(f.section, "Grade assignment");
    assert.match(f.body().textContent, /Fictional Student/);
    assert.match(f.body().textContent, /Maximum earned: 120 points/);
    assert.ok(walk(f.body()).some((e) => e.tag === "button" && e.textContent === "Save changes"));
    assert.equal(f.canLeave(), true);
    const input = walk(f.body()).find((e) => e.id === "worksheet-student-mark");
    input.value = "100";
    input.events.input({});
    assert.equal(f.canLeave(), false);
});
test("the section roster opens attendance through the shared worksheet", async () => {
    const f = await fixture();
    await f.click(f.section, "Take attendance");
    assert.match(f.body().textContent, /Choose a class date/);
    assert.ok(walk(f.body()).some((e) => e.tag === "input" && e.id === "worksheet-date"));
    assert.equal(f.canLeave(), true);
});
test("assignment editing preserves the bonus in points and its revision", async () => {
    const f = await fixture();
    await f.click(f.section, "Edit assignment");
    const flow = f.flow();
    assert.equal(flow.args[5].bonus_hundredths, 20);
    assert.equal(flow.args[5].possible_hundredths, 100);
    assert.equal(flow.args[3]({ bonus_hundredths: 3000 }).revision, "current");
});
test("score editing permits only the explicit combined limit and explains the denominator", async () => {
    const f = await fixture();
    await f.click(f.section, "Grade & attendance");
    assert.match(f.body().textContent, /115.00%/);
    assert.match(f.body().textContent, /15 bonus points in counted work/);
    await f.click(f.body(), "Enter score");
    assert.equal(f.flow().form.elements.earned_hundredths.max, 120);
    assert.match(f.body().textContent, /Maximum earned: 120 points/);
    assert.equal(
        f.flow().form.elements.earned_hundredths.getAttribute("aria-describedby"),
        "score-point-limit",
    );
});
test("a late assignment read cannot reopen a closed private drawer", async () => {
    const f = await fixture();
    f.defer();
    const pending = f.click(f.section, "Edit assignment");
    await Promise.resolve();
    const other = f.close();
    f.release();
    await pending;
    assert.equal(f.body(), other);
    assert.equal(f.flow(), undefined);
});
test("the score editor retains an excused mark and submits inclusion as an explicit choice", async () => {
    const f = await fixture(true);
    await f.click(f.section, "Grade & attendance");
    assert.match(f.body().textContent, /Excused ·/);
    await f.click(f.body(), "Enter score");
    const flow = f.flow();
    assert.equal(flow.args[5].excused, true);
    assert.equal(flow.args[5].earned_hundredths, 115);
    assert.match(f.body().textContent, /without clearing the entered mark/);
    assert.equal(flow.form.elements.excused.getAttribute("aria-describedby"), "score-point-limit");
    const included = flow.args[3]({ earned_hundredths: 11500, excused: false });
    assert.equal(included.excused, false);
    assert.equal(included.earned_hundredths, 11500);
    assert.equal(included.version, 1);
});
