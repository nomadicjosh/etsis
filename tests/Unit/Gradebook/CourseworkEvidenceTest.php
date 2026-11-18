<?php

declare(strict_types=1);

namespace Tests\Unit\Gradebook;

use Brick\Math\BigRational;
use Brick\Math\Internal\Calculator\NativeCalculator;
use Brick\Math\Internal\CalculatorRegistry;
use Domain\Gradebook\ValueObject\CourseworkGradeEvidence;
use Domain\Gradebook\ValueObject\GradebookCalculation;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CourseworkEvidenceTest extends TestCase
{
    private function facts(): array
    {
        return [
            "student_id" => EntityId::generate()->value,
            "section_id" => EntityId::generate()->value,
            "mode" => "points",
            "weights" => [],
            "categories" => [],
            "calculation_version" => 0,
            "scores" => [[EntityId::generate()->value, 10000, 0, 9200, false, 1]],
            "grade_id" => EntityId::generate()->value,
            "min_score_milli" => 80000,
            "max_score_milli" => 92999,
            "exact_percent" => "92",
        ];
    }

    public function testCanonicalEvidenceSurvivesEnvelopeOrderingAndCanBeRecalculatedExplicitly(): void
    {
        $facts = $this->facts();
        $evidence = new CourseworkGradeEvidence($facts);
        $evidence->verifyCalculation();
        self::assertSame(
            $evidence->revision(),
            new CourseworkGradeEvidence(array_reverse($facts, true))->revision(),
        );
        self::assertSame("92%", $evidence->summary()["score_display"]);
        self::assertSame("≈ 33.333333%", CourseworkGradeEvidence::formatPercent(BigRational::ofFraction(100, 3)));
        self::assertSame("0%", CourseworkGradeEvidence::formatPercent(BigRational::zero()));
        $facts["scores"][0][3] = 9100;
        $changed = new CourseworkGradeEvidence($facts);
        self::assertNotSame($evidence->revision(), $changed->revision());
        $this->expectException(InvalidArgumentException::class);
        $changed->verifyCalculation();
    }

    public static function invalidFacts(): array
    {
        return [
            ["extra"],
            ["duplicate"],
            ["limit"],
            ["earned"],
            ["version"],
            ["scope"],
            ["range"],
            ["fraction"],
            ["weights"],
            ["size"],
            ["calculation_version"],
        ];
    }

    #[DataProvider("invalidFacts")]
    public function testMalformedOrUnboundedAcceptedInputsAreRejected(string $kind): void
    {
        $facts = $this->facts();
        switch ($kind) {
            case "extra":
                $facts["unrecognized"] = true;
                break;
            case "duplicate":
                $facts["scores"][] = $facts["scores"][0];
                break;
            case "limit":
                $facts["scores"][0][1] = 0;
                break;
            case "earned":
                $facts["scores"][0][3] = "9200";
                break;
            case "version":
                $facts["scores"][0][5] = -1;
                break;
            case "scope":
                $facts["student_id"] = "wrong-student";
                break;
            case "range":
                $facts["min_score_milli"] = 93000;
                break;
            case "fraction":
                $facts["exact_percent"] = "184/2";
                break;
            case "weights":
                $facts["mode"] = "weighted";
                $facts["weights"] = [EntityId::generate()->value => 10000];
                break;
            case "size":
                $facts["exact_percent"] = str_repeat("1", CourseworkGradeEvidence::MAX_BYTES);
                break;
            case "calculation_version":
                $facts["calculation_version"] = true;
                break;
        }
        try {
            new CourseworkGradeEvidence($facts);
            self::fail("Malformed coursework evidence was accepted.");
        } catch (InvalidArgumentException | ValidationException) {
            self::assertTrue(true);
        }
    }

    public function testFiveHundredWeightedAssignmentsHaveAPortableExactCalculation(): void
    {
        $previous = CalculatorRegistry::get();
        CalculatorRegistry::set(new NativeCalculator());
        try {
            $scores = [];
            $weights = [];
            for ($i = 0; $i < GradebookCalculation::LIMIT; ++$i) {
                $id = EntityId::generate()->value;
                $weights[$id] = 20;
                $scores[] = [
                    "assignment_id" => $id,
                    "possible_hundredths" => 10000 + ($i % 10),
                    "earned_hundredths" => 7000 + $i,
                ];
            }
            $result = new GradebookCalculation("weighted", $weights)->summarize($scores, true);
            $score = BigRational::of($result["exact_percent"]);
            self::assertGreaterThan(0, $score->compareTo(70));
            self::assertLessThan(0, $score->compareTo(75));
            self::assertSame(10000, $result["coverage_hundredths"]);
            self::assertSame(0, $result["pending"]);
        } finally {
            CalculatorRegistry::set($previous);
        }
    }

    public function testHighlyVariedWeightsKeepFastFeedbackButBoundExactReviewWork(): void
    {
        $scores = [];
        $weights = [];
        for ($i = 0; $i < 500; ++$i) {
            $id = EntityId::generate()->value;
            $weights[$id] = 20;
            $scores[] = [
                "assignment_id" => $id,
                "possible_hundredths" => 10000 + $i,
                "earned_hundredths" => 7000 + $i,
            ];
        }
        $calculation = new GradebookCalculation("weighted", $weights);
        self::assertNotNull($calculation->summarize($scores)["percent_hundredths"]);
        $this->expectException(\Domain\Gradebook\Exception\CourseworkCalculationLimitException::class);
        $calculation->summarize($scores, true);
    }
}
