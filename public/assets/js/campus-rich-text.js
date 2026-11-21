/* Tiptap / ProseMirror (MIT), DOMPurify (Apache-2.0 option). All assets are self-hosted. */
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
        if (
            post.content_format === "html" &&
            typeof post.content_html === "string"
        )
            body.append(fragment(post.content_html));
        else {
            body.style.whiteSpace = "pre-wrap";
            body.textContent = post.content;
        }
        return body;
    }
    function cleanHTML(html) {
        if (!window.DOMPurify)
            throw new Error("The news sanitizer could not load.");
        const container = document.createElement("div");
        container.append(fragment(html));
        return container.innerHTML;
    }
    function plainHTML(text) {
        const container = document.createElement("div");
        for (const line of text.split("\n")) {
            const paragraph = document.createElement("p");
            paragraph.textContent = line;
            container.append(paragraph);
        }
        return container.innerHTML;
    }
    function safeLink(value) {
        try {
            const parsed = new URL(value, location.origin);
            return (
                /^(https?:\/\/|mailto:|\/(?!\/)|#)/i.test(value) &&
                !parsed.username &&
                !parsed.password
            );
        } catch {
            return false;
        }
    }
    function loadEditor() {
        if (window.CampusTiptap) return Promise.resolve();
        if (!loading)
            loading = new Promise((resolve, reject) => {
                const script = document.createElement("script");
                script.src = "/assets/vendor/tiptap.js";
                script.onload = () =>
                    window.CampusTiptap ? resolve() : script.onerror();
                script.onerror = () => {
                    script.remove();
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
        const format = node("input", {
            type: "hidden",
            name: "content_format",
            value: initialFormat,
        });
        form.append(format);
        let cleanup = () => {};
        let editor = null,
            shell = null,
            currentFormat = initialFormat,
            generation = 0;
        let richDraft = null,
            plainDraft = null;
        async function activate() {
            const token = ++generation;
            cleanup();
            cleanup = () => {};
            if (editor) {
                richDraft = editor.getHTML();
                plainDraft = source.value = editor
                    .getText({ blockSeparator: "\n" })
                    .trimEnd();
                editor.destroy();
                editor = null;
                shell.remove();
                shell = null;
                currentFormat = "plain";
            }
            source.setCustomValidity("");
            source.hidden = false;
            format.value = currentFormat;
            if (kind.value !== "news") {
                format.value = "plain";
                return;
            }
            await loadEditor();
            if (
                token !== generation ||
                kind.value !== "news" ||
                !form.isConnected
            )
                return;
            const prefix = source.id;
            const label = form.querySelector(`label[for="${prefix}"]`);
            label.id ||= `${prefix}-label`;
            const help = node(
                "p",
                { id: `${prefix}-format-help`, class: "form-text mb-0" },
                "Format your news with headings, lists and links. Images and embedded media are not included. Use Tab to move through the formatting controls and editor.",
            );
            const alert = node("p", {
                class: "text-danger mt-2 mb-0",
                role: "alert",
                hidden: true,
            });
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
            shell = node(
                "div",
                { class: "campus-news-editor border rounded mb-2" },
                toolbar,
                area,
            );
            source.after(shell, help, alert);
            const content =
                currentFormat === "html"
                    ? cleanHTML(source.value)
                    : richDraft !== null && source.value === plainDraft
                      ? richDraft
                      : plainHTML(source.value);
            editor = new window.CampusTiptap.Editor({
                element: area,
                content,
                injectCSS: false,
                extensions: [
                    window.CampusTiptap.StarterKit.configure({
                        heading: { levels: [2, 3] },
                        code: false,
                        codeBlock: false,
                        horizontalRule: false,
                        trailingNode: false,
                        undoRedo: { depth: 50, newGroupDelay: 800 },
                        link: {
                            openOnClick: false,
                            autolink: false,
                            linkOnPaste: false,
                            isAllowedUri: safeLink,
                            HTMLAttributes: {
                                target: null,
                                rel: "noopener noreferrer",
                            },
                        },
                    }),
                ],
                editorProps: { transformPastedHTML: cleanHTML },
            });
            const tiptap = editor,
                root = tiptap.view.dom,
                controls = [];
            let range = { from: 1, to: 1 };
            root.setAttribute("role", "textbox");
            root.setAttribute("aria-multiline", "true");
            root.setAttribute("aria-labelledby", label.id);
            root.setAttribute("aria-describedby", help.id);
            root.setAttribute("aria-required", "true");
            root.tabIndex = 0;
            const focusEditor = () => tiptap.commands.focus();
            label.addEventListener("click", focusEditor);
            const restore = () =>
                tiptap.chain().focus().setTextSelection(range).run();
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
                    node("i", {
                        class: `bi bi-${icon}`,
                        "aria-hidden": "true",
                    }),
                );
                button.addEventListener("mousedown", (event) =>
                    event.preventDefault(),
                );
                button.addEventListener("click", () => {
                    restore();
                    run(button);
                    reflect();
                });
                toolbar.append(button);
                return button;
            }
            function reflect() {
                for (const [button, name] of controls)
                    button.setAttribute(
                        "aria-pressed",
                        String(tiptap.isActive(name)),
                    );
                style.value = String(
                    tiptap.getAttributes("heading").level || "",
                );
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
                    () => tiptap.commands.toggleMark(name),
                    true,
                );
                controls.push([button, name]);
            }
            for (const [text, icon, name, command] of [
                ["Bulleted list", "list-ul", "bulletList", "toggleBulletList"],
                [
                    "Numbered list",
                    "list-ol",
                    "orderedList",
                    "toggleOrderedList",
                ],
                ["Quote", "quote", "blockquote", "toggleBlockquote"],
            ]) {
                const button = tool(
                    text,
                    icon,
                    () => tiptap.commands[command](),
                    true,
                );
                controls.push([button, name]);
            }
            style.addEventListener("change", () => {
                const value = style.value;
                restore();
                if (value) tiptap.commands.setHeading({ level: Number(value) });
                else tiptap.commands.setParagraph();
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
                node(
                    "label",
                    { for: url.id, class: "form-label" },
                    "Link address",
                ),
                url,
            );
            const linkButton = tool("Add or edit link", "link-45deg", () => {
                linkGroup.hidden = false;
                url.value = tiptap.getAttributes("link").href || "";
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
                if (!safeLink(value)) {
                    url.setCustomValidity(
                        "Use a website address, email link or local path without credentials.",
                    );
                    url.reportValidity();
                    return;
                }
                restore();
                tiptap
                    .chain()
                    .extendMarkRange("link")
                    .setLink({ href: value })
                    .run();
                linkGroup.hidden = true;
            });
            const cancel = node(
                "button",
                { type: "button", class: "btn btn-sm btn-link mt-2" },
                "Cancel",
            );
            cancel.addEventListener("click", () => {
                linkGroup.hidden = true;
                url.setCustomValidity("");
                linkButton.focus();
            });
            linkGroup.append(addLink, cancel);
            shell.append(linkGroup);
            tool("Remove link", "link", () =>
                tiptap.chain().extendMarkRange("link").unsetLink().run(),
            );
            tool("Undo", "arrow-counterclockwise", () =>
                tiptap.commands.undo(),
            );
            tool("Redo", "arrow-clockwise", () => tiptap.commands.redo());
            tool("Clear formatting", "eraser", () =>
                tiptap.chain().unsetAllMarks().clearNodes().run(),
            );
            currentFormat = format.value = "html";
            source.hidden = true;
            function sync() {
                const hasText = !!tiptap.getText().trim();
                source.value = hasText
                    ? tiptap.getHTML().replaceAll("&nbsp;", " ")
                    : "";
                root.setAttribute(
                    "aria-invalid",
                    String(!hasText && !alert.hidden),
                );
                source.setCustomValidity("");
                if (hasText) alert.hidden = true;
                if (new TextEncoder().encode(source.value).length > 60000) {
                    source.setCustomValidity(
                        "This update is too long. Shorten its text or formatting before saving.",
                    );
                }
                source.dispatchEvent(new Event("input", { bubbles: true }));
            }
            const beforeSubmit = () => {
                if (kind.value === "news" && editor === tiptap) sync();
            };
            form.addEventListener("submit", beforeSubmit, true);
            tiptap.on("update", sync);
            const selectionChanged = () => {
                const { from, to } = tiptap.state.selection;
                range = { from, to };
                reflect();
            };
            tiptap.on("selectionUpdate", selectionChanged);
            tiptap.on("transaction", reflect);
            const invalid = (event) => {
                if (kind.value !== "news") return;
                event.preventDefault();
                alert.hidden = false;
                alert.textContent = source.value
                    ? source.validationMessage
                    : "Add some news text before saving.";
                root.setAttribute("aria-invalid", "true");
                tiptap.commands.focus();
            };
            source.addEventListener("invalid", invalid);
            cleanup = () => {
                label.removeEventListener("click", focusEditor);
                source.removeEventListener("invalid", invalid);
                form.removeEventListener("submit", beforeSubmit, true);
                tiptap.off("update", sync);
                tiptap.off("selectionUpdate", selectionChanged);
                tiptap.off("transaction", reflect);
                help.remove();
                alert.remove();
            };
            reflect();
            sync();
        }
        function failed() {
            if (!form.isConnected || kind.value !== "news") return;
            cleanup();
            if (editor) {
                editor.destroy();
                editor = null;
            }
            shell?.remove();
            shell = null;
            const message =
                "The news editor could not load. Your content is preserved. Retry before saving.";
            source.hidden = true;
            format.value = currentFormat;
            source.setCustomValidity(message);
            const error = node(
                "div",
                { class: "alert alert-danger", role: "alert", tabindex: "-1" },
                message,
            );
            const retry = node(
                "button",
                { type: "button", class: "btn btn-outline-primary" },
                "Retry news editor",
            );
            retry.addEventListener("click", () => start());
            error.append(retry);
            source.after(error);
            const invalid = (event) => {
                event.preventDefault();
                error.focus();
            };
            source.addEventListener("invalid", invalid);
            cleanup = () => {
                error.remove();
                source.removeEventListener("invalid", invalid);
            };
        }
        async function start() {
            try {
                await activate();
            } catch {
                failed();
            }
        }
        kind.addEventListener("change", start);
        const observer = new MutationObserver(() => {
            if (form.isConnected) return;
            generation++;
            cleanup();
            editor?.destroy();
            editor = null;
            kind.removeEventListener("change", start);
            observer.disconnect();
        });
        observer.observe(document.body, { childList: true, subtree: true });
        await start();
    }
    return { fragment, article, mount };
})();
