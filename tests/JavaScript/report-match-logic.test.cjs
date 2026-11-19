"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const vm = require("node:vm"),
    fs = require("node:fs");
const window = {};
vm.runInNewContext(fs.readFileSync("public/assets/js/report-match-logic.js", "utf8"), { window });
const plain = (value) => JSON.parse(JSON.stringify(value));
function create() {
    const definition = {
        existence: {
            registration: {
                mode: "any",
                filters: { condition: "AND", rules: [{ id: "term", value: "26/FA" }] },
            },
            application: { mode: "any", filters: null },
        },
    };
    return { definition, model: window.createReportMatchLogic(definition) };
}
test("earlier matching cards retain their All meaning and source criteria", () => {
    const { definition, model } = create();
    assert.deepEqual(plain(definition.existence_logic), {
        condition: "AND",
        rules: [{ relation: "registration" }, { relation: "application" }],
    });
    model.normalize();
    assert.equal(definition.existence.registration.filters.rules[0].value, "26/FA");
});
test("moving a condition into a nested Any group keeps exactly one copy and every criterion", () => {
    const { definition, model } = create();
    assert.equal(model.addGroup([]), true);
    model.move("application", [2]);
    // The target group is resolved before the earlier source card is removed.
    assert.deepEqual(plain(definition.existence_logic), {
        condition: "AND",
        rules: [{ relation: "registration" }, { condition: "OR", rules: [{ relation: "application" }] }],
    });
    model.move("registration", [1]);
    assert.equal(definition.existence_logic.rules.length, 1);
    assert.equal(definition.existence_logic.rules[0].rules.length, 2);
    assert.equal(definition.existence.registration.filters.rules[0].value, "26/FA");
});
test("keyboard reordering and ungrouping preserve both source records", () => {
    const { definition, model } = create();
    model.shift("registration", 1);
    assert.equal(definition.existence_logic.rules[0].relation, "application");
    model.addGroup([]);
    model.move("registration", [2]);
    model.ungroup([1]);
    assert.deepEqual(plain(definition.existence_logic.rules), [
        { relation: "application" },
        { relation: "registration" },
    ]);
});
test("removing a named branch prunes its logic and newly added cards join the root", () => {
    const { definition, model } = create();
    delete definition.existence.registration;
    definition.existence.credit = { mode: "none", filters: null };
    model.normalize();
    assert.deepEqual(plain(definition.existence_logic.rules), [
        { relation: "application" },
        { relation: "credit" },
    ]);
});
test("card drops reorder within a group without duplicating their condition", () => {
    const { definition, model } = create();
    model.move("application", [], "registration");
    model.move("application", [], "application");
    assert.deepEqual(plain(definition.existence_logic.rules), [
        { relation: "application" },
        { relation: "registration" },
    ]);
});
test("depth and total group limits remain bounded while editing", () => {
    const { model } = create();
    model.addGroup([]);
    model.addGroup([2]);
    model.addGroup([2, 0]);
    assert.equal(model.addGroup([2, 0, 0]), false);
    while (model.groups().length < 7) assert.equal(model.addGroup([]), true);
    assert.equal(model.addGroup([]), false);
});

test("empty groups need an explicit choice before preview or save", () => {
    const { model } = create();
    model.assertReady();
    model.addGroup([]);
    assert.throws(() => model.assertReady(), /empty groups/);
    model.move("application", [2]);
    assert.doesNotThrow(() => model.assertReady());
});
