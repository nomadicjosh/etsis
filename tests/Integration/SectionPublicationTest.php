<?php

declare(strict_types=1);

require_once dirname(__DIR__) . "/Support/SisFixture.php";

use Codefy\Domain\EventSourcing\EventStream;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Registration\Event\SectionDefinitionWasPublished;
use Domain\Registration\Section;
use Domain\Registration\SectionPublication;
use Domain\Registration\Service\SectionCourseBindingService;
use Domain\Registration\Service\SectionPublicationService;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Registration\ValueObject\SectionPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCourseTextProjection;
use Infrastructure\Persistence\Repository\SqlSectionCourseBindingRepository;
use PHPUnit\Framework\TestCase;

final class SectionPublicationTest extends TestCase
{
    private SisFixture $f;
    private Section $section;
    private SectionPublicationService $service;
    private SqlHistoricalEventStore $history;
    private CoursePublicationService $courses;
    private SqlCatalogRevisionRepository $catalog;

    protected function setUp(): void
    {
        $this->f = $f = new SisFixture();
        $this->section = $f->section();
        $this->catalog = new SqlCatalogRevisionRepository($f->sql);
        $this->history = new SqlHistoricalEventStore(
            $f->sql,
            $f->transactions,
            $f->clock,
            new ProtectedEventCodec(
                EventStoreFixture::config($f->connection->queryBuilder()),
            ),
            new HistoricalSchemaRegistry(),
        );
        $relationships = new SqlCatalogWorkflowRepository($f->sql);
        $this->courses = new CoursePublicationService(
            $this->catalog,
            $relationships,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new SqlCourseTextProjection($f->sql),
        );
        $bindings = new SqlSectionCourseBindingRepository($f->sql);
        $review = $this->courses->review(
            $this->section->courseId,
            "Fictional course publication",
        );
        $version = $this->courses->publish(
            $this->section->courseId,
            $review["review_token"],
        );
        $bind = new SectionCourseBindingService(
            $this->catalog,
            $this->courses,
            $bindings,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $proof = $bind->review(
            $this->section->id,
            new EntityId($version["operation_id"]),
            "Fictional binding",
        );
        $bind->commit($this->section->id, $proof["review_token"]);
        $this->service = new SectionPublicationService(
            $this->catalog,
            $relationships,
            $bindings,
            $this->courses,
            $this->history,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql),
        );
    }

    private function review(
        string $start = "2026-08-24",
        string $end = "2026-12-18",
    ): array {
        return $this->service->review(
            $this->section->id,
            $start,
            $end,
            "Fictional instructional period",
        );
    }

    private function stream(): StreamReference
    {
        return new StreamReference(
            new SectionPublicationId($this->section->id->value),
            "section_publication",
        );
    }

    public function testReviewDoesNotPublishAndConfirmationUsesActualTimeAndProtectedContext(): void
    {
        $before = $this->f->sql->rows("sis_history_events");
        $review = $this->review();
        self::assertSame($before, $this->f->sql->rows("sis_history_events"));
        self::assertNull($this->service->setup($this->section->id)["latest"]);
        $this->f->clock->time = $this->f->clock->time->modify("+3 minutes");
        $receipt = $this->service->publish(
            $this->section->id,
            $review["review_token"],
        );
        $event = $this->history->page($this->stream())->events[0];
        self::assertSame(
            $this->f->clock->time->getTimestamp(),
            $event->context->effectiveAt->getTimestamp(),
        );
        self::assertSame($review["operation_id"], $receipt["operation_id"]);
        self::assertEquals(
            $review["definition"],
            $event->event->payload()["definition"],
        );
        self::assertSame(
            "Fictional instructional period",
            $event->context->reason,
        );
        $raw = json_encode(
            $this->f->sql->rows("sis_history_events"),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString(
            "Fictional instructional period",
            $raw,
        );
        self::assertStringNotContainsString("instruction_starts_on", $raw);
        self::assertSame(
            $before,
            array_slice(
                $this->f->sql->rows("sis_history_events"),
                0,
                count($before),
            ),
        );
        $page = $this->service->versions($this->section->id);
        self::assertArrayNotHasKey("reason", $page["items"][0]);
        self::assertStringNotContainsString(
            "actor_id",
            json_encode($page, JSON_THROW_ON_ERROR),
        );
        self::assertSame(
            "2026-08-24",
            $page["items"][0]["definition"]["instruction_starts_on"],
        );
    }

    public function testVersionsPreserveDefinitionsAndExactRetryCannotReplaceLaterPublication(): void
    {
        $review = $this->review();
        $first = $this->service->publish(
            $this->section->id,
            $review["review_token"],
        );
        $original = $this->f->sql->rows("sis_history_events");
        $this->catalog->save("sections", $this->section->id, [
            "capacity" => 35,
            "delivery_mode" => "hybrid",
        ]);
        $next = $this->review("2026-09-01", "2026-11-30");
        $second = $this->service->publish(
            $this->section->id,
            $next["review_token"],
        );
        $this->f->clock->time = $this->f->clock->time->modify("+2 days");
        $this->catalog->save("sections", $this->section->id, [
            "capacity" => 40,
        ]);
        self::assertSame(
            $first,
            $this->service->publish(
                $this->section->id,
                $review["review_token"],
            ),
        );
        self::assertSame(
            $second,
            $this->service->publish($this->section->id, $next["review_token"]),
        );
        self::assertSame(
            $original,
            array_slice(
                $this->f->sql->rows("sis_history_events"),
                0,
                count($original),
            ),
        );
        $items = $this->service->versions($this->section->id)["items"];
        self::assertCount(2, $items);
        self::assertSame(20, $items[0]["definition"]["capacity"]);
        self::assertSame(35, $items[1]["definition"]["capacity"]);
        self::assertSame(
            2,
            $this->service->setup($this->section->id)["latest"]["version"],
        );
        $root = SectionPublication::root($this->stream()->aggregateId);
        foreach ($this->history->page($this->stream())->events as $stored) {
            $root->acceptHistorical($stored->event);
        }
        self::assertSame(
            $second["operation_id"],
            $root->latest()["version_id"],
        );
        $events = array_map(
            static fn($s) => $s->event,
            $this->history->page($this->stream())->events,
        );
        $framework = SectionPublication::reconstituteFromEventStream(
            new EventStream($this->stream()->aggregateId, $events),
        );
        self::assertSame($root->latest(), $framework->latest());
    }

    public function testStaleReviewAndCompetingPublicationDoNotAppendAnything(): void
    {
        $review = $this->review();
        $competing = $this->review("2026-09-01");
        $this->service->publish($this->section->id, $review["review_token"]);
        $before = $this->f->sql->rows("sis_history_events");
        try {
            $this->service->publish(
                $this->section->id,
                $competing["review_token"],
            );
            self::fail("A competing review must be repeated.");
        } catch (ConcurrentModificationException) {
            self::assertSame(
                $before,
                $this->f->sql->rows("sis_history_events"),
            );
        }
    }

    public function testTermAndSectionChangesRequireAnotherReview(): void
    {
        $changes = [["sections", "capacity", 24], ["terms", "ends_on", "2026-12-21"]];
        foreach ($changes as [$kind, $field, $value]) {
            $review = $this->review();
            $id = $kind === "sections" ? $this->section->id : $this->f->term;
            $this->catalog->save($kind, $id, [$field => $value]);
            try {
                $this->service->publish(
                    $this->section->id,
                    $review["review_token"],
                );
                self::fail("Changed setup must invalidate the review.");
            } catch (ConcurrentModificationException) {
                self::assertCount(
                    0,
                    $this->history->page($this->stream())->events,
                );
            }
        }
    }

    public function testUnconfirmedReviewExpires(): void
    {
        $review = $this->review();
        $this->f->clock->time = $this->f->clock->time->modify("+20 minutes");
        $this->expectException(ConcurrentModificationException::class);
        $this->service->publish($this->section->id, $review["review_token"]);
    }

    public function testActorBoundProofAndCurrentPermissionAreRequiredForAllActions(): void
    {
        $review = $this->review();
        $actor = $this->f->access->actor;
        $this->f->access->actor = EntityId::generate();
        try {
            $this->service->publish(
                $this->section->id,
                $review["review_token"],
            );
            self::fail("Another staff member cannot use the proof.");
        } catch (AccessDeniedException) {
            $this->f->access->actor = $actor;
        }
        $this->service->publish($this->section->id, $review["review_token"]);
        $this->f->access->staff = false;
        $this->f->access->grants = ["sis:registration:manage"];
        $actions = [
            fn() => $this->review(),
            fn() => $this->service->setup($this->section->id),
            fn() => $this->service->versions($this->section->id),
            fn() => $this->service->publish(
                $this->section->id,
                $review["review_token"],
            ),
        ];
        foreach ($actions as $action) {
            try {
                $action();
                self::fail(
                    "Registration authority alone must not grant publication access.",
                );
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAuditFailureRollsBackEventHeadAndReceipt(): void
    {
        $review = $this->review();
        $before = $this->f->sql->rows("sis_history_events");
        $this->f->connection->pdo->exec(
            "CREATE TRIGGER publication_audit_failure BEFORE INSERT ON sis_audit BEGIN SELECT RAISE(ABORT, 'fictional audit failure'); END",
        );
        try {
            $this->service->publish(
                $this->section->id,
                $review["review_token"],
            );
            self::fail("Audit failure must roll back.");
        } catch (Throwable $error) {
            self::assertStringContainsString(
                "fictional audit failure",
                $error->getMessage(),
            );
        }
        self::assertSame($before, $this->f->sql->rows("sis_history_events"));
        self::assertCount(0, $this->history->page($this->stream())->events);
        $this->f->connection->pdo->exec(
            "DROP TRIGGER publication_audit_failure",
        );
        self::assertSame(
            1,
            $this->service->publish(
                $this->section->id,
                $review["review_token"],
            )["last_version"],
        );
    }

    public function testStrictDatesReasonsAndFrozenDefinitionRejectMalformedValues(): void
    {
        $invalid = [
            ["2026-02-30", "2026-12-18", "Reason"],
            ["2026-08-24", "2026-08-23", "Reason"],
            ["2026-8-24", "2026-12-18", "Reason"],
            ["2026-08-24", "2026-12-18", ""],
            ["2026-08-24", "2026-12-18", "Bad\x00reason"],
            ["2026-08-24", "2026-12-18", str_repeat("x", 1001)],
        ];
        foreach ($invalid as [$start, $end, $reason]) {
            try {
                $this->service->review(
                    $this->section->id,
                    $start,
                    $end,
                    $reason,
                );
                self::fail("Malformed review was accepted.");
            } catch (InvalidArgumentException $error) {
                self::assertInstanceOf(\Domain\Shared\Exception\ValidationException::class, $error);
                self::assertCount(
                    0,
                    $this->history->page($this->stream())->events,
                );
            }
        }
        $definition = $this->review()["definition"];
        $definition["capacity"] = "20";
        $this->expectException(InvalidArgumentException::class);
        new SectionDefinitionV1($definition);
    }

    public function testDatesOutsideParentTermAreExplicitWithoutChangingTermPolicy(): void
    {
        $term = $this->catalog->get("terms", $this->f->term);
        $review = $this->review("2026-07-20", "2027-01-02");
        $this->service->publish($this->section->id, $review["review_token"]);
        self::assertSame($term, $this->catalog->get("terms", $this->f->term));
        self::assertSame(
            "2026-07-20",
            $this->service->setup($this->section->id)["latest"]["definition"][
                "instruction_starts_on"
            ],
        );
    }

    public function testBindingProjectionMustMatchItsActualProtectedDecision(): void
    {
        $review = $this->courses->review(
            $this->section->courseId,
            "Another real publication",
        );
        $version = $this->courses->publish(
            $this->section->courseId,
            $review["review_token"],
        );
        $bound = new SqlSectionCourseBindingRepository($this->f->sql)->bound(
            $this->section->id,
        );
        $projection = $this->f->sql->one("sis_course_versions", [
            "id" => $bound->versionId->value,
        ]);
        $projection["id"] = $version["operation_id"];
        $this->f->sql->insert("sis_course_versions", $projection);
        $this->f->sql->update(
            "sis_section_course_versions",
            ["course_version_id" => $version["operation_id"]],
            ["id" => $this->section->id->value],
        );
        $this->expectException(CorruptHistoryException::class);
        $this->review();
    }

    public function testUnboundSectionRequiresExplicitCourseChoiceAndDoesNotManufactureHistory(): void
    {
        $unbound = $this->f->section();
        self::assertNull(
            $this->service->setup($unbound->id)["course_definition"],
        );
        $before = $this->f->sql->rows("sis_history_events");
        try {
            $this->service->review(
                $unbound->id,
                "2026-08-24",
                "2026-12-18",
                "Reason",
            );
            self::fail(
                "An unbound section must first select a course publication.",
            );
        } catch (DomainException) {
            self::assertSame(
                $before,
                $this->f->sql->rows("sis_history_events"),
            );
        }
    }

    public function testPrivateHistoryPagesFreezeTheirHeadAndRejectAnotherSectionCursor(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $review = $this->review();
            $this->service->publish(
                $this->section->id,
                $review["review_token"],
            );
        }
        $first = $this->service->versions($this->section->id);
        self::assertCount(20, $first["items"]);
        $review = $this->review();
        $this->service->publish($this->section->id, $review["review_token"]);
        $last = $this->service->versions(
            $this->section->id,
            $first["next_cursor"],
        );
        self::assertCount(1, $last["items"]);
        self::assertSame(21, $last["through_version"]);
        $another = $this->f->section();
        $this->expectException(CorruptHistoryException::class);
        $this->service->versions($another->id, $first["next_cursor"]);
    }

    public function testPurposeAndSectionBoundTokensRejectCrossUse(): void
    {
        $proof = $this->f->dropCipher->seal(
            json_encode(
                ["kind" => "course-publication-review.v2"],
                JSON_THROW_ON_ERROR,
            ),
        );
        try {
            $this->service->publish($this->section->id, $proof);
            self::fail("Another token purpose must be rejected.");
        } catch (InvalidArgumentException) {
            self::assertCount(0, $this->history->page($this->stream())->events);
        }
        $review = $this->review();
        $this->expectException(InvalidArgumentException::class);
        $this->service->publish(
            $this->f->section()->id,
            $review["review_token"],
        );
    }

    public function testPublishingNewDetailsDoesNotReinterpretExistingEnrollmentCreditOrCharges(): void
    {
        $first = $this->review();
        $this->service->publish($this->section->id, $first["review_token"]);
        $student = $this->f->student();
        $this->f->service->register($student->id, $this->section->id, true);
        $enrollments = $this->f->sql->rows("sis_enrollments");
        $credits = $this->f->sql->rows("sis_academic_credits");
        $events = $this->f->sql->rows("sis_history_events");
        $this->catalog->save("sections", $this->section->id, [
            "capacity" => 45,
            "fee_minor" => 15000,
        ]);
        $review = $this->review("2026-09-01", "2026-11-30");
        $this->service->publish($this->section->id, $review["review_token"]);
        self::assertSame($enrollments, $this->f->sql->rows("sis_enrollments"));
        self::assertSame($credits, $this->f->sql->rows("sis_academic_credits"));
        self::assertSame(
            $events,
            array_slice(
                $this->f->sql->rows("sis_history_events"),
                0,
                count($events),
            ),
        );
        self::assertCount(
            count($events) + 1,
            $this->f->sql->rows("sis_history_events"),
        );
    }

    public function testPermissionRevocationWhileWaitingForTheSectionLockBlocksPublication(): void
    {
        $proof = $this->review();
        $transactions = new class ($this->f) implements
            \Domain\Shared\Persistence\UnitOfWork
        {
            public function __construct(private SisFixture $fixture)
            {
            }
            public function run(Closure $operation): mixed
            {
                return $this->fixture->transactions->run(function () use (
                    $operation,
                ): mixed {
                    $this->fixture->access->staff = false;
                    $this->fixture->access->grants = [];
                    return $operation();
                });
            }
        };
        $service = new SectionPublicationService(
            $this->catalog,
            new SqlCatalogWorkflowRepository($this->f->sql),
            new SqlSectionCourseBindingRepository($this->f->sql),
            $this->courses,
            $this->history,
            $this->f->access,
            $transactions,
            $this->f->audit,
            $this->f->dropCipher,
            $this->f->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->f->sql),
        );
        $this->expectException(AccessDeniedException::class);
        try {
            $service->publish($this->section->id, $proof["review_token"]);
        } finally {
            self::assertCount(0, $this->history->page($this->stream())->events);
        }
    }

    public function testMissingBindingProjectionIsDetectedAtTheReviewedCommandBoundary(): void
    {
        $this->f->sql->execute(
            "DELETE FROM sis_section_course_versions WHERE id = ?",
            [$this->section->id->value],
        );
        self::assertNull($this->service->setup($this->section->id)['course_definition']);
        $this->expectException(CorruptHistoryException::class);
        $this->review();
    }

    public function testTeachingIdentityRetainsTheCanonicalPersonAfterTheSignInLinkChanges(): void
    {
        $account = $this->f->user();
        $person = $this->f->student($account)->personId;
        $this->catalog->save('sections', $this->section->id, ['faculty_user_id' => $account->value]);
        $proof = $this->review();
        self::assertSame($person->value, $proof['definition']['faculty_person_id']);
        $receipt = $this->service->publish($this->section->id, $proof['review_token']);
        $this->f->sql->update('sis_persons', ['owner_user_id' => null], ['id' => $person->value]);
        self::assertSame($receipt, $this->service->publish($this->section->id, $proof['review_token']));
        $retained = $this->service->versions($this->section->id)['items'][0]['definition'];
        self::assertSame($person->value, $retained['faculty_person_id']);
        self::assertSame($account->value, $retained['faculty_user_id']);
    }

    public function testPublicationProjectsAnIndexedImmutableReferenceWithoutChangingEarlierVersions(): void
    {
        $proof = $this->review();
        $first = $this->service->publish($this->section->id, $proof['review_token']);
        $cache = new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->f->sql);
        $before = $cache->find(new EntityId($first['operation_id']));
        self::assertSame($this->section->id->value, $before->section->value);
        self::assertEquals($proof['definition'], $before->definition->data);
        $this->catalog->save('sections', $this->section->id, ['capacity' => 35]);
        $next = $this->review('2026-09-01');
        $this->service->publish($this->section->id, $next['review_token']);
        self::assertEquals($before, $cache->find($before->publication));
        self::assertCount(2, $this->f->sql->rows('sis_section_versions'));
        self::assertSame($first, $this->service->publish($this->section->id, $proof['review_token']));
        self::assertCount(2, $this->f->sql->rows('sis_section_versions'));
    }

    public function testEarlierPublicationCanBeVerifiedAndCachedWithoutFabricatingOrChangingEvents(): void
    {
        $proof = $this->review();
        $receipt = $this->service->publish($this->section->id, $proof['review_token']);
        $id = new EntityId($receipt['operation_id']);
        $this->f->sql->execute('DELETE FROM sis_section_versions WHERE id = ?', [$id->value]);
        $events = $this->f->sql->rows('sis_history_events');
        $audit = $this->f->sql->rows('sis_audit');
        self::assertSame($receipt, $this->service->publish($this->section->id, $proof['review_token']));
        self::assertSame([], $this->f->sql->rows('sis_section_versions'));
        $verified = $this->service->cacheVersion($this->section->id, $id);
        self::assertEquals($proof['definition'], $verified->definition->data);
        self::assertEquals($verified, $this->service->cacheVersion($this->section->id, $id));
        self::assertSame($events, $this->f->sql->rows('sis_history_events'));
        self::assertSame($audit, $this->f->sql->rows('sis_audit'));
    }

    public function testMalformedCachedNumbersAreRejectedWithoutSilentCoercion(): void
    {
        $proof = $this->review();
        $receipt = $this->service->publish($this->section->id, $proof['review_token']);
        $this->f->sql->update('sis_section_versions', ['capacity' => '20invalid'], ['id' => $receipt['operation_id']]);
        $cache = new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->f->sql);
        $this->expectException(CorruptHistoryException::class);
        $cache->find(new EntityId($receipt['operation_id']));
    }

    public function testCacheDisagreementIsRejectedRatherThanOverwritten(): void
    {
        $proof = $this->review();
        $receipt = $this->service->publish($this->section->id, $proof['review_token']);
        $this->f->sql->update('sis_section_versions', ['capacity' => 99], ['id' => $receipt['operation_id']]);
        $this->expectException(CorruptHistoryException::class);
        $this->service->cacheVersion($this->section->id, new EntityId($receipt['operation_id']));
    }

    public function testCrossSectionReferenceAndUnauthorizedCachePreparationAreRejected(): void
    {
        $proof = $this->review();
        $receipt = $this->service->publish($this->section->id, $proof['review_token']);
        $id = new EntityId($receipt['operation_id']);
        try {
            $this->service->cacheVersion($this->f->section()->id, $id);
            self::fail('Another section must not claim the publication.');
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertCount(1, $this->f->sql->rows('sis_section_versions'));
        }
        $this->f->access->staff = false;
        $this->f->access->grants = ['sis:registration:manage'];
        $this->expectException(AccessDeniedException::class);
        $this->service->cacheVersion($this->section->id, $id);
    }

    public function testProjectionFailureRollsBackPublicationAndItsReceipt(): void
    {
        $proof = $this->review();
        $before = $this->f->sql->rows('sis_history_events');
        $this->f->connection->pdo->exec("CREATE TRIGGER fail_section_cache BEFORE INSERT ON sis_section_versions BEGIN SELECT RAISE(ABORT, 'fictional cache failure'); END");
        try {
            $this->service->publish($this->section->id, $proof['review_token']);
            self::fail('The projection failure must roll back publication.');
        } catch (PDOException) {
            self::assertSame($before, $this->f->sql->rows('sis_history_events'));
            self::assertSame([], $this->f->sql->rows('sis_section_versions'));
        }
        $this->f->connection->pdo->exec('DROP TRIGGER fail_section_cache');
        self::assertSame(1, $this->service->publish($this->section->id, $proof['review_token'])['last_version']);
    }

    public function testSetupUsesReadModelsWithoutReplayOrAWriteTransaction(): void
    {
        $proof = $this->review();
        $this->service->publish($this->section->id, $proof['review_token']);
        $history = $this->createStub(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history->method('page')->willThrowException(new LogicException('Routine setup must not replay history.'));
        $transactions = $this->createStub(\Domain\Shared\Persistence\UnitOfWork::class);
        $transactions->method('run')->willThrowException(new LogicException('Routine setup must not open a write transaction.'));
        $f = $this->f;
        $relationships = new SqlCatalogWorkflowRepository($f->sql);
        $courses = new CoursePublicationService(
            $this->catalog,
            $relationships,
            $history,
            $f->access,
            $transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new SqlCourseTextProjection($f->sql)
        );
        $service = new SectionPublicationService(
            $this->catalog,
            $relationships,
            new SqlSectionCourseBindingRepository($f->sql),
            $courses,
            $history,
            $f->access,
            $transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock,
            new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($f->sql)
        );
        $before = $f->sql->rows('sis_history_events');
        $setup = $service->setup($this->section->id);
        self::assertSame('ready', $setup['publication_status']);
        self::assertSame($proof['operation_id'], $setup['latest']['version_id']);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
    }

    public function testMissingLatestProjectionNeverFallsBackToAnOlderPublication(): void
    {
        $first = $this->review();
        $this->service->publish($this->section->id, $first['review_token']);
        $second = $this->review('2026-09-01');
        $this->service->publish($this->section->id, $second['review_token']);
        $this->f->sql->execute('DELETE FROM sis_section_versions WHERE id = ?', [$second['operation_id']]);
        $before = $this->f->sql->rows('sis_history_events');
        $setup = $this->service->setup($this->section->id);
        self::assertSame('unavailable', $setup['publication_status']);
        self::assertNull($setup['latest']);
        self::assertCount(1, $this->f->sql->rows('sis_section_versions'));
        self::assertSame($before, $this->f->sql->rows('sis_history_events'));
        $this->service->cacheVersion($this->section->id, new EntityId($second['operation_id']));
        self::assertSame($second['operation_id'], $this->service->setup($this->section->id)['latest']['version_id']);
    }

    public function testUnpublishedSetupUsesTermSuggestionsWithoutManufacturingHistory(): void
    {
        $before = $this->f->sql->rows('sis_history_events');
        $setup = $this->service->setup($this->section->id);
        self::assertSame('unpublished', $setup['publication_status']);
        self::assertNull($setup['latest']);
        self::assertSame($before, $this->f->sql->rows('sis_history_events'));
    }

    private function selectedOffering(): array
    {
        $review = $this->review();
        $published = $this->service->publish($this->section->id, $review['review_token']);
        $selection = $this->service->reviewSelection(
            $this->section->id,
            new EntityId($published['operation_id']),
            'Fictional initial offering selection'
        );
        return [$published, $selection];
    }

    public function testSelectedOfferingIsRecordedOnNewEnrollmentAndAcademicEvents(): void
    {
        [$publication, $review] = $this->selectedOffering();
        self::assertSame([], $this->f->sql->rows('sis_section_offering_versions'));
        $before = $this->f->sql->rows('sis_history_events');
        $this->service->select($this->section->id, $review['review_token']);
        self::assertSame($before, array_slice($this->f->sql->rows('sis_history_events'), 0, count($before)));
        $student = $this->f->student();
        $enrollment = $this->f->service->register($student->id, $this->section->id, true);
        $cache = new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->f->sql);
        self::assertSame($publication['operation_id'], $cache->enrollmentVersion($enrollment->id)->value);
        $stream = new StreamReference(new \Domain\Registration\ValueObject\EnrollmentHistoryId($enrollment->id->value), 'enrollment');
        $registered = $this->history->page($stream)->events[0]->event;
        self::assertSame($publication['operation_id'], $registered->metaParam('section_version_id'));
        $mapping = $this->f->sql->rows('sis_academic_credit_streams', ['enrollment_id' => $enrollment->id->value], 1)[0];
        $creditStream = new StreamReference(new \Domain\AcademicRecord\ValueObject\AcademicCreditId($mapping['id']), 'academic_credit');
        $established = $this->history->page($creditStream)->events[0]->event;
        self::assertSame($publication['operation_id'], $established->metaParam('section_version_id'));
        $root = \Domain\Registration\EnrollmentHistory::reconstituteFromEventStream(new EventStream($stream->aggregateId, [$registered]));
        $credit = \Domain\AcademicRecord\AcademicCredit::reconstituteFromEventStream(new EventStream($creditStream->aggregateId, [$established]));
        self::assertSame($publication['operation_id'], $root->sectionVersion());
        self::assertSame($root->sectionVersion(), $credit->sectionVersion());
        $this->catalog->save('courses', $this->section->courseId, ['title' => 'Future catalog title']);
        $rows = $this->f->sql->rows('sis_academic_credits');
        self::assertNotSame('Future catalog title', $rows[0]['course_title']);
        self::assertSame($enrollment->id->value, $this->f->service->register($student->id, $this->section->id, true)->id->value);
    }

    public function testOfferingSelectionRetryPreservesLaterEnrollmentAndPublication(): void
    {
        [, $review] = $this->selectedOffering();
        $receipt = $this->service->select($this->section->id, $review['review_token']);
        $student = $this->f->student();
        $this->f->service->register($student->id, $this->section->id, true);
        $later = $this->review('2026-09-01');
        $this->service->publish($this->section->id, $later['review_token']);
        $before = $this->f->sql->rows('sis_history_events');
        $this->f->clock->time = $this->f->clock->time->modify('+1 day');
        self::assertSame($receipt, $this->service->select($this->section->id, $review['review_token']));
        self::assertSame($before, $this->f->sql->rows('sis_history_events'));
        self::assertSame($review['publication_id'], $this->service->setup($this->section->id)['selected_offering']['version_id']);
    }

    public function testEnrollmentAfterSelectionReviewPreventsRetrospectiveBinding(): void
    {
        [, $review] = $this->selectedOffering();
        $this->f->service->register($this->f->student()->id, $this->section->id, true);
        $before = $this->f->sql->rows('sis_history_events');
        try {
            $this->service->select($this->section->id, $review['review_token']);
            self::fail('A populated section must not acquire an initial binding.');
        } catch (DomainException) {
            self::assertSame($before, $this->f->sql->rows('sis_history_events'));
            self::assertSame([], $this->f->sql->rows('sis_section_offering_versions'));
            self::assertSame([], $this->f->sql->rows('sis_enrollment_section_versions'));
        }
    }

    public function testCompetingInitialSelectionsCannotReplaceTheFirstAcceptedVersion(): void
    {
        [, $first] = $this->selectedOffering();
        $next = $this->review('2026-09-01');
        $published = $this->service->publish($this->section->id, $next['review_token']);
        $second = $this->service->reviewSelection($this->section->id, new EntityId($published['operation_id']), 'Different offering');
        $this->service->select($this->section->id, $first['review_token']);
        $before = $this->f->sql->rows('sis_history_events');
        try {
            $this->service->select($this->section->id, $second['review_token']);
            self::fail('A competing selection overwrote the first one.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->f->sql->rows('sis_history_events'));
            self::assertSame($first['publication_id'], $this->service->setup($this->section->id)['selected_offering']['version_id']);
        }
    }

    public function testStaleAndExpiredOfferingReviewsLeaveNoSelection(): void
    {
        [, $review] = $this->selectedOffering();
        $this->catalog->save('sections', $this->section->id, ['capacity' => 23]);
        try {
            $this->service->select($this->section->id, $review['review_token']);
            self::fail('A changed offering must invalidate its review.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $this->f->sql->rows('sis_section_offering_versions'));
        }
        $this->catalog->save('sections', $this->section->id, ['capacity' => 20]);
        $this->f->clock->time = $this->f->clock->time->modify('+20 minutes');
        $this->expectException(ConcurrentModificationException::class);
        $this->service->select($this->section->id, $review['review_token']);
    }

    public function testOfferingSelectionRequiresCurrentCatalogPermissionAndOriginalActor(): void
    {
        [, $review] = $this->selectedOffering();
        $actor = $this->f->access->actor;
        $this->f->access->actor = $this->f->user();
        try {
            $this->service->select($this->section->id, $review['review_token']);
            self::fail('Another actor reused the review.');
        } catch (AccessDeniedException) {
            $this->f->access->actor = $actor;
        }
        $this->f->access->staff = false;
        $this->f->access->grants = ['sis:registration:manage'];
        $this->expectException(AccessDeniedException::class);
        $this->service->select($this->section->id, $review['review_token']);
    }

    public function testOfferingSelectionAuditFailureRollsBackAllAcceptedState(): void
    {
        [, $review] = $this->selectedOffering();
        $before = $this->f->sql->rows('sis_history_events');
        $this->f->connection->pdo->exec("CREATE TRIGGER fail_selection_audit BEFORE INSERT ON sis_audit WHEN NEW.action = 'section.offering_version_selected' BEGIN SELECT RAISE(ABORT, 'fictional selection failure'); END");
        try {
            $this->service->select($this->section->id, $review['review_token']);
            self::fail('Audit failure did not roll back the selection.');
        } catch (PDOException) {
            self::assertSame($before, $this->f->sql->rows('sis_history_events'));
            self::assertSame([], $this->f->sql->rows('sis_section_offering_versions'));
        }
        $this->f->connection->pdo->exec('DROP TRIGGER fail_selection_audit');
        self::assertSame(1, $this->service->select($this->section->id, $review['review_token'])['last_version']);
    }

    public function testCorruptSelectionProjectionStopsRegistrationWithoutACharge(): void
    {
        [$publication, $review] = $this->selectedOffering();
        $this->service->select($this->section->id, $review['review_token']);
        $this->f->sql->update('sis_section_versions', ['capacity' => 99], ['id' => $publication['operation_id']]);
        $student = $this->f->student();
        $before = $this->f->sql->rows('sis_history_events');
        try {
            $this->f->service->register($student->id, $this->section->id, true);
            self::fail('A changed definition projection was accepted.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->f->sql->rows('sis_history_events'));
            self::assertSame([], $this->f->sql->rows('sis_enrollments'));
        }
    }

    public function testOperationalOfferingChangesRequireReviewBeforeAnotherRegistration(): void
    {
        [, $review] = $this->selectedOffering();
        $this->service->select($this->section->id, $review['review_token']);
        $student = $this->f->student();
        $this->f->service->register($student->id, $this->section->id, true);
        $this->catalog->save('sections', $this->section->id, ['capacity' => 25]);
        self::assertNotNull($this->f->service->register($student->id, $this->section->id, true));
        $another = $this->f->student();
        $before = $this->f->sql->rows('sis_history_events');
        try {
            $this->f->service->register($another->id, $this->section->id, true);
            self::fail('A different current offering was registered under the retained definition.');
        } catch (DomainException) {
            self::assertSame($before, $this->f->sql->rows('sis_history_events'));
        }
    }

    public function testEarlyDropRetainsVersionReferenceAndRetakeGetsANewReferenceIdentity(): void
    {
        [$publication, $review] = $this->selectedOffering();
        $this->service->select($this->section->id, $review['review_token']);
        $student = $this->f->student();
        $first = $this->f->service->register($student->id, $this->section->id, true);
        $this->f->service->drop($student->id, $this->section->id);
        self::assertSame([], $this->f->sql->rows('sis_academic_credits'));
        self::assertSame([], $this->f->sql->rows('sis_enrollments'));
        $second = $this->f->service->register($student->id, $this->section->id, true);
        self::assertNotSame($first->id->value, $second->id->value);
        $cache = new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->f->sql);
        self::assertSame($publication['operation_id'], $cache->enrollmentVersion($first->id)->value);
        self::assertSame($publication['operation_id'], $cache->enrollmentVersion($second->id)->value);
        self::assertCount(2, $this->f->sql->rows('sis_enrollment_section_versions'));
    }

    public function testMissingEnrollmentReferenceIsDetectedAndCanBeRebuiltFromRecordedMetadata(): void
    {
        [, $review] = $this->selectedOffering();
        $this->service->select($this->section->id, $review['review_token']);
        $student = $this->f->student();
        $enrollment = $this->f->service->register($student->id, $this->section->id, true);
        $this->f->sql->execute('DELETE FROM sis_enrollment_section_versions WHERE id = ?', [$enrollment->id->value]);
        try {
            $this->f->service->register($student->id, $this->section->id, true);
            self::fail('A missing retained reference was ignored.');
        } catch (CorruptHistoryException) {
            $stream = new StreamReference(new \Domain\Registration\ValueObject\EnrollmentHistoryId($enrollment->id->value), 'enrollment');
            $recorded = $this->history->page($stream)->events[0]->event;
            $cache = new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->f->sql);
            $before = $this->f->sql->rows('sis_history_events');
            $cache->retainEnrollmentVersion($enrollment->id, new EntityId($recorded->metaParam('section_version_id')));
            self::assertSame($enrollment->id->value, $this->f->service->register($student->id, $this->section->id, true)->id->value);
            self::assertSame($before, $this->f->sql->rows('sis_history_events'));
        }
    }

    public function testSelectionInvalidatesAnEarlierGroupRegistrationReview(): void
    {
        [$publication, $review] = $this->selectedOffering();
        $selection = new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $this->section->id,
            $this->f->student()->id
        );
        $f = $this->f;
        $batch = new \Domain\Registration\Service\RegistrationBatchService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationBatchRepository($f->sql),
            $f->registration,
            $f->admissions,
            $f->service,
            $f->access,
            $f->transactions,
            $f->audit,
            $f->clock
        );
        $before = $batch->review($selection);
        $this->service->select($this->section->id, $review['review_token']);
        $this->expectException(ConcurrentModificationException::class);
        $batch->commit(new EntityId($before['operation_id']), $selection, $before['revision']);
    }

    public function testSelfServiceKeepsTheSameOfferingReferenceAsStaffRegistration(): void
    {
        [$publication, $review] = $this->selectedOffering();
        $this->service->select($this->section->id, $review['review_token']);
        $owner = $this->f->user();
        $student = $this->f->student($owner);
        $this->f->access->actor = $owner;
        $this->f->access->staff = false;
        $this->f->access->grants = [];
        $enrollment = $this->f->service->register($student->id, $this->section->id);
        self::assertSame('N', $enrollment->status->value);
        $cache = new \Infrastructure\Persistence\Repository\SqlSectionVersionRepository($this->f->sql);
        self::assertSame($publication['operation_id'], $cache->enrollmentVersion($enrollment->id)->value);
    }

    public function testMissingOfferingPointerCannotMakeRegistrationSilentlyUnversioned(): void
    {
        [, $review] = $this->selectedOffering();
        $this->service->select($this->section->id, $review['review_token']);
        $this->f->sql->execute('DELETE FROM sis_section_offering_versions WHERE id = ?', [$this->section->id->value]);
        $student = $this->f->student();
        $this->expectException(CorruptHistoryException::class);
        $this->f->service->register($student->id, $this->section->id, true);
    }

    public function testEnrollmentReferenceFailureRollsBackTheSeatCreditChargeAndReceipts(): void
    {
        [, $review] = $this->selectedOffering();
        $this->service->select($this->section->id, $review['review_token']);
        $student = $this->f->student();
        $before = $this->f->sql->rows('sis_history_events');
        $this->f->connection->pdo->exec("CREATE TRIGGER fail_enrollment_ref BEFORE INSERT ON sis_enrollment_section_versions BEGIN SELECT RAISE(ABORT, 'fictional reference failure'); END");
        try {
            $this->f->service->register($student->id, $this->section->id, true);
            self::fail('Reference projection failure must roll back the full registration.');
        } catch (PDOException) {
            self::assertSame($before, $this->f->sql->rows('sis_history_events'));
            self::assertSame([], $this->f->sql->rows('sis_enrollments'));
            self::assertSame([], $this->f->sql->rows('sis_academic_credits'));
            self::assertSame([], $this->f->sql->rows('sis_enrollment_section_versions'));
        }
        $this->f->connection->pdo->exec('DROP TRIGGER fail_enrollment_ref');
        $this->f->service->register($student->id, $this->section->id, true);
        self::assertCount(1, $this->f->sql->rows('sis_enrollment_section_versions'));
    }

    public function testForeignPublicationAndWrongPurposeCannotSelectAnOffering(): void
    {
        [$publication, $proof] = $this->selectedOffering();
        try {
            $this->service->reviewSelection($this->f->section()->id, new EntityId($publication['operation_id']), 'Foreign section');
            self::fail('A section claimed another section publication.');
        } catch (\Domain\Shared\Exception\RecordNotFoundException) {
            self::assertSame([], $this->f->sql->rows('sis_section_offering_versions'));
        }
        $publicationReview = $this->review();
        $this->expectException(InvalidArgumentException::class);
        $this->service->select($this->section->id, $publicationReview['review_token']);
    }

    public function testRegistryRejectsUnknownFieldsAndReplayRejectsBrokenPredecessor(): void
    {
        $review = $this->review();
        $this->service->publish($this->section->id, $review["review_token"]);
        $stored = $this->history->page($this->stream())->events[0];
        $payload = $stored->event->payload();
        $payload["definition"]["unexpected"] = true;
        $bad = SectionDefinitionWasPublished::occur(
            $this->stream()->aggregateId,
            $payload,
            ["__aggregate_type" => "section_publication"],
        );
        try {
            new HistoricalSchemaRegistry()->describe($bad);
            self::fail("Unexpected historical fields must be rejected.");
        } catch (CorruptHistoryException) {
            self::assertTrue(true);
        }
        $payload = $stored->event->payload();
        $payload["previous_version_id"] = EntityId::generate()->value;
        $bad = SectionDefinitionWasPublished::occur(
            $this->stream()->aggregateId,
            $payload,
            ["__aggregate_type" => "section_publication"],
        )->withPlayhead(1);
        $this->expectException(CorruptHistoryException::class);
        SectionPublication::root(
            $this->stream()->aggregateId,
        )->acceptHistorical($bad);
    }
}
