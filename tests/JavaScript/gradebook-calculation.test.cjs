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
async function editor(bonus = false) {
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
            ...(bonus ? [{ id: "extra", title: "Reflection", possible_hundredths: 0 }] : []),
        ],
    };
    const teaching = window.createWorkspaceTeaching({
        can: () => false,
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
        p.assignmentLabel({
            category_title: "Tasks",
            category_weight_hundredths: 4000,
        }),
        "Tasks · 40.00% category",
    );
    assert.equal(p.assignmentLabel({ excluded: true, weight_hundredths: null }), "Excluded");
});

test("category assignment paging and search keep choices and bounded DOM nodes", () => {
    const assignments = Array.from({ length: 73 }, (_, i) => ({
        id: `a${i}`,
        title: `Task ${i}`,
    }));
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
    const drop = walk(form).find((n) => n.id === "category-0-drop");
    drop.value = "1";
    drop.events.input();
    assert.equal(save.disabled, false);
    form.events.submit({ preventDefault() {} });
    await e.done();
    assert.deepEqual(JSON.parse(JSON.stringify(e.sent[0])), {
        mode: "category",
        weights: {},
        revision: "revision",
        categories: [
            {
                title: "Quizzes",
                weight_hundredths: 4000,
                assignments: ["one"],
                drop_lowest: 1,
            },
            {
                title: "Exams",
                weight_hundredths: 6000,
                assignments: ["two"],
                drop_lowest: 0,
            },
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
        {
            title: "Exams",
            weight_hundredths: 10000,
            assignments: ["two"],
            drop_lowest: 0,
        },
    ]);
    assert.equal(walk(e.element).find((n) => n.id === "gradebook-category-two").value, "category-1");
});

test("drop count is optional by default, labeled and retained through mode changes", () => {
    let changes = 0;
    const e = presenter().editor(
        [{ id: "one", title: "Quiz" }],
        [{ title: "Tasks", weight_hundredths: 10000, assignments: ["one"] }],
        () => {
            changes++;
        },
    );
    e.setEnabled(true);
    const drop = walk(e.element).find((n) => n.id === "category-0-drop");
    assert.equal(drop.value, "0");
    assert.equal(drop.attributes.required, true);
    assert.match(drop.getAttribute("aria-describedby"), /gradebook-drop-help category-0-drop-error/);
    assert.ok(walk(e.element).some((n) => n.tag === "label" && n.getAttribute("for") === drop.id));
    drop.value = "2";
    drop.events.input();
    assert.equal(changes, 1);
    assert.equal(e.serialize()[0].drop_lowest, 2);
    e.setEnabled(false);
    e.setEnabled(true);
    assert.equal(e.serialize()[0].drop_lowest, 2);
    assert.equal(e.valid(), true);
});
test("invalid drop counts block setup before a request is sent", () => {
    const e = presenter().editor(
        [],
        [
            {
                title: "Tasks",
                weight_hundredths: 10000,
                assignments: [],
                drop_lowest: 1,
            },
        ],
        () => {},
    );
    e.setEnabled(true);
    const input = walk(e.element).find((n) => n.id === "category-0-drop");
    for (const value of ["", "-1", "500", "1.5", "invalid"]) {
        input.value = value;
        assert.equal(e.valid(), false, value);
        assert.equal(input.getAttribute("aria-invalid"), "true");
        assert.equal(walk(e.element).find((n) => n.id === "category-0-drop-error").hidden, false);
    }
    input.value = "499";
    assert.equal(e.valid(), true);
    assert.equal(input.getAttribute("aria-invalid"), "false");
    assert.equal(walk(e.element).find((n) => n.id === "category-0-drop-error").hidden, true);
});
test("shared feedback explains dropped scores without interpreting their titles as markup", () => {
    const p = presenter();
    const body = p.panel({
        mode: "category",
        percent_hundredths: 9000,
        coverage_hundredths: 10000,
        graded: 1,
        pending: 0,
        excluded: 0,
        dropped: 1,
        categories: [
            {
                title: "Tasks",
                weight_hundredths: 10000,
                percent_hundredths: 9000,
                coverage_hundredths: 10000,
                graded: 2,
                assignments: 2,
                drop_lowest: 1,
                dropped: 1,
            },
        ],
        dropped_assignments: [
            {
                assignment_id: "one",
                title: "Quiz <img>",
                earned_hundredths: 0,
                possible_hundredths: 1000,
            },
        ],
    });
    assert.match(body.textContent, /1 dropped from average/);
    assert.match(body.textContent, /Drop up to 1 lowest graded score/);
    assert.match(body.textContent, /Quiz <img> · 0\/10 points/);
    assert.match(body.textContent, /Ungraded work is never dropped/);
    assert.ok(walk(body).some((n) => n.tag === "details"));
    assert.ok(walk(body).some((n) => n.tag === "summary" && /View 1 dropped score/.test(n.textContent)));
    assert.equal(
        walk(body).some((n) => n.tag === "img"),
        false,
    );
    assert.match(
        p.assignmentLabel({
            category_title: "Tasks",
            category_weight_hundredths: 10000,
            category_drop_lowest: 1,
            dropped: true,
        }),
        /^Dropped from average/,
    );
    assert.match(body.textContent, /official final grade is recorded separately/);
});

test("bonus feedback keeps over-100 averages visible and explains recorded bonus points", () => {
    const p = presenter();
    const panel = p.panel({
        mode: "weighted",
        percent_hundredths: 11500,
        coverage_hundredths: 10000,
        graded: 1,
        pending: 0,
        excluded: 0,
        bonus_enabled: true,
        bonus_earned_hundredths: 1500,
    });
    assert.match(panel.textContent, /115.00%/);
    assert.match(panel.textContent, /15 bonus points in counted work/);
    assert.match(panel.textContent, /running average can exceed 100%/);
    assert.match(panel.textContent, /Official grades are recorded separately/);
    const label = p.assignmentLabel({
        possible_hundredths: 10000,
        earned_hundredths: 11500,
        bonus_hundredths: 2000,
    });
    assert.match(label, /Bonus limit \+20 points/);
    assert.match(label, /15 bonus points recorded/);
});

test("shared feedback distinguishes excused work from pending work and retains saved marks as text", () => {
    const p = presenter();
    const panel = p.panel({
        mode: "category",
        percent_hundredths: 8000,
        coverage_hundredths: 10000,
        graded: 1,
        pending: 0,
        excluded: 0,
        excused: 2,
        awaiting_categories: 0,
        categories: [
            {
                title: "Excused tasks",
                weight_hundredths: 6000,
                percent_hundredths: null,
                coverage_hundredths: 0,
                assignments: 2,
                graded: 0,
                excused: 2,
                fully_excused: true,
            },
            {
                title: "Remaining",
                weight_hundredths: 4000,
                percent_hundredths: 8000,
                coverage_hundredths: 10000,
                assignments: 1,
                graded: 1,
                excused: 0,
                fully_excused: false,
            },
        ],
        excused_assignments: [
            { title: "Quiz <img>", earned_hundredths: 0, possible_hundredths: 1000 },
            {
                title: "Other task",
                earned_hundredths: null,
                possible_hundredths: 1000,
            },
        ],
    });
    assert.match(panel.textContent, /0 awaiting scores · 2 excused/);
    assert.match(panel.textContent, /Excused for this student/);
    assert.match(panel.textContent, /Saved mark 0 \/ 10 points/);
    assert.match(panel.textContent, /No mark recorded/);
    assert.match(panel.textContent, /remaining weights are normalized/);
    assert.ok(walk(panel).some((n) => n.tag === "summary" && n.textContent === "View 2 excused assignments"));
    assert.equal(
        walk(panel).some((n) => n.tag === "img"),
        false,
    );
    assert.match(
        p.assignmentLabel({
            excused: true,
            possible_hundredths: 1000,
            earned_hundredths: 0,
        }),
        /^Excused · By points/,
    );
});

test("bonus weights stay separate from the required 100 percent and equal weights preserve them", async () => {
    const e = await editor(true);
    e.click();
    await e.done();
    const body = e.body();
    const mode = walk(body).find((x) => x.id === "gradebook-mode");
    mode.value = "weighted";
    mode.events.change();
    const inputs = walk(body).filter((x) => x.tag === "input" && x.id.startsWith("weight-"));
    const submit = walk(body).find((x) => x.attributes.type === "submit");
    inputs[0].value = "40";
    inputs[1].value = "60";
    inputs[2].value = "10";
    inputs[2].events.input();
    assert.equal(submit.disabled, false);
    assert.match(body.textContent, /100.00% of 100% regular weights/);
    assert.match(body.textContent, /10.00 percentage points, separately/);
    assert.match(body.textContent, /Reflection · Bonus percentage points/);
    walk(body)
        .find((x) => x.tag === "button" && x.textContent === "Equal weights")
        .attributes.onclick({ currentTarget: node("button") });
    await e.done();
    assert.equal(inputs[0].value, "50.00");
    assert.equal(inputs[1].value, "50.00");
    assert.equal(inputs[2].value, "10");
    const form = walk(body).find((x) => x.tag === "form");
    form.events.submit({ preventDefault() {} });
    await e.done();
    assert.deepEqual(JSON.parse(JSON.stringify(e.sent[0].weights)), {
        one: 5000,
        two: 5000,
        extra: 1000,
    });
    inputs[2].value = "100.01";
    inputs[2].events.input();
    assert.equal(submit.disabled, true);
});
test("shared bonus-only feedback explains optional work and points that cannot yet apply", () => {
    const p = presenter();
    const score = {
        possible_hundredths: 0,
        bonus_hundredths: 1000,
        earned_hundredths: 500,
        weight_hundredths: 1000,
    };
    assert.equal(p.scoreLabel(score), "5 bonus points");
    assert.equal(p.scoreLabel({ ...score, earned_hundredths: null }), "Not graded");
    assert.match(p.assignmentLabel(score), /Bonus only · Up to 10 bonus percentage points/);
    const panel = p.panel({
        mode: "points",
        percent_hundredths: null,
        coverage_hundredths: 0,
        pending: 1,
        bonus_only: 2,
        bonus_pending: 1,
        bonus_enabled: true,
        bonus_earned_hundredths: 0,
        unapplied_bonus_hundredths: 500,
    });
    assert.match(panel.textContent, /No graded regular work yet/);
    assert.match(panel.textContent, /1 optional scores not entered/);
    assert.match(panel.textContent, /5 recorded bonus points are not applied yet/);
    assert.doesNotMatch(panel.textContent, /Infinity|NaN/);
});
