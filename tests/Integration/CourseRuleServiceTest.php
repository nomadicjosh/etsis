<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";

use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Rules\Service\CourseRuleService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\Repository\SqlCourseRuleRepository;
use Infrastructure\Persistence\Repository\SqlSubjectRepository;
use PHPUnit\Framework\TestCase;

final class CourseRuleServiceTest extends TestCase
{
    private function service(
        SisFixture $f,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): CourseRuleService {
        $history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(EventStoreFixture::config($f->connection->queryBuilder())),
            new HistoricalSchemaRegistry(),
        );
        return new CourseRuleService(
            new SqlCourseRuleRepository($f->sql),
            new PublishedCourseReader($history),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock,
        );
    }
    private function tree(): array
    {
        return [
            "condition" => "AND",
            "rules" => [["id" => "course_level_number", "operator" => "greater", "value" => 200]],
        ];
    }
    private function digest(SisFixture $f): string
    {
        $rows = [];
        foreach (
            ["sis_history_events", "sis_history_streams", "sis_history_operations", "sis_rule_definitions"]
            as $table
        ) {
            $rows[$table] = $f->sql->rows($table);
        }
        return hash("sha256", json_encode($rows, JSON_THROW_ON_ERROR));
    }
    public function testSavingAnotherRevisionRetainsTheOriginalAndDoesNotWriteAcademicEvents(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $before = $this->digest($f);
        $first = $service->save(null, null, "da.math.upper", "Fictional upper-level math", $this->tree());
        $second = $service->save(new EntityId($first["rule_id"]), 1, $first["code"], "Revised title", [
            "condition" => "AND",
            "rules" => [["id" => "course_level_number", "operator" => "greater", "value" => 300]],
        ]);
        self::assertSame(1, $first["revision"]);
        self::assertSame(2, $second["revision"]);
        self::assertSame("DA.MATH.UPPER", $first["code"]);
        self::assertSame($first, $service->get(new EntityId($first["id"])));
        self::assertNotSame($first["id"], $second["id"]);
        self::assertSame($second["id"], $service->page()["items"][0]["id"]);
        self::assertSame(
            [2, 1],
            array_column($service->versions(new EntityId($first["rule_id"]))["items"], "revision"),
        );
        self::assertSame($before, $this->digest($f));
    }
    public function testStaleEditAndDuplicateCodeDoNotAppendAnything(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $first = $service->save(null, null, "DA.MATH", "Math", $this->tree());
        $service->save(new EntityId($first["rule_id"]), 1, $first["code"], "Math revised", $this->tree());
        foreach ([true, false] as $duplicate) {
            $count = count($f->sql->rows("sis_course_rules"));
            try {
                $service->save(
                    $duplicate ? null : new EntityId($first["rule_id"]),
                    $duplicate ? null : 1,
                    "DA.MATH",
                    "Math",
                    $this->tree(),
                );
                self::fail("Duplicate or stale course rule was accepted.");
            } catch (ConcurrentModificationException $e) {
                self::assertNull($e->getPrevious());
                self::assertCount($count, $f->sql->rows("sis_course_rules"));
            }
        }
    }
    public function testSavedReferenceLabelsRemainFrozenAfterRenamingTheSubject(): void
    {
        $f = new SisFixture();
        $subjects = new SqlSubjectRepository($f->sql);
        $id = EntityId::generate();
        $subjects->save(new SubjectSnapshot($id, "ENG", "English"), true, true);
        $service = $this->service($f);
        $saved = $service->save(null, null, "DA.ENG", "English", [
            "condition" => "AND",
            "rules" => [["id" => "subject_id", "operator" => "equal", "value" => $id->value]],
        ]);
        $subjects->save(new SubjectSnapshot($id, "LANG", "Languages"), true, false);
        self::assertSame(
            "ENG",
            $service->get(new EntityId($saved["id"]))["definition"]["references"]["subject_id"][$id->value][
                "code"
            ],
        );
    }
    public function testReadsAndWritesRequireBothPermissions(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $saved = $service->save(null, null, "DA.TEST", "Test", $this->tree());
        $f->access->staff = false;
        foreach ([["sis:rules:manage"], ["sis:catalog:manage"], []] as $grants) {
            $f->access->grants = $grants;
            foreach ([
                fn () => $service->get(new EntityId($saved["id"])),
                fn () => $service->save(null, null, "DA.DENIED", "Denied", $this->tree()),
                fn () => $service->preview($this->tree(), []),
            ] as $operation) {
                try {
                    $operation();
                    self::fail("Missing rule/catalog permission was ignored.");
                } catch (AccessDeniedException) {
                    self::assertCount(1, $f->sql->rows("sis_course_rules"));
                }
            }
        }
    }
    public function testRuleAndAuditWritesRollBackTogether(): void
    {
        $f = new SisFixture();
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional audit failure");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $this->service($f, $audit)->save(null, null, "DA.TEST", "Test", $this->tree());
            self::fail();
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows("sis_course_rules"));
        }
    }
    public function testRootAndRevisionPagesRemainBoundedAndSearchIsLiteral(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        for ($i = 0; $i < 21; $i++) {
            $first = $service->save(null, null, "DA.TEST." . $i, "Test", $this->tree());
        }
        $page = $service->page("DA.TEST");
        self::assertCount(20, $page["items"]);
        self::assertNotNull($page["next_after"]);
        self::assertCount(1, $service->page("DA.TEST", new EntityId($page["next_after"]))["items"]);
        self::assertSame([], $service->page("%_")["items"]);
        for ($i = 1; $i <= 21; $i++) {
            $service->save(
                new EntityId($first["rule_id"]),
                $i,
                $first["code"],
                "Revision " . $i,
                $this->tree(),
            );
        }
        $versions = $service->versions(new EntityId($first["rule_id"]));
        self::assertCount(20, $versions["items"]);
        self::assertCount(
            2,
            $service->versions(new EntityId($first["rule_id"]), $versions["next_before"])["items"],
        );
    }
    public function testPreviewUsesTheExactPublishedSubjectAfterCurrentAssignmentsChange(): void
    {
        $catalog = new CatalogWorkflowFixture();
        $f = $catalog->sis;
        $course = EntityId::generate();
        $f->registration->addCourse(
            new \Domain\Catalog\Course($course, "ENG.101", "Fictional English", 3000),
        );
        $subjects = new SqlSubjectRepository($f->sql);
        $eng = EntityId::generate();
        $other = EntityId::generate();
        $subjects->save(new SubjectSnapshot($eng, "ENG", "English"), true, true);
        $subjects->save(new SubjectSnapshot($other, "OTHER", "Other"), true, true);
        $subjects->assign($course, $eng);
        $publication = $catalog->publications();
        $review = $publication->review($course, "Fictional course approval");
        $version = $publication->publish($course, $review["review_token"])["operation_id"];
        $subjects->assign($course, $other);
        $before = $this->digest($f);
        $result = $this->service($f)->preview(
            [
                "condition" => "AND",
                "rules" => [["id" => "subject_id", "operator" => "equal", "value" => $eng->value]],
            ],
            [["course_id" => $course->value, "version_id" => $version]],
        );
        self::assertSame("matches", $result["items"][0]["outcome"]);
        self::assertSame($before, $this->digest($f));
        $unknown = $this->service($f)->preview($this->tree(), [
            ["course_id" => $course->value, "version_id" => $version],
        ]);
        self::assertSame("unknown", $unknown["items"][0]["outcome"]);
    }
}
