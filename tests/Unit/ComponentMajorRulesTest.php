<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Catalog\ValueObject\ComponentMajorRules;
use Domain\Catalog\ValueObject\ComponentSelectionRules;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class ComponentMajorRulesTest extends TestCase
{
    private function choice(int $id, string $kind, ?array $parent = null): array
    {
        return [
            "id" => str_pad((string) $id, 26, "0", STR_PAD_LEFT),
            "code" => "C" . $id,
            "title" => "Fictional component " . $id,
            "kind" => $kind,
            "parent_major" =>
                $parent === null
                    ? null
                    : array_intersect_key($parent, array_flip(["id", "code", "title"])),
        ];
    }
    private function choices(): array
    {
        $a = $this->choice(1, "major");
        $b = $this->choice(2, "major");
        return [
            $a,
            $b,
            $this->choice(3, "concentration", $a),
            $this->choice(4, "concentration", $b),
            $this->choice(5, "minor"),
            $this->choice(6, "specialization", $a),
        ];
    }
    public function testFeasibilityMatchesIndependentExhaustiveSelections(): void
    {
        $offered = $this->choices();
        for ($seed = 0; $seed < 45; $seed++) {
            $minimum = $seed % 6;
            $maximum = min(6, $minimum + ($seed % 3));
            $majorMinimum = $seed % 3;
            $limits = [
                "major" => [
                    "minimum" => $majorMinimum,
                    "maximum" => min(2, $majorMinimum + (intdiv($seed, 3) % 3)),
                ],
                "concentration" => ["minimum" => intdiv($seed, 2) % 3, "maximum" => 2],
                "minor" => ["minimum" => intdiv($seed, 4) % 2, "maximum" => 1],
            ];
            $possible = false;
            for ($mask = 0; $mask < 64; $mask++) {
                $selected = array_values(
                    array_filter(
                        $offered,
                        static fn(array $r, int $index): bool => ($mask & (1 << $index)) !== 0,
                        ARRAY_FILTER_USE_BOTH,
                    ),
                );
                if (count($selected) < $minimum || count($selected) > $maximum) {
                    continue;
                }
                $counts = array_count_values(array_column($selected, "kind"));
                $ids = array_column($selected, "id");
                $valid = true;
                foreach ($limits as $kind => $rule) {
                    $count = $counts[$kind] ?? 0;
                    $valid = $valid && $count >= $rule["minimum"] && $count <= $rule["maximum"];
                }
                foreach ($selected as $r) {
                    if (
                        $r["parent_major"] !== null &&
                        !in_array($r["parent_major"]["id"], $ids, true)
                    ) {
                        $valid = false;
                    }
                }
                $possible = $possible || $valid;
            }
            try {
                new ComponentSelectionRules($limits)->publication($offered, $minimum, $maximum);
                $actual = true;
            } catch (ValidationException) {
                $actual = false;
            }
            self::assertSame($possible, $actual, "Policy " . $seed);
        }
    }
    public function testRemovingARequiredMajorCannotLeaveItsConcentrationSelected(): void
    {
        $offered = $this->choices();
        new ComponentMajorRules()->selection($offered, [$offered[0]["id"], $offered[2]["id"]]);
        $this->expectException(ValidationException::class);
        new ComponentMajorRules()->selection($offered, [$offered[1]["id"], $offered[2]["id"]]);
    }
    public function testMissingForeignOrRelabeledParentReferencesAreRejected(): void
    {
        foreach (["foreign", "label", "kind"] as $case) {
            $offered = $this->choices();
            if ($case === "foreign") {
                $offered[2]["parent_major"]["id"] = str_repeat("0", 25) . "9";
            }
            if ($case === "label") {
                $offered[2]["parent_major"]["title"] = "A guessed title";
            }
            if ($case === "kind") {
                $offered[2]["kind"] = "minor";
            }
            try {
                new ComponentMajorRules()->references($offered);
                self::fail("An invalid parent reference passed.");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }
    public function testOneHundredOfferedChoicesRemainBoundedAndCanMeetLinkedCounts(): void
    {
        $offered = [];
        for ($i = 1; $i <= 20; $i++) {
            $offered[] = $this->choice($i, "major");
        }
        for ($i = 21; $i <= 100; $i++) {
            $offered[] = $this->choice($i, "concentration", $offered[($i - 21) % 20]);
        }
        new ComponentSelectionRules([
            "major" => ["minimum" => 1, "maximum" => 1],
            "concentration" => ["minimum" => 4, "maximum" => 4],
        ])->publication($offered, 5, 5);
        self::assertCount(100, $offered);
    }
}
