<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\TranscriptWorklistFixture;

final class TranscriptWorklistTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/TranscriptWorklistFixture.php";
    }
    public function testAssignmentClaimClearAndScopedQueuePreserveBusinessFactsAndDoNotGrantAccess(): void
    {
        $t = new TranscriptWorklistFixture();
        $f = $t->transcripts->sis;
        $service = $t->service();
        [$colleague, $person] = $t->colleague();
        $tables = [
            "sis_transcript_requests",
            "sis_transcript_documents",
            "sis_transcript_deliveries",
            "sis_transcript_updates",
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_access_user_permissions",
            "sis_access_user_roles",
        ];
        $before = array_map($f->sql->rows(...), $tables);
        self::assertSame(0, $service->view($t->request)["version"]);
        self::assertCount(1, $service->page("unassigned")["items"]);
        $saved = $service->save($t->request, 0, 0, $colleague, $person);
        self::assertSame(1, $saved["version"]);
        self::assertSame("Fictional Registrar", $saved["assignee_name"]);
        self::assertSame([], $service->page("unassigned")["items"]);
        self::assertSame([], $service->page("mine")["items"]);
        $f->access->actor = $colleague;
        self::assertCount(1, $service->page("mine")["items"]);
        self::assertSame(1, $service->save($t->request, 1, 0, $colleague, $person)["version"]);
        self::assertSame(2, $service->save($t->request, 1, 0, null, null)["version"]);
        self::assertCount(1, $service->page("unassigned")["items"]);
        self::assertSame($before, array_map($f->sql->rows(...), $tables));
    }
    public function testStaleAssignmentAndRequestRevisionsCannotOverwriteAColleaguesWork(): void
    {
        $t = new TranscriptWorklistFixture();
        [$user, $person] = $t->colleague();
        $service = $t->service();
        $service->save($t->request, 0, 0, $user, $person);
        foreach ([[0, 0], [1, 1]] as [$version, $request]) {
            try {
                $service->save($t->request, $version, $request, null, null);
                self::fail("A stale assignment committed.");
            } catch (ConcurrentModificationException) {
                self::assertSame($user->value, $service->view($t->request)["assignee_id"]);
            }
        }
    }
    public function testEligibleSearchRequiresBothDutiesBeforePagingAndOmitsDisabledAndUnlinkedAccounts(): void
    {
        $t = new TranscriptWorklistFixture();
        $f = $t->transcripts->sis;
        for ($i = 0; $i < 21; $i++) {
            $t->colleague("Registrar partial " . $i, ["sis:transcript:manage"]);
        }
        $valid = [];
        for ($i = 0; $i < 21; $i++) {
            $valid[] = $t->colleague("Registrar eligible " . $i)[0]->value;
        }
        [$disabled] = $t->colleague("Registrar disabled");
        $f->sql->update("users", ["sign_in_enabled" => 0], ["user_id" => $disabled->value]);
        $unlinked = $f->user();
        $t->grant($unlinked);
        $service = $t->service();
        $page = $service->assignees("Registrar");
        self::assertCount(20, $page["items"]);
        self::assertNotNull($page["next_after"]);
        $last = $service->assignees("Registrar", new EntityId($page["next_after"]));
        self::assertCount(1, $last["items"]);
        self::assertEqualsCanonicalizing($valid, array_column([...$page["items"], ...$last["items"]], "id"));
        self::assertArrayHasKey("person_id", $page["items"][0]);
        self::assertSame([], $service->assignees("R")["items"]);
        self::assertSame([], $service->assignees("%_")["items"]);
    }
    public function testCandidatePermissionAndAccountChangesAreRejectedWithoutChangingAssignment(): void
    {
        $t = new TranscriptWorklistFixture();
        $f = $t->transcripts->sis;
        [$partial, $partialPerson] = $t->colleague("Partial", ["sis:transcript:manage"]);
        try {
            $t->service()->save($t->request, 0, 0, $partial, $partialPerson);
            self::fail("A partial duty was enough.");
        } catch (ValidationException) {
            self::assertSame([], $f->sql->rows("sis_transcript_assignments"));
        }
        [$user, $person] = $t->colleague();
        try {
            $t->service()->save($t->request, 0, 0, $user, EntityId::generate());
            self::fail("A substituted person was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_transcript_assignments"));
        }
        $f->sql->update("users", ["sign_in_enabled" => 0], ["user_id" => $user->value]);
        $this->expectException(ValidationException::class);
        $t->service()->save($t->request, 0, 0, $user, $person);
    }
    public function testUnavailableColleaguesRemainRecordedUntilAnAuthorizedOperatorReroutesWork(): void
    {
        foreach (["disabled", "revoked"] as $change) {
            $t = new TranscriptWorklistFixture();
            $f = $t->transcripts->sis;
            [$user, $person] = $t->colleague();
            $service = $t->service();
            $service->save($t->request, 0, 0, $user, $person);
            $before = $f->sql->rows("sis_transcript_assignments");
            if ($change === "disabled") {
                $f->sql->update("users", ["sign_in_enabled" => 0], ["user_id" => $user->value]);
            } else {
                $permission = new EntityId(
                    $f->sql->one("sis_access_permissions", ["name" => "sis:student:read"])["id"],
                );
                $t->permissions->assign(GrantKind::UserPermission, $user, $permission, false);
            }
            self::assertSame("Assigned account unavailable", $service->view($t->request)["assignee_name"]);
            self::assertSame("Assigned account unavailable", $service->page()["items"][0]["assignee_name"]);
            self::assertSame([], $service->page("unassigned")["items"]);
            self::assertSame($before, $f->sql->rows("sis_transcript_assignments"));
            self::assertSame(2, $service->save($t->request, 1, 0, null, null)["version"]);
            self::assertCount(1, $service->page("unassigned")["items"]);
        }
    }
    public function testFinalAuditAuthorityCandidateSourceAndProjectionChangesRollbackAssignments(): void
    {
        foreach (["audit", "actor", "candidate", "source", "projection", "link"] as $failure) {
            $t = new TranscriptWorklistFixture();
            $f = $t->transcripts->sis;
            [$user, $person] = $t->colleague();
            $replacement = $f->user();
            $before = [
                $f->sql->rows("sis_transcript_requests"),
                $f->sql->rows("sis_access_user_permissions"),
                $f->sql->rows("sis_persons"),
            ];
            $audit = $this->createStub(AuditTrail::class);
            $audit
                ->method("record")
                ->willReturnCallback(function () use ($failure, $t, $f, $user, $person, $replacement): void {
                    if ($failure === "audit") {
                        throw new \RuntimeException("Fictional required audit failure");
                    }
                    if ($failure === "actor") {
                        $f->access->staff = false;
                    }
                    if ($failure === "candidate") {
                        $permission = new EntityId(
                            $f->sql->one("sis_access_permissions", ["name" => "sis:student:read"])["id"],
                        );
                        $t->permissions->assign(GrantKind::UserPermission, $user, $permission, false);
                    }
                    if ($failure === "source") {
                        $f->sql->update(
                            "sis_transcript_requests",
                            ["status" => "cancelled"],
                            ["id" => $t->request->value],
                        );
                    }
                    if ($failure === "projection") {
                        $f->sql->execute("DELETE FROM sis_transcript_assignments");
                    }
                    if ($failure === "link") {
                        $f->sql->update(
                            "sis_persons",
                            ["owner_user_id" => $replacement->value],
                            ["id" => $person->value],
                        );
                    }
                });
            try {
                $t->service($audit)->save($t->request, 0, 0, $user, $person);
                self::fail("A failed assignment committed.");
            } catch (\RuntimeException | ValidationException) {
                self::assertSame([], $f->sql->rows("sis_transcript_assignments"));
                self::assertSame($before, [
                    $f->sql->rows("sis_transcript_requests"),
                    $f->sql->rows("sis_access_user_permissions"),
                    $f->sql->rows("sis_persons"),
                ]);
            }
        }
    }
    public function testFinishedRequestsRetainAssignmentsAndCanBeFoundWithoutReassignment(): void
    {
        $t = new TranscriptWorklistFixture();
        $f = $t->transcripts->sis;
        [$user, $person] = $t->colleague();
        $service = $t->service();
        $service->save($t->request, 0, 0, $user, $person);
        $row = $f->sql->rows("sis_transcript_assignments");
        $f->access->actor = new EntityId(
            $f->sql->one("sis_transcript_requests", ["id" => $t->request->value])["owner_user_id"],
        );
        $t->transcripts->campus->service->transitionTranscript($t->request, "cancelled", 0);
        self::assertSame([], $service->page()["items"]);
        self::assertCount(1, $service->page("all", "finished")["items"]);
        self::assertFalse($service->view($t->request)["can_assign"]);
        try {
            $service->save($t->request, 1, 1, null, null);
            self::fail("A finished request was reassigned.");
        } catch (ConcurrentModificationException) {
            self::assertSame($row, $f->sql->rows("sis_transcript_assignments"));
        }
    }
    public function testOperatorNeedsActualCurrentDutiesEvenWhenSessionAuthorizationIsCached(): void
    {
        $t = new TranscriptWorklistFixture();
        $f = $t->transcripts->sis;
        $permission = new EntityId($f->sql->one("sis_access_permissions", ["name" => "sis:student:read"])["id"]);
        $t->permissions->assign(GrantKind::UserPermission, $f->access->actor, $permission, false);
        $this->expectException(AccessDeniedException::class);
        $t->service()->page();
    }
    public function testQueueFiltersApplyBeforePagingAndIdentityChangesDoNotTransferMyWork(): void
    {
        $t = new TranscriptWorklistFixture();
        $f = $t->transcripts->sis;
        [$user, $person] = $t->colleague();
        $service = $t->service();
        $record = $t->transcripts->repository->request($t->request);
        for ($index = 0; $index < 23; $index++) {
            $id = $index === 0 ? $t->request : EntityId::generate();
            if ($index > 0) {
                $t->transcripts->campus->service->requestTranscript(
                    $id,
                    new EntityId($record["student_id"]),
                    "pickup",
                    "",
                );
            }
            if ($index < 22) {
                $service->save($id, 0, 0, $user, $person);
            }
        }
        self::assertCount(1, $service->page("unassigned")["items"]);
        $f->access->actor = $user;
        $first = $service->page("mine");
        self::assertCount(20, $first["items"]);
        $last = $service->page("mine", after: new EntityId($first["next_after"]));
        self::assertCount(2, $last["items"]);
        self::assertNull($last["next_after"]);
        self::assertCount(22, array_unique(array_column([...$first["items"], ...$last["items"]], "id")));
        self::assertArrayNotHasKey("assignee_id", $first["items"][0]);
        $newOwner = $f->user();
        $f->sql->update("sis_persons", ["owner_user_id" => $newOwner->value], ["id" => $person->value]);
        $f->legacyPerson($user, "Fictional", "Replacement");
        self::assertSame([], $service->page("mine")["items"]);
    }

    public function testARealPreparationRequiresFreshAssignmentContextAndAssignmentPreservesTheDocument(): void
    {
        $t = new TranscriptWorklistFixture();
        $f = $t->transcripts->sis;
        [$user, $person] = $t->colleague();
        $renderer = $this->createStub(\Domain\Transcript\Service\TranscriptDocumentRenderer::class);
        $renderer->method("html")->willReturn("<p>Fictional transcript</p>");
        $renderer->method("pdf")->willReturn("%PDF-1.4 Fictional transcript");
        $fulfillment = $t->transcripts->service($renderer);
        $review = $fulfillment->review($t->request);
        $fulfillment->prepare(
            $t->request,
            new EntityId($review["operation_id"]),
            $review["revision"],
            true,
            $review["review_token"],
        );
        $before = [
            $f->sql->rows("sis_transcript_documents"),
            $f->sql->rows("sis_transcript_requests"),
            $f->sql->rows("sis_transcript_updates"),
        ];
        try {
            $t->service()->save($t->request, 0, 0, $user, $person);
            self::fail("A stale preparation context was accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_transcript_assignments"));
        }
        $view = $t->service()->view($t->request);
        self::assertSame(1, $view["request_version"]);
        $t->service()->save($t->request, 0, 1, $user, $person);
        self::assertSame($before, [
            $f->sql->rows("sis_transcript_documents"),
            $f->sql->rows("sis_transcript_requests"),
            $f->sql->rows("sis_transcript_updates"),
        ]);
    }
}
