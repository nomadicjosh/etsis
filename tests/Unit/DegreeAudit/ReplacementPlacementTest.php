<?php

declare(strict_types=1);

namespace Tests\Unit\DegreeAudit;

use Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted;
use Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked;
use Domain\DegreeAudit\RequirementAdjustments;
use Domain\DegreeAudit\ValueObject\RequirementAdjustmentDefinitionV2;
use Domain\DegreeAudit\ValueObject\RequirementAdjustmentsId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use PHPUnit\Framework\TestCase;

final class ReplacementPlacementTest extends TestCase
{
    private static function id(int $n): string
    {
        return "01A" . str_pad((string) $n, 23, "0", STR_PAD_LEFT);
    }

    private function payload(): array
    {
        return [
            "adjustment_id" => self::id(2),
            "supersedes_adjustment_id" => null,
            "student_id" => self::id(3),
            "program_id" => self::id(4),
            "requirement_decision_id" => self::id(5),
            "template_id" => self::id(6),
            "requirements_version_id" => self::id(7),
            "definition" => [
                "kind" => "substitution",
                "course_id" => self::id(8),
                "replacement_course_id" => self::id(9),
                "replacement_version_id" => self::id(10),
            ],
        ];
    }

    private function preference(): array
    {
        return array_replace($this->payload(), [
            "adjustment_id" => self::id(11),
            "definition" => [
                "kind" => "placement",
                "course_id" => self::id(9),
                "course_version_id" => self::id(10),
                "group_id" => self::id(12),
                "group_version_id" => self::id(13),
                "substitution_adjustment_id" => self::id(2),
                "required_course_id" => self::id(8),
            ],
        ]);
    }

    public function testReplayRequiresTheExactActiveSourceAtThePlacementDecision(): void
    {
        $preference = $this->preference();
        $cases = [$preference];
        $keys = ["course_id", "course_version_id", "required_course_id", "substitution_adjustment_id"];
        foreach ($keys as $key) {
            $p = $preference;
            $p["definition"][$key] = self::id(20);
            $cases[] = $p;
        }
        foreach (["template_id", "requirements_version_id"] as $key) {
            $cases[] = array_replace($preference, [$key => self::id(20)]);
        }
        foreach ($cases as $index => $p) {
            $root = RequirementAdjustments::root(
                new RequirementAdjustmentsId(self::id(1)),
            );
            $version = 1;
            if ($index !== 0) {
                $root->acceptHistorical(
                    RequirementAdjustmentWasGranted::occur(
                        $root->aggregateId(),
                        $this->payload(),
                    )->withPlayhead($version++),
                );
            }
            try {
                $root->acceptHistorical(
                    RequirementAdjustmentWasGranted::occur(
                        $root->aggregateId(),
                        $p,
                    )->withPlayhead($version),
                );
                self::fail(
                    "Invalid source dependency entered replayed placement state.",
                );
            } catch (CorruptHistoryException) {
                self::assertCount($index === 0 ? 0 : 1, $root->active());
            }
        }
    }

    public function testOriginalSourceAndIndependentPreferenceRemainDistinctDuringReplay(): void
    {
        $root = RequirementAdjustments::root(
            new RequirementAdjustmentsId(self::id(1)),
        );
        $root->acceptHistorical(
            RequirementAdjustmentWasGranted::occur(
                $root->aggregateId(),
                $this->payload(),
            )->withPlayhead(1),
        );
        $p = $this->preference();
        $definition = new RequirementAdjustmentDefinitionV2($p["definition"]);
        self::assertSame("placement:" . self::id(9), $definition->key());
        $root->acceptHistorical(
            RequirementAdjustmentWasGranted::occur(
                $root->aggregateId(),
                $p,
            )->withPlayhead(2),
        );
        $root->acceptHistorical(
            RequirementAdjustmentWasRevoked::occur($root->aggregateId(), [
                "adjustment_id" => self::id(2),
                "decision_id" => self::id(14),
                "student_id" => self::id(3),
                "program_id" => self::id(4),
            ])->withPlayhead(3),
        );
        self::assertSame([self::id(11) => $p], $root->active());
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical(
            RequirementAdjustmentWasGranted::occur(
                $root->aggregateId(),
                array_replace($p, [
                    "adjustment_id" => self::id(15),
                    "supersedes_adjustment_id" => self::id(11),
                ]),
            )->withPlayhead(4),
        );
    }

    public function testPartialOrMalformedSourceReferencesAreRejected(): void
    {
        $d = $this->preference()["definition"];
        $cases = [
            array_diff_key($d, ["required_course_id" => true]),
            array_diff_key($d, ["substitution_adjustment_id" => true]),
            array_replace($d, ["required_course_id" => null]),
            array_replace($d, ["substitution_adjustment_id" => "invalid"]),
            array_replace($d, ["required_course_id" => $d["course_id"]]),
        ];
        foreach ($cases as $invalid) {
            try {
                new RequirementAdjustmentDefinitionV2($invalid);
                self::fail("Malformed source references accepted.");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
