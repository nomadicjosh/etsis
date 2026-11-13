<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ReusableGroupFixture.php";

use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AuthoringAdvice;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\DegreeAudit\Service\DegreeAssistanceService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use PHPUnit\Framework\TestCase;

final class DegreeAssistanceTest extends TestCase
{
    private ReusableGroupFixture $f;
    private AuthoringAssistant $assistant;
    private DegreeAssistanceService $service;
    protected function setUp(): void
    {
        $this->f = new ReusableGroupFixture();
        $this->f->degrees->save($this->f->degree, 0, new AuditRequirementsV3($this->f->definition));
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
                return new AuthoringAdvice("Verify policy choices.", ["Verify group contents separately."]);
            }
        };
        $this->service = new DegreeAssistanceService(
            $this->f->degrees,
            $this->assistant,
            $this->f->sis->access,
            $this->f->sis->audit,
        );
    }
    public function testPreviewIsMinimizedAndSuggestionsCannotChangeRequirementsOrAcademicHistory(): void
    {
        $f = $this->f;
        $before = $f->digest("sis_degree_audit_templates");
        $history = $f->digest("sis_history_events");
        $preview = $this->service->preview($f->degree);
        self::assertSame([], $this->assistant->calls);
        self::assertCount(1, $preview["draft"]["groups"]);
        self::assertTrue($preview["draft"]["groups"][0]["published_version_selected"]);
        self::assertSame("General education", $preview["draft"]["groups"][0]["requirements"]["name"]);
        self::assertCount(5, $preview["draft"]["groups"][0]["requirements"]["courses"]);
        self::assertArrayHasKey(
            "legacy_average_group_gpa_milli",
            $preview["draft"]["groups"][0]["requirements"],
        );
        foreach (
            [
                $f->group->value,
                $f->groupVersion->value,
                $f->degree->value,
                "course_ids",
                "actor_id",
                "student_id",
            ]
            as $private
        ) {
            self::assertStringNotContainsString($private, json_encode($preview["draft"]));
        }
        self::assertSame(120000, $preview["draft"]["min_credits_milli"]);
        $this->assistant->duringCall = function (): void {
            self::assertFalse($this->f->sis->connection->pdo->inTransaction());
        };
        $result = $this->service->suggest($f->degree, true, $preview["revision"]);
        self::assertSame("Verify policy choices.", $result["advice"]["summary"]);
        self::assertSame(AuthoringTask::DegreeReadiness, $this->assistant->calls[0][0]);
        self::assertSame($before, $f->digest("sis_degree_audit_templates"));
        self::assertSame($history, $f->digest("sis_history_events"));
    }
    private function addSharedGroup(): array
    {
        $id = \Domain\Shared\ValueObject\EntityId::generate();
        $d = \Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2::blank("Fictional shared course group")
            ->definition;
        $d["min_credits_milli"] = 3000;
        $d["courses"] = [$this->f->groupDefinition["courses"][0]];
        $d["rules"] = [$this->f->groupDefinition["rules"][0]];
        $this->f->groups->create($id, new \Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2($d));
        $review = $this->f->groups->review($id, "Fictional shared group approval");
        $published = $this->f->groups->publish($id, $review["review_token"]);
        $definition = $this->f->degrees->view($this->f->degree)["definition"];
        $definition["groups"][] = ["group_id" => $id->value, "version_id" => $published["operation_id"]];
        $this->f->degrees->save($this->f->degree, 1, new AuditRequirementsV3($definition));
        return $definition;
    }
    public function testSharedCoursesUseOneRequestLocalIdentityAndAreReplayedOnce(): void
    {
        $this->addSharedGroup();
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $reads = [];
        $history
            ->expects(self::exactly(7))
            ->method("page")
            ->willReturnCallback(function ($stream, $cursor, $limit) use (&$reads) {
                $key = $stream->type . ":" . (string) $stream->aggregateId;
                $reads[$key] = ($reads[$key] ?? 0) + 1;
                return $this->f->history->page($stream, $cursor, $limit);
            });
        $reader = new \Domain\DegreeAudit\Service\PublishedRequirementsReader($history);
        $service = new DegreeAssistanceService(
            $this->f->degreeService(publishedReader: $reader),
            $this->assistant,
            $this->f->sis->access,
            $this->f->sis->audit,
        );
        $p = $service->preview($this->f->degree);
        $groups = $p["draft"]["groups"];
        self::assertCount(2, $groups);
        self::assertSame(
            $groups[0]["requirements"]["courses"][0]["degree_course_position"],
            $groups[1]["requirements"]["courses"][0]["degree_course_position"],
        );
        self::assertSame([1], $groups[1]["requirements"]["rules"][0]["course_positions"]);
        self::assertCount(7, $reads); // Two group streams and five distinct course streams.
        self::assertSame([1], array_values(array_unique($reads)));
        self::assertSame([], $this->assistant->calls);
    }
    public function testUnselectedGroupNeverSuppliesItsCurrentDraftContents(): void
    {
        $definition = $this->f->definition;
        $definition["groups"][0]["version_id"] = null;
        $this->f->degrees->save($this->f->degree, 1, new AuditRequirementsV3($definition));
        $p = $this->service->preview($this->f->degree);
        self::assertSame([["position" => 1, "published_version_selected" => false]], $p["draft"]["groups"]);
        self::assertStringNotContainsString("General education", json_encode($p["draft"]));
    }
    public function testLaterGroupAndCourseEditsAndPublicationsDoNotChangeSelectedFacts(): void
    {
        $p = $this->service->preview($this->f->degree);
        $d = $this->f->groupDefinition;
        $d["name"] = "Later group name";
        $this->f->groups->save(
            $this->f->group,
            0,
            new \Domain\DegreeAudit\ValueObject\CourseGroupDefinition($d),
        );
        $this->f->publishGroup();
        $course = new \Domain\Shared\ValueObject\EntityId($d["courses"][0]["course_id"]);
        $this->f->sis->sql->update(
            "sis_courses",
            ["title" => "Later course title"],
            ["id" => $course->value],
        );
        $review = $this->f->catalog->publications()->review($course, "Fictional revised course approval");
        $this->f->catalog->publications()->publish($course, $review["review_token"]);
        self::assertSame($p, $this->service->preview($this->f->degree));
    }
    public function testUnverifiableCompositionReturnsFixedUnavailableErrorWithoutSendingAnything(): void
    {
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history
            ->expects(self::once())
            ->method("page")
            ->willThrowException(
                new \Domain\Shared\EventSourcing\HistoryReadLimitException("fictional private detail"),
            );
        $reader = new \Domain\DegreeAudit\Service\PublishedRequirementsReader($history);
        $service = new DegreeAssistanceService(
            $this->f->degreeService(publishedReader: $reader),
            $this->assistant,
            $this->f->sis->access,
            $this->f->sis->audit,
        );
        $controller = new \Application\Http\Controller\SisDegreeAssistanceController($service);
        $response = $controller->preview($this->f->degree->value);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString("review limit", (string) $response->getBody());
        self::assertStringNotContainsString("fictional private detail", (string) $response->getBody());
        self::assertSame([], $this->assistant->calls);
    }
    public function testOldSetupOnlyConsentCannotAuthorizeExpandedComposition(): void
    {
        $repository = new \Infrastructure\Persistence\Repository\SqlRequirementsRepository(
            $this->f->sis->sql,
        );
        $row = $repository->get($this->f->degree);
        $context = $repository->context(
            new \Domain\Shared\ValueObject\EntityId($row["program_id"]),
            new \Domain\Shared\ValueObject\EntityId($row["academic_year_id"]),
        );
        $oldSnapshot = hash("sha256", json_encode([$row, $context], JSON_THROW_ON_ERROR));
        $oldConsent = hash(
            "sha256",
            json_encode([$oldSnapshot, $this->assistant->fingerprint], JSON_THROW_ON_ERROR),
        );
        self::assertNotSame($oldConsent, $this->service->preview($this->f->degree)["revision"]);
        try {
            $this->service->suggest($this->f->degree, true, $oldConsent);
            self::fail("Earlier setup-only consent was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testDegreeGrantIsIndependentOfCourseAndSectionGrants(): void
    {
        $this->f->sis->access->staff = false;
        $this->f->sis->access->grants = [
            "sis:catalog:manage",
            "sis:ai:course:readiness",
            "sis:ai:section:readiness",
        ];
        $this->expectException(AccessDeniedException::class);
        $this->service->preview($this->f->degree);
    }
    public function testConsentAndFreshProviderPreviewAreRequired(): void
    {
        $preview = $this->service->preview($this->f->degree);
        try {
            $this->service->suggest($this->f->degree, false, $preview["revision"]);
            self::fail();
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->assistant->calls);
        }
        $this->assistant->fingerprint = "replacement";
        try {
            $this->service->suggest($this->f->degree, true, $preview["revision"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    private function change(): void
    {
        $row = $this->f->degrees->view($this->f->degree);
        $definition = $row["definition"];
        $definition["name"] = "Changed degree setup";
        $this->f->degrees->save($this->f->degree, $row["revision"], new AuditRequirementsV3($definition));
    }
    public function testStaleSetupCannotBeSent(): void
    {
        $preview = $this->service->preview($this->f->degree);
        $this->change();
        try {
            $this->service->suggest($this->f->degree, true, $preview["revision"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->assistant->calls);
        }
    }
    public function testChangedSetupDuringCallDiscardsSuggestions(): void
    {
        $preview = $this->service->preview($this->f->degree);
        $this->assistant->duringCall = fn() => $this->change();
        $this->expectException(ConcurrentModificationException::class);
        $this->service->suggest($this->f->degree, true, $preview["revision"]);
    }
    public function testRevokedPermissionDuringCallDiscardsSuggestions(): void
    {
        $preview = $this->service->preview($this->f->degree);
        $this->assistant->duringCall = function (): void {
            $this->f->sis->access->staff = false;
            $this->f->sis->access->grants = ["sis:catalog:manage"];
        };
        $this->expectException(AccessDeniedException::class);
        $this->service->suggest($this->f->degree, true, $preview["revision"]);
    }
}
