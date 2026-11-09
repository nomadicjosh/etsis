<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Catalog\ValueObject\OfferingPlan;
use Domain\Registration\Section;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class CatalogSetupWorkflowTest extends TestCase
{
    public function testCourseCloneCopiesOnlyReviewedPrerequisitesIntoAnInactiveDraft(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $source = $f->section();
        $p = $f->section();
        $unselected = $f->section();
        $f->service->register($f->student()->id, $source->id);
        $f->registration->addPrerequisite($source->courseId, $p->courseId);
        $f->registration->addPrerequisite($source->courseId, $unselected->courseId);
        $credits = $f->sql->rows('sis_academic_credits');
        $enrollments = $f->sql->rows('sis_enrollments');
        $events = $f->sql->rows('sis_history_events');
        $sections = $f->sql->rows('sis_sections');
        $service = $c->clones();
        $review = $service->review($source->courseId);
        $draft = new Course(new EntityId($review['operation_id']), 'MATH.102', 'Fictional Algebra II', 4000, false);
        $result = $service->commit($source->courseId, $draft, [$p->courseId], $review['revision']);
        self::assertFalse($f->registration->course($draft->id)->active);
        self::assertSame([$p->courseId->value], array_column($c->operations->prerequisites($draft->id), 'id'));
        self::assertSame($result, $service->commit($source->courseId, $draft, [$p->courseId], $review['revision']));
        self::assertSame($result, $service->saved($draft->id));
        self::assertSame($credits, $f->sql->rows('sis_academic_credits'));
        self::assertSame($enrollments, $f->sql->rows('sis_enrollments'));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertSame($sections, $f->sql->rows('sis_sections'));
        self::assertCount(1, $f->sql->rows('sis_catalog_operations'));
    }

    public function testCourseCloneRejectsStalePrerequisitesAndUnreviewedPrerequisiteIds(): void
    {
        foreach (['stale', 'foreign'] as $case) {
            $c = new CatalogWorkflowFixture();
            $f = $c->sis;
            $source = $f->section();
            $p = $f->section();
            $service = $c->clones();
            $review = $service->review($source->courseId);
            $draft = new Course(new EntityId($review['operation_id']), 'MATH.102', 'Fictional Algebra II', 4000, false);
            if ($case === 'stale') {
                $f->registration->addPrerequisite($source->courseId, $p->courseId);
            }
            try {
                $service->commit($source->courseId, $draft, [$p->courseId], $review['revision']);
                self::fail('Unreviewed prerequisites were copied.');
            } catch (ConcurrentModificationException | ValidationException) {
                self::assertNull($c->operations->operation($draft->id));
                self::assertSame([], $f->sql->rows('sis_courses', ['id' => $draft->id->value]));
            }
        }
    }

    public function testOfferingDraftSaveAndExplicitPublicationAreSeparateAtomicRepeatableActions(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $plan = $c->plan();
        $service = $c->offerings();
        $events = $f->sql->rows('sis_history_events');
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $draft = $plan->sections[0];
        self::assertSame([], $f->sql->rows('sis_sections', ['id' => $draft->id->value]));
        $saved = $service->save($id, $plan, $review['revision']);
        self::assertFalse($f->registration->section($draft->id)->active);
        self::assertFalse($f->registration->section($draft->id)->webRegistration);
        self::assertNull($saved['published_at']);
        self::assertSame($saved, $service->save($id, $plan, $review['revision']));
        $publication = $c->publicationReview($service, $id);
        self::assertTrue($publication['sections'][0]['web_registration']);
        $published = $service->publish($id, $publication['review_token']);
        self::assertNotNull($published['published_at']);
        self::assertTrue($f->registration->section($draft->id)->active);
        self::assertTrue($f->registration->section($draft->id)->webRegistration);
        self::assertSame($published, $service->publish($id, $publication['review_token']));
        self::assertSame($published, $service->save($id, $plan, $review['revision']));
        self::assertSame(
            ['course-definition-was-published', 'section-course-version-was-bound'],
            array_column($f->sql->rows('sis_history_events'), 'event_type')
        );
        self::assertCount(1, $service->page()['items']);
        self::assertCount(1, $f->sql->rows('sis_audit', ['action' => 'section.published']));
    }

    public function testDraftsCannotRegisterAndPublicationDoesNotRelaxRegistrationRules(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $plan = $c->plan();
        $service = $c->offerings();
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $draft = $plan->sections[0];
        $service->save($id, $plan, $review['revision']);
        $student = $f->student();
        try {
            $f->service->register($student->id, $draft->id);
            self::fail('An inactive draft was registered.');
        } catch (DomainException) {
            self::assertNull($f->registration->enrollment($student->id, $draft->id));
        }
        $publication = $c->publicationReview($service, $id);
        $service->publish($id, $publication['review_token']);
        $f->clock->time = new DateTimeImmutable('2026-09-15T10:00:00-07:00');
        try {
            $f->service->register($student->id, $draft->id);
            self::fail('Publication bypassed a closed window.');
        } catch (DomainException) {
            self::assertNull($f->registration->enrollment($student->id, $draft->id));
        }
    }

    public function testChangedCourseAndPublicationDraftRequireFreshReviews(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $plan = $c->plan();
        $service = $c->offerings();
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $draft = $plan->sections[0];
        $f->sql->update('sis_courses', ['title' => 'Changed course'], ['id' => $draft->courseId->value]);
        try {
            $service->save($id, $plan, $review['revision']);
            self::fail('A stale offering plan was saved.');
        } catch (ConcurrentModificationException) {
            self::assertNull($c->operations->operation($id));
        }
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $service->save($id, $plan, $review['revision']);
        $publication = $c->publicationReview($service, $id);
        $f->sql->update('sis_sections', ['fee_minor' => 999], ['id' => $draft->id->value]);
        try {
            $service->publish($id, $publication['review_token']);
            self::fail('An unreviewed price was published.');
        } catch (ConcurrentModificationException) {
            self::assertFalse($f->registration->section($draft->id)->active);
            self::assertNull($service->saved($id)['published_at']);
        }
    }

    public function testOfferingAndCloneRetriesAreBoundToActorAndPayload(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $plan = $c->plan();
        $service = $c->offerings();
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $service->save($id, $plan, $review['revision']);
        $f->access->actor = $f->user();
        $this->expectException(ConcurrentModificationException::class);
        $service->save($id, $plan, $review['revision']);
    }

    public function testCatalogWorkflowPermissionIsRequiredForAllReadsAndWrites(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $plan = $c->plan();
        $service = $c->offerings();
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $service->save($id, $plan, $review['revision']);
        $f->access->staff = false;
        $f->access->grants = ['sis:registration:manage'];
        foreach (['review', 'save', 'saved', 'page', 'reviewPublication', 'publish', 'cloneReview'] as $method) {
            try {
                match ($method) {
                    'review' => $service->review($plan), 'save' => $service->save($id, $plan, $review['revision']),
                    'saved' => $service->saved($id), 'page' => $service->page(),
                    'reviewPublication' => $service->reviewPublication($id, [], 'Fictional reason'), 'publish' => $service->publish($id, 'revision'),
                    'cloneReview' => $c->clones()->review($plan->sections[0]->courseId),
                };
                self::fail('Registrar registration access granted catalog administration.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMaximumOfferingPlanRetainsEveryDraftAndLongUnicodeLabelWithoutTruncation(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $existing = $f->section();
        $f->sql->update('sis_courses', ['title' => str_repeat('學', 191)], ['id' => $existing->courseId->value]);
        $sections = [];
        for ($i = 0; $i < 25; ++$i) {
            $sections[] = new Section(
                EntityId::generate(),
                $existing->courseId,
                $f->term,
                null,
                'FICTIONAL.' . $i,
                30,
                false,
                true,
                $existing->fee
            );
        }
        $plan = new OfferingPlan($f->term, ...$sections);
        $service = $c->offerings();
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $saved = $service->save($id, $plan, $review['revision']);
        self::assertCount(25, $saved['review']['sections']);
        self::assertSame(str_repeat('學', 191), $saved['review']['sections'][24]['course_title']);
        self::assertGreaterThan(65535, strlen($c->operations->operation($id)['encrypted_content']));
        $publication = $c->publicationReview($service, $id);
        $service->publish($id, $publication['review_token']);
        foreach ($sections as $section) {
            self::assertTrue($f->registration->section($section->id)->active);
        }
    }

    public function testSectionCodesCanBeReusedInAnotherTermAndDuplicatePlanCodesAreRejected(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $existing = $f->section();
        $term = EntityId::generate();
        $f->sql->insert('sis_terms', ['id' => $term->value, 'code' => '27/SP', 'starts_on' => '2027-01-01',
            'ends_on' => '2027-05-31', 'add_drop_ends_on' => '2027-01-15']);
        $draft = new Section(
            EntityId::generate(),
            $existing->courseId,
            $term,
            null,
            $existing->code,
            30,
            false,
            false,
            $existing->fee
        );
        $plan = new OfferingPlan($term, $draft);
        $service = $c->offerings();
        $review = $service->review($plan);
        $service->save(new EntityId($review['operation_id']), $plan, $review['revision']);
        self::assertSame($term->value, $f->registration->section($draft->id)->termId->value);
        $this->expectException(ValidationException::class);
        new OfferingPlan($term, $draft, new Section(
            EntityId::generate(),
            $draft->courseId,
            $term,
            null,
            mb_strtolower($draft->code),
            30,
            false,
            false,
            $draft->fee
        ));
    }

    public function testLatePublicationAuditFailureRollsBackAllActivations(): void
    {
        $c = new CatalogWorkflowFixture();
        $f = $c->sis;
        $plan = $c->plan();
        $other = $f->section();
        $s = $plan->sections[0];
        $plan = new OfferingPlan($f->term, $s, new Section(
            EntityId::generate(),
            $other->courseId,
            $f->term,
            null,
            'MATH.102.26FA.A',
            30,
            false,
            true,
            $s->fee
        ));
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function (EntityId $actor, string $action, EntityId $subject): void {
            if ($action === 'catalog.offerings_published') {
                throw new RuntimeException('Fictional publication failure');
            }
        });
        $service = $c->offerings($audit);
        $review = $service->review($plan);
        $id = new EntityId($review['operation_id']);
        $service->save($id, $plan, $review['revision']);
        $publication = $c->publicationReview($service, $id);
        try {
            $service->publish($id, $publication['review_token']);
            self::fail('Partial publication was committed.');
        } catch (RuntimeException $error) {
            self::assertSame('Fictional publication failure', $error->getMessage());
        }
        foreach ($plan->sections as $section) {
            self::assertFalse($f->registration->section($section->id)->active);
        }
        self::assertNull($service->saved($id)['published_at']);
    }
}
