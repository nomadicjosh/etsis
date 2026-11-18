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
    vm.runInNewContext(fs.readFileSync("public/assets/js/workspace-teaching.js", "utf8"), { window });
    let body, pending, release;
    const sent = [];
    const setup = {
        mode: "points",
        weights: {},
        revision: "revision",
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
    const inputs = walk(body).filter((x) => x.tag === "input"),
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
    const inputs = walk(form).filter((x) => x.tag === "input");
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
            .filter((x) => x.tag === "input")
            .map((x) => x.value),
        ["50.00", "50.00"],
    );
    assert.equal(e.sent.length, 0);
    assert.equal(walk(e.body()).find((x) => x.attributes.type === "submit").disabled, false);
});
