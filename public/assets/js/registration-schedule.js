/* Shared, text-only timetable assessment for staff and student registration. No persistence or mutations. */
window.createRegistrationSchedule = function (ui) {
    "use strict";
    const { node } = ui;
    const text = (value) => {
        if (!value?.status || value.status === "not_recorded")
            return "Timetable check not recorded";
        if (value.status === "not_rechecked")
            return "Existing enrollment — unchanged";
        if (value.conflict_count)
            return value.blocked
                ? "Meetings overlap — registration blocked"
                : "Meetings overlap — warning";
        if (value.unpublished_count)
            return "Unpublished schedules — not fully checked";
        return "No overlaps in published meetings";
    };
    const panel = (value) =>
        node(
            "section",
            {
                class: `alert ${value?.blocked ? "alert-danger" : value?.conflict_count || value?.unpublished_count ? "alert-warning" : "alert-info"}`,
                role: "status",
                "aria-live": "polite",
                "aria-label": "Registration timetable check",
            },
            node("h3", { class: "h6" }, text(value)),
            value?.unpublished_count
                ? node(
                      "p",
                      {},
                      `${value.unpublished_count} section ${value.unpublished_count === 1 ? "schedule is" : "schedules are"} unpublished. These meetings could not be checked.`,
                  )
                : null,
            value?.conflicts?.length
                ? node(
                      "ul",
                      {},
                      value.conflicts.map((item) =>
                          node(
                              "li",
                              {},
                              `${item.section_code}: ${item.starts_at.replace("T", " ").replace("Z", " UTC")} through ${item.ends_at.replace("T", " ").replace("Z", " UTC")}`,
                          ),
                      ),
                  )
                : null,
            node(
                "p",
                { class: "small mb-0" },
                "The check uses published meeting plans, including online meetings. Registration checks the timetable again when you confirm.",
            ),
        );
    return { text, panel };
};
