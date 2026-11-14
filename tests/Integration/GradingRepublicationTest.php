<?php

declare(strict_types=1);

namespace Tests\Integration;

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";

use Domain\AcademicRecord\ValueObject\GradeSnapshot;
use Domain\DegreeAudit\Service\GradingRepublicationVerifier;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\Gradebook\Service\RetainedGradingReader;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use ReusableGroupFixture;

final class GradingRepublicationTest extends TestCase
{
    private function definition(): array
    {
        return [
            [
                "code" => "A",
                "min_score_milli" => 90000,
                "max_score_milli" => 100000,
                "points_milli" => 4000,
                "passes" => true,
                "counts_gpa" => true,
            ],
            [
                "code" => "F",
                "min_score_milli" => 0,
                "max_score_milli" => 89999,
                "points_milli" => 0,
                "passes" => false,
                "counts_gpa" => true,
            ],
        ];
    }

    private function work(ReusableGroupFixture $f, string $version): array
    {
        $grade = $f->sis->sql->one("sis_grades", ["scale_version_id" => $version, "code" => "A"]);
        return [
            "id" => EntityId::generate()->value,
            "grade" => GradeSnapshot::fromRow($grade)->facts,
            "status" => "A",
            "credit_type" => "institutional",
            "attempted_milli" => 3000,
        ];
    }

    private function requirements(ReusableGroupFixture $f, array $snapshot): array
    {
        return ["definition" => $f->definition, "grade_scale" => $snapshot];
    }

    public function testBothDirectionsVerifyExactDefinitionsOnceAndPreserveAllRecordedFacts(): void
    {
        $f = new ReusableGroupFixture();
        $f->sis->gradeScale($this->definition());
        $first = $f->gradingScales()->resolve();
        $f->sis->gradeScale(array_reverse($this->definition()));
        $second = $f->gradingScales()->resolve();
        $reader = new RetainedGradingReader($f->history);
        $verifier = new GradingRepublicationVerifier($reader);
        $before = $f->digest("sis_history_events");
        foreach ([[$first, $second], [$second, $first]] as [$primary, $award]) {
            $work = [$this->work($f, $award["version_id"])];
            $captured = serialize($work);
            $r = $verifier->prepare(
                $work,
                $this->requirements($f, $primary),
                new HistoryReadBudget(maximumPages: 1),
            );
            self::assertSame([$award["version_id"] => true], $r["identical_grading_publications"]);
            self::assertSame($captured, serialize($work));
            self::assertSame($primary, $r["grade_scale"]);
        }
        $definitions = $reader->selected(
            new EntityId(GradingScaleService::GLOBAL_ID),
            [new EntityId($first["version_id"]), new EntityId($second["version_id"])],
            new HistoryReadBudget(maximumPages: 1),
        );
        self::assertTrue($definitions[$first["version_id"]]->samePolicy($definitions[$second["version_id"]]));
        self::assertSame($before, $f->digest("sis_history_events"));
    }

    public function testEqualMaximumChangedMeaningDifferentScaleAndUnknownProvenanceNeverBecomeCompatible(): void
    {
        $f = new ReusableGroupFixture();
        $f->sis->gradeScale($this->definition());
        $primary = $f->gradingScales()->resolve();
        $changed = $this->definition();
        $changed[0]["min_score_milli"] = 91000;
        $f->sis->gradeScale($changed);
        $later = $f->gradingScales()->resolve();
        $verifier = new GradingRepublicationVerifier(new RetainedGradingReader($f->history));
        $req = $this->requirements($f, $primary);
        $r = $verifier->prepare([$this->work($f, $later["version_id"])], $req, new HistoryReadBudget());
        self::assertSame([], $r["identical_grading_publications"]);
        $f->sis->gradeScale($this->definition(), $f->sis->program);
        $program = $f->gradingScales()->resolve($f->sis->program);
        $work = $this->work($f, $program["version_id"]);
        $req["identical_grading_publications"] = [$later["version_id"] => true];
        $r = $verifier->prepare([$work], $req, new HistoryReadBudget(maximumPages: 1));
        self::assertArrayNotHasKey("identical_grading_publications", $r);
        $work["grade"]["scale_id"] = $work["grade"]["scale_version_id"] = null;
        $r = $verifier->prepare([$work], $req, new HistoryReadBudget());
        self::assertArrayNotHasKey("identical_grading_publications", $r);
    }

    public function testTransplantedSnapshotOrGradeFactsCannotReuseVerifiedPublicationIdentity(): void
    {
        $f = new ReusableGroupFixture();
        $f->sis->gradeScale($this->definition());
        $primary = $f->gradingScales()->resolve();
        $f->sis->gradeScale($this->definition());
        $later = $f->gradingScales()->resolve();
        $verifier = new GradingRepublicationVerifier(new RetainedGradingReader($f->history));
        $work = $this->work($f, $later["version_id"]);
        $wrong = $work;
        $wrong["grade"]["points_milli"] = 3000;
        $wrongSnapshot = $primary;
        $wrongSnapshot["definition"]["grades"][0]["min_score_milli"] = 91000;
        foreach ([[$wrong, $primary], [$work, $wrongSnapshot]] as [$item, $snapshot]) {
            try {
                $verifier->prepare([$item], $this->requirements($f, $snapshot), new HistoryReadBudget());
                self::fail("A transplanted grading fact was trusted.");
            } catch (CorruptHistoryException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMissingPublicationsAndReplayLimitsReturnNoPartialProof(): void
    {
        $f = new ReusableGroupFixture();
        $f->sis->gradeScale($this->definition());
        $first = $f->gradingScales()->resolve();
        $f->sis->gradeScale($this->definition());
        $second = $f->gradingScales()->resolve();
        $reader = new RetainedGradingReader($f->history);
        try {
            $reader->selected(
                new EntityId(GradingScaleService::GLOBAL_ID),
                [new EntityId($first["version_id"]), new EntityId($second["version_id"])],
                new HistoryReadBudget(maximumEvents: 1),
            );
            self::fail("Partial verification returned definitions.");
        } catch (HistoryReadLimitException) {
            self::assertTrue(true);
        }
        $this->expectException(RecordNotFoundException::class);
        $reader->selected(
            new EntityId(GradingScaleService::GLOBAL_ID),
            [EntityId::generate()],
            new HistoryReadBudget(),
        );
    }
}
