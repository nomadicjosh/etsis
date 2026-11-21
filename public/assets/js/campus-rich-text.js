/* Quill (BSD-3-Clause), DOMPurify (Apache-2.0 option). All assets are self-hosted. */
window.CampusRichText = (() => {
    "use strict";
    const tags = [
        "p",
        "br",
        "h2",
        "h3",
        "strong",
        "b",
        "em",
        "i",
        "u",
        "s",
        "blockquote",
        "ol",
        "ul",
        "li",
        "a",
    ];
    let loading;
    function fragment(html) {
        if (!window.DOMPurify) return document.createTextNode(html);
        return window.DOMPurify.sanitize(html, {
            ALLOWED_TAGS: tags,
            ALLOWED_ATTR: ["href", "title", "rel"],
            ALLOW_DATA_ATTR: false,
            ALLOW_ARIA_ATTR: false,
            RETURN_DOM_FRAGMENT: true,
            ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|\/(?!\/)|#)/i,
        });
    }
    function article(post, node) {
        const body = node("div", { class: "campus-article-body" });
        if (post.content_format === "html" && typeof post.content_html === "string")
            body.append(fragment(post.content_html));
        else {
            body.style.whiteSpace = "pre-wrap";
            body.textContent = post.content;
        }
        return body;
    }
    function loadEditor() {
        if (window.Quill) return Promise.resolve();
        if (!loading)
            loading = new Promise((resolve, reject) => {
                const sheet = document.createElement("link");
                sheet.rel = "stylesheet";
                sheet.href = "/assets/vendor/quill.core.css";
                document.head.append(sheet);
                const script = document.createElement("script");
                script.src = "/assets/vendor/quill.js";
                script.onload = resolve;
                script.onerror = () => {
                    loading = null;
                    reject(new Error("The news editor could not load."));
                };
                document.head.append(script);
            });
        return loading;
    }
    async function mount(form, node, initialFormat = "plain") {
        const source = form.elements.content,
            kind = form.elements.kind;
        const format = node("input", { type: "hidden", name: "content_format", value: "plain" });
        form.append(format);
        let cleanup = () => {};
        let editor = null,
            shell = null,
            currentFormat = initialFormat,
            generation = 0;
        async function activate() {
            const token = ++generation;
            cleanup();
            cleanup = () => {};
            if (editor) {
                source.value = editor.getText().trimEnd();
                editor.disable();
                editor = null;
                shell.remove();
                shell = null;
                currentFormat = "plain";
            }
            source.hidden = false;
            format.value = "plain";
            if (kind.value !== "news") return;
            await loadEditor();
            if (token !== generation || kind.value !== "news") return;
            const prefix = source.id;
            const label = form.querySelector(`label[for="${prefix}"]`);
            label.id ||= `${prefix}-label`;
            const help = node(
                "p",
                { id: `${prefix}-format-help`, class: "form-text mb-0" },
                "Format your news with headings, lists and links. Images and embedded media are not included. Use Tab to move through the formatting controls and editor.",
            );
            const alert = node("p", { class: "text-danger mt-2 mb-0", role: "alert", hidden: true });
            const toolbar = node("div", {
                class: "campus-editor-toolbar d-flex flex-wrap gap-1 p-2 border-bottom",
                role: "group",
                "aria-label": "News formatting",
            });
            const area = node("div", { class: "campus-editor-area" });
            const style = node(
                "select",
                {
                    id: `${prefix}-style`,
                    class: "form-select form-select-sm campus-editor-style",
                    "aria-label": "Text style",
                },
                node("option", { value: "" }, "Paragraph"),
                node("option", { value: "2" }, "Heading 2"),
                node("option", { value: "3" }, "Heading 3"),
            );
            toolbar.append(style);
            shell = node("div", { class: "campus-news-editor border rounded mb-2" }, toolbar, area);
            source.after(shell, help, alert);
            editor = new window.Quill(area, {
                theme: null,
                placeholder: "Write your campus update…",
                formats: ["header", "bold", "italic", "underline", "strike", "blockquote", "list", "link"],
                modules: { toolbar: false, history: { delay: 800, maxStack: 50, userOnly: true } },
            });
            const quill = editor,
                controls = [];
            let range = { index: 0, length: 0 };
            quill.root.setAttribute("role", "textbox");
            quill.root.setAttribute("aria-multiline", "true");
            quill.root.setAttribute("aria-labelledby", label.id);
            quill.root.setAttribute("aria-describedby", help.id);
            quill.root.setAttribute("aria-required", "true");
            quill.root.tabIndex = 0;
            const focusEditor = () => quill.focus();
            label.addEventListener("click", focusEditor);
            const restore = () => {
                quill.focus();
                quill.setSelection(range.index, range.length, "silent");
            };
            function tool(text, icon, run, toggle = false) {
                const button = node(
                    "button",
                    {
                        type: "button",
                        class: "btn btn-sm btn-outline-secondary",
                        "aria-label": text,
                        title: text,
                        ...(toggle ? { "aria-pressed": "false" } : {}),
                    },
                    node("i", { class: `bi bi-${icon}`, "aria-hidden": "true" }),
                );
                button.addEventListener("mousedown", (event) => event.preventDefault());
                button.addEventListener("click", () => {
                    restore();
                    run(button);
                    reflect();
                });
                toolbar.append(button);
                return button;
            }
            function reflect() {
                const values = quill.getFormat(range);
                for (const [button, name, value] of controls)
                    button.setAttribute(
                        "aria-pressed",
                        String(value == null ? !!values[name] : values[name] === value),
                    );
                style.value = String(values.header || "");
            }
            for (const [text, icon, name] of [
                ["Bold", "type-bold", "bold"],
                ["Italic", "type-italic", "italic"],
                ["Underline", "type-underline", "underline"],
                ["Strikethrough", "type-strikethrough", "strike"],
            ]) {
                const button = tool(
                    text,
                    icon,
                    () => quill.format(name, !quill.getFormat(range)[name]),
                    true,
                );
                controls.push([button, name]);
            }
            for (const [text, icon, name, value] of [
                ["Bulleted list", "list-ul", "list", "bullet"],
                ["Numbered list", "list-ol", "list", "ordered"],
                ["Quote", "quote", "blockquote", true],
            ]) {
                const button = tool(
                    text,
                    icon,
                    () =>
                        quill.formatLine(
                            range.index,
                            range.length,
                            name,
                            quill.getFormat(range)[name] === value ? false : value,
                        ),
                    true,
                );
                controls.push([button, name, value]);
            }
            style.addEventListener("change", () => {
                const value = style.value;
                restore();
                quill.formatLine(range.index, range.length, "header", value ? Number(value) : false);
                reflect();
            });
            const url = node("input", {
                id: `${prefix}-url`,
                type: "text",
                class: "form-control",
                maxlength: "2048",
                inputmode: "url",
            });
            const linkGroup = node(
                "div",
                { class: "p-2 border-top", hidden: true },
                node("label", { for: url.id, class: "form-label" }, "Link address"),
                url,
            );
            const linkButton = tool("Add or edit link", "link-45deg", () => {
                linkGroup.hidden = false;
                url.value = quill.getFormat(range).link || "";
                url.focus();
            });
            const addLink = node(
                "button",
                { type: "button", class: "btn btn-sm btn-primary mt-2" },
                "Apply link",
            );
            addLink.addEventListener("click", () => {
                url.setCustomValidity("");
                const value = url.value.trim();
                try {
                    const parsed = new URL(value, location.origin);
                    if (
                        !/^(https?:\/\/|mailto:|\/(?!\/)|#)/i.test(value) ||
                        parsed.username ||
                        parsed.password
                    )
                        throw new Error();
                } catch {
                    url.setCustomValidity(
                        "Use a website address, email link or local path without credentials.",
                    );
                    url.reportValidity();
                    return;
                }
                restore();
                quill.format("link", value);
                linkGroup.hidden = true;
            });
            const cancel = node("button", { type: "button", class: "btn btn-sm btn-link mt-2" }, "Cancel");
            cancel.addEventListener("click", () => {
                linkGroup.hidden = true;
                url.setCustomValidity("");
                linkButton.focus();
            });
            linkGroup.append(addLink, cancel);
            shell.append(linkGroup);
            tool("Remove link", "link", () => quill.format("link", false));
            tool("Undo", "arrow-counterclockwise", () => quill.history.undo());
            tool("Redo", "arrow-clockwise", () => quill.history.redo());
            tool("Clear formatting", "eraser", () => quill.removeFormat(range.index, range.length));
            if (currentFormat === "html")
                quill.setContents(
                    quill.clipboard.convert({
                        html: window.DOMPurify.sanitize(source.value, {
                            ALLOWED_TAGS: tags,
                            ALLOWED_ATTR: ["href", "title", "rel"],
                        }),
                    }),
                    "silent",
                );
            else quill.setText(source.value, "silent");
            currentFormat = format.value = "html";
            source.hidden = true;
            function sync() {
                const hasText = !!quill.getText().trim();
                source.value = hasText ? quill.getSemanticHTML().replaceAll("&nbsp;", " ") : "";
                quill.root.setAttribute("aria-invalid", String(!hasText && !alert.hidden));
                if (hasText) {
                    alert.hidden = true;
                    source.setCustomValidity("");
                }
                if (new TextEncoder().encode(source.value).length > 60000) {
                    source.setCustomValidity("This update is too long. Shorten its text or formatting before saving.");
                }
                source.dispatchEvent(new Event("input", { bubbles: true }));
            }
            const beforeSubmit = () => {
                if (kind.value === "news" && editor === quill) { quill.update("silent"); sync(); }
            };
            form.addEventListener("submit", beforeSubmit, true);
            quill.on("text-change", sync);
            quill.on("selection-change", (selected) => {
                if (selected) {
                    range = selected;
                    reflect();
                }
            });
            const invalid = event => {
                if (kind.value !== "news") return;
                event.preventDefault(); alert.hidden = false;
                alert.textContent = source.value ? source.validationMessage : "Add some news text before saving.";
                quill.root.setAttribute("aria-invalid", "true"); quill.focus();
            };
            source.addEventListener("invalid", invalid);
            cleanup = () => {
                label.removeEventListener("click", focusEditor);
                source.removeEventListener("invalid", invalid);
                form.removeEventListener("submit", beforeSubmit, true);
                quill.off("text-change", sync);
                help.remove(); alert.remove();
            };
            quill.history.clear();
            sync();
        }
        kind.addEventListener("change", () => {
            activate().catch(() => {});
        });
        try {
            await activate();
        } catch {
            source.hidden = false;
            format.value = "plain";
        }
    }
    return { fragment, article, mount };
})();
