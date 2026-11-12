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
