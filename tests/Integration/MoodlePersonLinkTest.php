<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Domain\Access\Enum\GrantKind;
use Domain\Audit\Repository\AuditTrail;
use Domain\Lms\Exception\MoodleConnectionUnavailableException;
use Domain\Lms\Service\MoodleBoundRecordReader;
use Domain\Lms\Service\MoodlePersonLinkService;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Lms\ValueObject\MoodleExternalId;
use Domain\Lms\ValueObject\MoodleRecordCheck;
use Domain\Lms\ValueObject\MoodleResourceKind;
use Domain\Person\Person;
use Domain\Person\ValueObject\InstitutionalId;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\UserAccountAccess;
use Infrastructure\Lms\SqlMoodleBindingRepository;
use Infrastructure\Lms\SqlMoodlePersonLinkRepository;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use PHPUnit\Framework\TestCase;

final class MoodlePersonLinkTest extends TestCase
{
    private SisFixture $f;
    private MoodleBoundRecordReader $reader;
    private SqlMoodleBindingRepository $bindings;
    private SqlMoodlePersonLinkRepository $links;
    private MoodlePersonLinkService $service;
    private Person $person;
    protected function setUp(): void
    {
        $this->f = new SisFixture();
        $this->person = $this->person();
        $this->bindings = new SqlMoodleBindingRepository($this->f->sql);
        $this->links = new SqlMoodlePersonLinkRepository($this->f->sql);
        $this->bindings->add(
            new MoodleBinding(
                EntityId::generate(),
                str_repeat("a", 64),
                str_repeat("b", 64),
                $this->f->access->actor,
                "2026-11-11T12:00:00Z",
            ),
            "fictional-ciphertext",
        );
        $this->reader = new class implements MoodleBoundRecordReader {
            public bool $active = true;
            public int $calls = 0;
            public bool $returned = true;
            public array $flags = ["suspended" => false, "deleted" => null];
            public ?Closure $duringCall = null;
            public function enabled(): bool
            {
                return $this->active;
            }
            public function inspectBound(
                MoodleBinding $binding,
                MoodleResourceKind $kind,
                MoodleExternalId $id,
            ): MoodleRecordCheck {
                $this->calls++;
                if ($this->duringCall !== null) {
                    ($this->duringCall)();
                }
                return new MoodleRecordCheck($kind, $this->returned, $this->flags);
            }
        };
        $this->service = $this->service();
    }
    private function person(?EntityId $owner = null): Person
    {
        $person = new Person(
            EntityId::generate(),
            $owner,
            "Alex",
            "Example",
            new InstitutionalId("S-" . bin2hex(random_bytes(4))),
        );
        $this->f->admissions->addPerson($person);
        return $person;
    }
    private function service(?AuditTrail $audit = null): MoodlePersonLinkService
    {
        return new MoodlePersonLinkService(
            $this->reader,
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
    private function review(): array
    {
        return $this->service->review(
            $this->person->id,
            new MoodleExternalId("123"),
            "Independently verified institutional account.",
        );
    }
    public function testStatusIsLocalAndReviewedApprovalIsAtomicEncryptedAndIdempotent(): void
    {
        $events = $this->f->sql->rows("sis_history_events");
        self::assertNull($this->service->status($this->person->id)["link"]);
        self::assertSame(0, $this->reader->calls);
        $this->reader->duringCall = function (): void {
            self::assertFalse($this->f->connection->pdo->inTransaction());
        };
        $review = $this->review();
        self::assertSame(["suspended" => false, "deleted" => null], $review["status"]);
        self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        $saved = $this->service->confirm($this->person->id, $review["review_token"]);
        self::assertSame("123", $saved["link"]["moodle_user_id"]);
        self::assertSame(2, $this->reader->calls);
        $row = $this->f->sql->rows("sis_moodle_person_links")[0];
        self::assertStringNotContainsString("Independently verified", $row["encrypted_review"]);
        self::assertStringContainsString(
            "Independently verified",
            $this->f->dropCipher->open($row["encrypted_review"]),
        );
        $audit = $this->f->audit->forSubject($this->person->id);
        $this->f->clock->time = $this->f->clock->time->modify("+1 day");
        $this->reader->active = false;
        self::assertSame($saved, $this->service->confirm($this->person->id, $review["review_token"]));
        self::assertSame($audit, $this->f->audit->forSubject($this->person->id));
        self::assertSame(2, $this->reader->calls);
        self::assertSame($events, $this->f->sql->rows("sis_history_events"));
    }
    public function testMoodleAdministrationAloneDoesNotGrantPersonLinkAccess(): void
    {
        $this->f->access->staff = false;
        foreach ([["sis:moodle:manage", "sis:person:manage"], ["sis:moodle:link-person"]] as $grants) {
            $this->f->access->grants = $grants;
            try {
                $this->service->status($this->person->id);
                self::fail();
            } catch (AccessDeniedException) {
                self::assertSame(0, $this->reader->calls);
            }
        }
    }

    public function testLinkedSignInAccountUsesTheSharedAccountBoundaryAndReturnsTheLink(): void
    {
        $this->person = $this->person($this->f->user());
        $this->reader->duringCall = function (): void {
            self::assertFalse($this->f->connection->pdo->inTransaction());
        };
        $review = $this->review();
        $saved = $this->service->confirm($this->person->id, $review["review_token"]);
        self::assertSame($saved["link"], $this->service->status($this->person->id)["link"]);
        self::assertSame(
            $this->person->ownerUserId->value,
            $this->f->admissions->person($this->person->id)->ownerUserId->value,
        );
    }

    public function testDatabaseUniquenessProtectsBothSidesAndPermissionIsIndependent(): void
    {
        $review = $this->review();
        $this->service->confirm($this->person->id, $review["review_token"]);
        $binding = $this->bindings->current();
        foreach (
            [
                [$this->person->id, new MoodleExternalId("456")],
                [$this->person()->id, new MoodleExternalId("123")],
            ]
            as [$person, $external]
        ) {
            try {
                $this->f->transactions->run(
                    fn() => $this->links->add(
                        new \Domain\Lms\ValueObject\MoodlePersonLink(
                            EntityId::generate(),
                            $binding->id,
                            $person,
                            $external,
                            $this->f->access->actor,
                            "2026-11-11T12:00:00Z",
                        ),
                        "fictional-encrypted-review",
                    ),
                );
                self::fail();
            } catch (ConcurrentModificationException) {
                self::assertCount(1, $this->f->sql->rows("sis_moodle_person_links"));
            }
        }
        $permission = $this->f->sql->rows(
            "sis_access_permissions",
            ["name" => MoodlePersonLinkService::LINK],
            1,
        )[0];
        self::assertSame(1, (int) $permission["is_system"]);
        $implications = $this->f->sql->rows("sis_access_implications", [
            "descendant_id" => $permission["id"],
        ]);
        self::assertCount(1, $implications);
        self::assertSame($permission["id"], $implications[0]["ancestor_id"]);
    }
    public function testDisabledIntegrationCannotReviewOrConfirmNewLinks(): void
    {
        $review = $this->review();
        $this->reader->active = false;
        $this->expectException(MoodleConnectionUnavailableException::class);
        $this->service->confirm($this->person->id, $review["review_token"]);
    }
    public function testExpiredReviewAndWrongActorOrPersonCannotConfirm(): void
    {
        $review = $this->review();
        $other = $this->person();
        try {
            $this->service->confirm($other->id, $review["review_token"]);
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
    public function testLocalProfileChangesAndRemoteStatusChangesRequireNewReview(): void
    {
        $review = $this->review();
        $this->f->sql->update("sis_persons", ["given_name" => "Changed"], ["id" => $this->person->id->value]);
        try {
            $this->service->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame(1, $this->reader->calls);
        }
        $review = $this->review();
        $this->reader->flags = ["suspended" => null];
        $this->expectException(ConcurrentModificationException::class);
        $this->service->confirm($this->person->id, $review["review_token"]);
    }
    public function testMissingSuspendedAndDeletedAccountsCannotReceiveNewLinks(): void
    {
        foreach (
            [
                ["returned" => false, "flags" => []],
                ["returned" => true, "flags" => ["suspended" => true]],
                ["returned" => true, "flags" => ["deleted" => true]],
            ]
            as $scenario
        ) {
            $this->reader->returned = $scenario["returned"];
            $this->reader->flags = $scenario["flags"];
            try {
                $this->review();
                self::fail();
            } catch (ValidationException) {
                self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
            }
        }
    }
    public function testConcurrentReviewsCannotLinkOneMoodleAccountToTwoPeople(): void
    {
        $review = $this->review();
        $other = $this->person();
        $second = $this->service->review($other->id, new MoodleExternalId("123"), "Fictional second review.");
        $this->service->confirm($this->person->id, $review["review_token"]);
        try {
            $this->service->confirm($other->id, $second["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $this->f->sql->rows("sis_moodle_person_links"));
        }
    }
    public function testPermissionRevocationDuringLookupPreventsConfirmation(): void
    {
        $review = $this->review();
        $this->reader->duringCall = function (): void {
            $this->f->access->staff = false;
        };
        try {
            $this->service->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (AccessDeniedException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        }
    }
    public function testOrdinaryAdministratorCannotLinkASuperAccount(): void
    {
        $target = $this->f->user();
        $person = $this->person($target);
        $roles = new SqlAccessRepository($this->f->sql);
        $roles->assign(GrantKind::UserRole, $target, $roles->roleNamed("super")->id, true);
        $this->expectException(AccessDeniedException::class);
        $this->service->review(
            $person->id,
            new MoodleExternalId("123"),
            "Fictional protected account review.",
        );
    }
    public function testAuditFailureRollsBackThePersonLinkAndNeverExposesProviderDetails(): void
    {
        $review = $this->review();
        $audit = new class ($this->f->audit) implements AuditTrail {
            public function __construct(private AuditTrail $delegate) {}
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                if ($action === "moodle.person_link_approved") {
                    throw new RuntimeException("Fictional audit outage.");
                }
                $this->delegate->record($actor, $action, $subject);
            }
            public function forSubject(EntityId $subject): array
            {
                return $this->delegate->forSubject($subject);
            }
        };
        try {
            $this->service($audit)->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (RuntimeException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        }
        $this->reader->duringCall = static function (): never {
            throw new RuntimeException("fictional-secret upstream profile");
        };
        try {
            $this->review();
            self::fail();
        } catch (MoodleConnectionUnavailableException $error) {
            self::assertNull($error->getPrevious());
        }
    }
    public function testMalformedOrCrossPurposeReviewsCannotCreateLinks(): void
    {
        $review = $this->review();
        $data = json_decode($this->f->dropCipher->open($review["review_token"]), true);
        foreach (
            [
                "fictional-token",
                $this->f->dropCipher->seal(json_encode(array_replace($data, ["kind" => "another-kind"]))),
                $this->f->dropCipher->seal(json_encode(array_replace($data, ["external_id" => "01"]))),
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
    }

    public function testMissingInstallationAndExpiryDuringLookupCannotCreateLinks(): void
    {
        $review = $this->review();
        $this->reader->duringCall = function (): void {
            $this->f->clock->time = $this->f->clock->time->modify("+21 minutes");
        };
        try {
            $this->service->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (ValidationException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        }
        $this->reader->duringCall = null;
        $binding = $this->bindings->current();
        $this->f->sql->execute("DELETE FROM sis_moodle_bindings WHERE id = ?", [$binding->id->value]);
        $calls = $this->reader->calls;
        try {
            $this->review();
            self::fail();
        } catch (ValidationException) {
            self::assertSame($calls, $this->reader->calls);
        }
    }

    public function testLocalChangeDuringConfirmationLookupIsRejected(): void
    {
        $review = $this->review();
        $this->reader->duringCall = function (): void {
            $this->f->sql->update(
                "sis_persons",
                ["given_name" => "Changed"],
                ["id" => $this->person->id->value],
            );
        };
        try {
            $this->service->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
        }
    }

    public function testPermissionRevokedAfterInsertRollsBackTheLinkAndApprovalAudit(): void
    {
        $review = $this->review();
        $audit = new class ($this->f) implements AuditTrail {
            public function __construct(private SisFixture $f) {}
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                $this->f->audit->record($actor, $action, $subject);
                if ($action === "moodle.person_link_approved") {
                    $this->f->access->staff = false;
                }
            }
            public function forSubject(EntityId $subject): array
            {
                return $this->f->audit->forSubject($subject);
            }
        };
        try {
            $this->service($audit)->confirm($this->person->id, $review["review_token"]);
            self::fail();
        } catch (AccessDeniedException) {
            self::assertSame([], $this->f->sql->rows("sis_moodle_person_links"));
            self::assertSame(
                [],
                $this->f->sql->rows("sis_audit", ["action" => "moodle.person_link_approved"]),
            );
        }
    }
}
