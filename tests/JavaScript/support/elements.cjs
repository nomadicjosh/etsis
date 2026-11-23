// Minimal element fixture for controller behavior; no layout engine, network or browser process.
class Element {
    constructor(tag, attributes = {}) {
        this.tag = tag; this.attributes = attributes; this.children = []; this.events = {};
        this.value = attributes.value ?? ''; this.checked = !!attributes.checked;
        this.hidden = !!attributes.hidden; this.id = attributes.id ?? ''; this.disabled = !!attributes.disabled;
        this.parent = null; this.files = []; this.name = attributes.name ?? ''; this.dataset = {}; this.text = ''; this.isConnected = true;
    }
    append(...children) {
        for (const child of children.flat(Infinity)) {
            if (child === null || child === undefined) continue;
            const element = child instanceof Element ? child : Object.assign(new Element('text'), { text: String(child) });
            element.parent = this; this.children.push(element);
        }
    }
    prepend(...children) { const previous = this.children; this.children = []; this.append(...children); this.children.push(...previous); }
    after(...children) {
        if (!this.parent) return;
        const holder = new Element('fragment');holder.append(...children);
        const index = this.parent.children.indexOf(this);
        for (const child of holder.children) child.parent = this.parent;
        this.parent.children.splice(index + 1, 0, ...holder.children);
    }
    replaceChildren(...children) { this.children = []; this.text = ''; this.append(...children); }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(''); }
    set textContent(text) { this.children = []; this.text = String(text); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    reportValidity() { return true; }
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(name, callback) { this.events[name] = callback; }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter(child => child !== this); }
    focus() { this.focused = true; }
    setCustomValidity() {}
    querySelectorAll(selector) { return walk(this).filter(element => element.tag === selector); }
    querySelector(selector) {
        const match = selector.match(/^\[data-entry="([^"]+)"\] \[data-field="([^"]+)"\]$/);
        if (!match) return /^[a-z][a-z0-9-]*$/i.test(selector) ? walk(this).find(element => element !== this && element.tag === selector) || null : null;
        const card = walk(this).find(element => element.attributes['data-entry'] === match[1]);
        return card ? walk(card).find(element => element.attributes['data-field'] === match[2]) : null;
    }
}
const node = (tag, attributes = {}, ...children) => {
    const element = new Element(tag, attributes); element.append(...children); return element;
};
const walk = element => [element, ...element.children.flatMap(walk)];
module.exports = { Element, node, walk };
