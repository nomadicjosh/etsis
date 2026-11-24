<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Symmetric incompatible pairs within one retained program definition. */
final class ComponentExclusions
{
    public readonly array $pairs;
    private int $steps = 0;

    public function __construct(array $pairs)
    {
        if (!array_is_list($pairs) || count($pairs) > 32) {
            $this->invalid('Use at most thirty-two incompatible curriculum pairs.');
        }
        $normalized = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2) {
                $this->invalid('Choose two distinct curriculum components for each incompatible pair.');
            }
            foreach ($pair as $id) {
                if (!is_string($id)) {
                    $this->invalid('Choose valid curriculum component identities.');
                }
                new EntityId($id);
            }
            sort($pair, SORT_STRING);
            $key = implode(':', $pair);
            if ($pair[0] === $pair[1] || isset($normalized[$key])) {
                $this->invalid('Use distinct components and list each incompatible pair only once.');
            }
            $normalized[$key] = $pair;
        }
        ksort($normalized, SORT_STRING);
        $this->pairs = array_values($normalized);
    }

    public function references(array $offered): void
    {
        $ids = array_fill_keys(array_column($offered, 'id'), true);
        foreach ($this->pairs as $pair) {
            if (!isset($ids[$pair[0]], $ids[$pair[1]])) {
                $this->invalid('Every incompatible pair must use active components offered by this program.');
            }
        }
    }

    public function selection(array $offered, array $ids): void
    {
        $this->references($offered);
        $selected = array_fill_keys($ids, true);
        $labels = array_column($offered, 'code', 'id');
        foreach ($this->pairs as [$left, $right]) {
            if (isset($selected[$left], $selected[$right])) {
                $this->invalid('Choose either ' . $labels[$left] . ' or ' . $labels[$right]
                    . '; this retained program policy does not allow them together.');
            }
        }
    }

    /** Prove at least one complete selection; reaching the bound never approves a policy. */
    public function publication(array $offered, array $limits, int $minimum, int $maximum): void
    {
        $this->references($offered);
        if ($this->pairs === []) {
            return;
        }
        foreach ($offered as $choice) {
            if (!is_string($choice['kind'] ?? null) || CurriculumComponentKind::tryFrom($choice['kind']) === null) {
                $this->invalid('Choose a curriculum type for every offered component before publishing exclusions.');
            }
        }
        $rules = $counts = [];
        foreach (CurriculumComponentKind::cases() as $kind) {
            $rules[$kind->value] = $limits[$kind->value] ?? ['minimum' => 0, 'maximum' => $maximum];
            $counts[$kind->value] = 0;
        }
        $conflicts = [];
        foreach ($this->pairs as [$left, $right]) {
            $conflicts[$left][$right] = $conflicts[$right][$left] = true;
        }
        // Required majors precede their children so a skipped major can never be borrowed later.
        usort($offered, static fn (array $a, array $b): int =>
            (($a['kind'] !== 'major') <=> ($b['kind'] !== 'major')) ?: strcmp($a['id'], $b['id']));
        $this->steps = 0;
        if (!$this->feasible($offered, 0, [], $counts, $rules, $conflicts, $minimum, $maximum)) {
            $this->invalid('Incompatible components cannot satisfy the required counts and major relationships. '
                . 'Review the offered choices, pairs and limits before publishing.');
        }
    }

    private function feasible(
        array $offered,
        int $index,
        array $selected,
        array $counts,
        array $rules,
        array $conflicts,
        int $minimum,
        int $maximum,
    ): bool {
        if (++$this->steps > 10000) {
            $this->invalid('The incompatible-choice policy exceeds bounded publication verification. '
                . 'Simplify its choices or limits before publishing.');
        }
        $total = count($selected);
        $enough = $total >= $minimum;
        foreach ($rules as $kind => $rule) {
            $enough = $enough && $counts[$kind] >= $rule['minimum'];
        }
        if ($enough) {
            return true;
        }
        if ($total >= $maximum || $index >= count($offered)) {
            return false;
        }
        $upper = $counts;
        for ($i = $index; $i < count($offered); $i++) {
            $upper[$offered[$i]['kind']]++;
        }
        $possible = 0;
        foreach ($rules as $kind => $rule) {
            if ($upper[$kind] < $rule['minimum']) {
                return false;
            }
            $possible += min($upper[$kind], $rule['maximum']);
        }
        if ($possible < $minimum) {
            return false;
        }
        $choice = $offered[$index];
        $id = $choice['id'];
        $kind = $choice['kind'];
        $parent = $choice['parent_major']['id'] ?? null;
        if (
            $counts[$kind] < $rules[$kind]['maximum']
            && ($parent === null || isset($selected[$parent]))
            && array_intersect_key($selected, $conflicts[$id] ?? []) === []
        ) {
            $next = $counts;
            $next[$kind]++;
            if (
                $this->feasible(
                    $offered,
                    $index + 1,
                    $selected + [$id => true],
                    $next,
                    $rules,
                    $conflicts,
                    $minimum,
                    $maximum
                )
            ) {
                return true;
            }
        }
        return $this->feasible($offered, $index + 1, $selected, $counts, $rules, $conflicts, $minimum, $maximum);
    }

    private function invalid(string $message): never
    {
        throw new ValidationException([['field' => 'component_exclusions', 'message' => $message]]);
    }
}
