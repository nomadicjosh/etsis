<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** One optional required major per concentration/track/specialization. No inferred historical links. */
final class ComponentMajorRules
{
    private int $steps = 0;
    private array $seen = [];
    public const array CHILD_KINDS = ["concentration", "track", "specialization"];

    public function references(array $offered): bool
    {
        $byId = array_column($offered, null, "id");
        $linked = false;
        foreach ($offered as $choice) {
            if (!array_key_exists("parent_major", $choice) || $choice["parent_major"] === null) {
                continue;
            }
            $linked = true;
            $reference = $choice["parent_major"];
            if (
                !is_array($reference) ||
                count($reference) !== 3 ||
                array_diff(array_keys($reference), ["id", "code", "title"]) !== [] ||
                !is_string($reference["id"] ?? null)
            ) {
                $this->invalid("Retain the exact required-major reference.");
            }
            new EntityId($reference["id"]);
            $parent = $byId[$reference["id"]] ?? null;
            if (
                !in_array($choice["kind"] ?? null, self::CHILD_KINDS, true) ||
                $parent === null ||
                ($parent["kind"] ?? null) !== "major" ||
                $choice["id"] === $parent["id"] ||
                $parent["code"] !== $reference["code"] ||
                $parent["title"] !== $reference["title"]
            ) {
                $this->invalid(
                    "Every linked component needs its active offered major in this program publication.",
                );
            }
        }
        return $linked;
    }
    public function selection(array $offered, array $ids): void
    {
        $this->references($offered);
        $selected = array_fill_keys($ids, true);
        foreach ($offered as $choice) {
            if (
                isset($selected[$choice["id"]]) &&
                ($choice["parent_major"] ?? null) !== null &&
                !isset($selected[$choice["parent_major"]["id"]])
            ) {
                $this->invalid(
                    "Select required major " .
                        $choice["parent_major"]["code"] .
                        " together with " .
                        $choice["code"] .
                        ", or remove the linked component.",
                );
            }
        }
    }
    public function publication(array $offered, array $limits, int $minimum, int $maximum): void
    {
        if (!$this->references($offered)) {
            return;
        }
        $rules = $counts = [];
        foreach (CurriculumComponentKind::cases() as $kind) {
            $rules[$kind->value] = $limits[$kind->value] ?? ["minimum" => 0, "maximum" => $maximum];
            $counts[$kind->value] = 0;
        }
        $majors = [];
        foreach ($offered as $choice) {
            if ($choice["kind"] === "major") {
                $majors[$choice["id"]] = [
                    "concentration" => 0,
                    "track" => 0,
                    "specialization" => 0,
                ];
            }
        }
        foreach ($offered as $choice) {
            if ($choice["kind"] === "major") {
                continue;
            }
            if (($choice["parent_major"] ?? null) === null) {
                $counts[$choice["kind"]]++;
            } else {
                $majors[$choice["parent_major"]["id"]][$choice["kind"]]++;
            }
        }
        $this->steps = 0;
        $this->seen = [];
        if (!$this->feasible(array_values($majors), 0, $counts, $rules, $minimum, $maximum)) {
            $this->invalid(
                "Required majors and linked components cannot satisfy the program count/type limits. " .
                    "Review the limits and offered choices.",
            );
        }
    }
    /**
     * Search only major choices; remaining independent per-type quantities have exact interval bounds.
     */
    private function feasible(
        array $majors,
        int $index,
        array $counts,
        array $rules,
        int $minimum,
        int $maximum,
    ): bool {
        if (++$this->steps > 10000) {
            $this->invalid(
                "The linked-major policy exceeds bounded publication verification. " .
                    "Simplify its choices or limits before publishing.",
            );
        }
        $key = $index . ":" . implode(":", $counts);
        if (isset($this->seen[$key])) {
            return false;
        }
        $this->seen[$key] = true;
        if ($counts["major"] > $rules["major"]["maximum"] || $counts["major"] > $maximum) {
            return false;
        }
        $required = $possible = 0;
        $enough = true;
        foreach ($rules as $kind => $rule) {
            $available = $kind === "major" ? $counts[$kind] : min($counts[$kind], $rule["maximum"]);
            $enough = $enough && $available >= $rule["minimum"];
            $required += $kind === "major" ? $counts[$kind] : $rule["minimum"];
            $possible += $available;
        }
        if ($enough && $required <= $maximum && $possible >= $minimum) {
            return true;
        }
        if (
            $index >= count($majors) ||
            $counts["major"] + count($majors) - $index < $rules["major"]["minimum"]
        ) {
            return false;
        }
        $upper = $counts;
        for ($i = $index; $i < count($majors); $i++) {
            $upper["major"]++;
            foreach ($majors[$i] as $kind => $quantity) {
                $upper[$kind] += $quantity;
            }
        }
        $potential = 0;
        foreach ($rules as $kind => $rule) {
            if ($upper[$kind] < $rule["minimum"]) {
                return false;
            }
            $potential += min($upper[$kind], $rule["maximum"]);
        }
        if ($potential < $minimum) {
            return false;
        }
        $next = $counts;
        $next["major"]++;
        foreach ($majors[$index] as $kind => $quantity) {
            $next[$kind] = min($next[$kind] + $quantity, $rules[$kind]["maximum"]);
        }
        return $this->feasible($majors, $index + 1, $next, $rules, $minimum, $maximum) ||
        $this->feasible($majors, $index + 1, $counts, $rules, $minimum, $maximum);
    }
    private function invalid(string $message): never
    {
        throw new ValidationException([["field" => "parent_major_id", "message" => $message]]);
    }
}
