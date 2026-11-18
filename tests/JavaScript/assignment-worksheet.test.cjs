"use strict";
const test = require("node:test"),
    assert = require("node:assert/strict");
const { walk } = require("./support/elements.cjs");
const { fixture, student, assignment } = require("./support/teaching-worksheet.cjs");
test("worksheet shows labeled marks, bonus limit and recorded excusal without sending scores", async () => {
    const f = await fixture([student("one", 1150, true), student("two", 0), student("three")]);
    assert.equal(f.get("worksheet-one-mark").value, "11.5");
    assert.equal(f.get("worksheet-one-mark").attributes.max, "12");
    assert.equal(f.get("worksheet-one-excused").checked, true);
    assert.equal(f.get("worksheet-two-mark").value, "0");
    assert.equal(f.get("worksheet-three-mark").value, "");
    assert.match(f.body().textContent, /Maximum earned: 12 points/);
    assert.match(f.body().textContent, /Fictional quiz <script>/);
    assert.equal(
        walk(f.body()).some((e) => e.tag === "script"),
        false,
    );
    assert.equal(f.writes.length, 0);
    assert.equal(f.control("Save changes").disabled, true);
    assert.equal(f.module.canLeave(), true);
    for (const id of ["worksheet-one-mark", "worksheet-one-excused"])
        assert.ok(walk(f.body()).some((e) => e.tag === "label" && e.attributes.for === id));
});
test("only changed rows save, preserving zero, explicit clear, excusal and both revisions", async () => {
    const f = await fixture([student("one", 800), student("two"), student("three", 700, true)]);
    f.change("worksheet-one-mark", "");
    f.change("worksheet-two-mark", "0");
    await f.submit();
    assert.deepEqual(f.writes, [
        {
            student_id: "one",
            enrollment_id: "enrollment-one",
            earned_hundredths: null,
            version: 2,
            excused: false,
            assignment_revision: "assignment-revision",
        },
        {
            student_id: "two",
            enrollment_id: "enrollment-two",
            earned_hundredths: 0,
            version: 0,
            excused: false,
            assignment_revision: "assignment-revision",
        },
    ]);
    assert.equal(f.module.canLeave(), true);
    assert.equal(f.control("Save changes").disabled, true);
    assert.match(f.body().textContent, /2 changes saved/);
    f.change("worksheet-three-excused", false, true);
    await f.submit();
    assert.equal(f.writes[2].earned_hundredths, 700);
    assert.equal(f.writes[2].excused, false);
    assert.equal(f.writes[2].version, 2);
    f.change("worksheet-two-mark", "1.25");
    await f.submit();
    assert.equal(f.writes[3].version, 1);
    assert.equal(f.writes[3].earned_hundredths, 125);
});
test("invalid fractional or over-limit marks stop all changes before sending", async () => {
    const f = await fixture();
    f.change("worksheet-one-mark", "9");
    for (const invalid of ["12.01", "-1", "1.111", "1e1"]) {
        f.change("worksheet-two-mark", invalid);
        await f.submit();
        assert.equal(f.writes.length, 0);
        assert.ok(f.get("worksheet-two-mark").validation);
        assert.equal(f.get("worksheet-two-mark").attributes["aria-invalid"], "true");
    }
});
test("unsaved navigation is guarded, and discard restores both the mark and excusal", async () => {
    const f = await fixture();
    f.change("worksheet-one-mark", "11");
    f.change("worksheet-one-excused", true, true);
    assert.equal(f.module.canLeave(), false);
    await f.click("Back to section");
    assert.equal(f.backs(), 0);
    await f.click("Discard changes");
    assert.equal(f.get("worksheet-one-mark").value, "8");
    assert.equal(f.get("worksheet-one-excused").checked, false);
    assert.equal(f.module.canLeave(), true);
    await f.click("Back to section");
    assert.equal(f.backs(), 1);
});
for (const status of [409, 403, undefined]) {
    test(`a ${status || "transport"} failure stops later rows and requires refresh without resending saved work`, async () => {
        const f = await fixture([student("one"), student("two"), student("three")]);
        for (const id of ["one", "two", "three"]) f.change(`worksheet-${id}-mark`, "10");
        f.fail(2, status);
        await f.submit();
        assert.equal(f.writes.length, 2);
        assert.equal(f.get("worksheet-one-status").textContent, "Saved");
        assert.match(f.body().textContent, /1 of 3 changes saved/);
        assert.equal(f.control("Save changes").disabled, true);
        assert.equal(f.get("worksheet-three-mark").value, "10");
        await f.click("Discard changes");
        assert.equal(f.get("worksheet-one-mark").value, "10");
        assert.equal(f.get("worksheet-two-mark").value, "");
        assert.equal(f.get("worksheet-three-mark").value, "");
        await f.click("Refresh");
        assert.equal(f.writes.length, 2);
    });
}
test("a rejected mark remains editable and successful rows are omitted from a retry", async () => {
    const f = await fixture([student("one"), student("two")]);
    f.change("worksheet-one-mark", "10");
    f.change("worksheet-two-mark", "10");
    f.fail(2, 422);
    await f.submit();
    assert.match(f.body().textContent, /1 of 2 changes saved/);
    assert.equal(f.control("Save changes").disabled, false);
    f.change("worksheet-two-mark", "9");
    await f.submit();
    assert.deepEqual(
        f.writes.map((w) => w.student_id),
        ["one", "two", "two"],
    );
});
test("saving has real progress and locks controls until each response arrives", async () => {
    const f = await fixture();
    f.change("worksheet-one-mark", "9");
    f.delay();
    const saving = f.submit();
    await new Promise(setImmediate);
    assert.equal(f.module.canLeave(), false);
    assert.equal(f.get("worksheet-one-mark").disabled, true);
    const progress = walk(f.body()).find((e) => e.tag === "progress");
    assert.equal(progress.hidden, false);
    assert.equal(progress.value, 0);
    assert.equal(progress.max, 1);
    assert.equal(f.control("Discard changes").disabled, true);
    f.release();
    await saving;
    assert.equal(progress.value, 1);
    assert.equal(f.get("worksheet-one-mark").disabled, false);
    assert.equal(f.module.canLeave(), true);
});
test("paging uses committed filters and a failed or guarded move does not advance the cursor", async () => {
    const f = await fixture([student("one")], "page-2");
    f.get("worksheet-search").value = "Not yet applied";
    f.queue({ assignment, items: [student("two")], next_after: null });
    await f.click("Next page");
    assert.deepEqual(f.calls[1].data, { search: "", state: "all", after: "page-2" });
    assert.equal(f.get("worksheet-search").value, "");
    f.change("worksheet-two-mark", "9");
    await f.click("Previous page");
    assert.equal(f.calls.length, 2);
    await f.click("Discard changes");
    await f.click("Previous page");
    assert.equal(f.calls[2].data.after, "");
    f.get("worksheet-search").value = " Alex Example ";
    f.get("worksheet-state").value = "pending";
    await f.submit(0);
    assert.deepEqual(f.calls[3].data, { search: "Alex Example", state: "pending", after: "" });
});
test("closing a worksheet during a save stops further score requests", async () => {
    const f = await fixture();
    f.change("worksheet-one-mark", "9");
    f.change("worksheet-two-mark", "10");
    f.delay();
    const saving = f.submit();
    await new Promise(setImmediate);
    f.close();
    f.release();
    await saving;
    assert.equal(f.writes.length, 1);
    assert.equal(f.module.canLeave(), true);
});
