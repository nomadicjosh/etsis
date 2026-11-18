/* Shared, text-only presentation of working gradebook feedback. */
window.createGradebookCalculation = function (ui) {
    "use strict";
    const { node } = ui;
    function panel(summary) {
        const percent = (value) => `${(Number(value) / 100).toFixed(2)}%`;
        return node(
            "section",
            { class: "alert alert-light border", "aria-label": "Gradebook average" },
            node("h4", { class: "h6" }, "Gradebook average"),
            node(
                "p",
                { class: "mb-1 fw-semibold" },
                summary.percent_hundredths == null
                    ? "No included scores yet"
                    : percent(summary.percent_hundredths),
            ),
            node(
                "p",
                { class: "mb-1" },
                `${summary.mode === "weighted" ? "Assignment weights" : "Total points"} · ${percent(summary.coverage_hundredths)} graded · ${summary.pending} awaiting scores${summary.excluded ? ` · ${summary.excluded} excluded` : ""}`,
            ),
            node(
                "p",
                { class: "small mb-0" },
                "Ungraded work is excluded from this running average. Recorded zero scores count. This is coursework feedback; your official final grade is recorded separately.",
            ),
        );
    }
    return { panel };
};
