<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AuthoringAdvice;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Catalog\Course;
use Domain\Catalog\Service\CourseAssistanceService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class FictionalCourseAssistant implements AuthoringAssistant
{
    public bool $enabled = true;
    public string $fingerprint = "fictional-provider";
    public array $calls = [];
    public ?Closure $duringCall = null;
    public function availability(): array
    {
        return [
            "enabled" => $this->enabled,
            "label" => "Fictional provider",
            "fingerprint" => $this->fingerprint,
        ];
    }
    public function suggest(AuthoringTask $task, array $draft): AuthoringAdvice
    {
        $this->calls[] = [$task, $draft];
        if ($this->duringCall !== null) {
            ($this->duringCall)();
        }
        return new AuthoringAdvice(
            "Verify this draft.",
            ["Check prerequisites."],
            $task === AuthoringTask::Readiness ? null : "A clearer fictional description.",
        );
    }
}
final class CourseAssistanceTest extends TestCase
{
    private SisFixture $f;
    private EntityId $course;
    private FictionalCourseAssistant $assistant;
    private CourseAssistanceService $service;
    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->course = EntityId::generate();
        $this->f->registration->addCourse(new Course($this->course, "MATH.101", "Algebra", 3000));
        $this->assistant = new FictionalCourseAssistant();
        $this->service = new CourseAssistanceService(
            $this->f->coursePublications(),
            $this->assistant,
            $this->f->access,
            $this->f->audit,
        );
    }
    public function testRewriteAndExpandRequireSavedTextAndIndependentActionGrants(): void
    {
        foreach ([AuthoringTask::Rewrite, AuthoringTask::Expand] as $task) {
            $preview = $this->service->preview($this->course);
            self::assertNotContains($task->value, $preview["tasks"]);
            try {
                $this->service->suggest($this->course, $task, true, $preview["revision"]);
                self::fail("An empty description was sent.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->assistant->calls);
            }
        }
        $this->f->sql->insert("sis_course_descriptions", [
            "id" => EntityId::generate()->value,
            "course_id" => $this->course->value,
            "description" => "An introduction to algebra.",
        ]);
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:catalog:manage", "sis:ai:course:description"];
        $preview = $this->service->preview($this->course);
        self::assertSame(["description"], $preview["tasks"]);
        foreach ([AuthoringTask::Rewrite, AuthoringTask::Expand] as $task) {
            try {
                $this->service->suggest($this->course, $task, true, $preview["revision"]);
                self::fail("Description generation incorrectly granted another action.");
            } catch (AccessDeniedException) {
                self::assertSame([], $this->assistant->calls);
            }
            $this->f->access->grants = ["sis:catalog:manage", "sis:ai:course:" . $task->value];
            $preview = $this->service->preview($this->course);
            self::assertSame([$task->value], $preview["tasks"]);
            $before = $this->f->coursePublications()->workingDefinition($this->course)->describe();
            $result = $this->service->suggest($this->course, $task, true, $preview["revision"]);
            self::assertSame("A clearer fictional description.", $result["advice"]["description"]);
            self::assertSame("An introduction to algebra.", $this->assistant->calls[0][1]["description"]);
            self::assertSame(
                $before,
                $this->f->coursePublications()->workingDefinition($this->course)->describe(),
            );
            $this->assistant->calls = [];
            $this->f->access->grants = ["sis:catalog:manage", "sis:ai:course:description"];
        }
    }
    public function testPreviewIsLocalAndRequestRunsOutsideTransactionWithoutEditingCourse(): void
    {
        $preview = $this->service->preview($this->course);
        self::assertSame([], $this->assistant->calls);
        self::assertSame(
            [
                "code",
                "title",
                "transcript_title",
                "description",
                "credits_milli",
                "prerequisite_count",
                "subject",
                "classifications",
            ],
            array_keys($preview["draft"]),
        );
        self::assertStringNotContainsString($this->course->value, json_encode($preview["draft"]));
        $this->assistant->duringCall = function (): void {
            self::assertFalse($this->f->connection->pdo->inTransaction());
        };
        $before = $this->f->coursePublications()->workingDefinition($this->course)->describe();
        $result = $this->service->suggest(
            $this->course,
            AuthoringTask::Readiness,
            true,
            $preview["revision"],
        );
        self::assertSame("Verify this draft.", $result["advice"]["summary"]);
        self::assertSame($preview["draft"], $this->assistant->calls[0][1]);
        self::assertSame(
            $before,
            $this->f->coursePublications()->workingDefinition($this->course)->describe(),
        );
        self::assertCount(1, $this->f->audit->forSubject($this->course));
        self::assertStringNotContainsString(
            "Verify this draft.",
            json_encode($this->f->audit->forSubject($this->course)),
        );
    }
    public function testConsentIsRequiredBeforeAnyProviderCall(): void
    {
        $preview = $this->service->preview($this->course);
        try {
            $this->service->suggest($this->course, AuthoringTask::Readiness, false, $preview["revision"]);
            self::fail();
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testProviderChangesRequireFreshConsent(): void
    {
        $preview = $this->service->preview($this->course);
        $this->assistant->fingerprint = "replacement-provider";
        try {
            $this->service->suggest($this->course, AuthoringTask::Readiness, true, $preview["revision"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testStaleCourseCannotBeSent(): void
    {
        $preview = $this->service->preview($this->course);
        $this->f->sql->update("sis_courses", ["title" => "Changed algebra"], ["id" => $this->course->value]);
        try {
            $this->service->suggest($this->course, AuthoringTask::Readiness, true, $preview["revision"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testCourseChangeDuringRequestDiscardsSuggestions(): void
    {
        $preview = $this->service->preview($this->course);
        $this->assistant->duringCall = function (): void {
            $this->f->sql->update(
                "sis_courses",
                ["title" => "Changed algebra"],
                ["id" => $this->course->value],
            );
        };
        $this->expectException(ConcurrentModificationException::class);
        $this->service->suggest($this->course, AuthoringTask::Readiness, true, $preview["revision"]);
    }
    public function testCatalogPermissionAloneCannotCallAssistance(): void
    {
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:catalog:manage"];
        self::assertSame([], $this->service->preview($this->course)["tasks"]);
        $this->expectException(AccessDeniedException::class);
        $this->service->suggest($this->course, AuthoringTask::Description, true, "irrelevant");
    }
    public function testActionGrantsAreIndependentAndRevocationDiscardsSuggestions(): void
    {
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:catalog:manage", "sis:ai:course:readiness"];
        $preview = $this->service->preview($this->course);
        self::assertSame(["readiness"], $preview["tasks"]);
        $this->assistant->duringCall = function (): void {
            $this->f->access->grants = ["sis:catalog:manage"];
        };
        $this->expectException(AccessDeniedException::class);
        $this->service->suggest($this->course, AuthoringTask::Readiness, true, $preview["revision"]);
    }
    public function testDisabledProviderCannotBeCalled(): void
    {
        $preview = $this->service->preview($this->course);
        $this->assistant->enabled = false;
        try {
            $this->service->suggest($this->course, AuthoringTask::Readiness, true, $preview["revision"]);
            self::fail();
        } catch (AssistanceUnavailableException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testAuditFailurePreventsProviderCall(): void
    {
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional audit outage.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $service = new CourseAssistanceService(
            $this->f->coursePublications(),
            $this->assistant,
            $this->f->access,
            $audit,
        );
        $preview = $service->preview($this->course);
        try {
            $service->suggest($this->course, AuthoringTask::Readiness, true, $preview["revision"]);
            self::fail();
        } catch (RuntimeException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testProviderFailureIsSanitized(): void
    {
        $preview = $this->service->preview($this->course);
        $this->assistant->duringCall = static function (): never {
            throw new RuntimeException("private upstream content");
        };
        try {
            $this->service->suggest($this->course, AuthoringTask::Readiness, true, $preview["revision"]);
            self::fail();
        } catch (AssistanceUnavailableException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString("private", $e->getMessage());
        }
    }
}
