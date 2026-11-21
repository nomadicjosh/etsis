<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Additional per-kind constraints; aggregate institutional/program limits always continue to apply. */
final readonly class ComponentSelectionRules
{
    public array $limits;
    public function __construct(array $limits)
    {
        if (count($limits) > count(CurriculumComponentKind::cases())) {
            $this->invalid();
        }
        foreach ($limits as $kind => $rule) {
            if (
                !is_string($kind) ||
                CurriculumComponentKind::tryFrom($kind) === null ||
                !is_array($rule) ||
                count($rule) !== 2 ||
                !is_int($rule["minimum"] ?? null) ||
                !is_int($rule["maximum"] ?? null) ||
                $rule["minimum"] < 0 ||
                $rule["maximum"] < $rule["minimum"] ||
                $rule["maximum"] > 100
            ) {
                $this->invalid();
            }
        }
        ksort($limits, SORT_STRING);
        $this->limits = array_map(
            static fn(array $rule): array => [
                "minimum" => $rule["minimum"],
                "maximum" => $rule["maximum"],
            ],
            $limits,
        );
    }
    /** Publication must have a feasible selection under both global and typed bounds. */
    public function publication(array $offered, int $minimum, int $maximum): void
    {
        $counts = [];
        foreach ($offered as $row) {
            $kind = $row["kind"] ?? null;
            if (!is_string($kind) || CurriculumComponentKind::tryFrom($kind) === null) {
                $this->invalid(
                    "Choose a component type for every offered reference before publishing.",
                );
            }
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }
        $required = $possible = 0;
        foreach (CurriculumComponentKind::cases() as $kind) {
            $rule = $this->limits[$kind->value] ?? ["minimum" => 0, "maximum" => $maximum];
            $available = $counts[$kind->value] ?? 0;
            if ($rule["minimum"] > $available) {
                $this->invalid(
                    "Offer enough active components of every required type before publishing.",
                );
            }
            $required += $rule["minimum"];
            $possible += min($rule["maximum"], $available);
        }
        if ($required > $maximum || $possible < $minimum) {
            $this->invalid(
                "The component-type limits cannot satisfy the overall program selection policy.",
            );
        }
    }
    public function selection(array $offered, array $ids): void
    {
        $selected = array_fill_keys($ids, true);
        $counts = [];
        foreach ($offered as $row) {
            if (isset($selected[$row["id"]])) {
                $kind = $row["kind"] ?? null;
                if (!is_string($kind) || CurriculumComponentKind::tryFrom($kind) === null) {
                    $this->invalid("A selected component has no retained curriculum type.");
                }
                $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            }
        }
        foreach ($this->limits as $kind => $rule) {
            $count = $counts[$kind] ?? 0;
            if ($count < $rule["minimum"] || $count > $rule["maximum"]) {
                $this->invalid(
                    "Choose " .
                        $rule["minimum"] .
                        "–" .
                        $rule["maximum"] .
                        " " .
                        $kind .
                        " components under this retained program policy.",
                );
            }
        }
    }
    private function invalid(
        string $message = "Use supported component types and whole-number limits from zero through one hundred.",
    ): never {
        throw new ValidationException([["field" => "kind_limits", "message" => $message]]);
    }
}
