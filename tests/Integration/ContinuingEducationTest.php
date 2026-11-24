<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\ValueObject\OfferingCeuChoice;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\Repository\SqlCampusRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use Infrastructure\Portal\SymfonyContentMarkup;
use PHPUnit\Framework\TestCase;
use Tests\Support\OfferingCreditFixture;

final class ContinuingEducationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/OfferingCreditFixture.php';
    }

    private function choose(OfferingCreditFixture $f, string $kind, string $mode, ?int $milli = null): void
    {
        $id = $kind === 'courses' ? $f->section->courseId : $f->section->id;
        $f->editor->continuingEducation(
            $kind,
            $id,
            new OfferingCeuChoice($mode, $milli),
            $f->editor->continuingEducationSettings($kind, $id)['revision']
        );
    }

    public function testInheritedCeusUseTheRetainedCourseAndFreezeAtRegistrationAcrossFutureAmendments(): void
    {
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $this->choose($f, 'courses', 'fixed', 1250);
        $courseVersion = $f->bind();
        self::assertSame(1250, new SqlCourseVersionProjection($s->sql)->get($courseVersion)
            ->definition->continuingEducation->milli);
        $this->choose($f, 'courses', 'fixed', 9000);
        self::assertSame(['ceu_milli' => 1250], $f->editor->continuingEducationSettings(
            'sections',
            $f->section->id,
        )['course_continuing_education']);
        $f->select($first = $f->publish());
        $student = $s->student();
        $s->service->register($student->id, $f->section->id, true);
        $campus = new SqlCampusRepository($s->sql, $s->clock, new SymfonyContentMarkup());
        self::assertSame(['ceu_milli' => 1250], $campus->enrollments($student->id)[0]['continuing_education']);
        $financial = $s->sql->rows('sis_student_accounts');
        $academic = $s->sql->rows('sis_academic_credits');
        $this->choose($f, 'sections', 'fixed', 2500);
        self::assertSame(['ceu_milli' => 1250], $campus->sections(null, '', null)['items'][0]['continuing_education']);
        $later = $s->student();
        try {
            $s->service->register($later->id, $f->section->id, true);
            self::fail('An unpublished CEU change was accepted.');
        } catch (\DomainException) {
            self::assertSame($academic, $s->sql->rows('sis_academic_credits'));
        }
        $f->amend($second = $f->publish());
        $s->service->register($later->id, $f->section->id, true);
        self::assertSame(['ceu_milli' => 1250], $campus->enrollments($student->id)[0]['continuing_education']);
        self::assertSame(['ceu_milli' => 2500], $campus->enrollments($later->id)[0]['continuing_education']);
        self::assertSame(['ceu_milli' => 2500], $campus->sections(null, '', null)['items'][0]['continuing_education']);
        self::assertSame($financial, $s->sql->rows('sis_student_accounts'));
        self::assertSame($academic[0], $s->sql->one('sis_academic_credits', ['id' => $academic[0]['id']]));
        self::assertSame(3000, (int) $academic[0]['attempted_milli']);
        self::assertNotEquals($first, $second);
    }

    public function testCourseOnlyCeuEditInvalidatesItsPublicationReviewAndOriginalAcceptedRetriesSurvive(): void
    {
        $f = new OfferingCreditFixture();
        $service = $f->sis->coursePublications();
        $course = $f->section->courseId;
        $review = $service->review($course, 'Fictional CEU declaration.');
        $this->choose($f, 'courses', 'fixed', 1234);
        try {
            $service->publish($course, $review['review_token']);
            self::fail('A CEU-only stale course review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sis->sql->rows('sis_history_events'));
        }
        $review = $service->review($course, 'Fictional exact CEUs.');
        $receipt = $service->publish($course, $review['review_token']);
        $this->choose($f, 'courses', 'none');
        $later = $service->review($course, 'Fictional explicit no CEUs.');
        $service->publish($course, $later['review_token']);
        self::assertSame($receipt, $service->publish($course, $review['review_token']));
        $versions = $service->versions($course)['items'];
        self::assertSame(['ceu_milli' => 1234], $versions[0]['definition']['continuing_education']);
        self::assertSame(['ceu_milli' => null], $versions[1]['definition']['continuing_education']);
    }

    public function testSectionReviewAndSelectionRejectCeuOnlyChangesWithoutPublishing(): void
    {
        $f = new OfferingCreditFixture();
        $f->bind();
        $term = $f->sis->sql->one('sis_terms', ['id' => $f->sis->term->value]);
        $review = $f->publications->review(
            $f->section->id,
            $term['starts_on'],
            $term['ends_on'],
            'Fictional initial offering.'
        );
        $this->choose($f, 'sections', 'fixed', 700);
        try {
            $f->publications->publish($f->section->id, $review['review_token']);
            self::fail('A CEU-only stale offering review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sis->sql->rows('sis_section_versions'));
        }
        $publication = $f->publish();
        $this->choose($f, 'sections', 'none');
        $this->expectException(ConcurrentModificationException::class);
        $f->select($publication);
    }

    public function testADeclaredOfferingRequiresPublicationAndExplicitNoneDiffersFromZero(): void
    {
        $f = new OfferingCreditFixture();
        $this->choose($f, 'courses', 'fixed', 1250);
        $f->bind();
        $student = $f->sis->student();
        try {
            $f->sis->service->register($student->id, $f->section->id, true);
            self::fail('Offered CEUs were accepted without a retained offering.');
        } catch (\DomainException) {
            self::assertSame([], $f->sis->sql->rows('sis_enrollments'));
        }
        $this->choose($f, 'sections', 'none');
        $f->select($none = $f->publish());
        self::assertSame(['ceu_milli' => null], $f->publications->offeringContext($f->section->id, $none)
            ['selected']->definition->data['continuing_education']);
        $this->choose($f, 'sections', 'fixed', 0);
        $f->amend($f->publish());
        $f->sis->service->register($student->id, $f->section->id, true);
        $campus = new SqlCampusRepository($f->sis->sql, $f->sis->clock, new SymfonyContentMarkup());
        self::assertSame(['ceu_milli' => 0], $campus->enrollments($student->id)[0]['continuing_education']);
    }

    public function testDraftSavesRecheckRevisionAndFinalAuthorityAndRollBackFailedAudit(): void
    {
        foreach (['courses', 'sections'] as $kind) {
            foreach (['audit', 'authority', 'source'] as $failure) {
                $f = new OfferingCreditFixture();
                $s = $f->sis;
                $id = $kind === 'courses' ? $f->section->courseId : $f->section->id;
                $revision = $f->editor->continuingEducationSettings($kind, $id)['revision'];
                $before = [$s->sql->rows('sis_courses'), $s->sql->rows('sis_sections'), $s->sql->rows('sis_audit')];
                $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
                $audit->expects(self::once())->method('record')->willReturnCallback(static function () use (
                    $s,
                    $failure,
                    $kind,
                    $id,
                ): void {
                    if ($failure === 'audit') {
                        throw new \RuntimeException('Fictional audit failure.');
                    }
                    if ($failure === 'authority') {
                        $s->access->staff = false;
                    } else {
                        $s->sql->update('sis_' . $kind, ['continuing_education' => null], ['id' => $id->value]);
                    }
                });
                $editor = new \Domain\Catalog\Service\CatalogEditor(
                    new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
                    $s->registration,
                    $s->access,
                    $s->transactions,
                    $audit,
                );
                try {
                    $editor->continuingEducation($kind, $id, new OfferingCeuChoice('fixed', 1234), $revision);
                    self::fail('An invalid final CEU save completed.');
                } catch (\RuntimeException | \DomainException $error) {
                    self::assertNotEmpty($error->getMessage());
                    self::assertSame($before, [$s->sql->rows('sis_courses'), $s->sql->rows('sis_sections'),
                        $s->sql->rows('sis_audit')]);
                }
            }
        }
    }

    public function testRetainedCeuProjectionTamperingFailsClosed(): void
    {
        $f = new OfferingCreditFixture();
        $this->choose($f, 'courses', 'fixed', 1250);
        $version = $f->bind();
        $f->sis->sql->update(
            'sis_course_versions',
            ['continuing_education' => '{"ceu_milli":9000}'],
            ['id' => $version->value]
        );
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $f->sis->service->register($f->sis->student()->id, $f->section->id, true);
    }

    public function testGroupedRegistrationKeepsAcceptedCeusAndRejectsLaterUnpublishedChanges(): void
    {
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $f->bind();
        $this->choose($f, 'sections', 'fixed', 750);
        $f->select($f->publish());
        $first = $s->student();
        $second = $s->student();
        $batch = $s->registrationBatches();
        $selection = new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $f->section->id,
            $first->id,
            $second->id,
        );
        $review = $batch->review($selection);
        $operation = new \Domain\Shared\ValueObject\EntityId($review['operation_id']);
        $receipt = $batch->commit($operation, $selection, $review['revision'], $review['review_token']);
        $this->choose($f, 'sections', 'fixed', 1500);
        self::assertSame($receipt, $batch->commit(
            $operation,
            $selection,
            $review['revision'],
            $review['review_token'],
        ));
        $campus = new SqlCampusRepository($s->sql, $s->clock, new SymfonyContentMarkup());
        foreach ([$first, $second] as $student) {
            self::assertSame(['ceu_milli' => 750], $campus->enrollments($student->id)[0]['continuing_education']);
        }
        $blocked = $batch->review(new \Domain\Registration\ValueObject\RegistrationBatchSelection(
            $f->section->id,
            $s->student()->id,
        ));
        self::assertNotEmpty($blocked['students'][0]['issues']);
    }

    public function testCourseCloneCopiesReviewedCeusAndInvalidatesChangedSourceReviews(): void
    {
        $f = new OfferingCreditFixture();
        $s = $f->sis;
        $service = new \Domain\Catalog\Service\CourseCloneService(
            new \Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->registration,
            $s->access,
            $s->transactions,
            $s->audit,
            $s->dropCipher,
            $s->clock,
        );
        $this->choose($f, 'courses', 'fixed', 1250);
        $review = $service->review($f->section->courseId);
        $draft = new \Domain\Catalog\Course(
            new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
            'CE.CLONE',
            'Fictional clone',
            1000,
            false,
        );
        $service->commit($f->section->courseId, $draft, [], $review['revision']);
        self::assertSame(1250, $s->registration->course($draft->id)->continuingEducation->milli);
        $review = $service->review($f->section->courseId);
        $this->choose($f, 'courses', 'fixed', 2500);
        $this->expectException(ConcurrentModificationException::class);
        $service->commit($f->section->courseId, new \Domain\Catalog\Course(
            new \Domain\Shared\ValueObject\EntityId($review['operation_id']),
            'CE.LATER',
            'Fictional later clone',
            1000,
            false,
        ), [], $review['revision']);
    }

    public function testEarlierCourseProjectionRetainsAnAbsentDeclarationWithoutInventingZeroOrNone(): void
    {
        $f = new OfferingCreditFixture();
        $projection = new SqlCourseVersionProjection($f->sis->sql);
        $definition = new \Domain\Catalog\ValueObject\PublishedCourseDefinition(
            $f->section->courseId,
            \Domain\Shared\ValueObject\EntityId::generate(),
            new \Domain\Catalog\ValueObject\CourseDefinition('CE.OLD', 'Fictional earlier course', 3000, []),
        );
        $projection->retain($definition);
        self::assertSame($definition->describe(), $projection->get($definition->versionId)->describe());
        self::assertArrayNotHasKey(
            'continuing_education',
            $projection->get($definition->versionId)->definition->describe(),
        );
        $projection->retain($definition);
    }

    public function testOfferingPlanActivationResolvesEachSectionsIndependentCeuChoice(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
        $fixture = new \CatalogWorkflowFixture();
        $s = $fixture->sis;
        $service = $fixture->offerings();
        $plan = $fixture->plan();
        $draft = $service->review($plan);
        $operation = new \Domain\Shared\ValueObject\EntityId($draft['operation_id']);
        $service->save($operation, $plan, $draft['revision']);
        $editor = new \Domain\Catalog\Service\CatalogEditor(
            new \Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository($s->sql),
            $s->registration,
            $s->access,
            $s->transactions,
            $s->audit,
        );
        $course = $plan->sections[0]->courseId;
        $editor->continuingEducation(
            'courses',
            $course,
            new OfferingCeuChoice('fixed', 1250),
            $editor->continuingEducationSettings('courses', $course)['revision']
        );
        $version = $s->publishCourse($course);
        $choices = [];
        foreach ($plan->sections as $index => $section) {
            $choices[$section->id->value] = $version;
            if ($index === 0) {
                $editor->continuingEducation(
                    'sections',
                    $section->id,
                    new OfferingCeuChoice('fixed', 750),
                    $editor->continuingEducationSettings('sections', $section->id)['revision']
                );
            }
        }
        $review = $service->reviewPublication($operation, $choices, 'Fictional planned CEUs.');
        $service->publish($operation, $review['review_token']);
        $campus = new SqlCampusRepository($s->sql, $s->clock, new SymfonyContentMarkup());
        foreach ($plan->sections as $index => $section) {
            $student = $s->student();
            $s->service->register($student->id, $section->id);
            self::assertSame(
                ['ceu_milli' => $index === 0 ? 750 : 1250],
                $campus->enrollments($student->id)[0]['continuing_education']
            );
        }
    }
}
