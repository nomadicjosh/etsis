<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Catalog\Course;
use Domain\Catalog\ValueObject\CreditPolicy;
use Domain\Finance\Enum\BillingBasis;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\AccountId;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use Infrastructure\Portal\SymfonyContentMarkup;
use PHPUnit\Framework\TestCase;
use Tests\Support\OfferingCreditFixture;

final class OfferingCreditsTest extends TestCase
{
    public function testOfferingPlanActivationCarriesEachSectionsChosenCredits(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
        $c = new \CatalogWorkflowFixture();
        $s = $c->sis;
        $service = $c->offerings();
        $plan = $c->plan();
        $draft = $service->review($plan);
        $operation = new EntityId($draft['operation_id']);
        $service->save($operation, $plan, $draft['revision']);
        $section = $plan->sections[0];
        $editor = new \Domain\Catalog\Service\CatalogEditor(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->registration,
            $s->access,
            $s->transactions,
            $s->audit,
        );
        $editor->creditPolicy(
            $section->courseId,
            3000,
            new CreditPolicy(1000, 6000, 500),
            $editor->creditSettings('courses', $section->courseId)['revision']
        );
        $version = $s->publishCourse($section->courseId);
        $editor->sectionCredits($section->id, 2500, $editor->creditSettings('sections', $section->id)['revision']);
        $choices = [];
        foreach ($plan->sections as $item) {
            $choices[$item->id->value] = $version;
        }
        $review = $service->reviewPublication($operation, $choices, 'Fictional varied offering credit approval.');
        self::assertSame(2500, $review['sections'][0]['section_definition']['credits_milli']);
        $service->publish($operation, $review['review_token']);
        $student = $s->student();
        $s->service->register($student->id, $section->id);
        self::assertSame(2500, (int) $s->sql->rows('sis_academic_credits')[0]['attempted_milli']);
    }

    public function testCreditEditsRollBackAfterAuditFailureAuthorityLossAndSourceChanges(): void
    {
        foreach (['course', 'section'] as $kind) {
            foreach (['audit', 'authority', 'source'] as $failure) {
                $f = new OfferingCreditFixture();
                $f->range();
                $s = $f->sis;
                $before = [$s->sql->rows('sis_courses'), $s->sql->rows('sis_sections'),
                    $s->sql->rows('sis_audit'), $s->sql->rows('sis_history_events')];
                $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
                $audit->method('record')->willReturnCallback(static function () use ($s, $f, $failure): void {
                    if ($failure === 'audit') {
                        throw new \RuntimeException('Fictional audit failure.');
                    }
                    if ($failure === 'authority') {
                        $s->access->staff = false;
                        return;
                    }
                    $s->sql->update('sis_courses', ['credit_policy' => json_encode(
                        (new CreditPolicy(1000, 6000, 1000))->describe(),
                        JSON_THROW_ON_ERROR,
                    )], ['id' => $f->section->courseId->value]);
                });
                $editor = new \Domain\Catalog\Service\CatalogEditor(
                    new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
                    $s->registration,
                    $s->access,
                    $s->transactions,
                    $audit,
                );
                try {
                    if ($kind === 'course') {
                        $editor->creditPolicy(
                            $f->section->courseId,
                            3500,
                            new CreditPolicy(1000, 6000, 500),
                            $editor->creditSettings('courses', $f->section->courseId)['revision']
                        );
                    } else {
                        $editor->sectionCredits(
                            $f->section->id,
                            2500,
                            $editor->creditSettings('sections', $f->section->id)['revision']
                        );
                    }
                    self::fail('An incomplete credit edit committed.');
                } catch (\RuntimeException | \DomainException $error) {
                    self::assertNotEmpty($error->getMessage());
                }
                self::assertSame($before, [$s->sql->rows('sis_courses'), $s->sql->rows('sis_sections'),
                    $s->sql->rows('sis_audit'), $s->sql->rows('sis_history_events')]);
            }
        }
    }

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/OfferingCreditFixture.php';
    }

    public function testOfferingQuantityFlowsThroughReviewedRegistrationPricingAcademicFactsLoadAndPortal(): void
    {
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $f->range();
        $courseVersion = $f->bind();
        $f->choose(2500);
        $publication = $f->publish();
        $f->select($publication);
        $student = $s->student();
        $db = $s->connection->queryBuilder();
        $prices = new TermBillingService(
            new EventSourcedBillingRuleRepository(
                $s->sql,
                EventStoreFixture::framework($db),
                $s->transactions,
                EventStoreFixture::financialWriter($db)
            ),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->financialCommands()
        );
        $prices->define(
            EntityId::generate(),
            $s->term,
            null,
            'Fictional credit rate',
            'tuition',
            new Money(10000, 'USD'),
            'Fictional pricing approval.',
            BillingBasis::RegisteredCredit
        );
        $batch = $s->registrationBatches();
        $selection = new RegistrationBatchSelection($f->section->id, $student->id);
        $review = $batch->review($selection);
        self::assertSame(2500, $review['section']['credits_milli']);
        self::assertSame(25000, $review['students'][0]['charges']['total_minor']);
        $receipt = $batch->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token']
        );
        self::assertSame(1, $receipt['created']);
        $credit = $s->sql->one('sis_academic_credits', ['student_id' => $student->id->value]);
        self::assertSame(2500, (int) $credit['attempted_milli']);
        self::assertSame(0, (int) $credit['completed_milli']);
        $account = $s->sql->one('sis_student_accounts', ['student_id' => $student->id->value]);
        self::assertSame(25000, $s->accounts->get(new AccountId($account['id']))->balance()->minor);
        $pursuit = new EntityId($s->sql->one('sis_student_programs', ['student_id' => $student->id->value])['id']);
        $load = $s->enrollmentLoad()->review($student->id, $pursuit, $s->term, 'staff');
        self::assertSame(2500, $load['credits_milli']);
        $campus = new SqlCampusRepository($s->sql, $s->clock, new SymfonyContentMarkup());
        self::assertSame(2500, (int) $campus->sections(null, '', null)['items'][0]['credits_milli']);
        self::assertSame(2500, (int) $campus->enrollments($student->id)[0]['credits_milli']);
        $retainedCourse = $s->coursePublications()->versions($f->section->courseId)['items'][0]['definition'];
        self::assertSame(3000, $retainedCourse['credits_milli']);
        $financial = $s->sql->rows('sis_student_accounts');
        $f->choose(4000);
        self::assertSame(2500, (int) $campus->enrollments($student->id)[0]['credits_milli']);
        self::assertSame(4000, (int) $campus->sections(null, '', null)['items'][0]['credits_milli']);
        self::assertSame($financial, $s->sql->rows('sis_student_accounts'));
        self::assertSame(2500, (int) $s->sql->one('sis_academic_credits', ['id' => $credit['id']])['attempted_milli']);
        self::assertSame($receipt, $batch->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token']
        ));
        $later = $s->student();
        $blocked = $batch->review(new RegistrationBatchSelection($f->section->id, $later->id));
        self::assertNotEmpty($blocked['students'][0]['issues']);
        $f->amend($f->publish());
        $next = $batch->review($laterSelection = new RegistrationBatchSelection($f->section->id, $later->id));
        self::assertSame(4000, $next['section']['credits_milli']);
        self::assertSame(40000, $next['students'][0]['charges']['total_minor']);
        $batch->commit(new EntityId($next['operation_id']), $laterSelection, $next['revision'], $next['review_token']);
        self::assertSame(2500, (int) $campus->enrollments($student->id)[0]['credits_milli']);
        self::assertSame(4000, (int) $campus->enrollments($later->id)[0]['credits_milli']);
        self::assertSame(2500, (int) $s->sql->one('sis_academic_credits', ['id' => $credit['id']])['attempted_milli']);
    }

    public function testRangeAndOfferingSetupRequireRetainedDefinitionsBeforeFirstRegistration(): void
    {
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $f->range();
        $f->choose(2500);
        $student = $s->student();
        try {
            $s->service->register($student->id, $f->section->id, true);
            self::fail('Unpublished offering credits were accepted.');
        } catch (\DomainException) {
            self::assertSame([], $s->sql->rows('sis_enrollments'));
            self::assertSame([], $s->sql->rows('sis_academic_credits'));
        }
        $f->bind();
        $f->select($f->publish());
        $s->service->register($student->id, $f->section->id, true);
        self::assertSame(2500, (int) $s->sql->rows('sis_academic_credits')[0]['attempted_milli']);
    }

    public function testOfferingChoicesUseTheBoundCoursePolicyAndNeverTodaysDraft(): void
    {
        $f = new OfferingCreditFixture();
        $f->range();
        $f->bind();
        $course = $f->section->courseId;
        $f->editor->creditPolicy(
            $course,
            3000,
            CreditPolicy::fixed(3000),
            $f->editor->creditSettings('courses', $course)['revision']
        );
        $f->choose(2500);
        self::assertSame(2500, $f->editor->creditSettings('sections', $f->section->id)['offering_credits_milli']);
        try {
            $f->choose(2250);
            self::fail('An off-step quantity was accepted.');
        } catch (ValidationException) {
            self::assertSame(2500, $f->editor->creditSettings('sections', $f->section->id)['offering_credits_milli']);
        }
        $f->choose(null);
        self::assertNull($f->editor->creditSettings('sections', $f->section->id)['offering_credits_milli']);
    }

    public function testCreditOnlyChangesInvalidateCourseAndSectionPublicationReviews(): void
    {
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $f->range();
        $review = $s->coursePublications()->review($f->section->courseId, 'Fictional reviewed range.');
        $f->editor->creditPolicy(
            $f->section->courseId,
            3000,
            new CreditPolicy(1000, 5000, 500),
            $f->editor->creditSettings('courses', $f->section->courseId)['revision']
        );
        try {
            $s->coursePublications()->publish($f->section->courseId, $review['review_token']);
            self::fail('A stale range review published.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        $f->bind();
        $f->choose(2500);
        $term = $s->sql->one('sis_terms', ['id' => $s->term->value]);
        $review = $f->publications->review($f->section->id, $term['starts_on'], $term['ends_on'], 'Fictional review.');
        $f->choose(4000);
        $this->expectException(ConcurrentModificationException::class);
        $f->publications->publish($f->section->id, $review['review_token']);
    }

    public function testGenericCourseEditsCannotBypassASavedCreditPolicy(): void
    {
        $f = new OfferingCreditFixture();
        $f->range();
        $course = $f->sis->registration->course($f->section->courseId);
        $this->expectException(ValidationException::class);
        $f->editor->course(
            new Course($course->id, $course->code, $course->title, 2250),
            $f->editor->view('courses', $course->id)['revision']
        );
    }

    public function testCloningCopiesTheRangeAndInvalidCloneDefaultsCannotLeavePartialDrafts(): void
    {
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $f->range();
        $clones = new \Domain\Catalog\Service\CourseCloneService(
            new \Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->registration,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock
        );
        $review = $clones->review($f->section->courseId);
        $draft = new Course(EntityId::generate(), 'COPY.1', 'Fictional copied range', 3500, false);
        $clones->commit($f->section->courseId, $draft, [], $review['revision']);
        self::assertSame(
            ['minimum_milli' => 1000, 'maximum_milli' => 6000, 'increment_milli' => 500],
            $f->editor->creditSettings('courses', $draft->id)['credit_policy']
        );
        $review = $clones->review($f->section->courseId);
        try {
            $clones->commit(
                $f->section->courseId,
                new Course(EntityId::generate(), 'COPY.2', 'Fictional invalid copied range', 3250, false),
                [],
                $review['revision']
            );
            self::fail('An invalid cloned default was accepted.');
        } catch (ValidationException) {
            self::assertSame([], $s->sql->rows('sis_courses', ['code' => 'COPY.2']));
        }
    }

    public function testTamperedRangeProjectionsCannotAuthorizeAnAcademicDecision(): void
    {
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $f->range();
        $version = $f->bind();
        $f->choose(2500);
        $f->select($f->publish());
        $student = $s->student();
        $s->sql->update('sis_course_versions', ['credit_policy' => json_encode(
            new CreditPolicy(1000, 6000, 250)->describe(),
            JSON_THROW_ON_ERROR,
        )], ['id' => $version->value]);
        $events = $s->sql->rows('sis_history_events');
        try {
            $s->service->register($student->id, $f->section->id, true);
            self::fail('A changed policy projection was trusted.');
        } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
            self::assertSame($events, $s->sql->rows('sis_history_events'));
            self::assertSame([], $s->sql->rows('sis_enrollments'));
            self::assertSame([], $s->sql->rows('sis_academic_credits'));
        }
    }
}
