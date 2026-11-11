<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/TransferAwardFixture.php";

use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Transfer\Service\TransferService;
use Domain\Transfer\ValueObject\TransferRevision;
use PHPUnit\Framework\TestCase;

final class TransferCoursePublicationTest extends TestCase
{
    private function fixture(bool $legacy = false): TransferAwardFixture
    {
        $f = new HiatusFixture();
        return new TransferAwardFixture($f->sis, $f->cipher, legacy: $legacy);
    }
    private function service(
        TransferAwardFixture $t,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): TransferService {
        $s = $t->sis;
        return new TransferService(
            new \Infrastructure\Persistence\Repository\SqlTransferRepository($s->sql, $s->gradeCatalog()),
            $s->registration,
            $s->admissions,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
                $s->sql,
                $s->gradeCatalog(),
            ),
            $s->transferCredits(),
        );
    }
    private function award(
        TransferAwardFixture $t,
        EntityId $version,
        string $on = "2026-05-02",
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): EntityId {
        $catalog = new \Infrastructure\Persistence\Repository\SqlTransferCatalogRepository(
            $t->sis->sql,
            $t->sis->gradeCatalog(),
        );
        return $this->service($t, $audit)->award(
            $t->student,
            $t->equivalency->id,
            $t->equivalency->externalInstitutionId,
            $t->sis->term,
            new DateTimeImmutable($on),
            3,
            TransferRevision::of("equivalencies", $catalog->get("equivalencies", $t->equivalency->id)),
            $version,
        );
    }
    public function testExplicitOlderPublicationSuppliesLabelsAndReferenceWhileAwardedCreditsStayIndependent(): void
    {
        $t = $this->fixture();
        $t->sis->sql->update(
            "sis_courses",
            ["title" => "Renamed today", "credits_milli" => 6000],
            ["id" => $t->course->value],
        );
        $later = $t->sis->publishCourse($t->course);
        $old = $this->award($t, $t->courseVersion);
        $oldFacts = $t->sis->transferCredits()->auditFacts($old, $t->student);
        self::assertSame($t->courseVersion->value, $oldFacts["course_version_id"]);
        self::assertSame("Computing", $oldFacts["credit"]["course_title"]);
        $new = $this->award($t, $later, "2026-05-03");
        $newFacts = $t->sis->transferCredits()->auditFacts($new, $t->student);
        self::assertSame("Renamed today", $newFacts["credit"]["course_title"]);
        self::assertSame(3000, $newFacts["credit"]["attempted_milli"]);
        self::assertSame($later->value, $newFacts["course_version_id"]);
        self::assertSame(
            $later->value,
            $t->sis->sql->one("sis_credit_course_versions", ["id" => $new->value])["course_version_id"],
        );
        $again = $this->award($t, $later, "2026-05-03");
        self::assertSame($new->value, $again->value);
    }
    public function testTransferOfficeCanBrowseCuratedFrozenChoicesWithoutCatalogEditingAuthority(): void
    {
        $t = $this->fixture();
        $t->sis->access->staff = false;
        $t->sis->access->grants = ["sis:transfer:manage", "sis:student:read"];
        $choices = $this->service($t)->courseVersions($t->equivalency->id);
        self::assertSame($t->courseVersion->value, $choices["items"][0]["version_id"]);
        self::assertSame("Computing", $choices["items"][0]["transcript_title"]);
        self::assertArrayNotHasKey("actor_id", $choices["items"][0]);
        self::assertArrayNotHasKey("reason", $choices["items"][0]);
        self::assertStringNotContainsString(
            "Fictional accepted course",
            json_encode($choices, JSON_THROW_ON_ERROR),
        );
        self::assertNotEmpty($this->award($t, $t->courseVersion)->value);
        $this->expectException(AccessDeniedException::class);
        $t->sis->coursePublications()->review($t->course, "Unauthorised catalog edit.");
    }
    public function testMissingOrForeignPublicationCannotAppendAnAwardOrAnyProjection(): void
    {
        $t = $this->fixture();
        $other = $t->sis->publishCourse($t->sis->section()->courseId);
        foreach ([$other, EntityId::generate()] as $version) {
            $events = $t->sis->sql->rows("sis_history_events");
            $awards = $t->sis->sql->rows("sis_transfer_awards");
            try {
                $this->award($t, $version);
                self::fail("A foreign or absent course publication was accepted.");
            } catch (RecordNotFoundException) {
                self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
                self::assertSame($awards, $t->sis->sql->rows("sis_transfer_awards"));
            }
        }
    }
    public function testRetryCannotSelectANewCourseVersionForAnExistingAward(): void
    {
        $t = $this->fixture();
        $later = $t->sis->publishCourse($t->course);
        $events = $t->sis->sql->rows("sis_history_events");
        try {
            $this->award($t, $later, "2026-05-01");
            self::fail("An original award retry replaced its publication.");
        } catch (DomainException) {
            self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
            self::assertSame(
                $t->courseVersion->value,
                $t->service()->view($t->student, $t->award)["course_version_id"],
            );
        }
    }
    public function testInitialRetryUsesItsOpeningPublicationAfterAReviewedAcceptanceChange(): void
    {
        $t = $this->fixture();
        $later = $t->sis->publishCourse($t->course);
        $review = $t->courses()->review($t->student, $t->award, $later, 0, "2026-08-01", "Fictional corrected acceptance.");
        $t->courses()->commit($t->student, $t->award, $review["review_token"]);
        $events = $t->sis->sql->rows("sis_history_events");
        self::assertSame($t->credit->value, $this->award($t, $t->courseVersion, "2026-05-01")->value);
        self::assertSame($later->value, $t->service()->view($t->student, $t->award)["course_version_id"]);
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        $this->expectException(DomainException::class);
        $this->award($t, $later, "2026-05-01");
    }
    public function testEarlierUnknownCourseVersionIsNeitherBackfilledNorTrustedFromAnInjectedIndex(): void
    {
        $t = $this->fixture(true);
        $later = $t->sis->publishCourse($t->course);
        self::assertNull(
            $t->sis->transferCredits()->auditFacts($t->credit, $t->student)["course_version_id"],
        );
        try {
            $this->award($t, $later, "2026-05-01");
            self::fail("A retry backfilled an earlier unknown definition.");
        } catch (DomainException) {
            self::assertTrue(true);
        }
        new \Infrastructure\Persistence\Repository\SqlCourseVersionProjection($t->sis->sql)->retain(
            $this->reader($t)->version($t->course, $later, new HistoryReadBudget()),
        );
        $t->sis->sql->insert("sis_credit_course_versions", [
            "id" => $t->credit->value,
            "course_version_id" => $later->value,
        ]);
        $this->expectException(CorruptHistoryException::class);
        $t->sis->transferCredits()->auditFacts($t->credit, $t->student);
    }
    public function testCorruptProjectionOrMissingIndexCannotBecomeUnverifiedHistoricalInputs(): void
    {
        $t = $this->fixture();
        $t->sis->sql->update(
            "sis_course_versions",
            ["title" => "Tampered projection"],
            ["id" => $t->courseVersion->value],
        );
        $events = $t->sis->sql->rows("sis_history_events");
        try {
            $this->award($t, $t->courseVersion);
            self::fail("A corrupted course projection was reused for a new award.");
        } catch (CorruptHistoryException) {
            self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        }
        $t->sis->sql->execute("DELETE FROM sis_credit_course_versions WHERE id = ?", [$t->credit->value]);
        $this->expectException(CorruptHistoryException::class);
        $t->sis->transferCredits()->auditFacts($t->credit, $t->student);
    }
    public function testPublicationReplayRemainsBoundedEvenWhenTheRequestedVersionWasFoundEarly(): void
    {
        $t = $this->fixture();
        $t->sis->publishCourse($t->course);
        $this->expectException(HistoryReadLimitException::class);
        $this->reader($t)->version($t->course, $t->courseVersion, new HistoryReadBudget(maximumEvents: 1));
    }
    private function reader(TransferAwardFixture $t): \Domain\Catalog\Service\PublishedCourseReader
    {
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $t->sis->sql,
            $t->sis->transactions,
            $t->sis->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(
                EventStoreFixture::config($t->sis->connection->queryBuilder()),
            ),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry(),
        );
        return new \Domain\Catalog\Service\PublishedCourseReader($history);
    }
    public function testKnownPublicationAndCreditIndexCanBeReconstructedWithoutAppendingHistory(): void
    {
        $t = $this->fixture();
        $root = \Domain\Transfer\TransferCredit::root(
            new \Domain\Transfer\ValueObject\TransferCreditId($t->award->value),
        );
        $history = EventStoreFixture::framework($t->sis->connection->queryBuilder())->getAggregateHistoryFor(
            new \Domain\Transfer\ValueObject\TransferCreditId($t->award->value),
        );
        foreach ($history as $event) {
            $root->acceptHistorical($event);
        }
        $definition = $this->reader($t)->version(
            $t->course,
            new EntityId($root->courseVersion()),
            new HistoryReadBudget(),
        );
        $events = $t->sis->sql->rows("sis_history_events");
        $receipts = $t->sis->sql->rows("sis_history_operations");
        $finance = $t->sis->financialEvents();
        $projectionRows = [
            "sis_credit_course_versions" => $t->credit,
            "sis_transfer_credit_streams" => $t->award,
            "sis_transfer_awards" => $t->award,
            "sis_academic_credits" => $t->credit,
            "sis_course_version_classes" => $t->courseVersion,
            "sis_course_version_subjects" => $t->courseVersion,
            "sis_course_versions" => $t->courseVersion,
        ];
        foreach ($projectionRows as $table => $id) {
            $t->sis->sql->execute("DELETE FROM " . $table . " WHERE id = ?", [$id->value]);
        }
        $repo = new \Infrastructure\Persistence\Repository\SqlTransferCreditRepository($t->sis->sql);
        $repo->create($root->source(), $root->credit(), $definition);
        self::assertEquals($root->credit(), $repo->credit($t->credit));
        self::assertSame($root->courseVersion(), $repo->courseVersion($t->credit)->value);
        self::assertSame(
            $root->courseVersion(),
            $t->sis->transferCredits()->auditFacts($t->credit, $t->student)["course_version_id"],
        );
        self::assertSame($events, $t->sis->sql->rows("sis_history_events"));
        self::assertSame($receipts, $t->sis->sql->rows("sis_history_operations"));
        self::assertSame($finance, $t->sis->financialEvents());
    }
    public function testAuditFailureRollsBackTheNewAwardAndAllNewDefinitionProjectionRows(): void
    {
        $t = $this->fixture();
        $later = $t->sis->publishCourse($t->course);
        $tables = [
            "sis_academic_credits",
            "sis_transfer_awards",
            "sis_transfer_credit_streams",
            "sis_credit_course_versions",
            "sis_course_versions",
            "sis_course_version_classes",
            "sis_course_version_subjects",
            "sis_history_events",
            "sis_history_operations",
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $t->sis->sql->rows($table);
        }
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException("Fictional award audit failure.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $this->award($t, $later, audit: $audit);
            self::fail("An unaudited course-bound award committed.");
        } catch (RuntimeException) {
            foreach ($before as $table => $rows) {
                self::assertSame($rows, $t->sis->sql->rows($table));
            }
        }
    }
}
