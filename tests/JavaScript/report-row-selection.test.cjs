"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const fs = require("node:fs"),
    vm = require("node:vm");
const { node, walk } = require("./support/elements.cjs");
const window = {};
vm.runInNewContext(fs.readFileSync("public/assets/js/report-row-selection.js", "utf8"), { window });
function fixture(value) {
    const changes = [];
    const ui = window.createReportRowSelection({
        node,
        button: (label, callback) => node("button", { onclick: callback }, label),
        labeled: (label, input) => node("label", {}, label, input),
    });
    const card = ui.card({
        id: "registration",
        metadata: { label: "Registrations", date_fields: { period: "Period starts" } },
        value,
        open: true,
        onToggle: () => {},
        onChange: (value, control) => changes.push({ value, control }),
    });
    return { ui, card, changes };
}
test("latest selection defaults to all equal dates and a server-approved date", () => {
    const f = fixture();
    walk(f.card)
        .find((item) => item.tag === "button" && item.textContent === "Latest")
        .attributes.onclick();
    assert.deepEqual(JSON.parse(JSON.stringify(f.changes[0])), {
        value: {
            date_field: "period",
            direction: "latest",
            ties: "all",
        },
        control: "mode-latest",
    });
});
test("explicit tie choice and returning to all rows do not change the date definition", () => {
    const f = fixture({ date_field: "period", direction: "earliest", ties: "all" });
    const keep = walk(f.card).find((item) => item.tag === "input");
    keep.checked = false;
    keep.events.change();
    assert.equal(f.changes[0].value.ties, "one");
    assert.equal(f.changes[0].value.direction, "earliest");
    walk(f.card)
        .find((item) => item.tag === "button" && item.textContent === "All")
        .attributes.onclick();
    assert.equal(f.changes[1].value, null);
});
test("result explanations use text and disclose date gaps and tie handling", () => {
    const f = fixture();
    const summary = f.ui.describe([
        { record: "<img>Applications", date: "Submitted on", direction: "latest", ties: "one" },
    ]);
    assert.match(summary.textContent, /<img>Applications/);
    assert.match(summary.textContent, /Unknown dates are excluded/);
    assert.match(summary.textContent, /stable record reference/);
    assert.equal(
        walk(summary).some((item) => item.tag === "img"),
        false,
    );
    assert.equal(f.ui.describe(), null);
});
