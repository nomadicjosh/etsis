<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/CampusFixture.php";

use Defuse\Crypto\Key;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Domain\Student\Service\RestrictionService;
use Infrastructure\Communications\DefuseMessageCipher;
use Infrastructure\Persistence\Repository\SqlRestrictionRepository;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class RestrictionWorkflowTest extends TestCase
{
    private function service(
        SisFixture $f,
        ?MessageCipher $cipher = null,
        ?AuditTrail $audit = null,
    ): RestrictionService {
        $config = Collection::factory([]);
        $config->setConfigKey("app", ["crypto_key" => Key::createNewRandomKey()->saveToAsciiSafeString()]);
        return new RestrictionService(
            new SqlRestrictionRepository($f->sql),
            $f->admissions,
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $cipher ?? new DefuseMessageCipher($config),
            $f->clock,
        );
    }

    private function hold(EntityId $person, ?string $end = null): Restriction
    {
        return new Restriction(
            EntityId::generate(),
            $person,
            "ADVISING",
            99,
            new DateRange(
                new DateTimeImmutable("2026-08-01"),
                $end === null ? null : new DateTimeImmutable($end),
            ),
            true,
        );
    }

    public function testCreationEncryptsNotesAndRetriesWithoutDuplicatingRecords(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $hold = $this->hold($f->student()->personId);
        $note = "Fictional sensitive advising note <script>example</script>.";
        $service->create($hold, $note);
        $service->create($hold, $note);
        self::assertCount(1, $f->sql->rows("sis_restrictions"));
        $stored = $f->sql->rows("sis_restriction_notes");
        self::assertCount(1, $stored);
        self::assertStringNotContainsString($note, $stored[0]["encrypted_content"]);
        self::assertSame($note, $service->notes($hold->id)["items"][0]["content"]);
        self::assertCount(1, $f->sql->rows("sis_audit", ["action" => "restriction.created"]));
        self::assertCount(1, $f->sql->rows("sis_audit", ["action" => "restriction.notes_read"]));
        self::assertStringNotContainsString(
            $note,
            json_encode($f->sql->rows("sis_audit"), JSON_THROW_ON_ERROR),
        );
        try {
            $service->create($hold, "Changed retry payload");
            self::fail("An operation ID was reused with different note content.");
        } catch (ConcurrentModificationException) {
            self::assertSame($note, $service->notes($hold->id)["items"][0]["content"]);
        }
    }

    public function testAppendedNotesAreRepeatableAndBoundToTheirAuthorAndRestriction(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $person = $f->student()->personId;
        $hold = $this->hold($person);
        $other = $this->hold($person);
        $service->create($hold, "First fictional note");
        $service->create($other, "Other fictional note");
        $noteId = EntityId::generate();
        $service->appendNote($hold->id, $noteId, "Fictional follow-up");
        $service->appendNote($hold->id, $noteId, "Fictional follow-up");
        self::assertCount(2, $service->notes($hold->id)["items"]);
        self::assertCount(1, $f->sql->rows("sis_audit", ["action" => "restriction.note_added"]));
        try {
            $service->appendNote($other->id, $noteId, "Fictional follow-up");
            self::fail("A note operation moved between restrictions.");
        } catch (ConcurrentModificationException) {
            self::assertCount(1, $service->notes($other->id)["items"]);
        }
        $f->access->actor = $f->user();
        $this->expectException(ConcurrentModificationException::class);
        $service->appendNote($hold->id, $noteId, "Fictional follow-up");
    }

    public function testDateOnlyHoldsIncludeTheWholeEndDayAndRespectStaffOverrides(): void
    {
        $hold = $this->hold(EntityId::generate(), "2026-08-10");
        self::assertTrue(
            $hold->blocksRegistration(new DateTimeImmutable("2026-08-10T23:59:59.999999-07:00"), false),
        );
        self::assertSame("ended", $hold->state(new DateTimeImmutable("2026-08-11T00:00:00-07:00")));
        self::assertSame("scheduled", $hold->state(new DateTimeImmutable("2026-07-31T23:59:59-07:00")));
        $informational = new Restriction(
            EntityId::generate(),
            $hold->personId,
            "INFO",
            10,
            $hold->dates,
            false,
        );
        self::assertFalse(
            $informational->blocksRegistration(new DateTimeImmutable("2026-08-10T12:00:00Z"), false),
        );
        self::assertFalse(
            $informational->blocksRegistration(new DateTimeImmutable("2026-08-10T12:00:00Z"), true),
        );
    }

    public function testScheduledEndsCanShortenButCannotExtendAndStaleWritesCannotWin(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $hold = $this->hold($f->student()->personId, "2026-08-20");
        $service->create($hold, "Fictional reason");
        $before = $service->view($hold->id);
        $service->end($hold->id, new DateTimeImmutable("2026-08-15"), $before["revision"]);
        try {
            $service->end($hold->id, new DateTimeImmutable("2026-08-12"), $before["revision"]);
            self::fail("A stale ending replaced a more recent decision.");
        } catch (ConcurrentModificationException) {
            self::assertSame("2026-08-15", $service->view($hold->id)["ends_on"]);
        }
        $fresh = $service->view($hold->id);
        try {
            $service->end($hold->id, new DateTimeImmutable("2026-08-16"), $fresh["revision"]);
            self::fail("A scheduled end was silently extended.");
        } catch (ValidationException $error) {
            self::assertSame("ends_on", $error->errors[0]["field"]);
        }
        $service->end($hold->id, new DateTimeImmutable("2026-08-09"), $fresh["revision"]);
        $ended = $service->view($hold->id);
        self::assertSame("ended", $ended["state"]);
        $service->appendNote($hold->id, EntityId::generate(), "Fictional historical follow-up");
        self::assertCount(2, $service->notes($hold->id)["items"]);
        $this->expectException(DomainException::class);
        $service->end($hold->id, new DateTimeImmutable("2026-08-08"), $ended["revision"]);
    }

    public function testOwnersWithoutRegistrarAuthorityCannotReadOrWriteInternalNotes(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $student = $f->student();
        $hold = $this->hold($student->personId);
        $service->create($hold, "Fictional private note");
        $revision = $service->view($hold->id)["revision"];
        $f->access->staff = false;
        $historyCases1 = [
                fn() => $service->view($hold->id),
                fn() => $service->page($student->personId),
                fn() => $service->notes($hold->id),
                fn() => $service->appendNote($hold->id, EntityId::generate(), "Not allowed"),
                fn() => $service->end($hold->id, new DateTimeImmutable("2026-08-09"), $revision),
                fn() => $service->create($this->hold($student->personId), "Not allowed"),
            ];
        foreach ($historyCases1 as $operation) {
            try {
                $operation();
                self::fail("Record ownership exposed internal hold management.");
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows("sis_restriction_notes"));
            }
        }
    }

    public function testPortalDoesNotExposeStaffNotesOrCiphertext(): void
    {
        $campus = new CampusFixture();
        $f = $campus->sis;
        $student = $f->student();
        $service = $this->service($f, $campus->cipher);
        $hold = $this->hold($student->personId);
        $service->create($hold, "Fictional note never shown in portal");
        $portal = json_encode($campus->service->me(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString("Fictional note never shown in portal", $portal);
        self::assertStringNotContainsString("encrypted_content", $portal);
        self::assertStringNotContainsString(
            $f->sql->rows("sis_restriction_notes")[0]["encrypted_content"],
            $portal,
        );
    }

    public function testListsAndNotesUseBoundedPages(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $person = $f->student()->personId;
        $first = null;
        for ($i = 0; $i < 51; ++$i) {
            $hold = $this->hold($person);
            $first ??= $hold;
            $service->create($hold, "Fictional reason " . $i);
        }
        $page = $service->page($person);
        self::assertCount(50, $page["items"]);
        self::assertArrayNotHasKey("content", $page["items"][0]);
        $last = $service->page($person, new EntityId($page["next_after"]));
        self::assertCount(1, $last["items"]);
        self::assertNull($last["next_after"]);
        for ($i = 0; $i < 50; ++$i) {
            $service->appendNote($first->id, EntityId::generate(), "Fictional follow-up " . $i);
        }
        $notes = $service->notes($first->id);
        self::assertCount(50, $notes["items"]);
        self::assertStringEndsWith("+00:00", $notes["items"][0]["created_at"]);
        $last = $service->notes($first->id, new EntityId($notes["next_after"]));
        self::assertCount(1, $last["items"]);
        self::assertNull($last["next_after"]);
    }

    public function testEncryptionAndAuditFailuresRollBackTheWholeMutation(): void
    {
        $f = new SisFixture();
        $person = $f->student()->personId;
        $hold = $this->hold($person);
        $cipher = $this->createStub(MessageCipher::class);
        $cipher->method("seal")->willThrowException(new RuntimeException("fictional encryption failure"));
        try {
            $this->service($f, $cipher)->create($hold, "Fictional content");
            self::fail("A hold committed without its encrypted reason.");
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows("sis_restrictions"));
            self::assertSame([], $f->sql->rows("sis_restriction_notes"));
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method("record")->willThrowException(new RuntimeException("fictional audit failure"));
        try {
            $this->service($f, audit: $audit)->create($hold, "Fictional content");
            self::fail("An unaudited restriction committed.");
        } catch (RuntimeException) {
            self::assertSame([], $f->sql->rows("sis_restrictions"));
            self::assertSame([], $f->sql->rows("sis_restriction_notes"));
        }
    }

    public function testRegistrationLocksPersonHoldsBeforeReadingEligibility(): void
    {
        $f = new SisFixture();
        $student = $f->student();
        $section = $f->section(20, 0);
        $order = [];
        $repository = $this->createStub(\Domain\Registration\Repository\RegistrationRepository::class);
        $registrationReflection = new ReflectionClass(\Domain\Registration\Repository\RegistrationRepository::class);
        $historyCases2 = $registrationReflection->getMethods();
        foreach ($historyCases2 as $method) {
            $name = $method->getName();
            if (!in_array($name, ["lockStudent", "lockPerson", "lockSection"], true)) {
                $repository->method($name)->willReturnCallback($f->registration->$name(...));
            }
        }
        foreach (["lockStudent", "lockPerson", "lockSection"] as $name) {
            $repository
                ->method($name)
                ->willReturnCallback(function (
                    EntityId $id,
                ) use (
                    $f,
                    $name,
                    &$order,
                    $student
): void {
                    $order[] = $name;
                    $f->registration->$name($id);
                    if ($name === "lockPerson") {
                        // Simulate a hold committed before this eligibility read acquired the shared person lock.
                        new SqlRestrictionRepository($f->sql)->add($this->hold($student->personId));
                    }
                });
        }
        $billing = $this->createMock(\Domain\Registration\Service\RegistrationBilling::class);
        $billing->expects(self::never())->method("charge");
        $service = new \Domain\Registration\Service\RegistrationService(
            $repository,
            $f->admissions,
            $f->access,
            $f->transactions,
            $billing,
            $f->audit,
            $f->clock,
            $f->drops,
            $f->academicCredits(),
            $f->enrollmentLedger(),
            $f->activityLedger(),
            $f->primaryProgramLedger(),
        );
        try {
            $service->register($student->id, $section->id, true);
            self::fail("Registration ignored a restriction after waiting for its lock.");
        } catch (DomainException) {
            self::assertSame(["lockStudent", "lockPerson", "lockSection"], $order);
            self::assertSame([], $f->sql->rows("sis_enrollments"));
        }
    }

    public function testNotesMigrationCanRollbackAndReapplyWithoutDeletingRestrictions(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $hold = $this->hold($f->student()->personId);
        $service->create($hold, "Fictional fixture for rollback");
        $migration = new CreateSisRestrictionNotesTable("notes-fixture");
        $migration->setObjectMap(
            new ArrayObject([
                "phpmig.adapter" => new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter(
                    $f->connection,
                    "test_migrations",
                ),
            ]),
        );
        $migration->down();
        $migration->up();
        self::assertCount(1, $f->sql->rows("sis_restrictions"));
        self::assertSame([], $f->sql->rows("sis_restriction_notes"));
    }

    public function testInvalidNotesNeverCreateAPartialRestriction(): void
    {
        $f = new SisFixture();
        $service = $this->service($f);
        $person = $f->student()->personId;
        foreach (["   ", str_repeat("x", 10001), "invalid\x00control", "invalid\xffencoding"] as $invalid) {
            try {
                $service->create($this->hold($person), $invalid);
                self::fail("Invalid staff-note content was stored.");
            } catch (ValidationException $error) {
                self::assertSame("note", $error->errors[0]["field"]);
                self::assertSame([], $f->sql->rows("sis_restrictions"));
                self::assertSame([], $f->sql->rows("sis_restriction_notes"));
            }
        }
    }

    public function testPrivateFailureDoesNotReflectStaffContentOrEncryptionErrors(): void
    {
        $f = new SisFixture();
        $hold = $this->hold($f->student()->personId);
        $cipher = $this->createStub(MessageCipher::class);
        $cipher->method("seal")->willThrowException(new RuntimeException("fictional-crypto-detail"));
        $service = $this->service($f, $cipher);
        $response = new \Application\Http\Middleware\SisPrivacyMiddleware()->process(
            new \Qubus\Http\ServerRequest([], [], "/sis/restrictions/", "POST"),
            new \Qubus\Routing\Handlers\CallableRequestHandler(static function () use (
                $service,
                $hold,
            ): never {
                $service->create($hold, "Fictional confidential note");
                throw new LogicException("Unreachable");
            }),
        );
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString("no-store", $response->getHeaderLine("Cache-Control"));
        self::assertStringNotContainsString("Fictional confidential note", (string) $response->getBody());
        self::assertStringNotContainsString("fictional-crypto-detail", (string) $response->getBody());
        self::assertSame([], $f->sql->rows("sis_restrictions"));
    }

    public function testEligibilityLookupIgnoresHistoryAndDistinguishesStudentAndStaffRules(): void
    {
        $f = new SisFixture();
        $person = $f->student()->personId;
        $repository = new SqlRestrictionRepository($f->sql);
        $repository->add($this->hold($person, "2026-08-09"));
        $repository->add(
            new Restriction(
                EntityId::generate(),
                $person,
                "LATER",
                99,
                new DateRange(new DateTimeImmutable("2026-08-11")),
                true,
            ),
        );
        $repository->add(
            new Restriction(
                EntityId::generate(),
                $person,
                "INFO",
                0,
                new DateRange(new DateTimeImmutable("2026-08-01")),
                false,
            ),
        );
        self::assertNull($f->registration->blockingRestriction($person, $f->clock->now(), false));
        self::assertNull($f->registration->blockingRestriction($person, $f->clock->now(), true));
        $staff = new Restriction(
            EntityId::generate(),
            $person,
            "STAFF",
            0,
            new DateRange(new DateTimeImmutable("2026-08-01")),
            true,
        );
        $repository->add($staff);
        self::assertNull($f->registration->blockingRestriction($person, $f->clock->now(), false));
        self::assertSame(
            $staff->id->value,
            $f->registration->blockingRestriction($person, $f->clock->now(), true)->id->value,
        );
        $student = new Restriction(
            EntityId::generate(),
            $person,
            "STUDENT",
            99,
            new DateRange(new DateTimeImmutable("2026-08-01"), new DateTimeImmutable("2026-08-10")),
            false,
        );
        $repository->add($student);
        self::assertSame(
            $student->id->value,
            $f->registration->blockingRestriction($person, $f->clock->now(), false)->id->value,
        );
        self::assertNull(
            $f->registration->blockingRestriction($f->student()->personId, $f->clock->now(), false),
        );
    }
}
