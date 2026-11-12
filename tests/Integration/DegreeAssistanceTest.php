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
        self::assertSame([["published_version_selected" => true]], $preview["draft"]["groups"]);
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
