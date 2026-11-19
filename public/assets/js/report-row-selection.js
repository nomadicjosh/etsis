/* Optional selection of whole related rows; all identifiers/dates are approved by the server. */
window.createReportRowSelection = function (ui) {
    "use strict";
    const { node, button, labeled } = ui;
    function describe(items = []) {
        if (!items.length) return null;
        return node(
            "aside",
            { class: "report-row-selection-summary", "aria-label": "Related row selection" },
            node("h3", {}, "Selected records"),
            node(
                "ul",
                {},
                items.map((item) =>
                    node(
                        "li",
                        {},
                        `${item.record}: ${item.direction === "latest" ? "latest" : "earliest"} by ${item.date}. ${item.ties === "all" ? "Equal dates are kept." : "One row is chosen; equal dates use a stable record reference."}`,
                    ),
                ),
            ),
            node(
                "p",
                { class: "form-text" },
                "Unknown dates are excluded from this choice. Join filters narrow candidates before selection; ordinary row filters apply afterwards. Matching-record cards remain independent.",
            ),
        );
    }
    function card({ id, metadata, value, open, onToggle, onChange }) {
        const dates = metadata.date_fields || {};
        if (!Object.keys(dates).length) return null;
        const mode = value?.direction || "all";
        const details = node(
            "details",
            { class: "report-row-selection", open, "data-record-selection": id },
            node("summary", {}, `Rows: ${{ all: "All", latest: "Latest", earliest: "Earliest" }[mode]}`),
        );
        details.addEventListener("toggle", () => {
            if (details.isConnected) onToggle(details.open);
        });
        const controls = node("div", {
            class: "report-row-selection-modes",
            role: "group",
            "aria-label": `${metadata.label} row selection`,
        });
        for (const [key, label] of [
            ["all", "All"],
            ["latest", "Latest"],
            ["earliest", "Earliest"],
        ]) {
            const control = button(
                label,
                () =>
                    onChange(
                        key === "all"
                            ? null
                            : {
                                  date_field: value?.date_field || Object.keys(dates)[0],
                                  direction: key,
                                  ties: value?.ties || "all",
                              },
                        `mode-${key}`,
                    ),
                key === mode ? "btn-sm btn-primary" : "btn-sm btn-outline-primary",
            );
            control.setAttribute("aria-pressed", String(key === mode));
            control.dataset.rowControl = `mode-${key}`;
            controls.append(control);
        }
        details.append(controls);
        if (mode !== "all") {
            const date = node("select", {
                class: "form-select form-select-sm",
                "aria-label": `${metadata.label}: date`,
                "data-row-control": "date",
            });
            for (const [field, label] of Object.entries(dates))
                date.append(node("option", { value: field, selected: field === value.date_field }, label));
            date.addEventListener("change", () => onChange({ ...value, date_field: date.value }, "date"));
            const keep = node("input", {
                type: "checkbox",
                class: "form-check-input",
                checked: value.ties === "all",
                "data-row-control": "ties",
            });
            keep.addEventListener("change", () =>
                onChange({ ...value, ties: keep.checked ? "all" : "one" }, "ties"),
            );
            details.append(
                labeled("Date", date),
                node("label", { class: "report-check" }, keep, "Keep equal dates"),
                node(
                    "p",
                    { class: "form-text" },
                    value.ties === "all"
                        ? "All records with the selected date remain in the report."
                        : "Choose one row when dates are equal. The record reference breaks the tie; it does not establish which happened first.",
                ),
                node(
                    "p",
                    { class: "form-text" },
                    "Unknown dates are excluded. Related-record matching cards keep their own scope.",
                ),
            );
        }
        return details;
    }
    return { card, describe };
};
