<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Lms\Repository\MoodleAccountDefaults;
use Domain\Lms\Service\MoodleAccountRequestPolicy;
use Domain\Lms\Service\MoodleAccountRequestService;
use Domain\Lms\Service\MoodleInstanceReader;
use Domain\Lms\ValueObject\MoodleAccountDraft;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleInstallation;
use Domain\Lms\ValueObject\MoodleInstanceIdentity;
use Domain\Lms\ValueObject\MoodlePersonLink;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleSiteUrl;
use Domain\Person\Person;
use Domain\Person\ValueObject\InstitutionalId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\UserAccountAccess;
use Infrastructure\Lms\SqlMoodleAccountRequestRepository;
use Infrastructure\Lms\SqlMoodleBindingRepository;
use Infrastructure\Lms\SqlMoodlePersonLinkRepository;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use PHPUnit\Framework\TestCase;

final class MoodleAccountRequestTest extends TestCase
{
    private SisFixture $f;
    private Person $person;
    private MoodleAccountRequestPolicy $policy;
    private MoodleInstanceReader $reader;
    private MoodleAccountDefaults $defaults;
    private SqlMoodleBindingRepository $bindings;
    private SqlMoodleAccountRequestRepository $requests;
    private SqlMoodlePersonLinkRepository $links;
    private MoodleAccountRequestService $service;

    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->person = $this->person();
        $this->bindings = new SqlMoodleBindingRepository($this->f->sql);
        $this->requests = new SqlMoodleAccountRequestRepository($this->f->sql);
        $this->links = new SqlMoodlePersonLinkRepository($this->f->sql);
        $this->bindings->add(
            new MoodleBinding(
                EntityId::generate(),
                hash("sha256", "https://moodle.example.invalid"),
                hash("sha256", str_repeat("a", 32)),
                $this->f->access->actor,
                "2026-11-12T12:00:00Z",
            ),
            "fictional-encrypted",
        );
        $this->policy = new class implements MoodleAccountRequestPolicy {
            public bool $active = true;
            public bool $processing = false;
            public function processingEnabled(): bool
            {
                return $this->active && $this->processing;
            }
            public function enabled(): bool
            {
                return $this->active;
            }
        };
        $this->reader = new class implements MoodleInstanceReader {
            public int $calls = 0;
            public bool $active = true;
            public string $identity = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";
            public ?Closure $duringCall = null;
            public function enabled(): bool
            {
                return $this->active;
            }
            public function identify(): MoodleInstallation
            {
                $this->calls++;
                if ($this->duringCall !== null) {
                    ($this->duringCall)();
                }
                return new MoodleInstallation(
                    new MoodleSiteUrl("https://moodle.example.invalid"),
                    new MoodleInstanceIdentity($this->identity),
                );
            }
        };
        $this->defaults = new class implements MoodleAccountDefaults {
            public string $email = "fictional@example.invalid";
            public function forOwner(?EntityId $owner): array
            {
                return ["username" => "alex_example", "email" => $this->email];
            }
        };
        $this->service = $this->service();
    }

    private function remote(): \Domain\Lms\Service\MoodleAccountProvisioner
    {
        return new class ($this->f) implements \Domain\Lms\Service\MoodleAccountProvisioner {
            public int $calls = 0;
            public int $nextId = 500;
            public array $operations = [];
            public bool $loseResponse = false;
            public ?Closure $duringCall = null;
            public string $status = "sso_ready";
            public function __construct(private SisFixture $f) {}
            public function policy(MoodleBinding $binding): \Domain\Lms\ValueObject\MoodleAccountPolicy
            {
                return new \Domain\Lms\ValueObject\MoodleAccountPolicy(str_repeat("a", 64), true, true);
            }
            public function provision(
                MoodleBinding $binding,
                EntityId $operation,
                string $policyHash,
                MoodleAccountDraft $draft,
                string $firstName,
                string $lastName,
            ): \Domain\Lms\ValueObject\MoodleProvisionedAccount {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction());
                TestCase::assertSame(str_repeat("a", 64), $policyHash);
                TestCase::assertSame("Alex", $firstName);
                $this->calls++;
                $this->operations[$operation->value] ??= new \Domain\Lms\ValueObject\MoodleProvisionedAccount(
                    new MoodleExternalId((string) ($this->nextId + count($this->operations))),
                    $this->status,
                );
                if ($this->duringCall !== null) {
                    ($this->duringCall)();
                }
                if ($this->loseResponse) {
                    $this->loseResponse = false;
                    throw new \Domain\Lms\Exception\MoodleConnectionUnavailableException();
                }
                return $this->operations[$operation->value];
            }
        };
    }
    private function worker(
        \Domain\Lms\Service\MoodleAccountProvisioner $remote,
        ?AuditTrail $audit = null,
    ): \Domain\Lms\Service\MoodleProvisioningWorker {
        return new \Domain\Lms\Service\MoodleProvisioningWorker(
            $this->policy,
            new \Infrastructure\Lms\SqlMoodleProvisioningQueue($this->f->sql),
            $this->bindings,
            $this->links,
            $this->f->admissions,
            new SqlAccessRepository($this->f->sql),
            new \Domain\Lms\Service\MoodleAccountProfile($this->defaults),
            $remote,
            $this->f->dropCipher,
            $this->f->transactions,
            $audit ?? $this->f->audit,
            $this->f->clock,
        );
    }
    private function permitWorker(): void
    {
        $this->policy->processing = true;
        $access = new SqlAccessRepository($this->f->sql);
        $access->assign(GrantKind::UserRole, $this->f->access->actor, $access->roleNamed("super")->id, true);
    }

    private function receiptReader(): \Domain\Lms\Service\MoodleAccountReceiptReader
    {
        return new class ($this->f) implements \Domain\Lms\Service\MoodleAccountReceiptReader {
            public int $calls = 0;
            public ?Closure $duringCall = null;
            public string $status = "recorded";
            public string $signInStatus = "sso_ready";
            public string $userId = "500";
            public function __construct(private SisFixture $f) {}
            public function receipt(
                MoodleBinding $binding,
                EntityId $operation,
                string $requestHash,
            ): \Domain\Lms\ValueObject\MoodleAccountReceipt {
                TestCase::assertFalse($this->f->connection->pdo->inTransaction());
                $row = $this->f->sql->one("sis_moodle_account_requests", ["id" => $operation->value]);
                $data = json_decode($this->f->dropCipher->open($row["encrypted_approval"]), true);
                $remote = [
                    "instance_hash" => $binding->instanceFingerprint,
                    "policy_hash" => $data["policy_hash"],
                    "operation" => $operation->value,
                    "username" => $data["username"],
                    "email" => $data["email"],
                    "firstname" => $data["firstname"],
                    "lastname" => $data["lastname"],
                    "sign_in" => $data["sign_in"],
                ];
                TestCase::assertSame(hash("sha256", json_encode($remote, JSON_THROW_ON_ERROR)), $requestHash);
                $this->calls++;
                if ($this->duringCall !== null) {
                    ($this->duringCall)();
                }
                return new \Domain\Lms\ValueObject\MoodleAccountReceipt(
                    $this->status,
                    $this->status === "recorded"
                        ? new \Domain\Lms\ValueObject\MoodleProvisionedAccount(
                            new MoodleExternalId($this->userId),
                            $this->signInStatus,
                        )
                        : null,
                );
            }
        };
    }

    private function inspector(
        \Domain\Lms\Service\MoodleAccountReceiptReader $reader,
        ?AuditTrail $audit = null,
    ): \Domain\Lms\Service\MoodleAccountReconciliationService {
        return new \Domain\Lms\Service\MoodleAccountReconciliationService(
            $this->requests,
            new \Infrastructure\Lms\SqlMoodleProvisioningQueue($this->f->sql),
            $this->bindings,
            $this->links,
            new \Domain\Lms\Service\MoodleAccountApprovalReader($this->f->dropCipher),
            $reader,
            $this->reader,
            $this->f->admissions,
            new \Domain\Lms\Service\MoodleAccountProfile($this->defaults),
            new UserAccountAccess(
                new SqlAccessRepository($this->f->sql),
                $this->f->access,
                $this->f->transactions,
            ),
            $this->f->access,
            $audit ?? $this->f->audit,
            new SqlAccessRepository($this->f->sql),
            $this->f->transactions,
            $this->f->dropCipher,
            $this->f->clock,
        );
    }

    private function uncertainRequest(string $mode = "sso"): void
    {
        $this->permitWorker();
        $review = $this->review($mode);
        $this->service->confirm($this->person->id, $review["review_token"]);
        $remote = $this->remote();
        $remote->loseResponse = true;
        self::assertSame("retry_pending", $this->worker($remote)->runOne());
        self::assertCount(1, $remote->operations);
    }

    private function stoppedRequest(string $mode = "sso"): void
    {
        $this->uncertainRequest($mode);
        $row = $this->f->sql->rows("sis_moodle_provisioning_outbox")[0];
        $this->f->sql->update(
            "sis_moodle_provisioning_outbox",
            [
                "state" => "review_required",
                "issue_code" => "attempt_limit",
                "attempts" => 6,
                "lease_until" => 0,
            ],
            ["id" => $row["id"]],
        );
    }

    public function testReviewedRecoveryRetainsOriginalApprovalCountersAndHistoryWithEncryptedEvidence(): void
    {
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        $service = $this->inspector($reader);
        $approval = $this->f->sql->rows("sis_moodle_account_requests");
        $history = $this->f->sql->rows("sis_history_events");
        $job = $this->f->sql->rows("sis_moodle_provisioning_outbox")[0];
        $review = $service->reviewRecovery(
            $this->person->id,
            "Independently verified the original account belongs to this person.",
        );
        self::assertSame("alex_example", $review["original_username"]);
        self::assertSame("500", $review["moodle_user_id"]);
        self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        $this->policy->active = false;
        $result = $service->confirmRecovery($this->person->id, $review["review_token"]);
        self::assertSame("completed", $result["state"]);
        self::assertSame("500", $result["link"]["moodle_user_id"]);
        self::assertSame(2, $reader->calls);
        self::assertSame($approval, $this->f->sql->rows("sis_moodle_account_requests"));
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
        $after = $this->f->sql->rows("sis_moodle_provisioning_outbox")[0];
        foreach (["id", "request_id", "attempts", "created_at"] as $field) {
            self::assertSame($job[$field], $after[$field]);
        }
        $recovery = $this->f->sql->rows("sis_moodle_account_recoveries")[0];
        $evidence = json_decode($this->f->dropCipher->open($recovery["encrypted_review"]), true);
        self::assertSame($approval[0]["id"], $recovery["request_id"]);
        self::assertSame($result["link"]["id"], $recovery["link_id"]);
        self::assertSame($this->f->access->actor->value, $evidence["review"]["actor_id"]);
        self::assertSame("2026-08-10T17:00:00Z", $evidence["recorded_at"]);
        self::assertStringNotContainsString("Independently verified", $recovery["encrypted_review"]);
        self::assertArrayNotHasKey("encrypted_review", $after);
    }

    public function testRecoveredEmailWarningRemainsIndependentAndOriginalConfirmationCannotUndoResolution(): void
    {
        $this->stoppedRequest("password_email");
        $reader = $this->receiptReader();
        $reader->signInStatus = "email_review_required";
        $service = $this->inspector($reader);
        $review = $service->reviewRecovery($this->person->id, "Verified original account identity.");
        $result = $service->confirmRecovery($this->person->id, $review["review_token"]);
        self::assertSame("review_required", $result["state"]);
        self::assertSame(
            "email_delivery",
            $this->service->status($this->person->id)["request"]["issue_code"],
        );
        $recovery = $this->f->sql->rows("sis_moodle_account_recoveries");
        $email = $this->resolutions()->review($this->person->id, "Verified access through support.");
        $this->resolutions()->confirm($this->person->id, $email["review_token"]);
        $audit = $this->f->audit->forSubject($this->person->id);
        $this->f->clock->time = $this->f->clock->time->modify("+21 minutes");
        $this->reader->active = false;
        self::assertSame($result, $service->confirmRecovery($this->person->id, $review["review_token"]));
        self::assertSame("completed", $this->service->status($this->person->id)["request"]["state"]);
        self::assertSame($recovery, $this->f->sql->rows("sis_moodle_account_recoveries"));
        self::assertSame($audit, $this->f->audit->forSubject($this->person->id));
        self::assertSame(2, $reader->calls);
    }

    public function testRecoveryNeedsSeparateLinkPermissionAndStoppedWorkBeforeRemoteRead(): void
    {
        $this->uncertainRequest();
        $reader = $this->receiptReader();
        try {
            $this->inspector($reader)->reviewRecovery($this->person->id, "Verified identity.");
            self::fail("Active worker operation must not be recovered.");
        } catch (ConcurrentModificationException) {
            self::assertSame(0, $reader->calls);
        }
        $this->f->access->staff = false;
        $this->f->access->grants = [MoodleAccountRequestService::CREATE, "sis:person:manage"];
        $this->expectException(AccessDeniedException::class);
        $this->inspector($reader)->reviewRecovery($this->person->id, "Verified identity.");
    }

    public function testRecoveryRefusesMissingRestrictedAndChangedRemoteReceipts(): void
    {
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        foreach (["not_observed", "restricted"] as $status) {
            $reader->status = $status;
            try {
                $this->inspector($reader)->reviewRecovery($this->person->id, "Verified identity.");
                self::fail("No eligible receipt was returned.");
            } catch (ValidationException) {
                self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
            }
        }
        $reader->status = "recorded";
        $review = $this->inspector($reader)->reviewRecovery($this->person->id, "Verified identity.");
        $reader->userId = "501";
        $this->expectException(ConcurrentModificationException::class);
        $this->inspector($reader)->confirmRecovery($this->person->id, $review["review_token"]);
    }

    public function testRecoveryAuditFailureRollsBackLinkReceiptAndQueueTransition(): void
    {
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        $review = $this->inspector($reader)->reviewRecovery($this->person->id, "Verified identity.");
        $job = $this->f->sql->rows("sis_moodle_provisioning_outbox");
        $audit = $this->createStub(AuditTrail::class);
        $audit
            ->method("record")
            ->willReturnCallback(static function (EntityId $actor, string $action, EntityId $subject): void {
                if ($action === "moodle.account_link_recovered") {
                    throw new RuntimeException("Fictional audit failure.");
                }
            });
        try {
            $this->inspector($reader, $audit)->confirmRecovery($this->person->id, $review["review_token"]);
            self::fail("Audit failure must abort recovery.");
        } catch (RuntimeException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
            self::assertSame([], $this->f->sql->rows("sis_moodle_account_recoveries"));
            self::assertSame($job, $this->f->sql->rows("sis_moodle_provisioning_outbox"));
        }
        self::assertSame(
            "completed",
            $this->inspector($reader)->confirmRecovery($this->person->id, $review["review_token"])["state"],
        );
    }

    public function testRecoveryRejectsCompetingReviewsAndActorOrPersonSubstitution(): void
    {
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        $service = $this->inspector($reader);
        $review = $service->reviewRecovery($this->person->id, "Verified identity.");
        $other = $service->reviewRecovery($this->person->id, "Another verification.");
        $actor = $this->f->access->actor;
        $this->f->access->actor = $this->f->user();
        try {
            $service->confirmRecovery($this->person->id, $review["review_token"]);
            self::fail("Another actor cannot confirm this review.");
        } catch (AccessDeniedException) {
            $this->f->access->actor = $actor;
        }
        try {
            $service->confirmRecovery($this->person()->id, $review["review_token"]);
            self::fail("Another person cannot receive this link.");
        } catch (AccessDeniedException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        }
        $service->confirmRecovery($this->person->id, $review["review_token"]);
        $calls = $reader->calls;
        try {
            $service->confirmRecovery($this->person->id, $other["review_token"]);
            self::fail("Another review cannot replace the recovery.");
        } catch (ConcurrentModificationException) {
            self::assertSame($calls, $reader->calls);
            self::assertCount(1, $this->f->sql->rows("sis_moodle_account_recoveries"));
        }
    }

    public function testRecoveryRejectsExpiredAndOtherPurposeProofBeforeRemoteRead(): void
    {
        $creation = $this->review();
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        $service = $this->inspector($reader);
        $review = $service->reviewRecovery($this->person->id, "Verified identity.");
        $this->f->clock->time = $this->f->clock->time->modify("+20 minutes");
        foreach (
            [$review["review_token"], $review["review_token"] . "tampered", $creation["review_token"]]
            as $token
        ) {
            try {
                $service->confirmRecovery($this->person->id, $token);
                self::fail("An expired or invalid proof was accepted.");
            } catch (ValidationException) {
                self::assertSame(1, $reader->calls);
                self::assertSame([], $this->f->sql->rows("sis_moodle_account_recoveries"));
            }
        }
    }

    public function testRecoveryRechecksLinkPermissionBeforeAndAfterReceiptLookup(): void
    {
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        $service = $this->inspector($reader);
        $review = $service->reviewRecovery($this->person->id, "Verified identity.");
        $this->f->access->staff = false;
        $this->f->access->grants = [MoodleAccountRequestService::CREATE, "sis:person:manage"];
        try {
            $service->confirmRecovery($this->person->id, $review["review_token"]);
            self::fail("Link authority was revoked.");
        } catch (AccessDeniedException) {
            self::assertSame(1, $reader->calls);
        }
        $this->f->access->staff = true;
        $reader->duringCall = function (): void {
            $this->f->access->staff = false;
            $this->f->access->grants = [MoodleAccountRequestService::CREATE, "sis:person:manage"];
        };
        try {
            $service->confirmRecovery($this->person->id, $review["review_token"]);
            self::fail("Link authority changed during lookup.");
        } catch (AccessDeniedException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
            self::assertSame([], $this->f->sql->rows("sis_moodle_account_recoveries"));
        }
    }

    public function testRecoveryCannotTakeAnAccountLinkedToAnotherPerson(): void
    {
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        $service = $this->inspector($reader);
        $review = $service->reviewRecovery($this->person->id, "Verified identity.");
        $other = $this->person();
        $binding = $this->bindings->current();
        $this->links->add(
            new MoodlePersonLink(
                EntityId::generate(),
                $binding->id,
                $other->id,
                new MoodleExternalId("500"),
                $this->f->access->actor,
                "2026-08-10T17:00:00Z",
            ),
            "fictional-existing-link",
        );
        try {
            $service->confirmRecovery($this->person->id, $review["review_token"]);
            self::fail("A linked account was taken from another person.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $this->f->sql->rows("sis_moodle_person_links"));
            self::assertSame(
                $other->id->value,
                $this->f->sql->rows("sis_moodle_person_links")[0]["person_id"],
            );
            self::assertSame([], $this->f->sql->rows("sis_moodle_account_recoveries"));
        }
    }

    public function testRecoveryRefusesProfileChangeAfterReviewAndDuringConfirmation(): void
    {
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        $service = $this->inspector($reader);
        $review = $service->reviewRecovery($this->person->id, "Verified identity.");
        $this->defaults->email = "changed@example.invalid";
        try {
            $service->confirmRecovery($this->person->id, $review["review_token"]);
            self::fail("Stale person review accepted.");
        } catch (ConcurrentModificationException) {
            self::assertSame(1, $reader->calls);
        }
        $review = $service->reviewRecovery(
            $this->person->id,
            "Verified the same person after the account-default change.",
        );
        self::assertTrue($review["profile_changed"]);
        self::assertSame("fictional@example.invalid", $review["original_email"]);
        $reader->duringCall = function (): void {
            $this->defaults->email = "changed-again@example.invalid";
        };
        $this->expectException(ConcurrentModificationException::class);
        $service->confirmRecovery($this->person->id, $review["review_token"]);
    }

    public function testRecoveryRetainsSuperOwnerProtection(): void
    {
        $owner = $this->f->user();
        $this->person = $this->person($owner);
        $access = new SqlAccessRepository($this->f->sql);
        $super = $access->roleNamed("super")->id;
        $access->assign(GrantKind::UserRole, $owner, $super, true);
        $this->stoppedRequest();
        $reader = $this->receiptReader();
        $service = $this->inspector($reader);
        $review = $service->reviewRecovery($this->person->id, "Verified identity.");
        $access->assign(GrantKind::UserRole, $this->f->access->actor, $super, false);
        $this->expectException(AccessDeniedException::class);
        $service->confirmRecovery($this->person->id, $review["review_token"]);
    }

    public function testOriginalReceiptInspectionKeepsLocalFactsAndJobsUnchangedWithoutProcessing(): void
    {
        $this->uncertainRequest();
        $reader = $this->receiptReader();
        $this->policy->active = false;
        $before = [];
        foreach (
            [
                "sis_moodle_account_requests",
                "sis_moodle_provisioning_outbox",
                "sis_moodle_person_links",
                "sis_history_events",
            ]
            as $table
        ) {
            $before[$table] = $this->f->sql->rows($table);
        }
        $result = $this->inspector($reader)->inspect($this->person->id);
        self::assertSame("recorded", $result["status"]);
        self::assertSame("500", $result["moodle_user_id"]);
        self::assertFalse($result["profile_changed"]);
        self::assertSame(1, $reader->calls);
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $this->f->sql->rows($table));
        }
        $encoded = json_encode($result);
        foreach (
            ["fictional@example.invalid", "alex_example", "policy_hash", "request_hash", "encrypted_approval"]
            as $private
        ) {
            self::assertStringNotContainsString($private, $encoded);
        }
    }

    public function testMissingReceiptAndRestrictedReceiptNeverAuthorizeReplacementOrClaimCompletion(): void
    {
        $this->uncertainRequest();
        $reader = $this->receiptReader();
        foreach (["not_observed", "restricted"] as $status) {
            $reader->status = $status;
            $result = $this->inspector($reader)->inspect($this->person->id);
            self::assertSame($status, $result["status"]);
            self::assertNull($result["moodle_user_id"]);
            self::assertStringContainsString("replacement", $result["message"]);
            self::assertSame("processing", $this->service->status($this->person->id)["request"]["state"]);
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        }
    }

    public function testChangedProfileCanInspectOriginalReceiptButIsFlaggedForSeparateReview(): void
    {
        $this->uncertainRequest();
        $this->defaults->email = "changed@example.invalid";
        $reader = $this->receiptReader();
        $result = $this->inspector($reader)->inspect($this->person->id);
        self::assertTrue($result["profile_changed"]);
        self::assertSame("recorded", $result["status"]);
        self::assertSame(1, $reader->calls);
    }

    public function testReceiptInspectionRejectsProfileOrQueueChangesDuringRemoteRead(): void
    {
        $this->uncertainRequest();
        $reader = $this->receiptReader();
        $reader->duringCall = function (): void {
            $this->defaults->email = "changed@example.invalid";
        };
        try {
            $this->inspector($reader)->inspect($this->person->id);
            self::fail("Stale receipt observation returned.");
        } catch (ConcurrentModificationException) {
            $this->defaults->email = "fictional@example.invalid";
        }
        $reader->duringCall = function (): void {
            $row = $this->f->sql->rows("sis_moodle_provisioning_outbox")[0];
            $this->f->sql->update(
                "sis_moodle_provisioning_outbox",
                ["version" => (int) $row["version"] + 1],
                ["id" => $row["id"]],
            );
        };
        $this->expectException(ConcurrentModificationException::class);
        $this->inspector($reader)->inspect($this->person->id);
    }

    public function testReceiptInspectionRechecksAuthorityAfterRemoteRead(): void
    {
        $this->uncertainRequest();
        $reader = $this->receiptReader();
        $reader->duringCall = function (): void {
            $this->f->access->staff = false;
        };
        $this->expectException(AccessDeniedException::class);
        $this->inspector($reader)->inspect($this->person->id);
    }

    public function testDisabledIntegrationAndTamperedApprovalNeverReadRemoteReceipt(): void
    {
        $this->uncertainRequest();
        $reader = $this->receiptReader();
        $this->reader->active = false;
        try {
            $this->inspector($reader)->inspect($this->person->id);
            self::fail("Disabled integration read remote receipt.");
        } catch (\Domain\Lms\Exception\MoodleConnectionUnavailableException) {
            self::assertSame(0, $reader->calls);
        }
        $this->reader->active = true;
        $row = $this->f->sql->rows("sis_moodle_account_requests")[0];
        $this->f->sql->update(
            "sis_moodle_account_requests",
            ["encrypted_approval" => "fictional-tampered-approval"],
            ["id" => $row["id"]],
        );
        try {
            $this->inspector($reader)->inspect($this->person->id);
            self::fail("Tampered approval read remote receipt.");
        } catch (Throwable) {
            self::assertSame(0, $reader->calls);
        }
    }

    public function testReceiptInspectionAuditFailurePreventsRemoteRead(): void
    {
        $this->uncertainRequest();
        $reader = $this->receiptReader();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional audit failure."));
        try {
            $this->inspector($reader, $audit)->inspect($this->person->id);
            self::fail("Unrecorded receipt check proceeded.");
        } catch (RuntimeException) {
            self::assertSame(0, $reader->calls);
        }
    }

    private function resolutions(
        ?AuditTrail $audit = null,
    ): \Domain\Lms\Service\MoodleAccountResolutionService {
        return new \Domain\Lms\Service\MoodleAccountResolutionService(
            $this->requests,
            $this->bindings,
            $this->links,
            $this->f->admissions,
            new \Domain\Lms\Service\MoodleAccountProfile($this->defaults),
            new UserAccountAccess(
                new SqlAccessRepository($this->f->sql),
                $this->f->access,
                $this->f->transactions,
            ),
            new SqlAccessRepository($this->f->sql),
            $this->f->access,
            $this->f->transactions,
            $audit ?? $this->f->audit,
            $this->f->dropCipher,
            $this->f->clock,
        );
    }

    private function emailReview(): array
    {
        $this->permitWorker();
        $review = $this->review("password_email");
        $this->service->confirm($this->person->id, $review["review_token"]);
        $remote = $this->remote();
        $remote->status = "email_review_required";
        self::assertSame("review_required", $this->worker($remote)->runOne());
        return $this->resolutions()->review(
            $this->person->id,
            "Verified access through institutional support.",
        );
    }

    public function testEmailResolutionPreservesApprovalLinkAndOperationWithoutNetworkOrHistoryChanges(): void
    {
        $review = $this->emailReview();
        $approval = $this->f->sql->rows("sis_moodle_account_requests")[0];
        $outbox = $this->f->sql->rows("sis_moodle_provisioning_outbox")[0];
        $links = $this->f->sql->rows("sis_moodle_person_links");
        $history = $this->f->sql->rows("sis_history_events");
        $this->policy->active = false;
        $this->reader->active = false;
        $calls = $this->reader->calls;
        self::assertSame(
            ["state" => "completed"],
            $this->resolutions()->confirm($this->person->id, $review["review_token"]),
        );
        $saved = $this->f->sql->rows("sis_moodle_account_requests")[0];
        foreach ($approval as $field => $value) {
            if (!in_array($field, ["resolution_hash", "encrypted_resolution"], true)) {
                self::assertSame($value, $saved[$field]);
            }
        }
        $evidence = json_decode($this->f->dropCipher->open($saved["encrypted_resolution"]), true);
        self::assertSame($this->f->access->actor->value, $evidence["review"]["actor_id"]);
        self::assertSame($approval["id"], $evidence["review"]["request_id"]);
        self::assertSame($links[0]["id"], $evidence["review"]["link_id"]);
        self::assertSame("Verified access through institutional support.", $evidence["review"]["reason"]);
        self::assertSame("2026-08-10T17:00:00Z", $evidence["resolved_at"]);
        self::assertStringNotContainsString($evidence["review"]["reason"], $saved["encrypted_resolution"]);
        $after = $this->f->sql->rows("sis_moodle_provisioning_outbox")[0];
        foreach (["request_id", "attempts", "created_at", "lease_until"] as $field) {
            self::assertSame($outbox[$field], $after[$field]);
        }
        self::assertSame("email_resolved", $after["issue_code"]);
        self::assertArrayNotHasKey("encrypted_resolution", $after);
        self::assertSame($links, $this->f->sql->rows("sis_moodle_person_links"));
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
        self::assertSame($calls, $this->reader->calls);
        self::assertStringNotContainsString(
            "Verified access",
            json_encode($this->service->status($this->person->id)),
        );
    }

    public function testResolutionRepeatAfterExpiryReturnsOriginalResultAndRejectsCompetingReview(): void
    {
        $review = $this->emailReview();
        $other = $this->resolutions()->review($this->person->id, "Another independently reviewed outcome.");
        $this->resolutions()->confirm($this->person->id, $review["review_token"]);
        $audit = $this->f->audit->forSubject($this->person->id);
        $row = $this->f->sql->rows("sis_moodle_account_requests");
        $this->f->clock->time = $this->f->clock->time->modify("+21 minutes");
        self::assertSame(
            ["state" => "completed"],
            $this->resolutions()->confirm($this->person->id, $review["review_token"]),
        );
        self::assertSame($audit, $this->f->audit->forSubject($this->person->id));
        self::assertSame($row, $this->f->sql->rows("sis_moodle_account_requests"));
        $this->expectException(ConcurrentModificationException::class);
        $this->resolutions()->confirm($this->person->id, $other["review_token"]);
    }

    public function testResolutionAuditFailureRollsBackStateAndEvidence(): void
    {
        $review = $this->emailReview();
        $rows = $this->f->sql->rows("sis_moodle_account_requests");
        $outbox = $this->f->sql->rows("sis_moodle_provisioning_outbox");
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("Fictional failure."));
        try {
            $this->resolutions($audit)->confirm($this->person->id, $review["review_token"]);
            self::fail("Audit failure must abort resolution.");
        } catch (RuntimeException) {
            self::assertSame($rows, $this->f->sql->rows("sis_moodle_account_requests"));
            self::assertSame($outbox, $this->f->sql->rows("sis_moodle_provisioning_outbox"));
        }
        self::assertSame(
            ["state" => "completed"],
            $this->resolutions()->confirm($this->person->id, $review["review_token"]),
        );
    }

    public function testResolutionRefusesExpiredTamperedAndCreationPurposeTokens(): void
    {
        $creation = $this->review();
        $review = $this->emailReview();
        $this->f->clock->time = $this->f->clock->time->modify("+20 minutes");
        foreach (
            [$review["review_token"], $review["review_token"] . "tampered", $creation["review_token"]]
            as $token
        ) {
            try {
                $this->resolutions()->confirm($this->person->id, $token);
                self::fail("Invalid or expired review accepted.");
            } catch (ValidationException) {
                self::assertSame(
                    "review_required",
                    $this->service->status($this->person->id)["request"]["state"],
                );
            }
        }
    }

    public function testResolutionRefusesActorSwitchAndPermissionLoss(): void
    {
        $review = $this->emailReview();
        $actor = $this->f->access->actor;
        $this->f->access->actor = $this->f->user();
        try {
            $this->resolutions()->confirm($this->person->id, $review["review_token"]);
            self::fail("Another actor must not confirm.");
        } catch (AccessDeniedException) {
            $this->f->access->actor = $actor;
        }
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:person:manage"];
        $this->expectException(AccessDeniedException::class);
        $this->resolutions()->confirm($this->person->id, $review["review_token"]);
    }

    public function testResolutionStillRequiresOwnerAccountPermission(): void
    {
        $this->person = $this->person($this->f->access->actor);
        $review = $this->emailReview();
        $this->f->access->staff = false;
        $this->f->access->grants = [MoodleAccountRequestService::CREATE, "sis:person:manage"];
        $this->expectException(AccessDeniedException::class);
        $this->resolutions()->confirm($this->person->id, $review["review_token"]);
    }

    public function testResolutionRetainsSuperAccountProtectionAfterReviewerLosesSuper(): void
    {
        $owner = $this->f->user();
        $this->person = $this->person($owner);
        $access = new SqlAccessRepository($this->f->sql);
        $super = $access->roleNamed("super")->id;
        $access->assign(GrantKind::UserRole, $owner, $super, true);
        $review = $this->emailReview();
        $access->assign(GrantKind::UserRole, $this->f->access->actor, $super, false);
        $this->expectException(AccessDeniedException::class);
        $this->resolutions()->confirm($this->person->id, $review["review_token"]);
    }

    public function testResolutionRefusesChangedVersionAndUncertainCreationOutcome(): void
    {
        $review = $this->emailReview();
        $row = $this->f->sql->rows("sis_moodle_provisioning_outbox")[0];
        $this->f->sql->update(
            "sis_moodle_provisioning_outbox",
            ["version" => (int) $row["version"] + 1],
            ["id" => $row["id"]],
        );
        try {
            $this->resolutions()->confirm($this->person->id, $review["review_token"]);
            self::fail("Stale review accepted.");
        } catch (ConcurrentModificationException) {
            self::assertNull($this->f->sql->rows("sis_moodle_account_requests")[0]["encrypted_resolution"]);
        }
        foreach (["connection_uncertain", "attempt_limit", "request_changed"] as $issue) {
            $this->f->sql->update(
                "sis_moodle_provisioning_outbox",
                ["issue_code" => $issue],
                ["id" => $row["id"]],
            );
            try {
                $this->resolutions()->review($this->person->id, "Verified support outcome.");
                self::fail("Uncertain creation cannot be marked resolved here.");
            } catch (ConcurrentModificationException) {
                self::assertNull($this->f->sql->rows("sis_moodle_account_requests")[0]["resolution_hash"]);
            }
        }
    }

    public function testResolutionRefusesChangedLinkProfileAndBlankReason(): void
    {
        $review = $this->emailReview();
        try {
            $this->resolutions()->review($this->person->id, "  ");
            self::fail("A reason is required.");
        } catch (ValidationException) {
            self::assertNull($this->f->sql->rows("sis_moodle_account_requests")[0]["resolution_hash"]);
        }
        $link = $this->f->sql->rows("sis_moodle_person_links")[0];
        $this->f->sql->update("sis_moodle_person_links", ["moodle_user_id" => "999"], ["id" => $link["id"]]);
        try {
            $this->resolutions()->confirm($this->person->id, $review["review_token"]);
            self::fail("Changed link accepted.");
        } catch (ConcurrentModificationException) {
            $this->f->sql->update(
                "sis_moodle_person_links",
                ["moodle_user_id" => $link["moodle_user_id"]],
                ["id" => $link["id"]],
            );
        }
        $this->f->sql->update("sis_persons", ["given_name" => "Changed"], ["id" => $this->person->id->value]);
        $this->expectException(ConcurrentModificationException::class);
        $this->resolutions()->confirm($this->person->id, $review["review_token"]);
    }

    public function testUnknownQueueIssueNeverBecomesPublicStatusText(): void
    {
        $this->emailReview();
        $row = $this->f->sql->rows("sis_moodle_provisioning_outbox")[0];
        $this->f->sql->update(
            "sis_moodle_provisioning_outbox",
            ["issue_code" => "fictional-private-upstream"],
            ["id" => $row["id"]],
        );
        try {
            $this->service->status($this->person->id);
            self::fail("Unrecognized issue must be refused.");
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString("fictional-private-upstream", $error->getMessage());
        }
    }
    public function testWorkerBothModesRetainLinkAndAuditWithNoHistoryMutation(): void
    {
        $this->permitWorker();
        $history = $this->f->sql->rows("sis_history_events");
        foreach (["sso_ready", "email_sent", "email_review_required"] as $index => $status) {
            $this->person = $this->person();
            $review = $this->review($status === "sso_ready" ? "sso" : "password_email");
            $this->service->confirm($this->person->id, $review["review_token"]);
            $remote = $this->remote();
            $remote->status = $status;
            $remote->nextId = 500 + $index;
            $expected = $status === "email_review_required" ? "review_required" : "completed";
            self::assertSame($expected, $this->worker($remote)->runOne());
            self::assertSame("idle", $this->worker($remote)->runOne());
            self::assertSame(1, $remote->calls);
            $saved = $this->service->status($this->person->id);
            self::assertSame((string) (500 + $index), $saved["link"]["moodle_user_id"]);
            self::assertSame($expected, $saved["request"]["state"]);
            if ($status === "email_review_required") {
                self::assertStringContainsString("password recovery", $saved["request"]["issue"]);
            }
        }
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
    }
    public function testWorkerUncertainResultReconcilesOriginalOperationAfterLease(): void
    {
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $remote = $this->remote();
        $remote->loseResponse = true;
        self::assertSame("disabled", $this->worker($remote)->runOne());
        self::assertSame(0, $remote->calls);
        $this->permitWorker();
        self::assertSame("retry_pending", $this->worker($remote)->runOne());
        self::assertSame("idle", $this->worker($remote)->runOne());
        self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        $this->f->clock->time = $this->f->clock->time->modify("+301 seconds");
        self::assertSame("completed", $this->worker($remote)->runOne());
        self::assertSame(2, $remote->calls);
        self::assertCount(1, $remote->operations);
        self::assertCount(1, $this->f->sql->rows("sis_moodle_person_links"));
    }
    public function testWorkerRefusesRevokedAuthorityAndChangedProfileBeforeRemoteWrite(): void
    {
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $this->policy->processing = true;
        $remote = $this->remote();
        self::assertSame("review_required", $this->worker($remote)->runOne());
        self::assertSame(0, $remote->calls);
        $this->permitWorker();
        $this->person = $this->person();
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $this->defaults->email = "changed@example.invalid";
        self::assertSame("review_required", $this->worker($remote)->runOne());
        self::assertSame(0, $remote->calls);
    }
    public function testWorkerRefusesPolicylessEarlierIntakeWithoutFabricatingPolicy(): void
    {
        $this->permitWorker();
        $review = $this->review();
        $saved = $this->service->confirm($this->person->id, $review["review_token"]);
        $row = $this->f->sql->one("sis_moodle_account_requests", ["id" => $saved["request"]["id"]]);
        $data = json_decode($this->f->dropCipher->open($row["encrypted_approval"]), true);
        unset($data["policy_hash"]);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        $this->f->sql->update(
            "sis_moodle_account_requests",
            [
                "encrypted_approval" => $this->f->dropCipher->seal($encoded),
                "review_hash" => hash("sha256", $encoded),
            ],
            ["id" => $row["id"]],
        );
        $remote = $this->remote();
        self::assertSame("review_required", $this->worker($remote)->runOne());
        self::assertSame(0, $remote->calls);
    }
    public function testWorkerChecksAuthorityAgainAfterRemoteResult(): void
    {
        $this->permitWorker();
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $remote = $this->remote();
        $remote->duringCall = function (): void {
            $this->policy->active = false;
        };
        self::assertSame("review_required", $this->worker($remote)->runOne());
        self::assertCount(1, $remote->operations);
        self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
    }
    public function testWorkerAuditFailureRollsBackLinkAndCompletionAndRetryReconciles(): void
    {
        $this->permitWorker();
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $audit = new class implements AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional audit failure");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $remote = $this->remote();
        try {
            $this->worker($remote, $audit)->runOne();
            self::fail();
        } catch (RuntimeException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        }
        $this->f->clock->time = $this->f->clock->time->modify("+301 seconds");
        self::assertSame("completed", $this->worker($remote)->runOne());
        self::assertCount(1, $remote->operations);
    }
    public function testWorkerStopsAfterFiveUncertainAttempts(): void
    {
        $this->permitWorker();
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $remote = $this->remote();
        $remote->duringCall = static function (): void {
            throw new \Domain\Lms\Exception\MoodleConnectionUnavailableException();
        };
        for ($i = 0; $i < 5; $i++) {
            self::assertSame("retry_pending", $this->worker($remote)->runOne());
            $this->f->clock->time = $this->f->clock->time->modify("+301 seconds");
        }
        self::assertSame("review_required", $this->worker($remote)->runOne());
        self::assertSame(5, $remote->calls);
        self::assertCount(1, $remote->operations);
    }
    public function testWorkerProtectsSuperOwnerAfterRequesterLosesSuperAuthority(): void
    {
        $this->permitWorker();
        $owner = $this->f->user();
        $this->person = $this->person($owner);
        $access = new SqlAccessRepository($this->f->sql);
        $super = $access->roleNamed("super")->id;
        $access->assign(GrantKind::UserRole, $owner, $super, true);
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        foreach ([MoodleAccountRequestService::CREATE, "sis:person:manage", "admin:edit:user"] as $name) {
            $permission = $this->f->sql->one("sis_access_permissions", ["name" => $name]);
            $access->assign(
                GrantKind::UserPermission,
                $this->f->access->actor,
                new EntityId($permission["id"]),
                true,
            );
        }
        $access->assign(GrantKind::UserRole, $this->f->access->actor, $super, false);
        $remote = $this->remote();
        self::assertSame("review_required", $this->worker($remote)->runOne());
        self::assertSame(0, $remote->calls);
    }
    public function testLeaseReclaimInvalidatesOlderWorkerCompletion(): void
    {
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $queue = new \Infrastructure\Lms\SqlMoodleProvisioningQueue($this->f->sql);
        $first = $queue->claim(1000);
        self::assertNull($queue->claim(1001));
        $second = $queue->claim(1301);
        self::assertSame($first["id"], $second["id"]);
        $this->expectException(ConcurrentModificationException::class);
        $queue->finish(new EntityId($first["id"]), $first["version"], "completed", "none");
    }
    private function person(?EntityId $owner = null): Person
    {
        $person = new Person(
            EntityId::generate(),
            $owner,
            "Alex",
            "Example",
            new InstitutionalId("T-" . bin2hex(random_bytes(4))),
        );
        $this->f->admissions->addPerson($person);
        return $person;
    }

    private function service(?AuditTrail $audit = null): MoodleAccountRequestService
    {
        return new MoodleAccountRequestService(
            $this->policy,
            $this->reader,
            new class implements \Domain\Lms\Service\MoodleAccountProvisioner {
                public function policy(MoodleBinding $binding): \Domain\Lms\ValueObject\MoodleAccountPolicy
                {
                    return new \Domain\Lms\ValueObject\MoodleAccountPolicy(str_repeat("a", 64), true, true);
                }
                public function provision(
                    MoodleBinding $binding,
                    EntityId $operation,
                    string $policyHash,
                    MoodleAccountDraft $draft,
                    string $firstName,
                    string $lastName,
                ): \Domain\Lms\ValueObject\MoodleProvisionedAccount {
                    throw new LogicException("Intake must not provision.");
                }
            },
            $this->requests,
            $this->defaults,
            $this->bindings,
            $this->links,
            $this->f->admissions,
            new UserAccountAccess(
                new SqlAccessRepository($this->f->sql),
                $this->f->access,
                $this->f->transactions,
            ),
            $this->f->access,
            $this->f->transactions,
            $audit ?? $this->f->audit,
            $this->f->dropCipher,
            $this->f->clock,
        );
    }

    private function review(string $mode = "sso"): array
    {
        return $this->service->review(
            $this->person->id,
            new MoodleAccountDraft("alex_example", "fictional@example.invalid", $mode),
            "Institution-approved Moodle access.",
        );
    }

    public function testBothSignInChoicesArePrivateReviewsAndQueueOnlyReferences(): void
    {
        $history = $this->f->sql->rows("sis_history_events");
        self::assertSame("alex_example", $this->service->status($this->person->id)["defaults"]["username"]);
        self::assertSame(0, $this->reader->calls);
        $this->reader->duringCall = function (): void {
            self::assertFalse($this->f->connection->pdo->inTransaction());
        };
        foreach (["sso", "password_email"] as $mode) {
            $this->person = $this->person();
            $review = $this->review($mode);
            self::assertSame($mode, $review["sign_in"]);
            self::assertNull($this->service->status($this->person->id)["request"]);
            $result = $this->service->confirm($this->person->id, $review["review_token"]);
            self::assertSame("queued", $result["request"]["state"]);
            self::assertFalse($result["processing_available"]);
            $row = $this->f->sql->one("sis_moodle_account_requests", ["id" => $result["request"]["id"]]);
            $approval = json_decode(
                $this->f->dropCipher->open($row["encrypted_approval"]),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertSame($mode, $approval["sign_in"]);
            self::assertSame("fictional@example.invalid", $approval["email"]);
            self::assertStringNotContainsString("fictional@example.invalid", json_encode($row));
            $job = $this->f->sql->one("sis_moodle_provisioning_outbox", [
                "request_id" => $result["request"]["id"],
            ]);
            self::assertEqualsCanonicalizing(
                [
                    "id",
                    "request_id",
                    "state",
                    "version",
                    "created_at",
                    "lease_until",
                    "attempts",
                    "issue_code",
                ],
                array_keys($job),
            );
            foreach (
                ["fictional@example.invalid", "alex_example", "Alex", "Institution-approved"]
                as $private
            ) {
                self::assertStringNotContainsString($private, json_encode($job));
            }
        }
        self::assertSame($history, $this->f->sql->rows("sis_history_events"));
        self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
    }

    public function testConfirmationRetriesKeepOneRequestOutboxAndApprovalAudit(): void
    {
        $review = $this->review();
        $saved = $this->service->confirm($this->person->id, $review["review_token"]);
        $audit = $this->f->audit->forSubject($this->person->id);
        $this->f->clock->time = $this->f->clock->time->modify("+1 day");
        $this->policy->active = false;
        self::assertSame($saved, $this->service->confirm($this->person->id, $review["review_token"]));
        self::assertSame(2, $this->reader->calls);
        self::assertCount(1, $this->f->sql->rows("sis_moodle_account_requests"));
        self::assertCount(1, $this->f->sql->rows("sis_moodle_provisioning_outbox"));
        self::assertSame($audit, $this->f->audit->forSubject($this->person->id));
    }

    public function testNonFacultyPersonCanRequestWithIndependentPermissionButLinkingPermissionAloneCannot(): void
    {
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:person:manage", "sis:moodle:link-person", "sis:moodle:manage"];
        try {
            $this->review();
            self::fail();
        } catch (AccessDeniedException) {
            self::assertSame(0, $this->reader->calls);
        }
        $this->f->access->grants = ["sis:person:manage", MoodleAccountRequestService::CREATE];
        $review = $this->review();
        self::assertSame(
            "queued",
            $this->service->confirm($this->person->id, $review["review_token"])["request"]["state"],
        );
    }

    public function testDisabledIntakeAndRevocationDuringReadSaveNothing(): void
    {
        $this->policy->active = false;
        self::assertNull($this->service->status($this->person->id)["defaults"]);
        try {
            $this->review();
            self::fail();
        } catch (ValidationException) {
            self::assertSame(0, $this->reader->calls);
        }
        $this->policy->active = true;
        $review = $this->review();
        $this->reader->duringCall = function (): void {
            $this->f->access->staff = false;
        };
        try {
            $this->service->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (AccessDeniedException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_account_requests"));
        }
    }

    public function testExpiredWrongPersonAndWrongActorReviewsCannotConfirm(): void
    {
        $review = $this->review();
        try {
            $this->service->confirm($this->person()->id, $review["review_token"]);
            self::fail();
        } catch (AccessDeniedException) {
            self::assertSame(1, $this->reader->calls);
        }
        $actor = $this->f->access->actor;
        $this->f->access->actor = $this->f->user();
        try {
            $this->service->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (AccessDeniedException) {
            self::assertSame(1, $this->reader->calls);
        }
        $this->f->access->actor = $actor;
        $this->f->clock->time = $this->f->clock->time->modify("+20 minutes");
        $this->expectException(ValidationException::class);
        $this->service->confirm($this->person->id, $review["review_token"]);
    }

    public function testProfileOrInstallationChangeInvalidatesReview(): void
    {
        $review = $this->review();
        $this->defaults->email = "changed@example.invalid";
        try {
            $this->service->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame(1, $this->reader->calls);
        }
        $review = $this->review();
        $this->reader->identity = str_repeat("b", 32);
        try {
            $this->service->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_account_requests"));
        }
    }

    public function testConcurrentReviewsAndExistingLinkCannotCreateTwoRequests(): void
    {
        $first = $this->review();
        $second = $this->review();
        $this->service->confirm($this->person->id, $first["review_token"]);
        try {
            $this->service->confirm($this->person->id, $second["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $this->f->sql->rows("sis_moodle_provisioning_outbox"));
        }
        $this->person = $this->person();
        $review = $this->review();
        $binding = $this->bindings->current();
        $this->links->add(
            new MoodlePersonLink(
                EntityId::generate(),
                $binding->id,
                $this->person->id,
                new MoodleExternalId("123"),
                $this->f->access->actor,
                "2026-11-12T12:00:00Z",
            ),
            "fictional-encrypted",
        );
        $this->expectException(ConcurrentModificationException::class);
        $this->service->confirm($this->person->id, $review["review_token"]);
    }

    public function testAtomicAuditFailureRollsBackApprovalAndOutbox(): void
    {
        $audit = new class ($this->f->audit) implements AuditTrail {
            public function __construct(private AuditTrail $delegate) {}
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                if ($action === "moodle.account_creation_requested") {
                    throw new RuntimeException("Fictional audit outage.");
                }
                $this->delegate->record($actor, $action, $subject);
            }
            public function forSubject(EntityId $subject): array
            {
                return $this->delegate->forSubject($subject);
            }
        };
        $review = $this->review();
        try {
            $this->service($audit)->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (RuntimeException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_account_requests"));
            self::assertSame([], $this->f->sql->rows("sis_moodle_provisioning_outbox"));
        }
    }

    public function testSuperAccountCannotBeRequestedByAnOrdinaryAdministrator(): void
    {
        $owner = $this->f->user();
        $this->person = $this->person($owner);
        $roles = new SqlAccessRepository($this->f->sql);
        $roles->assign(GrantKind::UserRole, $owner, $roles->roleNamed("super")->id, true);
        $this->expectException(AccessDeniedException::class);
        $this->review();
    }

    public function testDraftValidationAndRedactionDoNotAcceptPasswordsOrUnknownModes(): void
    {
        $draft = new MoodleAccountDraft("alex_example", "fictional@example.invalid", "sso");
        self::assertStringNotContainsString("fictional@example.invalid", json_encode($draft));
        self::assertStringNotContainsString("alex_example", print_r($draft, true));
        foreach (
            [
                ["Bad Username", "valid@example.invalid", "sso"],
                ["valid", "bad-email", "sso"],
                ["valid", "valid@example.invalid", "unknown"],
            ]
            as $data
        ) {
            try {
                new MoodleAccountDraft(...$data);
                self::fail();
            } catch (ValidationException) {
                self::assertSame(0, $this->reader->calls);
            }
        }
    }

    public function testTamperedOrCrossPurposeProofCannotEnqueue(): void
    {
        $review = $this->review();
        $data = json_decode($this->f->dropCipher->open($review["review_token"]), true);
        foreach (
            [
                "fictional-token",
                $this->f->dropCipher->seal(
                    json_encode(array_replace($data, ["kind" => "moodle-person-link"])),
                ),
                $this->f->dropCipher->seal(json_encode(array_replace($data, ["sign_in" => "unsupported"]))),
            ]
            as $token
        ) {
            try {
                $this->service->confirm($this->person->id, $token);
                self::fail();
            } catch (ValidationException) {
                self::assertSame(1, $this->reader->calls);
            }
        }
        self::assertSame([], $this->f->sql->rows("sis_moodle_provisioning_outbox"));
    }

    public function testQueuedCreationBlocksACompetingManualLink(): void
    {
        $reader = new class implements \Domain\Lms\Service\MoodleBoundRecordReader {
            public function enabled(): bool
            {
                return true;
            }
            public function inspectBound(
                MoodleBinding $binding,
                \Domain\Lms\ValueObject\MoodleResourceKind $kind,
                MoodleExternalId $id,
            ): \Domain\Lms\ValueObject\MoodleRecordCheck {
                return new \Domain\Lms\ValueObject\MoodleRecordCheck($kind, true);
            }
        };
        $linker = new \Domain\Lms\Service\MoodlePersonLinkService(
            $reader,
            $this->bindings,
            $this->links,
            $this->requests,
            $this->f->admissions,
            new UserAccountAccess(
                new SqlAccessRepository($this->f->sql),
                $this->f->access,
                $this->f->transactions,
            ),
            $this->f->access,
            $this->f->transactions,
            $this->f->audit,
            $this->f->dropCipher,
            $this->f->clock,
        );
        $oldReview = $linker->review(
            $this->person->id,
            new MoodleExternalId("123"),
            "Fictional initial manual review.",
        );
        $creation = $this->review();
        $this->service->confirm($this->person->id, $creation["review_token"]);
        try {
            $linker->confirm($this->person->id, $oldReview["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        }
        $this->expectException(ConcurrentModificationException::class);
        $linker->review(
            $this->person->id,
            new MoodleExternalId("123"),
            "Fictional subsequent manual review.",
        );
    }

    public function testIndependentProtectedPermissionAndUniqueRequestScope(): void
    {
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $binding = $this->bindings->current();
        try {
            $this->f->transactions->run(
                fn() => $this->requests->add(
                    new \Domain\Lms\ValueObject\MoodleAccountRequest(
                        EntityId::generate(),
                        $binding->id,
                        $this->person->id,
                        $this->f->access->actor,
                        "2026-11-12T12:00:00Z",
                        str_repeat("a", 64),
                    ),
                    "fictional-encrypted",
                ),
            );
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $this->f->sql->rows("sis_moodle_account_requests"));
        }
        $permission = $this->f->sql->rows(
            "sis_access_permissions",
            ["name" => MoodleAccountRequestService::CREATE],
            1,
        )[0];
        self::assertSame(1, (int) $permission["is_system"]);
        $implications = $this->f->sql->rows("sis_access_implications", [
            "descendant_id" => $permission["id"],
        ]);
        self::assertCount(1, $implications);
        self::assertSame($permission["id"], $implications[0]["ancestor_id"]);
    }
}
