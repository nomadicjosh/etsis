<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AuthoringAdvice;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Registration\Service\SectionAssistanceService;
use Domain\Registration\Service\SectionPublicationService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use Infrastructure\Persistence\Repository\SqlSectionVersionRepository;
use PHPUnit\Framework\TestCase;

final class SectionAssistanceTest extends TestCase
{
    private SisFixture $f;
    private EntityId $section;
    private AuthoringAssistant $assistant;
    private SectionAssistanceService $service;
    private SectionPublicationService $publications;
    private \Infrastructure\EventSourcing\SqlHistoricalEventStore $history;
    protected function setUp(): void
    {
        $f = $this->f = new SisFixture();
        $this->section = $f->section()->id;
        $this->assistant = new class implements AuthoringAssistant {
            public array $calls = [];
            public string $fingerprint = "fictional-provider";
            public ?Closure $duringCall = null;
            public function availability(): array
            {
                return [
                    "enabled" => true,
                    "label" => "Fictional provider",
                    "fingerprint" => $this->fingerprint,
                ];
            }
            public function suggest(AuthoringTask $task, array $draft): AuthoringAdvice
            {
                $this->calls[] = [$task, $draft];
                if ($this->duringCall) {
                    ($this->duringCall)();
                }
                return new AuthoringAdvice("Verify saved setup.", ["Check delivery mode."]);
            }
        };
        $history = $this->history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        $this->publications = new SectionPublicationService(
            new SqlCatalogRevisionRepository($f->sql),
            new SqlCatalogWorkflowRepository($f->sql),
            new SqlSectionCourseBindingRepository($f->sql),
            $f->coursePublications(),
            $history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new SqlSectionVersionRepository($f->sql),
        );
        $this->service = new SectionAssistanceService(
            $this->publications,
            $this->assistant,
            $f->access,
            $f->audit,
        );
    }
    public function testPreviewIsMinimizedAndProviderRunsOutsideTransactionsWithoutWritesToAcademicFacts(): void
    {
        $before = $this->f->sql->rows("sis_sections");
        $events = $this->f->sql->rows("sis_history_events");
        $preview = $this->service->preview($this->section);
        self::assertSame([], $this->assistant->calls);
        self::assertSame(
            [
                "section_code",
                "capacity",
                "delivery_mode",
                "teaching_assignment_present",
                "term",
                "retained_course",
            ],
            array_keys($preview["draft"]),
        );
        self::assertNull($preview["draft"]["retained_course"]);
        foreach (
            ["fee_minor", "faculty_user_id", "faculty_person_id", "reason", $this->section->value]
            as $private
        ) {
            self::assertStringNotContainsString($private, json_encode($preview["draft"]));
        }
        $this->assistant->duringCall = function (): void {
            self::assertFalse($this->f->connection->pdo->inTransaction());
        };
        $result = $this->service->suggest($this->section, true, $preview["revision"]);
        self::assertSame("Verify saved setup.", $result["advice"]["summary"]);
        self::assertSame(AuthoringTask::SectionReadiness, $this->assistant->calls[0][0]);
        self::assertSame($preview["draft"], $this->assistant->calls[0][1]);
        self::assertSame($before, $this->f->sql->rows("sis_sections"));
        self::assertSame($events, $this->f->sql->rows("sis_history_events"));
        self::assertCount(1, $this->f->audit->forSubject($this->section));
    }
    public function testBoundCourseUsesRetainedTextAndExcludesIdentityReferences(): void
    {
        $f = $this->f;
        $row = $f->sql->one("sis_sections", ["id" => $this->section->value]);
        $course = new EntityId($row["course_id"]);
        $version = $f->publishCourse($course);
        $binding = new \Domain\Registration\Service\SectionCourseBindingService(
            new SqlCatalogRevisionRepository($f->sql), $f->coursePublications(),
            new SqlSectionCourseBindingRepository($f->sql), $this->history,
            $f->access, $f->transactions, $f->audit, $f->dropCipher, $f->clock,
        );
        $proof = $binding->review($this->section, $version, "Fictional binding for assistance test.");
        $binding->commit($this->section, $proof["review_token"]);
        $retained = $this->service->preview($this->section)["draft"]["retained_course"];
        $f->sql->update("sis_courses", ["title" => "New unpublished title"], ["id" => $course->value]);
        self::assertSame($retained, $this->service->preview($this->section)["draft"]["retained_course"]);
        self::assertSame("Computing", $retained["title"]);
        self::assertStringNotContainsString($course->value, json_encode($retained));
        self::assertStringNotContainsString($version->value, json_encode($retained));
    }
    public function testCatalogPermissionDoesNotGrantSectionAssistance(): void
    {
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:catalog:manage", "sis:ai:course:readiness"];
        $this->expectException(AccessDeniedException::class);
        $this->service->preview($this->section);
    }
    public function testConsentIsRequiredBeforeCallingProvider(): void
    {
        $preview = $this->service->preview($this->section);
        try {
            $this->service->suggest($this->section, false, $preview["revision"]);
            self::fail();
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testProviderChangeRequiresNewPreview(): void
    {
        $preview = $this->service->preview($this->section);
        $this->assistant->fingerprint = "replacement-provider";
        try {
            $this->service->suggest($this->section, true, $preview["revision"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testSectionChangePreventsSendingStaleFacts(): void
    {
        $preview = $this->service->preview($this->section);
        $this->f->sql->update("sis_sections", ["capacity" => 30], ["id" => $this->section->value]);
        try {
            $this->service->suggest($this->section, true, $preview["revision"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testSectionChangeDuringCallDiscardsResults(): void
    {
        $preview = $this->service->preview($this->section);
        $this->assistant->duringCall = function (): void {
            $this->f->sql->update("sis_sections", ["capacity" => 30], ["id" => $this->section->value]);
        };
        $this->expectException(ConcurrentModificationException::class);
        $this->service->suggest($this->section, true, $preview["revision"]);
    }
    public function testPermissionRevocationDuringCallDiscardsResults(): void
    {
        $preview = $this->service->preview($this->section);
        $this->assistant->duringCall = function (): void {
            $this->f->access->staff = false;
            $this->f->access->grants = ["sis:catalog:manage"];
        };
        $this->expectException(AccessDeniedException::class);
        $this->service->suggest($this->section, true, $preview["revision"]);
    }
}
