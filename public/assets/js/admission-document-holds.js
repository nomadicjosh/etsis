(() => {
  "use strict";
  let sequence = 0;
  /* Staff-only document preservation controls. Reasons and review proofs stay in memory. */
  window.createAdmissionDocumentHolds = function ({
    node,
    request,
    task,
    notify,
    labels = {},
  }) {
    const fallbackLabels = {
        scope_help: "Holds do not share files, extend applicant access, or restore removed files.",
        unavailable_account: "Unavailable account",
        preservation: "Document preservation",
        holds_help: "Holds protect existing and future admissions files from removal.",
        finish_change: "Finish or cancel the document hold change first.",
        scope_application: "This application",
        scope_person: "All applications for this person",
        review_heading: "Document hold review",
        review_change: "Review hold change",
        cancel_change: "Cancel hold change",
        hold_reason: "Reason for hold",
        release_reason: "Reason for release",
        reviewing: "Reviewing document hold…",
        place_confirmation: "Confirm this hold. File holds and decision evidence remain separate.",
        release_confirmation: "Release this scope’s hold. Other holds, deadlines and decision evidence still apply.",
        confirm_hold: "Confirm hold",
        confirm_release: "Confirm release",
        saving: "Saving document hold…",
        saved: "Document hold change saved.",
        revise_change: "Revise hold change",
        review_ready: "Review ready. Confirm or revise this change.",
        active: "Hold active",
        inactive: "No active hold",
        release_application: "Release application hold",
        place_application: "Place application hold",
        release_person: "Release person hold",
        place_person: "Place person hold",
        history: "Hold history",
        released: "Hold released",
        history_empty: "No document hold decisions recorded.",
        loading_history: "Loading hold history…",
        history_loaded: "Hold history loaded.",
        newer_history: "Newer holds",
        earlier_history: "Earlier holds",
    };
    const text = key => labels[key] || fallbackLabels[key];
    return function mount(
      container,
      application,
      data,
      { alive, write, reload, beforeChange, beforeRequest = () => true, status },
    ) {
      const base = `/sis/applications/${application}/document-holds/`;
      const section = node("section", {
        class: "record-section document-preservation",
        "aria-label": text("preservation"),
      });
      section.append(
        node(
          "h3",
          { class: "h6" },
          node("i", { class: "bi bi-shield-lock me-2", "aria-hidden": "true" }),
          text("preservation"),
        ),
        node(
          "p",
          { class: "form-text" },
          text(
            "holds_help",
          ),
        ),
        node(
          "p",
          { class: "form-text" },
          text(
            "scope_help",
          ),
        ),
      );
      const editor = node("div"),
        history = node("div");
      let editing = false,
        epoch = 0,
        reasonInput = null;
      const guard = () => {
        if (!editing) return true;
        status.textContent = text(
          "finish_change",
        );
        reasonInput?.focus();
        return false;
      };
      const stamp = (value) =>
        new Date(value).toLocaleString(undefined, { timeZoneName: "short" });
      const recorder = (name) => name || text("unavailable_account");
      const button = (key, run, style = "btn-outline-secondary") =>
        node(
          "button",
          {
            type: "button",
            class: `btn ${style}`,
            onclick: (event) => task(run, event.currentTarget),
          },
          text(key),
        );
      const scopeLabel = (scope) =>
        text(
          scope === "case"
            ? "scope_application"
            : "scope_person",
        );
      function edit(scope) {
        if (!beforeChange()) return;
        editing = true;
        const ticket = ++epoch;
        const action = data.scopes[scope].active ? "release" : "place";
        const form = node("form", {
          class: "document-hold-form border-top pt-3 mt-3",
        });
        const fieldId = `document-scope-hold-${++sequence}`;
        const reason = node("textarea", {
          id: fieldId,
          class: "form-control",
          rows: 2,
          maxlength: 1000,
          required: true,
        });
        reasonInput = reason;
        const preview = node("section", {
          class: "mt-3",
          "aria-label": text("review_heading"),
        });
        const review = node(
          "button",
          { type: "submit", class: "btn btn-primary" },
          text("review_change"),
        );
        const cancel = button("cancel_change", async () => {
          epoch++;
          editing = false;
          editor.replaceChildren();
          scopeButtons[scope].focus();
        });
        const actions = node(
          "div",
          { class: "record-form-actions mt-3" },
          review,
          cancel,
        );
        form.append(
          node("h4", { class: "h6" }, scopeLabel(scope)),
          node(
            "label",
            { for: fieldId, class: "form-label" },
            text(action === "place" ? "hold_reason" : "release_reason"),
          ),
          reason,
          actions,
          preview,
        );
        let proof = null;
        const fresh = () => alive() && ticket === epoch && editor.isConnected;
        function revise() {
          proof = null;
          preview.replaceChildren();
          reason.readOnly = false;
          review.hidden = false;
          reason.focus();
        }
        reason.addEventListener("input", () => {
          proof = null;
          preview.replaceChildren();
        });
        form.addEventListener("submit", (event) => {
          event.preventDefault();
          if (!beforeRequest() || !form.reportValidity()) return;
          task(async () => {
            await write(async () => {
              status.textContent = text("reviewing");
              proof = await request(`${base}review/`, "POST", {
                scope,
                action,
                revision: data.revision,
                reason: reason.value,
              });
              if (!fresh()) return;
              const accepted = proof;
              reason.readOnly = true;
              review.hidden = true;
              preview.replaceChildren(
                node(
                  "p",
                  { class: "alert alert-warning" },
                  text(
                    action === "place"
                      ? "place_confirmation"
                      : "release_confirmation",
                  ),
                ),
                node(
                  "p",
                  { class: "private-text text-break" },
                  accepted.reason,
                ),
                node(
                  "div",
                  { class: "record-form-actions" },
                  button(
                    action === "place" ? "confirm_hold" : "confirm_release",
                    () =>
                      beforeRequest() && write(async () => {
                        if (!fresh() || proof !== accepted) return;
                        status.textContent = text("saving");
                        await request(`${base}confirm/`, "POST", accepted);
                        if (!fresh()) return;
                        await reload();
                        editing = false;
                        notify(text("saved"));
                      }),
                    "btn-primary",
                  ),
                  button("revise_change", async () => {
                    if (fresh()) revise();
                  }),
                ),
              );
              status.textContent = text(
                "review_ready",
              );
            });
            if (fresh()) preview.querySelector("button")?.focus();
          }, review);
        });
        editor.replaceChildren(form);
        reason.focus();
      }
      const scopeButtons = {};
      const scopes = node("div", { class: "row g-3" });
      for (const scope of ["case", "person"]) {
        const state = data.scopes[scope];
        const card = node(
          "div",
          { class: "col-12 col-lg-6" },
          node(
            "div",
            { class: "border rounded p-3 h-100" },
            node("h4", { class: "h6" }, scopeLabel(scope)),
            node(
              "p",
              {},
              text(state.active ? "active" : "inactive"),
            ),
          ),
        );
        const content = card.firstElementChild;
        if (state.active)
          content.append(
            node("p", { class: "private-text text-break" }, state.reason),
            node(
              "p",
              { class: "form-text text-break" },
              recorder(state.placed_by_name),
            ),
            node("p", { class: "form-text" }, stamp(state.created_at)),
          );
        const labelKey =
          scope === "case"
            ? state.active
              ? "release_application"
              : "place_application"
            : state.active
              ? "release_person"
              : "place_person";
        scopeButtons[scope] = button(labelKey, async () => edit(scope));
        content.append(scopeButtons[scope]);
        scopes.append(card);
      }
      section.append(scopes, editor);
      let opened = false,
        pageTicket = 0,
        cursor = null,
        trail = [];
      const historyToggle = button("history", async () => {
        if (!beforeChange()) return;
        opened = !opened;
        historyToggle.setAttribute("aria-expanded", String(opened));
        history.hidden = !opened;
        if (opened) renderHistory(data, null, []);
      });
      history.id = `document-hold-history-${++sequence}`;
      history.hidden = true;
      historyToggle.setAttribute("aria-expanded", "false");
      historyToggle.setAttribute("aria-controls", history.id);
      function renderHistory(page, next, previous) {
        cursor = next;
        trail = previous;
        const list = node("div");
        for (const item of page.history) {
          const record = node(
            "article",
            { class: "border-top pt-3 mt-3" },
            node("h4", { class: "h6" }, scopeLabel(item.scope)),
            node("p", {}, text(item.active ? "active" : "released")),
            node(
              "p",
              { class: "form-text text-break" },
              recorder(item.placed_by_name),
            ),
            node("p", { class: "form-text" }, stamp(item.created_at)),
            node("p", { class: "private-text text-break" }, item.reason),
          );
          if (item.released_at)
            record.append(
              node("p", { class: "form-text" }, stamp(item.released_at)),
              node(
                "p",
                { class: "form-text text-break" },
                recorder(item.released_by_name),
              ),
              node(
                "p",
                { class: "private-text text-break" },
                item.release_reason,
              ),
            );
          list.append(record);
        }
        if (!page.history.length)
          list.append(
            node("p", {}, text("history_empty")),
          );
        const actions = node("div", { class: "record-form-actions mt-3" });
        async function load(nextCursor, previousTrail) {
          if (!beforeChange()) return;
          const ticket = ++pageTicket;
          await write(async () => {
            status.textContent = text("loading_history");
            const result = await request(
              `${base}${nextCursor ? `?before=${encodeURIComponent(nextCursor)}` : ""}`,
            );
            if (!alive() || ticket !== pageTicket) return;
            renderHistory(result, nextCursor, previousTrail);
            status.textContent = text("history_loaded");
          });
        }
        if (trail.length)
          actions.append(
            button("newer_history", () => load(trail.at(-1), trail.slice(0, -1))),
          );
        if (page.next_cursor)
          actions.append(
            button("earlier_history", () =>
              load(page.next_cursor, [...trail, cursor]),
            ),
          );
        history.replaceChildren(list, actions);
      }
      section.append(
        node("div", { class: "record-form-actions mt-3" }, historyToggle),
        history,
      );
      container.append(section);
      return guard;
    };
  };
})();
