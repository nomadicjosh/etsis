"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const { walk } = require("./support/elements.cjs");
const { fixture, student, term } = require("./support/teaching-worksheet.cjs");
const pupil = (id, status = null) => ({ ...student(id), status, revision: status ? `original-${id}` : null });
async function register(items = [pupil("one"), pupil("two", "late")], next = null) {
    const f = await fixture(items, next, true);
    f.change("worksheet-date", "2026-08-11");
    await f.submit(0);
    return f;
}
test("attendance starts with an explicitly chosen labeled date and no invented marks", async () => {
    const f = await fixture([pupil("one")], null, true);
    assert.equal(f.calls.length, 1);
    assert.deepEqual(f.calls[0].data, { held_on: null, search: "", state: "all", after: "" });
    assert.equal(f.get("worksheet-date").min, term.starts_on);
    assert.equal(f.get("worksheet-date").max, term.ends_on);
    assert.match(f.body().textContent, /Choose a class date/);
    assert.match(f.body().textContent, /not a reconstructed past roster/);
    assert.equal(f.get("worksheet-one-mark"), undefined);
    assert.equal(f.writes.length, 0);
    assert.equal(f.module.canLeave(), true);
});
test("one register shows unmarked, present, absent, late and excused choices with native labels", async () => {
    const f = await register();
    assert.equal(f.get("worksheet-one-mark").value, "");
    assert.equal(f.get("worksheet-two-mark").value, "late");
    const choices = f.get("worksheet-one-mark").children.map((e) => e.attributes.value);
    assert.deepEqual(choices, ["", "present", "absent", "late", "excused"]);
    assert.match(f.body().textContent, /Attendance · 2026-08-11/);
    assert.ok(walk(f.body()).some((e) => e.tag === "label" && e.attributes.for === "worksheet-one-mark"));
    assert.equal(f.get("worksheet-two-mark").children[0].disabled, true);
});
test("fill touches only displayed unmarked students, preserves edits and requires an explicit save", async () => {
    const f = await register([pupil("one"), pupil("two", "late"), pupil("three")], "another-page");
    f.change("worksheet-three-mark", "absent");
    await f.click("Fill unmarked as present");
    assert.equal(f.get("worksheet-one-mark").value, "present");
    assert.equal(f.get("worksheet-two-mark").value, "late");
    assert.equal(f.get("worksheet-three-mark").value, "absent");
    assert.equal(f.writes.length, 0);
    assert.equal(f.calls.length, 2);
    assert.equal(f.module.canLeave(), false);
    await f.submit();
    assert.deepEqual(f.writes, [
        {
            student_id: "one",
            enrollment_id: "enrollment-one",
            status: "present",
            held_on: "2026-08-11",
            revision: null,
        },
        {
            student_id: "three",
            enrollment_id: "enrollment-three",
            status: "absent",
            held_on: "2026-08-11",
            revision: null,
        },
    ]);
    assert.equal(f.module.canLeave(), true);
    assert.equal(f.control("Save changes").disabled, true);
});
test("a second correction sends the returned server revision and never clears a saved mark", async () => {
    const f = await register();
    f.change("worksheet-one-mark", "present");
    await f.submit();
    f.change("worksheet-one-mark", "excused");
    await f.submit();
    assert.equal(f.writes[1].revision, "saved-1");
    assert.equal(f.writes[1].status, "excused");
    f.change("worksheet-one-mark", "");
    await f.submit();
    assert.equal(f.writes.length, 2);
    assert.ok(f.get("worksheet-one-mark").validation);
});
test("date changes require loading and do not move edits to a different class date", async () => {
    const f = await register();
    f.change("worksheet-date", "2026-08-12");
    assert.equal(f.get("worksheet-one-mark").disabled, true);
    assert.equal(f.control("Fill unmarked as present").disabled, true);
    assert.equal(f.control("Save changes").disabled, true);
    f.queue({ term, held_on: "2026-08-12", items: [pupil("one")], next_after: null });
    await f.submit(0);
    assert.equal(f.get("worksheet-one-mark").disabled, false);
    f.change("worksheet-one-mark", "late");
    assert.equal(f.get("worksheet-date").disabled, true);
    await f.submit();
    assert.equal(f.writes[0].held_on, "2026-08-12");
});
test("paging retains the applied date and status filter and guards unsaved attendance", async () => {
    const f = await register([pupil("one")], "next-page");
    f.get("worksheet-state").value = "unmarked";
    await f.submit(0);
    f.queue({ term, held_on: "2026-08-11", items: [pupil("two")], next_after: null });
    await f.click("Next page");
    assert.deepEqual(f.calls.at(-1).data, {
        held_on: "2026-08-11",
        search: "",
        state: "unmarked",
        after: "next-page",
    });
    f.change("worksheet-two-mark", "absent");
    const requests = f.calls.length;
    await f.click("Previous page");
    assert.equal(f.calls.length, requests);
    await f.click("Discard changes");
    assert.equal(f.get("worksheet-two-mark").value, "");
});
test("a conflicting correction stops the page save without sending later students", async () => {
    const f = await register([pupil("one"), pupil("two"), pupil("three")]);
    await f.click("Fill unmarked as present");
    f.fail(2, 409);
    await f.submit();
    assert.equal(f.writes.length, 2);
    assert.equal(f.get("worksheet-one-status").textContent, "Saved");
    assert.equal(f.get("worksheet-three-mark").value, "present");
    assert.match(f.body().textContent, /1 of 3 changes saved/);
    assert.equal(f.control("Save changes").disabled, true);
});
test("a late register response does not populate a closed private worksheet", async () => {
    const f = await register();
    f.delayRead();
    f.change("worksheet-date", "2026-08-12");
    const loading = f.submit(0);
    await new Promise(setImmediate);
    const before = f.body().textContent;
    f.close();
    f.release();
    await loading;
    assert.equal(f.body().textContent, before);
    assert.equal(f.module.canLeave(), true);
});
