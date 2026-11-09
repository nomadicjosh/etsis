/* Bounded student reports. Definitions are immutable; result pages reflect live records. */
window.createWorkspaceReports = function (ui) {
    "use strict";
    const {
        node,
        request,
        requestFile,
        task,
        can,
        table,
        openDrawer,
        openRecord,
        flow,
        enhance,
        closeDrawer,
    } = ui;
    const button = (
        label,
        callback,
        style = "btn-outline-primary",
        settled = () => {},
    ) =>
        node(
            "button",
            {
                type: "button",
                class: `btn ${style}`,
                onclick: (event) =>
                    task(callback, event.currentTarget).finally(settled),
            },
            label,
        );
    async function create() {
        return flow(
            "Create saved report",
            [
                ["name", "Report name"],
                [
                    "program_id",
                    "Primary program (optional)",
                    "lookup",
                    "programs",
                    false,
                ],
                [
                    "term_id",
                    "Institutional entry term (optional)",
                    "lookup",
                    "terms",
                    false,
                ],
                [
                    "activity",
                    "Student activity",
                    "choice",
                    ["All students", "Active only", "Inactive only"],
                ],
            ],
            "/sis/reports/",
            ({ activity, ...data }) => ({
                ...data,
                active:
                    activity === "All students"
                        ? null
                        : activity === "Active only",
            }),
            closeDrawer,
            {},
            "Select the student's current primary program and original institutional entry term. A report's selection stays fixed; create another report for a different selection. Sharing explicitly permits the recipient to see these limited student results.",
        );
    }
    async function record(id) {
        const report = await request(`/sis/reports/${id}/`);
        if (report.relational) return ui.builder().record(id);
        const body = openDrawer(report.name, "STUDENT REPORT");
        body.append(
            node(
                "dl",
                { class: "detail-list" },
                [
                    [
                        "Primary program",
                        report.program_code
                            ? `${report.program_code} · ${report.program_title}`
                            : "All programs",
                    ],
                    [
                        "Institutional entry term",
                        report.entry_term_code || "All entry terms",
                    ],
                    [
                        "Student activity",
                        report.active === null
                            ? "All students"
                            : report.active
                              ? "Active only"
                              : "Inactive only",
                    ],
                ].map(([label, value]) =>
                    node(
                        "div",
                        {},
                        node("dt", {}, label),
                        node("dd", {}, value),
                    ),
                ),
            ),
        );
        if (report.can_run) {
            body.append(
                node(
                    "p",
                    { class: "form-text" },
                    "Live results, 50 students per page. A CSV download re-runs the selected page; student records and membership may change.",
                ),
            );
            const status = node("p", { role: "status", "aria-live": "polite" });
            const result = node("div");
            const navigation = node("div", { class: "inline-actions" });
            let after = "",
                nextAfter = null,
                cursors = [],
                loaded = false,
                loading = false;
            const refresh = button(
                "Run report",
                () => load("", []),
                "btn-primary",
                controls,
            );
            const previous = button(
                "Previous results page",
                () => load(cursors.at(-1) || "", cursors.slice(0, -1)),
                undefined,
                controls,
            );
            const next = button(
                "Next results page",
                () => load(nextAfter, [...cursors, after]),
                undefined,
                controls,
            );
            const download = button(
                "Download this results page (CSV)",
                async () => {
                    try {
                        await requestFile(
                            `/sis/reports/${id}/export/`,
                            { after },
                            "student-report-page.csv",
                        );
                    } catch (error) {
                        if (error.status === 403) forget();
                        throw error;
                    }
                },
                undefined,
                controls,
            );
            function controls() {
                refresh.disabled = loading;
                previous.disabled = loading || !loaded || !cursors.length;
                next.disabled = loading || !loaded || !nextAfter;
                download.disabled = loading || !loaded;
            }
            function forget() {
                result.replaceChildren();
                loaded = false;
                nextAfter = null;
                cursors = [];
                status.textContent =
                    "Results are unavailable. Access may have changed.";
                controls();
            }
            async function load(cursor, history) {
                if (loading) return;
                loading = true;
                controls();
                try {
                    const page = await request(
                        `/sis/reports/${id}/run/`,
                        "POST",
                        {
                            after: cursor,
                        },
                    );
                    if (!body.isConnected) return;
                    after = cursor;
                    cursors = history;
                    nextAfter = page.next_after;
                    loaded = true;
                    status.textContent = `Page ${cursors.length + 1} · ${page.items.length} students.${nextAfter ? " More results are available." : " End of results."}`;
                    result.replaceChildren(
                        page.items.length
                            ? node(
                                  "div",
                                  {
                                      class: "table-responsive",
                                      tabindex: "0",
                                      role: "region",
                                      "aria-label": "Scrollable report results",
                                  },
                                  table(
                                      [
                                          "Institutional ID",
                                          "Primary program",
                                          "Entry term",
                                          "Activity",
                                          "Action",
                                      ],
                                      page.items.map((item) => [
                                          item.institutional_id,
                                          item.program_code,
                                          item.entry_term_code,
                                          item.active ? "Active" : "Inactive",
                                          can("sis:student:read")
                                              ? button(
                                                    "Open student academic record",
                                                    () =>
                                                        openRecord("students", {
                                                            id: item.student_id,
                                                        }),
                                                )
                                              : "—",
                                      ]),
                                      "Student report results",
                                  ),
                              )
                            : node(
                                  "p",
                                  {},
                                  "No students match this report's selection.",
                              ),
                    );
                } catch (error) {
                    if (error.status === 403) forget();
                    throw error;
                } finally {
                    loading = false;
                    controls();
                }
            }
            navigation.append(refresh, previous, next, download);
            body.append(navigation, status, result);
            controls();
        } else
            body.append(
                node(
                    "p",
                    {},
                    "Running this report requires the report execution permission.",
                ),
            );
        if (report.can_manage) await enhance("reports", { id }, body);
    }
    return { create, record };
};
