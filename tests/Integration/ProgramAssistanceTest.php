<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/ProgramPublicationFixture.php";

use Domain\Assistance\Exception\AssistanceUnavailableException;
use Domain\Assistance\Service\AuthoringAssistant;
use Domain\Assistance\ValueObject\AssistanceFailure;
use Domain\Assistance\ValueObject\AuthoringAdvice;
use Domain\Assistance\ValueObject\AuthoringTask;
use Domain\Catalog\Service\ProgramAssistanceService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use PHPUnit\Framework\TestCase;

final class FictionalProgramAssistant implements AuthoringAssistant
{
    public array $calls = [];
    public ?Closure $duringCall = null;
    public string $fingerprint = "fictional-provider";
    public function availability(): array
    {
        return [
            "enabled" => true,
            "label" => "Fictional service",
            "fingerprint" => $this->fingerprint,
        ];
    }
    public function suggest(AuthoringTask $task, array $draft): AuthoringAdvice
    {
        $this->calls[] = [$task, $draft];
        if ($this->duringCall !== null) {
            ($this->duringCall)();
        }
        return new AuthoringAdvice("Review the fictional program.", [], "A clearer fictional description.");
    }
}
final class ProgramAssistanceTest extends TestCase
{
    private ProgramPublicationFixture $p;
    private FictionalProgramAssistant $assistant;
    private ProgramAssistanceService $service;
    protected function setUp(): void
    {
        $this->p = new ProgramPublicationFixture();
        $this->assistant = new FictionalProgramAssistant();
        $this->service = new ProgramAssistanceService(
            $this->p->service,
            $this->assistant,
            $this->p->sis->access,
            $this->p->sis->audit,
        );
    }
    private function saveDescription(): void
    {
        $saved = $this->p->service->draft($this->p->sis->program);
        $this->p->service->save(
            $this->p->sis->program,
            $saved["definition"]["code"],
            $saved["definition"]["title"],
            "Fictional academic program.",
            null,
            null,
            null,
            $saved["revision"],
        );
    }
    public function testPreviewIsLocalMinimizedAndSuggestionsDoNotSaveOrPublish(): void
    {
        $this->saveDescription();
        $f = $this->p->sis;
        $before = $this->p->service->draft($f->program);
        $events = $f->sql->rows("sis_history_events");
        $preview = $this->service->preview($f->program);
        self::assertSame([], $this->assistant->calls);
        self::assertSame($before["revision"], $preview["saved_revision"]);
        self::assertSame(
            ["kind", "code", "title", "description", "classifications"],
            array_keys($preview["draft"]),
        );
        self::assertStringNotContainsString($f->program->value, json_encode($preview["draft"]));
        $this->assistant->duringCall = function () use ($f): void {
            self::assertFalse($f->connection->pdo->inTransaction());
        };
        foreach ([AuthoringTask::Description, AuthoringTask::Rewrite, AuthoringTask::Expand] as $task) {
            $result = $this->service->suggest($f->program, $task, true, $preview["revision"]);
            self::assertSame("A clearer fictional description.", $result["advice"]["description"]);
            self::assertSame($before, $this->p->service->draft($f->program));
            self::assertSame($events, $f->sql->rows("sis_history_events"));
        }
        self::assertSame($preview["draft"], $this->assistant->calls[0][1]);
        self::assertStringNotContainsString(
            "A clearer fictional description.",
            json_encode($f->audit->forSubject($f->program)),
        );
    }
    public function testConsentAndSavedTextAreRequiredBeforeSending(): void
    {
        $preview = $this->service->preview($this->p->sis->program);
        self::assertSame(["description"], $preview["tasks"]);
        foreach (
            [
                [AuthoringTask::Description, false],
                [AuthoringTask::Rewrite, true],
                [AuthoringTask::Expand, true],
                [AuthoringTask::Readiness, true],
            ]
            as [$task, $consent]
        ) {
            try {
                $this->service->suggest($this->p->sis->program, $task, $consent, $preview["revision"]);
                self::fail("Invalid assistance request accepted.");
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->assistant->calls);
            }
        }
    }
    public function testCatalogAndEachProgramActionAreIndependentGrants(): void
    {
        $this->saveDescription();
        $f = $this->p->sis;
        $f->access->staff = false;
        foreach ([AuthoringTask::Description, AuthoringTask::Rewrite, AuthoringTask::Expand] as $task) {
            $f->access->grants = ["sis:catalog:manage", "sis:ai:course:" . $task->value];
            self::assertSame([], $this->service->preview($f->program)["tasks"]);
            try {
                $this->service->suggest($f->program, $task, true, "");
                self::fail("A course permission granted program assistance.");
            } catch (AccessDeniedException) {
                self::assertSame([], $this->assistant->calls);
            }
            $f->access->grants = ["sis:catalog:manage", "sis:ai:program:" . $task->value];
            $preview = $this->service->preview($f->program);
            self::assertSame([$task->value], $preview["tasks"]);
            $this->service->suggest($f->program, $task, true, $preview["revision"]);
            $this->assistant->calls = [];
        }
        $f->access->grants = ["sis:ai:program:description"];
        $this->expectException(AccessDeniedException::class);
        $this->service->preview($f->program);
    }
    public function testChangedProgramOrProviderRequiresFreshConsent(): void
    {
        foreach (["program", "provider"] as $change) {
            $preview = $this->service->preview($this->p->sis->program);
            if ($change === "program") {
                $this->saveDescription();
            } else {
                $this->assistant->fingerprint = "replacement-provider";
            }
            try {
                $this->service->suggest(
                    $this->p->sis->program,
                    AuthoringTask::Description,
                    true,
                    $preview["revision"],
                );
                self::fail("A stale preview was accepted.");
            } catch (ConcurrentModificationException) {
                self::assertSame([], $this->assistant->calls);
            }
        }
    }
    public function testChangedProgramDuringNetworkWorkDiscardsAdvice(): void
    {
        $preview = $this->service->preview($this->p->sis->program);
        $this->assistant->duringCall = fn() => $this->saveDescription();
        $this->expectException(ConcurrentModificationException::class);
        $this->service->suggest(
            $this->p->sis->program,
            AuthoringTask::Description,
            true,
            $preview["revision"],
        );
    }
    public function testRevokedPermissionDuringNetworkWorkDiscardsAdvice(): void
    {
        $preview = $this->service->preview($this->p->sis->program);
        $this->assistant->duringCall = function (): void {
            $this->p->sis->access->staff = false;
            $this->p->sis->access->grants = ["sis:catalog:manage"];
        };
        $this->expectException(AccessDeniedException::class);
        $this->service->suggest(
            $this->p->sis->program,
            AuthoringTask::Description,
            true,
            $preview["revision"],
        );
    }
    public function testControllerReturnsFixedAuthenticationErrorWithoutUpstreamDetails(): void
    {
        $preview = $this->service->preview($this->p->sis->program);
        $this->assistant->duringCall = static fn() => throw new AssistanceUnavailableException(
            AssistanceFailure::Authentication,
        );
        $controller = new \Application\Http\Controller\SisProgramAssistanceController($this->service);
        $request = new \Qubus\Http\ServerRequest()->withParsedBody([
            "task" => "description",
            "consent" => true,
            "revision" => $preview["revision"],
        ]);
        $response = $controller->suggest($request, $this->p->sis->program->value);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ["error" => AssistanceFailure::Authentication->message()],
            json_decode((string) $response->getBody(), true),
        );
    }
}
