<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Audit\Repository\AuditTrail;
use Infrastructure\Persistence\Repository\SqlAdmissionCaseworkRepository;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdmissionChangeRequestFixture;

final class AdmissionChangeRequestTest extends TestCase
{
    private function fixture(): AdmissionChangeRequestFixture
    {
        require_once dirname(__DIR__) .
        "/Support/AdmissionChangeRequestFixture.php";
        return new AdmissionChangeRequestFixture();
    }

    public function testOwnerRequestStaffReplyAndOriginalRetriesPreserveAllBusinessFacts(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $before = $f->facts();
        $owner = $f->owner();
        $page = $owner->listing($f->application, true);
        $id = new EntityId($page["new_id"]);
        $message = "PRIVATE intended program <script>example</script>.";
        $created = $owner->request(
            $f->application,
            $id,
            "program",
            $message,
            $page["application_revision"],
        );
        self::assertSame("requested", $created["status"]);
        self::assertSame(
            $created,
            $owner->request(
                $f->application,
                $id,
                "program",
                $message,
                $page["application_revision"],
            ),
        );
        $s->access->actor = $f->manager;
        $staff = $f->service();
        $pending = $staff->listing($f->application, false);
        self::assertSame($message, $pending["items"][0]["message"]);
        $closed = $staff->finish(
            $f->application,
            $id,
            false,
            0,
            $page["application_revision"],
            "Owner-visible resolution.",
        );
        self::assertSame("closed", $closed["status"]);
        self::assertSame(
            $closed,
            $staff->finish(
                $f->application,
                $id,
                false,
                0,
                $page["application_revision"],
                "Owner-visible resolution.",
            ),
        );
        self::assertSame([], $staff->listing($f->application, false)["items"]);
        $f->owner();
        self::assertSame(
            "Owner-visible resolution.",
            $owner->listing($f->application, true, true)["items"][0][
                "resolution"
            ],
        );
        self::assertSame(
            $created,
            $owner->request(
                $f->application,
                $id,
                "program",
                $message,
                $page["application_revision"],
            ),
        );
        self::assertSame($before, $f->facts());
        self::assertCount(1, $s->sql->rows("sis_admission_updates"));
        self::assertMatchesRegularExpression(
            '/T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z$/D',
            $s->sql->rows('sis_admission_updates')[0]['updated_at']
        );
        $stored = json_encode($s->sql->rows("sis_admission_change_requests"));
        self::assertStringNotContainsString("PRIVATE", $stored);
        self::assertStringNotContainsString("Owner-visible", $stored);
        self::assertArrayNotHasKey("receipts", $pending["items"][0]);
        self::assertArrayNotHasKey("owner_user_id", $pending["items"][0]);
    }

    public function testOwnersAndStaffRetainSeparateAuthorityAndOneActiveRequestPerArea(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $owner = $f->owner();
        $page = $owner->listing($f->application, true);
        $id = new EntityId($page["new_id"]);
        $owner->request(
            $f->application,
            $id,
            "entry_term",
            "Fictional later intake.",
            $page["application_revision"],
        );
        try {
            $owner->request(
                $f->application,
                EntityId::generate(),
                "entry_term",
                "Duplicate area.",
                $page["application_revision"],
            );
            self::fail("Duplicate active area accepted.");
        } catch (ConcurrentModificationException) {
            self::assertCount(
                1,
                $s->sql->rows("sis_admission_change_requests"),
            );
        }
        $s->access->staff = false;
        $s->access->grants = [];
        try {
            $owner->listing($f->application, false);
            self::fail("Ownership granted staff request access.");
        } catch (AccessDeniedException) {
            self::assertSame(
                "requested",
                $owner->listing($f->application, true)["items"][0]["status"],
            );
        }
        $s->access->actor = $f->manager;
        $s->access->staff = true;
        try {
            $owner->listing($f->application, true);
            self::fail("Staff access bypassed owner routing.");
        } catch (AccessDeniedException) {
            self::assertCount(
                1,
                $s->sql->rows("sis_admission_change_requests"),
            );
        }
        $f->owner();
        $owner->finish(
            $f->application,
            $id,
            true,
            0,
            $page["application_revision"],
            "No longer needed.",
        );
        self::assertSame([], $owner->listing($f->application, true)["items"]);
        $owner->request(
            $f->application,
            EntityId::generate(),
            "entry_term",
            "A new follow-up.",
            $page["application_revision"],
        );
        self::assertCount(2, $s->sql->rows("sis_admission_change_requests"));
    }

    public function testRealProgramCorrectionRequiresFreshClosureAndOriginalReceiptsSurviveLaterDecisions(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $owner = $f->owner();
        $revision = $s->admissions->application($f->application)->version;
        $id = EntityId::generate();
        $created = $owner->request(
            $f->application,
            $id,
            "program",
            "Please review the fictional arts program.",
            $revision,
        );
        $s->access->actor = $f->manager;
        $target = EntityId::generate();
        $s->sql->insert("sis_programs", [
            "id" => $target->value,
            "code" => "ARTS-REQUEST",
            "title" => "Fictional Arts",
        ]);
        $editor = $f->programCorrections();
        $editor->commit(
            $f->application,
            $editor->review(
                $f->application,
                $target,
                "Reviewed fictional request.",
            )["review_token"],
        );
        $history = $f->facts();
        try {
            $f->service()->finish(
                $f->application,
                $id,
                false,
                0,
                $revision,
                "The program was corrected.",
            );
            self::fail("An old application revision closed a follow-up.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                "requested",
                $f->service()->listing($f->application, false)["items"][0][
                    "status"
                ],
            );
        }
        $page = $f->service()->listing($f->application, false);
        self::assertTrue($page["items"][0]["application_changed"]);
        $closed = $f
            ->service()
            ->finish(
                $f->application,
                $id,
                false,
                0,
                $page["application_revision"],
                "The program was corrected.",
            );
        self::assertSame($history, $f->facts());
        $decision = $s->decisionService();
        $decision->commit(
            $f->application,
            $decision->review($f->application, true, "Fictional acceptance.")[
                "review_token"
            ],
        );
        $matriculation = $s->matriculationService();
        $matriculation->commit(
            $f->application,
            $matriculation->review($f->application, "Fictional matriculation.")[
                "review_token"
            ],
        );
        $later = $f->facts();
        self::assertSame(
            $closed,
            $f
                ->service()
                ->finish(
                    $f->application,
                    $id,
                    false,
                    0,
                    $page["application_revision"],
                    "The program was corrected.",
                ),
        );
        self::assertSame(
            $created,
            $f
                ->owner()
                ->request(
                    $f->application,
                    $id,
                    "program",
                    "Please review the fictional arts program.",
                    $revision,
                ),
        );
        try {
            $owner->request(
                $f->application,
                EntityId::generate(),
                "entry_term",
                "A new request after matriculation.",
                $revision,
            );
            self::fail("A decided application accepted a new target request.");
        } catch (ConcurrentModificationException) {
            self::assertSame($later, $f->facts());
        }
    }

    public function testFollowUpQueueIncludesUnstartedAndFinishedCasesWithoutChangingFormalReadiness(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $queue = new SqlAdmissionCaseworkRepository($s->sql, $s->dropCipher);
        self::assertSame(
            [],
            $queue->queue(null, false, false, "2026-11-24", null),
        );
        $id = EntityId::generate();
        $f->owner()->request(
            $f->application,
            $id,
            "entry_term",
            "Fictional entry correction.",
            0,
        );
        $rows = $queue->queue(null, false, false, "2026-11-24", null);
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]["requests_pending"]);
        self::assertSame(1, (int) $rows[0]["casework_missing"]);
        self::assertSame([], $s->sql->rows("sis_admission_cases"));
        self::assertSame(
            [],
            $queue->queue(null, false, true, "2026-11-24", null),
        );
        self::assertSame(
            [],
            $queue->queue($f->manager, false, false, "2026-11-24", null),
        );
        self::assertCount(
            1,
            $queue->queue(null, true, false, "2026-11-24", null),
        );
        $s->access->actor = $f->manager;
        $f->casework()->save($f->application, ['work_status' => 'done', 'items' => []], 0, 'keep');
        $completedCase = $s->sql->rows('sis_admission_cases');
        $queued = $f->casework()->queue();
        self::assertSame('done', $queued['items'][0]['work_status']);
        self::assertSame(1, $queued['items'][0]['requests_pending']);
        self::assertFalse($queued['items'][0]['casework_missing']);
        $state = $s->reviewStateService();
        $state->commit(
            $f->application,
            $state->review($f->application, true, "Fictional withdrawal.")[
                "review_token"
            ],
        );
        self::assertCount(
            1,
            $queue->queue(null, false, false, "2026-11-24", null),
        );
        $page = $f->service()->listing($f->application, false);
        $f->service()->finish(
            $f->application,
            $id,
            false,
            0,
            $page["application_revision"],
            "Contact admissions to reopen review.",
        );
        self::assertSame(
            [],
            $queue->queue(null, false, false, "2026-11-24", null),
        );
        self::assertSame(
            "withdrawn",
            $s->admissions->application($f->application)->status->value,
        );
        self::assertSame($completedCase, $s->sql->rows('sis_admission_cases'));
    }

    public function testRequiredAuditFailureAndPostAuditApplicationDriftRollBackPrivateWorkingWrites(): void
    {
        foreach (["failure", "drift", "access", "request"] as $scenario) {
            $f = $this->fixture();
            $s = $f->sis;
            $f->owner();
            $before = $f->facts();
            $audit = new class ($s, $f->application, $scenario) implements
                AuditTrail
            {
                public function __construct(
                    private \SisFixture $sis,
                    private EntityId $application,
                    private string $scenario,
                ) {
                }
                public function forSubject(
                    EntityId $subject,
                    int $limit = 100,
                ): array {
                    return [];
                }
                public function record(
                    EntityId $actor,
                    string $action,
                    EntityId $subject,
                ): void {
                    if ($this->scenario === "failure") {
                        throw new \RuntimeException("Fictional audit failure.");
                    }
                    if ($this->scenario === "drift") {
                        $this->sis->sql->update(
                            "sis_applications",
                            ["version" => 99],
                            ["id" => $this->application->value],
                        );
                    }
                    if ($this->scenario === "access") {
                        $this->sis->access->actor = EntityId::generate();
                    }
                    if ($this->scenario === "request") {
                        $this->sis->sql->update(
                            "sis_admission_change_requests",
                            ["status" => "closed"],
                            ["id" => $subject->value],
                        );
                    }
                }
            };
            try {
                $f->service($audit)->request(
                    $f->application,
                    EntityId::generate(),
                    "program",
                    "Fictional private request.",
                    0,
                );
                self::fail(
                    "Audit failure or boundary drift accepted a request.",
                );
            } catch (\RuntimeException $error) {
                self::assertNotInstanceOf(
                    \PHPUnit\Framework\AssertionFailedError::class,
                    $error,
                );
                self::assertSame(
                    [],
                    $s->sql->rows("sis_admission_change_requests"),
                );
                self::assertSame($before, $f->facts());
            }
        }
    }

    public function testEncryptedEnvelopeTamperingAndDifferentOriginalRetriesAreRejected(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $service = $f->owner();
        $id = EntityId::generate();
        $service->request(
            $f->application,
            $id,
            "program",
            "Fictional original message.",
            0,
        );
        try {
            $service->request(
                $f->application,
                $id,
                "program",
                "Different fictional message.",
                0,
            );
            self::fail("A different request reused an accepted reference.");
        } catch (ConcurrentModificationException) {
            self::assertCount(
                1,
                $s->sql->rows("sis_admission_change_requests"),
            );
        }
        $s->sql->update(
            "sis_admission_change_requests",
            ["area" => "entry_term"],
            ["id" => $id->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $service->listing($f->application, true);
    }

    public function testRelinkedOwnerDoesNotReceiveOriginalRequestAndClosureCreatesNoUpdateForDifferentOwner(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $service = $f->owner();
        $id = EntityId::generate();
        $service->request(
            $f->application,
            $id,
            "program",
            "Original account private request.",
            0,
        );
        $nextOwner = $s->user();
        $a = $s->admissions->application($f->application);
        $s->sql->update(
            "sis_persons",
            ["owner_user_id" => $nextOwner->value],
            ["id" => $a->personId->value],
        );
        try {
            $service->listing($f->application, true);
            self::fail("An old owner retained application access.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->actor = $nextOwner;
        self::assertSame([], $service->listing($f->application, true)["items"]);
        $s->access->actor = $f->manager;
        self::assertTrue(
            $service->listing($f->application, false)["items"][0][
                "owner_changed"
            ],
        );
        $service->finish(
            $f->application,
            $id,
            false,
            0,
            0,
            "Resolved without messaging the new account.",
        );
        self::assertSame([], $s->sql->rows("sis_admission_updates"));
    }

    public function testFinishedPagesAndLifetimeLimitAreBoundedWhileExactOriginalRetriesRemainAvailable(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $repository = new \Infrastructure\Persistence\Repository\SqlAdmissionChangeRequestRepository(
            $s->sql,
            $s->dropCipher,
        );
        $a = $s->admissions->application($f->application);
        $ids = [];
        for ($i = 0; $i < 99; $i++) {
            $id = EntityId::generate();
            $ids[] = $id->value;
            $record = \Domain\Admissions\AdmissionChangeRequest::create(
                $id,
                $a->id,
                $a->personId,
                $f->owner,
                0,
                "program",
                "Fictional completed follow-up.",
                "2026-08-10T17:00:00Z",
            );
            $record->finish(
                "cancel",
                $f->owner,
                0,
                0,
                "Fictional cancellation.",
                "2026-08-10T17:00:01Z",
            );
            $repository->save($record, null);
        }
        rsort($ids);
        $owner = $f->owner();
        $first = $owner->listing($a->id, true, true);
        self::assertSame(
            array_slice($ids, 0, 10),
            array_column($first["items"], "id"),
        );
        $second = $owner->listing(
            $a->id,
            true,
            true,
            new EntityId($first["next_after"]),
        );
        self::assertSame(
            array_slice($ids, 10, 10),
            array_column($second["items"], "id"),
        );
        $id = EntityId::generate();
        $accepted = $owner->request(
            $a->id,
            $id,
            "entry_term",
            "Fictional hundredth request.",
            0,
        );
        try {
            $owner->request(
                $a->id,
                EntityId::generate(),
                "program",
                "Fictional excess request.",
                0,
            );
            self::fail("The lifetime request limit was bypassed.");
        } catch (ConcurrentModificationException) {
            self::assertSame(100, $repository->count($a->id));
        }
        self::assertSame(
            $accepted,
            $owner->request(
                $a->id,
                $id,
                "entry_term",
                "Fictional hundredth request.",
                0,
            ),
        );
    }

    public function testClosureAuditFailureRollsBackReplyAndOwnerUpdateAndReadAuditFailureReturnsNoDetails(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $id = EntityId::generate();
        $f->owner()->request(
            $f->application,
            $id,
            "program",
            "Fictional request.",
            0,
        );
        $s->access->actor = $f->manager;
        $before = $s->sql->rows("sis_admission_change_requests");
        $audit = new class implements AuditTrail {
            public function record(
                EntityId $actor,
                string $action,
                EntityId $subject,
            ): void {
                throw new \RuntimeException(
                    "Fictional required audit failure.",
                );
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        foreach (["finish", "read"] as $operation) {
            try {
                if ($operation === "finish") {
                    $f->service($audit)->finish(
                        $f->application,
                        $id,
                        false,
                        0,
                        0,
                        "Fictional reply.",
                    );
                } else {
                    $f->service($audit)->listing($f->application, false);
                }
                self::fail(
                    "Required audit failure returned private follow-up data.",
                );
            } catch (\RuntimeException $error) {
                self::assertSame(
                    "Fictional required audit failure.",
                    $error->getMessage(),
                );
            }
            self::assertSame(
                $before,
                $s->sql->rows("sis_admission_change_requests"),
            );
            self::assertSame([], $s->sql->rows("sis_admission_updates"));
        }
    }

    public function testSuperAccountProtectionAppliesToTheOriginalOwnerAfterAccountRelinking(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $id = EntityId::generate();
        $f->owner()->request(
            $f->application,
            $id,
            "program",
            "Fictional original owner request.",
            0,
        );
        $a = $s->admissions->application($f->application);
        $replacement = $s->user();
        $s->sql->update(
            "sis_persons",
            ["owner_user_id" => $replacement->value],
            ["id" => $a->personId->value],
        );
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository(
            $s->sql,
        );
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $f->owner,
            $roles->roleNamed("super")->id,
            true,
        );
        $s->access->actor = $f->manager;
        $s->access->staff = false;
        $s->access->grants = ["sis:admissions:manage"];
        $this->expectException(AccessDeniedException::class);
        $f->service()->listing($f->application, false);
    }
}
