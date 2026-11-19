/* Report-editor working groups. Server validation remains authoritative for execution. */
window.createReportMatchLogic = function (definition) {
    "use strict";
    function normalize() {
        const available = new Set(Object.keys(definition.existence || {}));
        const seen = new Set();
        function visit(group) {
            return {
                condition: group.condition === "OR" ? "OR" : "AND",
                rules: (group.rules || []).flatMap((rule) => {
                    if (rule.condition) return [visit(rule)];
                    if (!available.has(rule.relation) || seen.has(rule.relation)) return [];
                    seen.add(rule.relation);
                    return [{ relation: rule.relation }];
                }),
            };
        }
        definition.existence_logic = visit(definition.existence_logic || { condition: "AND", rules: [] });
        for (const id of available)
            if (!seen.has(id)) definition.existence_logic.rules.push({ relation: id });
        return definition.existence_logic;
    }
    function group(path) {
        let current = definition.existence_logic;
        for (const index of path) current = current?.rules[index];
        if (!current?.condition) throw new Error("Choose a current condition group.");
        return current;
    }
    function groups() {
        const result = [];
        function visit(current, path, label) {
            result.push({ group: current, path, label });
            let number = 0;
            current.rules.forEach((rule, index) => {
                if (rule.condition)
                    visit(
                        rule,
                        [...path, index],
                        label === "Main group" ? `Group ${++number}` : `${label}.${++number}`,
                    );
            });
        }
        visit(definition.existence_logic, [], "Main group");
        return result;
    }
    function location(relation, current = definition.existence_logic) {
        const index = current.rules.findIndex((rule) => rule.relation === relation);
        if (index >= 0) return { group: current, index };
        for (const rule of current.rules)
            if (rule.condition) {
                const found = location(relation, rule);
                if (found) return found;
            }
        return null;
    }
    function move(relation, path, before = null) {
        const destination = group(path),
            source = location(relation);
        if (!source || before === relation) return;
        const [rule] = source.group.rules.splice(source.index, 1);
        const at = destination.rules.findIndex((item) => item.relation === before);
        destination.rules.splice(at < 0 ? destination.rules.length : at, 0, rule);
    }
    function shift(relation, direction) {
        const source = location(relation);
        if (!source) return;
        const target = source.index + direction;
        if (target < 0 || target >= source.group.rules.length) return;
        const [rule] = source.group.rules.splice(source.index, 1);
        source.group.rules.splice(target, 0, rule);
    }
    function addGroup(path) {
        if (path.length >= 3 || groups().length >= 7) return false;
        group(path).rules.push({ condition: "OR", rules: [] });
        return true;
    }
    function ungroup(path) {
        if (!path.length) return;
        const parent = group(path.slice(0, -1)),
            index = path.at(-1);
        parent.rules.splice(index, 1, ...group(path).rules);
    }
    function assertReady() {
        if (groups().some((entry) => entry.path.length > 0 && !entry.group.rules.length)) {
            throw new Error("Move a match into each group, or remove the empty groups before continuing.");
        }
    }
    normalize();
    return { normalize, group, groups, location, move, shift, addGroup, ungroup, assertReady };
};
