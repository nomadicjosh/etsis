"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { Element, node, walk } = require("./support/elements.cjs");
Element.prototype.removeAttribute = function (name) {
    delete this.attributes[name];
};
function presenter() {
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/gradebook-calculation.js", "utf8"), { window });
    return window.createGradebookCalculation({ node });
}
test("feedback distinguishes zero from ungraded and explains official grade boundary", () => {
    const p = presenter();
    const summary = {
        mode: "weighted",
        percent_hundredths: 0,
        coverage_hundredths: 4000,
        pending: 1,
        excluded: 2,
    };
    assert.match(p.panel(summary).textContent, /0.00%/);
    assert.match(p.panel(summary).textContent, /40.00% graded/);
    assert.match(p.panel(summary).textContent, /1 awaiting scores · 2 excluded/);
    assert.match(p.panel(summary).textContent, /official final grade is recorded separately/);
    assert.match(p.panel({ ...summary, percent_hundredths: null }).textContent, /No included scores yet/);
});
async function editor() {
    const window = {};
    vm.runInNewContext(fs.readFileSync("public/assets/js/gradebook-calculation.js", "utf8"), { window });
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-teaching.js", "utf8"), { window });
    let body, pending, release;
    const sent = [];
    const setup = {
        mode: "points",
        weights: {},
        revision: "revision",
        categories: [],
        assignments: [
            { id: "one", title: "Quiz <img>" },
            { id: "two", title: "Exam" },
        ],
    };
    const teaching = window.createWorkspaceTeaching({
        node,
        table: () => node("table"),
        badge: (text) => node("span", {}, text),
        recordSummary: () => node("div"),
        task: (run) => (pending = Promise.resolve().then(run)),
        openDrawer: () => (body = node("div")),
        openRecord: async () => {
            sent.push("back");
        },
        request: async (path, method, data) => {
            if (path.includes("/roster/")) return { items: [], can_grade: true };
            if (path.includes("/context/assignments/")) return { items: [] };
            if (method === "POST") {
                sent.push(data);
                return {};
            }
            return release
                ? new Promise((resolve) => {
                      release(resolve);
                  })
                : setup;
        },
    });
    const section = node("div");
    await teaching.section(section, { id: "section", code: "TEST.101" }, "teaching");
    const click = () =>
        walk(section)
            .find((e) => e.tag === "button" && e.textContent === "Gradebook setup")
            .attributes.onclick({ currentTarget: node("button") });
    return {
        click,
        sent,
        body: () => body,
        done: () => pending,
        defer: (callback) => (release = callback),
    };
}
test("weighted editor validates totals and serializes hundredth-percent weights", async () => {
    const e = await editor();
    e.click();
    await e.done();
    const body = e.body(),
        form = walk(body).find((x) => x.tag === "form"),
        mode = walk(body).find((x) => x.tag === "select");
    const inputs = walk(body).filter((x) => x.tag === "input" && x.id.startsWith("weight-")),
        submit = walk(body).find((x) => x.attributes.type === "submit");
    assert.equal(inputs[0].disabled, true);
    assert.equal(
        walk(body).some((x) => x.tag === "img"),
        false,
    );
    assert.ok(walk(body).some((x) => x.tag === "label" && x.attributes.for === inputs[0].id));
    mode.value = "weighted";
    mode.events.change();
    assert.equal(submit.disabled, true);
    inputs[0].value = "40.25";
    inputs[1].value = "59.75";
    inputs[0].events.input();
    assert.equal(submit.disabled, false);
    form.events.submit({ preventDefault() {} });
    await e.done();
    assert.deepEqual(JSON.parse(JSON.stringify(e.sent[0])), {
        mode: "weighted",
        weights: { one: 4025, two: 5975 },
        revision: "revision",
        categories: [],
    });
});
test("late setup responses cannot populate a closed drawer", async () => {
    const e = await editor();
    let resolve;
    e.defer((callback) => {
        resolve = callback;
    });
    e.click();
    await Promise.resolve();
    await Promise.resolve();
    e.body().isConnected = false;
    resolve({ mode: "points", assignments: [] });
    await e.done();
    assert.equal(e.body().children.length, 0);
});

test("returning to points removes stale weights from the submitted setup", async () => {
    const e = await editor();
    e.click();
    await e.done();
    const form = walk(e.body()).find((x) => x.tag === "form");
    const mode = walk(form).find((x) => x.tag === "select");
    const inputs = walk(form).filter((x) => x.tag === "input" && x.id.startsWith("weight-"));
    mode.value = "weighted";
    mode.events.change();
    inputs[0].value = "100";
    inputs[0].events.input();
    mode.value = "points";
    mode.events.change();
    form.events.submit({ preventDefault() {} });
    await e.done();
    assert.deepEqual(JSON.parse(JSON.stringify(e.sent[0])), {
        mode: "points",
        weights: {},
        revision: "revision",
        categories: [],
    });
    assert.equal(inputs[0].disabled, true);
});

test("equal weights prepares an exact 100 percent total without saving automatically", async () => {
    const e = await editor();
    e.click();
    await e.done();
    const mode = walk(e.body()).find((x) => x.tag === "select");
    mode.value = "weighted";
    mode.events.change();
    const equal = walk(e.body()).find((x) => x.tag === "button" && x.textContent === "Equal weights");
    equal.attributes.onclick({ currentTarget: equal });
    await e.done();
    assert.deepEqual(
        walk(e.body())
            .filter((x) => x.tag === "input" && x.id.startsWith("weight-"))
            .map((x) => x.value),
        ["50.00", "50.00"],
    );
    assert.equal(e.sent.length, 0);
    assert.equal(walk(e.body()).find((x) => x.attributes.type === "submit").disabled, false);
});

test("category feedback explains partial coverage and renders names as text", () => {
    const p = presenter();
    const panel = p.panel({
        mode: "category",
        percent_hundredths: 6667,
        coverage_hundredths: 5400,
        pending: 1,
        excluded: 1,
        awaiting_categories: 1,
        categories: [
            {
                title: "Tasks <img>",
                coverage_hundredths: 1000,
                weight_hundredths: 4000,
                percent_hundredths: 5000,
                graded: 1,
                assignments: 2,
            },
            {
                title: "Final",
                weight_hundredths: 1000,
                percent_hundredths: null,
                coverage_hundredths: 0,
                graded: 0,
                assignments: 0,
            },
        ],
    });
    assert.match(panel.textContent, /Category weights · 54.00% graded/);
    assert.match(panel.textContent, /66.67%/);
    assert.match(panel.textContent, /Final/);
    assert.match(panel.textContent, /No scores · 10.00% of grade/);
    assert.match(panel.textContent, /No assignments/);
    assert.equal(
        walk(panel).some((e) => e.tag === "img"),
        false,
    );
    assert.equal(
        p.assignmentLabel({ category_title: "Tasks", category_weight_hundredths: 4000 }),
        "Tasks · 40.00% category",
    );
    assert.equal(p.assignmentLabel({ excluded: true, weight_hundredths: null }), "Excluded");
});

test("category assignment paging and search keep choices and bounded DOM nodes", () => {
    const assignments = Array.from({ length: 73 }, (_, i) => ({ id: `a${i}`, title: `Task ${i}` }));
    let changes = 0;
    const editor = presenter().editor(
        assignments,
        [
            { title: "Tasks", weight_hundredths: 4000, assignments: ["a0"] },
            { title: "Exam", weight_hundredths: 6000, assignments: [] },
        ],
        () => ++changes,
    );
    editor.setEnabled(true);
    assert.equal(walk(editor.element).filter((e) => e.tag === "select").length, 50);
    const search = walk(editor.element).find((e) => e.id === "gradebook-assignment-search");
    search.value = "Task 72";
    search.events.input();
    const selected = walk(editor.element).find((e) => e.tag === "select");
    assert.equal(walk(editor.element).filter((e) => e.tag === "select").length, 1);
    selected.value = "category-1";
    selected.events.change();
    search.value = "";
    search.events.input();
    const next = walk(editor.element).find((e) => e.tag === "button" && e.textContent === "Next");
    next.events.click();
    assert.equal(walk(editor.element).find((e) => e.tag === "select").focused, true);
    assert.equal(walk(editor.element).find((e) => e.id === "gradebook-category-a72").value, "category-1");
    assert.equal(walk(editor.element).filter((e) => e.tag === "select").length, 23);
    assert.deepEqual(JSON.parse(JSON.stringify(editor.serialize()[1].assignments)), ["a72"]);
    assert.equal(changes, 1);
    assert.equal(editor.valid(), true);
    editor.setEnabled(false);
    assert.equal(editor.element.hidden, true);
    assert.ok(
        walk(editor.element)
            .filter((e) => ["input", "button", "select"].includes(e.tag))
            .every((e) => e.disabled),
    );
    editor.setEnabled(true);
    assert.deepEqual(JSON.parse(JSON.stringify(editor.serialize()[1].assignments)), ["a72"]);
});

test("removing a category explicitly excludes its assignments and restores keyboard focus", () => {
    const editor = presenter().editor(
        [{ id: "one", title: "Quiz" }],
        [{ title: "Tasks", weight_hundredths: 10000, assignments: ["one"] }],
        () => {},
    );
    editor.setEnabled(true);
    walk(editor.element)
        .find((e) => e.getAttribute("aria-label") === "Remove category Tasks")
        .events.click();
    assert.deepEqual(JSON.parse(JSON.stringify(editor.serialize())), []);
    assert.equal(walk(editor.element).find((e) => e.tag === "select").value, "");
    assert.match(editor.element.textContent, /1 assignment is now excluded/);
    assert.equal(walk(editor.element).find((e) => e.textContent === "Add category").focused, true);
    assert.equal(editor.valid(), false);
});

test("the full setup form saves categories and assignment membership through the existing endpoint", async () => {
    const e = await editor();
    e.click();
    await e.done();
    const form = walk(e.body()).find((n) => n.tag === "form");
    const mode = walk(form).find((n) => n.id === "gradebook-mode");
    mode.value = "category";
    mode.events.change();
    const add = walk(form).find((n) => n.tag === "button" && n.textContent === "Add category");
    add.events.click();
    add.events.click();
    for (const [index, name, weight] of [
        [0, "Quizzes", "40"],
        [1, "Exams", "60"],
    ]) {
        const title = walk(form).find((n) => n.id === `category-${index}-name`);
        title.value = name;
        title.events.input();
        const input = walk(form).find((n) => n.id === `category-${index}-weight`);
        input.value = weight;
        input.events.input();
    }
    for (const [id, value] of [
        ["one", "category-0"],
        ["two", "category-1"],
    ]) {
        const input = walk(form).find((n) => n.id === `gradebook-category-${id}`);
        input.value = value;
        input.events.change();
        // Selecting another assignment does not replace the focused control.
        assert.equal(
            walk(form).find((n) => n.id === input.id),
            input,
        );
    }
    const save = walk(form).find((n) => n.attributes.type === "submit");
    assert.equal(save.disabled, false);
    form.events.submit({ preventDefault() {} });
    await e.done();
    assert.deepEqual(JSON.parse(JSON.stringify(e.sent[0])), {
        mode: "category",
        weights: {},
        revision: "revision",
        categories: [
            { title: "Quizzes", weight_hundredths: 4000, assignments: ["one"] },
            { title: "Exams", weight_hundredths: 6000, assignments: ["two"] },
        ],
    });
});

test("category names must be unique and deleting an earlier category preserves later membership", () => {
    const e = presenter().editor(
        [
            { id: "one", title: "Quiz" },
            { id: "two", title: "Exam" },
        ],
        [
            { title: "Tasks", weight_hundredths: 0, assignments: ["one"] },
            { title: "Exams", weight_hundredths: 10000, assignments: ["two"] },
        ],
        () => {},
    );
    e.setEnabled(true);
    const name = walk(e.element).find((n) => n.id === "category-1-name");
    name.value = "tasks";
    name.events.input();
    assert.equal(e.valid(), false);
    name.value = "Exams";
    name.events.input();
    walk(e.element)
        .find((n) => n.getAttribute("aria-label") === "Remove category Tasks")
        .events.click();
    assert.equal(e.valid(), true);
    assert.deepEqual(JSON.parse(JSON.stringify(e.serialize())), [
        { title: "Exams", weight_hundredths: 10000, assignments: ["two"] },
    ]);
    assert.equal(walk(e.element).find((n) => n.id === "gradebook-category-two").value, "category-1");
});
