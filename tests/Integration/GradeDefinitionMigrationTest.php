<?php

declare(strict_types=1);
require_once dirname(__DIR__) . "/Support/SisFixture.php";
require_once dirname(__DIR__, 2) . "/database/migrations/20261109000002_CreateSisVersionedGrades.php";
use Domain\Gradebook\Event\GradingScaleWasChanged;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\Gradebook\ValueObject\GradingScaleId;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter;
final class GradeDefinitionMigrationTest extends TestCase
{
    private function definition(int $points = 4000): array
    {
        return [
            "grades" => [
                [
                    "code" => "A+",
                    "min_score_milli" => 97000,
                    "max_score_milli" => 100000,
                    "points_milli" => $points,
                    "passes" => true,
                    "counts_gpa" => true,
                ],
            ],
        ];
    }
    private function recorded(SisFixture $f, int $position, ?EntityId $previous = null): array
    {
        $version = EntityId::generate();
        $definition = $this->definition($position === 1 ? 4000 : 5000);
        $stream = new StreamReference(new GradingScaleId(GradingScaleService::GLOBAL_ID), "grading_scale");
        $history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
            new HistoricalSchemaRegistry(),
        );
        $data = [
            "kind" => "grading-scale-review.v1",
            "program_id" => null,
            "actor_id" => $f->access->actor->value,
            "operation_id" => $version->value,
            "expected_version" => $position - 1,
            "previous_version_id" => $previous?->value,
            "definition" => $definition,
            "reason" => "Fictional earlier approved grading policy",
            "effective_at" => $f->clock->now()->format(DATE_ATOM),
        ];
        $event = GradingScaleWasChanged::occur(
            $stream->aggregateId,
            [
                "version_id" => $version->value,
                "previous_version_id" => $previous?->value,
                "definition" => $definition,
            ],
            ["__aggregate_type" => "grading_scale"],
        )->withPlayhead($position);
        $receipt = $history->append(
            $stream,
            $version,
            $position - 1,
            new EventContext($f->access->actor, $f->clock->now(), $version, reason: $data["reason"]),
            [$event],
        );
        $values = [
            "version_id" => $version->value,
            "position" => $position,
            "definition_json" => json_encode($definition, JSON_THROW_ON_ERROR),
        ];
        if ($position === 1) {
            $f->sql->insert(
                "sis_grading_scales",
                ["id" => GradingScaleService::GLOBAL_ID, "scope_key" => "global", "program_id" => null] +
                    $values,
            );
        } else {
            $f->sql->update("sis_grading_scales", $values, ["scope_key" => "global"]);
        }
        return [
            "id" => $version,
            "receipt" => $receipt,
            "token" => $f->dropCipher->seal(json_encode($data, JSON_THROW_ON_ERROR)),
        ];
    }
    private function migrate(SisFixture $f, bool $withKey = true): void
    {
        $m = new CreateSisVersionedGrades("grades");
        $map = ["phpmig.adapter" => new DbalMigrationAdapter($f->connection, "test_migrations")];
        if ($withKey) {
            $map["sis.history_codec"] = new ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            );
        }
        $m->setObjectMap(new ArrayObject($map));
        $m->up();
    }
    public function testExistingGradeIdsAndReferringAcademicRecordsSurviveSqliteCutover(): void
    {
        $f = new SisFixture(versionedGrades: false);
        $f->connection->pdo->exec("PRAGMA foreign_keys = ON");
        $grade = new \Domain\Gradebook\GradeDefinition(EntityId::generate(), "OLD", 3700, true, true);
        $f->legacyGrade($grade);
        $student = $f->student();
        $section = $f->section();
        $e = $f->service->register($student->id, $section->id);
        $f->sql->update(
            "sis_academic_credits",
            ["grade_id" => $grade->id->value, "grade_points_milli" => 11100, "completed_milli" => 3000],
            ["source_id" => $e->id->value],
        );
        $credit = $f->sql->one("sis_academic_credits", ["source_id" => $e->id->value]);
        $events = $f->sql->rows("sis_history_events");
        $this->migrate($f);
        self::assertSame($credit, $f->sql->one("sis_academic_credits", ["source_id" => $e->id->value]));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame($grade->id->value, $f->sql->one("sis_grades", ["code" => "OLD"])["id"]);
        self::assertNull($f->sql->one("sis_grades", ["code" => "OLD"])["scale_version_id"]);
        self::assertSame("default", $f->gradingScales()->resolve()["source"]);
        self::assertSame(1, (int) $f->connection->pdo->query("PRAGMA foreign_keys")->fetchColumn());
        self::assertSame([], $f->connection->pdo->query("PRAGMA foreign_key_check")->fetchAll());
    }
    public function testRealRecordedPoliciesReconstructAllVersionsAndAcceptedReceiptsWithoutRewritingHistory(): void
    {
        $f = new SisFixture(versionedGrades: false);
        $first = $this->recorded($f, 1);
        $second = $this->recorded($f, 2, $first["id"]);
        $events = $f->sql->rows("sis_history_events");
        $this->migrate($f);
        self::assertCount(2, $f->sql->rows("sis_grades", ["code" => "A+"]));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame(5000, $f->gradingScales()->resolve()["maximum_milli"]);
        self::assertSame($first["receipt"], $f->gradingScales()->commit(null, $first["token"]));
        self::assertSame($second["receipt"], $f->gradingScales()->commit(null, $second["token"]));
        $old = $f->gradingScales()->publication(null, $first["id"]);
        self::assertSame(4000, $old["grades"][0]["points_milli"]);
        self::assertSame(PublishedGradeId::forVersion($first["id"], "A+")->value, $old["grades"][0]["id"]);
        self::assertArrayNotHasKey(
            "definition_json",
            $f->sql->one("sis_grading_scales", ["scope_key" => "global"]),
        );
        $this->migrate($f);
        self::assertCount(2, $f->sql->rows("sis_grades"));
        self::assertSame($events, $f->sql->rows("sis_history_events"));
    }
    public function testChangedJsonProjectionStopsCutoverAndCanResumeAfterExplicitRepair(): void
    {
        $f = new SisFixture(versionedGrades: false);
        $r = $this->recorded($f, 1);
        $events = $f->sql->rows("sis_history_events");
        $f->sql->update(
            "sis_grading_scales",
            ["definition_json" => json_encode($this->definition(9000), JSON_THROW_ON_ERROR)],
            ["scope_key" => "global"],
        );
        try {
            $this->migrate($f);
            self::fail("Changed projection was accepted.");
        } catch (CorruptHistoryException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertSame([], $f->sql->rows("sis_grades"));
            self::assertArrayHasKey(
                "definition_json",
                $f->sql->one("sis_grading_scales", ["scope_key" => "global"]),
            );
        }
        $f->sql->update(
            "sis_grading_scales",
            ["definition_json" => json_encode($this->definition(), JSON_THROW_ON_ERROR)],
            ["scope_key" => "global"],
        );
        $this->migrate($f);
        self::assertSame($r["receipt"], $f->gradingScales()->commit(null, $r["token"]));
    }
    public function testMissingDecryptionConfigurationNeverDiscardsRecordedProjection(): void
    {
        $f = new SisFixture(versionedGrades: false);
        $this->recorded($f, 1);
        $events = $f->sql->rows("sis_history_events");
        try {
            $this->migrate($f, false);
            self::fail("History verified without configured keys.");
        } catch (LogicException) {
            self::assertSame($events, $f->sql->rows("sis_history_events"));
            self::assertArrayHasKey(
                "definition_json",
                $f->sql->one("sis_grading_scales", ["scope_key" => "global"]),
            );
        }
        $this->migrate($f);
        self::assertCount(1, $f->sql->rows("sis_grades"));
    }
    public function testCustomSqliteSchemaIsNotSilentlyDiscardedAndForeignKeyModeIsPreserved(): void
    {
        $f = new SisFixture(versionedGrades: false);
        $f->connection->pdo->exec("PRAGMA foreign_keys = ON");
        $f->connection->pdo->exec("CREATE INDEX custom_grade_index ON sis_grades (points_milli)");
        $this->expectException(LogicException::class);
        try {
            $this->migrate($f);
        } finally {
            self::assertSame(1, (int) $f->connection->pdo->query("PRAGMA foreign_keys")->fetchColumn());
        }
    }
}
