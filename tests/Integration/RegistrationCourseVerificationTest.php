<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Service\EnrollmentLedger;
use Domain\Registration\Service\RegistrationCourseRead;
use Domain\Registration\Service\RegistrationService;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Registration\ValueObject\RegistrationPlanSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlEnrollmentHistoryRepository;
use Infrastructure\Persistence\Repository\SqlSectionVersionRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RegistrationCourseVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
    }

    private function history(SisFixture $s, int &$pages): HistoricalEventStore
    {
        $inner = \EventStoreFixture::history($s->connection->queryBuilder());
        $spy = $this->createStub(HistoricalEventStore::class);
        foreach (get_class_methods(HistoricalEventStore::class) as $method) {
            $spy->method($method)->willReturnCallback(static function (...$args) use ($inner, $method, &$pages): mixed {
                if ($method === 'page' && $args[0]->type === 'course_publication') {
                    $pages++;
                }
                return $inner->$method(...$args);
            });
        }
        return $spy;
    }

    private function service(
        SisFixture $s,
        HistoricalEventStore $history,
        ?AuditTrail $audit = null,
        ?RegistrationRepository $repository = null
    ): RegistrationService {
        return new RegistrationService(
            $repository ?? $s->registration,
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->registrationBilling,
            $audit ?? $s->audit,
            $s->clock,
            $s->drops,
            $s->academicCredits(),
            new EnrollmentLedger(
                new SqlEnrollmentHistoryRepository($s->sql),
                $history,
                $s->access,
                $s->clock,
                new SqlSectionVersionRepository($s->sql)
            ),
            $s->activityLedger(),
            $s->primaryProgramLedger(),
            $s->workPursuits(),
            $s->prerequisites(),
            $s->schedules(),
            $s->corequisites(),
            $s->windowPolicies(),
        );
    }

    private function snapshot(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_courses', 'sis_course_versions', 'sis_enrollments',
            'sis_academic_credits', 'sis_student_accounts', 'sis_account_balances', 'sis_registration_streams',
            'sis_registration_decisions', 'sis_academic_credit_streams',
            'sis_registration_batches', 'sis_audit', 'sis_history_events', 'sis_history_streams',
            'sis_history_operations']);
    }

    public function testOnePublicationScanServesTheWholeGroupAndFreshCommandsVerifyAgain(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $section = $s->section(40, 100);
        for ($i = 0; $i < 21; $i++) {
            $version = $s->publishCourse($section->courseId);
        }
        $binding = $f->bindings()->review($section->id, $version, 'Fictional selected edition');
        $f->bindings()->commit($section->id, $binding['review_token']);
        $students = array_map(fn(): EntityId => $s->student()->id, range(1, 25));
        $pages = 0;
        $service = $this->service($s, $this->history($s, $pages));
        $batch = $s->registrationBatches(registration: $service);
        $selection = new RegistrationBatchSelection($section->id, ...$students);
        $review = $batch->review($selection, true);
        self::assertSame(0, $pages);
        $result = $batch->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token'],
            true
        );
        self::assertSame(25, $result['created']);
        self::assertSame(2, $pages, 'Twenty-one publications require two pages, independent of the student count.');
        $before = $this->snapshot($s);
        self::assertSame($result, $batch->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token'],
            true
        ));
        self::assertSame(2, $pages);
        self::assertSame($before, $this->snapshot($s));
        $service->register($s->student()->id, $section->id, true);
        self::assertSame(4, $pages, 'A separate registration must verify history again.');
    }

    public function testRegistrationLocksTheTargetCourseBeforeItsPolicyReadAndOriginalRetriesNeedNoNewPolicyRead(): void
    {
        $s = new SisFixture();
        $section = $s->section();
        $student = $s->student();
        $calls = [];
        $repository = $this->createStub(RegistrationRepository::class);
        foreach (get_class_methods(RegistrationRepository::class) as $method) {
            $repository->method($method)->willReturnCallback(static function (...$args) use (
                $s,
                $method,
                &$calls
            ): mixed {
                $calls[] = $method;
                return $s->registration->$method(...$args);
            });
        }
        $pages = 0;
        $service = $this->service($s, $this->history($s, $pages), repository: $repository);
        $accepted = $service->register($student->id, $section->id, true);
        self::assertSame(
            ['lockStudent', 'lockPerson', 'lockSection', 'lockCourse'],
            array_values(array_filter($calls, static fn(string $call): bool => str_starts_with($call, 'lock')))
        );
        self::assertLessThan(array_search('courseForSection', $calls, true), array_search('lockCourse', $calls, true));
        $calls = [];
        self::assertSame($accepted->id->value, $service->register($student->id, $section->id, true)->id->value);
        self::assertNotContains('courseForSection', $calls);
        self::assertNotContains('requiredCourses', $calls);
    }

    public function testCachedPublicationStillRejectsChangedProjectionAndSelectionsAreBounded(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $section = $s->section();
        $version = $s->publishCourse($section->courseId);
        $binding = $f->bindings()->review($section->id, $version, 'Fictional immutable edition');
        $f->bindings()->commit($section->id, $binding['review_token']);
        $course = $s->registration->courseForSection($s->registration->section($section->id));
        $pages = 0;
        $reader = new PublishedCourseReader($this->history($s, $pages));
        $read = new RegistrationCourseRead();
        $budget = new HistoryReadBudget();
        for ($i = 0; $i < 100; $i++) {
            $read->verify($course, $reader, $budget);
        }
        self::assertSame(1, $pages);
        $s->sql->update(
            'sis_course_versions',
            ['title' => 'Fictional inconsistent projection'],
            ['id' => $version->value]
        );
        try {
            $read->verify(
                $s->registration->courseForSection($s->registration->section($section->id)),
                $reader,
                $budget
            );
            self::fail('The verified cache bypassed comparison of current course facts.');
        } catch (CorruptHistoryException) {
            self::assertSame(1, $pages);
        }
        $draft = new Course(EntityId::generate(), 'FICTIONAL', 'Fictional draft', 3000);
        for ($i = 0; $i < 100; $i++) {
            $read->selection(EntityId::generate(), $draft, []);
        }
        $this->expectException(HistoryReadLimitException::class);
        $read->selection(EntityId::generate(), $draft, []);
    }

    public function testAnInterruptedBoundedScanNeverBecomesAVerifiedCachedPublication(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $section = $s->section();
        $s->publishCourse($section->courseId);
        $version = $s->publishCourse($section->courseId);
        $binding = $f->bindings()->review($section->id, $version, 'Fictional bounded course verification');
        $f->bindings()->commit($section->id, $binding['review_token']);
        $course = $s->registration->courseForSection($s->registration->section($section->id));
        $pages = 0;
        $reader = new PublishedCourseReader($this->history($s, $pages));
        $read = new RegistrationCourseRead();
        try {
            $read->verify($course, $reader, new HistoryReadBudget(maximumPages: 1, maximumEvents: 1));
            self::fail('A partial publication scan was accepted.');
        } catch (HistoryReadLimitException) {
            self::assertSame(1, $pages);
        }
        $read->verify($course, $reader, new HistoryReadBudget());
        self::assertSame(2, $pages, 'A failed scan must not populate the verified cache.');
    }

    public function testFinalSingleRegistrationAuditCannotChangeTheAssessedDraftCourse(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(fee: 100);
        $pages = 0;
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action, EntityId $id)
 use ($s, $section): void {
            $s->audit->record($actor, $action, $id);
            $s->sql->update('sis_courses', ['credits_milli' => 4000], ['id' => $section->courseId->value]);
        });
        $before = $this->snapshot($s);
        try {
            $this->service($s, $this->history($s, $pages), $audit)->register($student->id, $section->id, true);
            self::fail('Course credits changed after the academic and financial decisions.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->snapshot($s));
            self::assertSame(0, $pages);
        }
    }

    public function testFinalGroupAuditCannotLeaveChangedPublishedCourseFactsAfterEarlierStudentsWereAccepted(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $section = $s->section(fee: 100);
        $version = $s->publishCourse($section->courseId);
        $binding = $f->bindings()->review($section->id, $version, 'Fictional group course edition');
        $f->bindings()->commit($section->id, $binding['review_token']);
        $selection = new RegistrationBatchSelection($section->id, $s->student()->id, $s->student()->id);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action, EntityId $id)
 use ($s, $version): void {
            $s->audit->record($actor, $action, $id);
            $s->sql->update(
                'sis_course_versions',
                ['title' => 'Fictional changed projection'],
                ['id' => $version->value]
            );
        });
        $pages = 0;
        $batch = $s->registrationBatches(audit: $audit, registration: $this->service($s, $this->history($s, $pages)));
        $review = $batch->review($selection, true);
        $before = $this->snapshot($s);
        try {
            $batch->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token'],
                true
            );
            self::fail('The group completed after its course projection changed.');
        } catch (ConcurrentModificationException | CorruptHistoryException) {
            self::assertSame($before, $this->snapshot($s));
            self::assertSame(1, $pages);
        }
    }

    public function testSchedulePlansKeepDifferentPublicationsOfOneCourseSeparateWhileSharingFinalVerification(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $a = $s->section(fee: 100);
        $b = $s->section(fee: 200);
        $s->sql->update('sis_sections', ['course_id' => $a->courseId->value], ['id' => $b->id->value]);
        $first = $s->publishCourse($a->courseId);
        $s->sql->update('sis_courses', ['title' => 'Fictional later edition'], ['id' => $a->courseId->value]);
        $second = $s->publishCourse($a->courseId);
        foreach ([[$a, $first], [$b, $second]] as [$section, $version]) {
            $binding = $f->bindings()->review($section->id, $version, 'Fictional explicit section edition');
            $f->bindings()->commit($section->id, $binding['review_token']);
        }
        $selection = new RegistrationPlanSelection($s->student()->id, $a->id, $b->id);
        $pages = 0;
        $batch = $s->registrationBatches(registration: $this->service($s, $this->history($s, $pages)));
        $review = $batch->reviewPlan($selection, true);
        $receipt = $batch->commitPlan(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token'],
            true
        );
        self::assertSame(2, $receipt['created']);
        self::assertSame(2, $pages);
        self::assertSame([$first->value, $second->value], array_column(
            $s->sql->rows('sis_credit_course_versions'),
            'course_version_id'
        ));
    }
}
