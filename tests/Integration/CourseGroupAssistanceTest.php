<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";

use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AssistanceFailure;
use Domain\Assistance\ValueObject\AuthoringAdvice;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\DegreeAudit\Service\CourseGroupAssistanceService;
use Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\Repository\SqlCourseGroupRepository;
use PHPUnit\Framework\TestCase;

final class FictionalGroupAuthoringAssistant implements AuthoringAssistant
{
    public array $calls = [];
    public ?Closure $duringCall = null;
    public string $fingerprint = "fictional-provider";
    public bool $enabled = true;
    public ?string $description = null;
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
            "Review saved group quantities.",
            ["Verify institutional policy."],
            $this->description,
        );
    }
}
final class CourseGroupAssistanceTest extends TestCase
{
    private ReusableGroupFixture $f;
    private FictionalGroupAuthoringAssistant $assistant;
    private CourseGroupAssistanceService $service;
    private array $definition;
    protected function setUp(): void
    {
        $this->f = new ReusableGroupFixture();
        $this->definition = CourseGroupDefinitionV2::blank("Fictional general education")->definition;
        $this->definition["min_credits_milli"] = 12000;
        $this->definition["min_course_grade_points_milli"] = 2500;
        $this->definition["courses"] = $this->f->groupDefinition["courses"];
        $this->definition["rules"] = $this->f->groupDefinition["rules"];
        $this->f->groups->save($this->f->group, 0, new CourseGroupDefinitionV2($this->definition));
        $this->assistant = new FictionalGroupAuthoringAssistant();
        $this->service = new CourseGroupAssistanceService(
            new SqlCourseGroupRepository($this->f->sis->sql),
            new PublishedCourseReader($this->f->history),
            $this->assistant,
            $this->f->sis->access,
            $this->f->sis->transactions,
            $this->f->sis->audit,
        );
    }
    private function changeDraft(): void
    {
        $this->definition["name"] = "Changed group";
        $this->f->groups->save($this->f->group, 1, new CourseGroupDefinitionV2($this->definition));
    }
    public function testPreviewRetainsSelectedCourseFactsAndOnlySendsMinimizedRules(): void
    {
        $p = $this->service->preview($this->f->group);
        self::assertSame([], $this->assistant->calls);
        self::assertSame(1, $p["saved_revision"]);
        self::assertCount(5, $p["draft"]["courses"]);
        self::assertSame(
            ["mode" => "choose", "minimum_courses" => 1, "course_positions" => [3, 4]],
            $p["draft"]["rules"][2],
        );
        self::assertSame(2500, $p["draft"]["min_course_grade_points_milli"]);
        self::assertSame(4000, $p["draft"]["max_course_grade_points_milli"]);
        self::assertSame(
            ["position", "code", "title", "credits_milli"],
            array_keys($p["draft"]["courses"][0]),
        );
        $json = json_encode($p["draft"]);
        foreach (
            array_merge(
                [
                    $this->f->group->value,
                    $this->f->groupVersion->value,
                    "grade_scale_program_id",
                    "course_id",
                    "version_id",
                    "actor_id",
                    "student_id",
                ],
                array_column($this->definition["courses"], "course_id"),
                array_column($this->definition["rules"], "id"),
            )
            as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
        $this->f->sis->sql->update(
            "sis_courses",
            ["title" => "Changed current title"],
            ["id" => $this->definition["courses"][0]["course_id"]],
        );
        self::assertSame($p, $this->service->preview($this->f->group));
    }
    public function testSuggestionsRunWithoutLocksAndCannotChangeDraftsOrPublications(): void
    {
        $drafts = $this->f->digest("sis_course_groups");
        $history = $this->f->digest("sis_history_events");
        $p = $this->service->preview($this->f->group);
        $this->assistant->duringCall = function (): void {
            self::assertFalse($this->f->sis->connection->pdo->inTransaction());
        };
        $result = $this->service->suggest($this->f->group, true, $p["revision"]);
        self::assertSame(AuthoringTask::GroupReadiness, $this->assistant->calls[0][0]);
        self::assertSame($p["draft"], $this->assistant->calls[0][1]);
        self::assertSame("Review saved group quantities.", $result["advice"]["summary"]);
        self::assertSame($drafts, $this->f->digest("sis_course_groups"));
        self::assertSame($history, $this->f->digest("sis_history_events"));
        self::assertStringNotContainsString(
            "Review saved group quantities.",
            json_encode($this->f->sis->audit->forSubject($this->f->group)),
        );
    }
    public function testConsentAndIndependentActionPermissionAreRequired(): void
    {
        $p = $this->service->preview($this->f->group);
        try {
            $this->service->suggest($this->f->group, false, $p["revision"]);
            self::fail();
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->assistant->calls);
        }
        $this->f->sis->access->staff = false;
        $this->f->sis->access->grants = [
            "sis:catalog:manage",
            "sis:ai:course:readiness",
            "sis:ai:degree:readiness",
        ];
        try {
            $this->service->preview($this->f->group);
            self::fail();
        } catch (AccessDeniedException) {
            self::assertSame([], $this->assistant->calls);
        }
        $this->f->sis->access->grants[] = "sis:ai:group:readiness";
        $p = $this->service->preview($this->f->group);
        $this->service->suggest($this->f->group, true, $p["revision"]);
        self::assertCount(1, $this->assistant->calls);
    }
    public function testDisabledAssistanceDoesNotCallProvider(): void
    {
        $p = $this->service->preview($this->f->group);
        $this->assistant->enabled = false;
        try {
            $this->service->suggest($this->f->group, true, $p["revision"]);
            self::fail();
        } catch (AssistanceUnavailableException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testStaleDraftOrProviderCannotBeSent(): void
    {
        foreach (["draft", "provider"] as $kind) {
            $p = $this->service->preview($this->f->group);
            if ($kind === "draft") {
                $this->changeDraft();
            } else {
                $this->assistant->fingerprint = "replacement";
            }
            try {
                $this->service->suggest($this->f->group, true, $p["revision"]);
                self::fail();
            } catch (ConcurrentModificationException) {
                self::assertSame([], $this->assistant->calls);
            }
        }
    }
    public function testGroupChangesDuringRequestDiscardSuggestions(): void
    {
        $p = $this->service->preview($this->f->group);
        $this->assistant->duringCall = fn() => $this->changeDraft();
        $this->expectException(ConcurrentModificationException::class);
        $this->service->suggest($this->f->group, true, $p["revision"]);
    }
    public function testRevokedPermissionDuringRequestDiscardsSuggestions(): void
    {
        $p = $this->service->preview($this->f->group);
        $this->assistant->duringCall = function (): void {
            $this->f->sis->access->staff = false;
            $this->f->sis->access->grants = ["sis:catalog:manage"];
        };
        $this->expectException(AccessDeniedException::class);
        $this->service->suggest($this->f->group, true, $p["revision"]);
    }
    public function testUnexpectedReplacementTextIsRejected(): void
    {
        $p = $this->service->preview($this->f->group);
        $this->assistant->description = "Unrequested replacement";
        $this->expectException(AssistanceUnavailableException::class);
        $this->service->suggest($this->f->group, true, $p["revision"]);
    }
    public function testControllerPreservesOnlyFixedProviderError(): void
    {
        $p = $this->service->preview($this->f->group);
        $this->assistant->duringCall = static fn() => throw new AssistanceUnavailableException(
            AssistanceFailure::Capacity,
        );
        $controller = new \Application\Http\Controller\SisCourseGroupAssistanceController($this->service);
        $request = new \Qubus\Http\ServerRequest()->withParsedBody([
            "consent" => true,
            "revision" => $p["revision"],
        ]);
        $response = $controller->suggest($request, $this->f->group->value);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ["error" => AssistanceFailure::Capacity->message()],
            json_decode((string) $response->getBody(), true),
        );
    }
    public function testExhaustedVerificationSendsNoPartialGroup(): void
    {
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history
            ->expects(self::once())
            ->method("page")
            ->willThrowException(new HistoryReadLimitException("fictional limit"));
        $service = new CourseGroupAssistanceService(
            new SqlCourseGroupRepository($this->f->sis->sql),
            new PublishedCourseReader($history),
            $this->assistant,
            $this->f->sis->access,
            $this->f->sis->transactions,
            $this->f->sis->audit,
        );
        try {
            $service->preview($this->f->group);
            self::fail();
        } catch (AssistanceUnavailableException $e) {
            self::assertSame([], $this->assistant->calls);
            self::assertNull($e->getPrevious());
        }
    }
}
