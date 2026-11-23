/* Shared presentation only. Return actions use existing callbacks; private task content is never cached. */
window.createWorkspaceSurfaces = function (ui) {
    "use strict";
    const { node, task, bootstrap } = ui;
    const visibleMenus = new Map();
    let frame = null;
    const glyph = (name) => node("i", { class: `bi bi-${name}`, "aria-hidden": "true" });
    function actionIcon(label) {
        const named = {
            "Degree requirements": "list-check",
            Specializations: "diagram-3",
            "Gradebook setup": "sliders",
            "Take attendance": "calendar-check",
            "Final-grade worksheet": "journal-check",
            "Class grade certification": "patch-check",
            "View transcript": "file-earmark-text",
            "Enrollment load": "speedometer2",
            "Academic standing": "award",
            "Contact information": "person-lines-fill",
            "Educational background": "mortarboard",
            "Manage addresses": "geo-alt",
            "Emergency contacts": "telephone",
            "Room timetable": "calendar3",
            "View agenda": "calendar-week",
            "Check availability": "calendar-check",
            "Request this room": "calendar-plus",
            "Reschedule": "calendar-event",
            "Cancel meeting": "calendar-x",
            "Award transfer credit": "mortarboard",
            "Define equivalency for this course": "arrow-left-right",
            "Academic details": "calendar-range",
            "Correct credits or grade": "pencil-square",
            "Prepare transcript": "file-earmark-check",
            "Record delivery": "box-arrow-up-right",
            "Confirm delivery": "check2-circle",
        };
        if (named[label]) return named[label];
        const rules = [
            [/^(Back|Return)\b/, "arrow-left"],
            [/^(Save|Update)\b/, "check2"],
            [/^(Confirm|Publish|Approve)\b/, "check2-circle"],
            [/^Review\b/, "clipboard-check"],
            [/^(Add|New|Create|Assign)\b/, "plus-lg"],
            [/^(Edit|Change|Revise)\b/, "pencil-square"],
            [/^(Search|Find)\b/, "search"],
            [/^(Refresh|Reload)\b/, "arrow-clockwise"],
            [/^(Choose|Select)\b/, "list-check"],
            [/^(Remove|Delete|Discard)\b/, "trash3"],
            [/^(Download|Export)\b/, "download"],
            [/history\b/i, "clock-history"],
            [/^(More|Actions)\b/, "three-dots"],
            [/^(Open|View|Inspect)\b/, "box-arrow-up-right"],
        ];
        return rules.find(([pattern]) => pattern.test(label))?.[1] || null;
    }
    function decorateButton(button, label) {
        if (button.classList.contains("record-link")) return;
        const name = actionIcon(label);
        if (name && !button.querySelector(".bi")) {
            button.classList.add("workspace-icon-control");
            button.prepend(glyph(name));
        }
    }
    function viewport() {
        const v = window.visualViewport;
        return {
            width: v?.width || document.documentElement.clientWidth,
            height: v?.height || window.innerHeight,
            top: v?.offsetTop || 0,
        };
    }
    function sizeMenu(toggle, menu) {
        const view = viewport(),
            r = toggle.getBoundingClientRect();
        const space = Math.max(r.top - view.top, view.top + view.height - r.bottom) - 16;
        menu.style.setProperty(
            "--workspace-menu-height",
            `${Math.max(40, Math.min(view.height - 16, space))}px`,
        );
        menu.style.setProperty("--workspace-menu-width", `${Math.max(0, view.width - 16)}px`);
    }
    const popperConfig = (config) => ({
        ...config,
        strategy: "fixed",
        modifiers: [
            ...config.modifiers,
            {
                name: "flip",
                options: {
                    rootBoundary: "viewport",
                    padding: 8,
                    fallbackPlacements: ["top-end", "bottom-start", "top-start"],
                },
            },
            {
                name: "preventOverflow",
                options: {
                    boundary: "viewport",
                    rootBoundary: "viewport",
                    padding: 8,
                    altAxis: true,
                    tether: false,
                },
            },
            { name: "offset", options: { offset: [0, 8] } },
        ],
    });
    function dropdown(toggle) {
        return bootstrap.Dropdown.getOrCreateInstance(toggle, { popperConfig });
    }
    function menuFor(toggle) {
        return toggle.parentElement?.querySelector(":scope > .dropdown-menu") || null;
    }
    function hideMenu(toggle, menu) {
        if (typeof menu?.hidePopover === "function" && menu.matches(":popover-open"))
            menu.hidePopover();
        visibleMenus.delete(toggle);
    }
    function closeMenus(root = document) {
        for (const [toggle, menu] of visibleMenus) {
            if (root.contains(toggle)) {
                bootstrap.Dropdown.getInstance(toggle)?.hide();
                hideMenu(toggle, menu);
            }
        }
    }
    function disposeMenus(root) {
        for (const [toggle, menu] of visibleMenus) {
            if (!toggle.isConnected || root?.contains(toggle)) hideMenu(toggle, menu);
        }
    }
    // The native top layer escapes scrolling tables, offcanvas transforms and the sidebar's stacking context.
    // Bootstrap still owns selection, dismissal, keyboard navigation and return focus; DOM ancestry is preserved.
    document.addEventListener("show.bs.dropdown", (event) => {
        if (event.defaultPrevented) return;
        const toggle = event.target,
            menu = menuFor(toggle);
        if (!menu || !toggle.getClientRects().length) return;
        sizeMenu(toggle, menu);
        if (typeof menu.showPopover === "function") {
            menu.setAttribute("popover", "manual");
            menu.classList.add("workspace-floating-menu");
            menu.showPopover();
        }
        visibleMenus.set(toggle, menu);
    });
    document.addEventListener("hidden.bs.dropdown", (event) =>
        hideMenu(event.target, menuFor(event.target)),
    );
    document.addEventListener(
        "click",
        (event) => {
            const toggle = event.target.closest('[data-bs-toggle="dropdown"]');
            if (toggle) dropdown(toggle);
        },
        true,
    );
    document.addEventListener(
        "keydown",
        (event) => {
            const toggle = event.target.closest('[data-bs-toggle="dropdown"]');
            if (toggle) dropdown(toggle);
        },
        true,
    );
    document.addEventListener("close", (event) => closeMenus(event.target), true);
    for (const type of ["hide.bs.offcanvas", "hide.bs.modal"])
        document.addEventListener(type, (event) => {
            if (!event.defaultPrevented && event.target instanceof Element)
                closeMenus(event.target);
        });
    function reposition() {
        if (frame !== null || !visibleMenus.size) return;
        frame = requestAnimationFrame(() => {
            frame = null;
            for (const [toggle, menu] of visibleMenus) {
                if (!toggle.isConnected) {
                    hideMenu(toggle, menu);
                    continue;
                }
                sizeMenu(toggle, menu);
                bootstrap.Dropdown.getInstance(toggle)?.update();
            }
        });
    }
    for (const type of ["show.bs.offcanvas", "show.bs.modal"])
        document.addEventListener(type, (event) => {
            if (!event.defaultPrevented) closeMenus();
        });
    document.addEventListener("shown.bs.offcanvas", reposition);
    window.addEventListener("resize", reposition);
    document.addEventListener("scroll", reposition, true);
    window.visualViewport?.addEventListener("resize", reposition);
    window.visualViewport?.addEventListener("scroll", reposition);
    window.addEventListener("pagehide", () => closeMenus());
    document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(dropdown);

    function taskNavigation(content, title, record = null) {
        const navigation = node("nav", {
            class: "record-task-navigation",
            "aria-label": "Task navigation",
            hidden: true,
        });
        let promoted = null,
            fallback = null;
        const current = node(
            "span",
            { class: "record-task-location", "aria-current": "page" },
            title,
        );
        function show(control) {
            navigation.replaceChildren(control, glyph("chevron-right"), current);
            navigation.hidden = false;
        }
        function restoreFallback() {
            if (!record) {
                navigation.replaceChildren();
                navigation.hidden = true;
                return;
            }
            fallback = node(
                "button",
                {
                    type: "button",
                    class: "btn btn-outline-secondary btn-sm record-task-return",
                    onclick: (event) => {
                        if (record && content.isConnected)
                            return task(record.run, event.currentTarget);
                    },
                },
                glyph("arrow-left"),
                "Back to record",
            );
            fallback.title = record.label;
            show(fallback);
        }
        restoreFallback();
        const observer = new MutationObserver((records) => {
            // A fresh task body must not retain the previous task's return action.
            if (records.some((record) => record.target === content && record.removedNodes.length)) {
                promoted = null;
                restoreFallback();
            }
            const source = [...content.querySelectorAll('button[type="button"], a')].find(
                (control) =>
                    /^(Back to |Back$|Return to )/.test(control.textContent.trim()) &&
                    control.getClientRects().length &&
                    !control.closest("table, .dropdown-menu, section, article, [hidden]") &&
                    (!control.closest("dialog") ||
                        control.closest("dialog") === content.closest("dialog")),
            );
            if (!source || source === promoted) return;
            promoted = source;
            fallback = null;
            const focused = source === document.activeElement;
            source.classList.remove("mb-3", "mt-3", "btn-link", "btn-primary", "btn-outline-primary");
            source.classList.add("btn", "btn-outline-secondary", "btn-sm", "record-task-return");
            decorateButton(source, source.textContent.trim());
            show(source);
            if (focused) source.focus({ preventScroll: true });
            observer.takeRecords();
        });
        observer.observe(content, { childList: true, subtree: true });
        return {
            element: navigation,
            dispose: () => {
                observer.disconnect();
                promoted = null;
                fallback = null;
                record = null;
            },
        };
    }
    return { dropdown, closeMenus, disposeMenus, decorateButton, taskNavigation };
};
