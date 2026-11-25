<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Catalog\Service\CoursePrerequisiteService;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\Section;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class PrerequisiteEquivalentsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
    }

    private function editor(CatalogWorkflowFixture $f, ?AuditTrail $audit = null): CoursePrerequisiteService
    {
        $s = $f->sis;
        return new CoursePrerequisiteService(
            new SqlCatalogRevisionRepository($s->sql),
            $f->operations,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
        );
    }

    private function pass(SisFixture $s, EntityId $student, Section $section): void
    {
        $enrollment = $s->service->register($student, $section->id, true);
        $grade = new EntityId($s->gradeCatalog()->byCode('P', $student)['id']);
        $review = $s->academicCredits()->review($student, $section->id, $enrollment->id, $grade, 0);
        $s->academicCredits()->commit($student, $section->id, $review['review_token']);
    }

    private function policy(
        CatalogWorkflowFixture $f,
        EntityId $target,
        array $required,
        ?array $equivalents,
        int $minimum = 1,
    ): array {
        $editor = $this->editor($f);
        return $editor->save(
            $target,
            [$required],
            $editor->current($target)['revision'],
            [$minimum],
            equivalents: $equivalents,
        );
    }

    public function testRegistrationCountsActualCoursesOnceAndRetainsOriginalEquivalenceProof(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $student = $s->student()->id;
        $a = $s->section(faculty: $s->access->actor);
        $b = $s->section();
        $c = $s->section(faculty: $s->access->actor);
        $target = $s->section();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 4000, true, false));
        $this->pass($s, $student, $c);
        $equivalents = [$a->courseId->value => [$c->courseId->value], $b->courseId->value => [$c->courseId->value]];
        $this->policy($f, $target->courseId, [$a->courseId->value, $b->courseId->value], $equivalents, 2);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $before = \EventStoreFixture::historyDigest($s->sql);
        foreach ([false, true] as $staff) {
            $review = $s->registrationBatches()->review($selection, $staff);
            self::assertSame('missing', $review['students'][0]['prerequisites']['status']);
            self::assertSame(1, $review['students'][0]['prerequisites']['groups'][0]['completed_count']);
            self::assertCount(3, $review['section']['prerequisite_courses']);
            self::assertCount(2, $review['students'][0]['prerequisites']['groups'][0]['equivalents']);
            self::assertStringNotContainsString('credit_id', json_encode($review));
        }
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        try {
            $s->service->register($student, $target->id);
            self::fail('One completed equivalent filled two distinct course slots.');
        } catch (\DomainException) {
            self::assertSame(0, $s->registration->sectionCount($target->id));
        }
        $this->pass($s, $student, $a);
        $review = $s->registrationBatches()->review($selection);
        self::assertSame('met', $review['students'][0]['prerequisites']['status']);
        $accepted = $s->service->register($student, $target->id);
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($accepted->id->value)));
        $proof = $events[0]->metaParam('prerequisite_evidence');
        self::assertEquals($equivalents, $proof['equivalents']);
        self::assertCount(2, $proof['support']);
        self::assertSame(
            \Infrastructure\EventSourcing\ProtectedEventCodec::canonical($proof),
            \Infrastructure\EventSourcing\ProtectedEventCodec::canonical(
                PrerequisiteDecision::fromRetained($proof)->retained()
            )
        );
        $this->policy($f, $target->courseId, [$b->courseId->value], null);
        $before = \EventStoreFixture::historyDigest($s->sql);
        self::assertSame($accepted->id->value, $s->service->register($student, $target->id)->id->value);
        self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
        self::assertSame($proof, $events[0]->metaParam('prerequisite_evidence'));
    }

    public function testPublicationsClonesAndReferenceEditsPreserveExplicitRulesAndEarlierAbsence(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $a = $s->section();
        $c = $s->section();
        $target = $s->section();
        $earlier = $s->publishCourse($target->courseId);
        $map = [$a->courseId->value => [$c->courseId->value]];
        $this->policy($f, $target->courseId, [$a->courseId->value], $map);
        $published = $s->publishCourse($target->courseId);
        $cache = new SqlCourseVersionProjection($s->sql);
        foreach ([$earlier, $published] as $id) {
            $cache->retain($s->coursePublications()->publishedDefinition($target->courseId, $id));
        }
        self::assertArrayNotHasKey('prerequisite_equivalents', $cache->get($earlier)->definition->describe());
        self::assertSame($map, $cache->get($published)->definition->prerequisiteGroups->equivalents);
        $review = $f->clones()->review($target->courseId);
        self::assertSame($map, $review['prerequisite_equivalents']);
        $clone = new Course(EntityId::generate(), 'COPY.EQUIV', 'Fictional equivalent clone', 3000, false);
        $before = $s->sql->rows('sis_courses');
        try {
            $f->clones()->commit($target->courseId, $clone, [$a->courseId], $review['revision']);
            self::fail('Clone silently removed a retained equivalent.');
        } catch (ValidationException) {
            self::assertSame($before, $s->sql->rows('sis_courses'));
        }
        $f->clones()->commit($target->courseId, $clone, [$a->courseId, $c->courseId], $review['revision']);
        self::assertSame($map, $f->operations->prerequisiteGroups($clone->id)->equivalents);
        $other = $s->section();
        $s->registration->addPrerequisite($clone->id, $other->courseId);
        self::assertSame($map, $f->operations->prerequisiteGroups($clone->id)->equivalents);
        $s->registration->removePrerequisite($clone->id, $a->courseId);
        self::assertSame([], $f->operations->prerequisiteGroups($clone->id)->equivalents);
        self::assertSame($map, $cache->get($published)->definition->prerequisiteGroups->equivalents);
    }

    public function testEquivalentOnlyChangesStaleReviewsAndFinalAuditDriftRollsBackEveryEffect(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $student = $s->student()->id;
        $a = $s->section(faculty: $s->access->actor);
        $b = $s->section();
        $c = $s->section();
        $target = $s->section();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 4000, true, false));
        $this->pass($s, $student, $a);
        $required = [$a->courseId->value];
        $this->policy($f, $target->courseId, $required, [$a->courseId->value => [$b->courseId->value]]);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $service = $s->registrationBatches();
        $review = $service->review($selection);
        $this->policy($f, $target->courseId, $required, [$a->courseId->value => [$c->courseId->value]]);
        try {
            $service->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token']
            );
            self::fail('An equivalent-only change survived a pending review.');
        } catch (ConcurrentModificationException) {
            self::assertSame(0, $s->registration->sectionCount($target->id));
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $target, $a, $b): void {
            $s->sql->update('sis_course_prerequisite_groups', ['equivalents_json' => json_encode([
                $a->courseId->value => [$b->courseId->value],
            ], JSON_THROW_ON_ERROR)], ['id' => $target->courseId->value]);
        });
        $service = $s->registrationBatches(audit: $audit);
        $review = $service->review($selection);
        $tables = ['sis_history_events', 'sis_history_operations', 'sis_enrollments', 'sis_academic_credits',
            'sis_student_accounts', 'sis_registration_batches', 'sis_course_prerequisite_groups'];
        $before = array_map($s->sql->rows(...), $tables);
        try {
            $service->commit(
                new EntityId($review['operation_id']),
                $selection,
                $review['revision'],
                $review['review_token']
            );
            self::fail('Final equivalent-policy drift committed coupled effects.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, array_map($s->sql->rows(...), $tables));
        }
    }

    public function testBoundRegistrationRefusesWeakenedEquivalentCache(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $student = $s->student()->id;
        $a = $s->section(faculty: $s->access->actor);
        $c = $s->section();
        $target = $s->section();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 4000, true, false));
        $this->pass($s, $student, $a);
        $this->policy($f, $target->courseId, [$a->courseId->value], [$a->courseId->value => [$c->courseId->value]]);
        $publication = $s->publishCourse($target->courseId);
        $binding = $f->bindings()->review($target->id, $publication, 'Fictional equivalent course binding.');
        $f->bindings()->commit($target->id, $binding['review_token']);
        $s->sql->update('sis_course_versions', ['equivalents_json' => null], ['id' => $publication->value]);
        $before = \EventStoreFixture::historyDigest($s->sql);
        try {
            $s->service->register($student, $target->id);
            self::fail('A weakened retained equivalent projection was accepted.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, \EventStoreFixture::historyDigest($s->sql));
            self::assertSame(0, $s->registration->sectionCount($target->id));
        }
    }

    public function testCatalogReferenceDriftAfterRequiredAuditRollsBackTheDraft(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $a = $s->section();
        $c = $s->section();
        $target = $s->section();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $c): void {
            $s->sql->update('sis_courses', ['active' => 0], ['id' => $c->courseId->value]);
        });
        $editor = $this->editor($f, $audit);
        $before = $s->sql->rows('sis_courses');
        try {
            $editor->save(
                $target->courseId,
                [[$a->courseId->value]],
                $editor->current($target->courseId)['revision'],
                equivalents: [$a->courseId->value => [$c->courseId->value]]
            );
            self::fail('Equivalent source retirement during audit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_courses'));
            self::assertSame([], $editor->current($target->courseId)['groups']);
        }
    }

    public function testTransferEquivalentUsesTheReceivingAwardAndSharedSourceAndSchedulePolicies(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $student = $s->student()->id;
        $a = $s->section();
        $c = $s->section();
        $target = $s->section();
        $transfer = new \TransferAwardFixture($s, $s->dropCipher, $student, course: $c->courseId);
        $editor = $this->editor($f);
        $map = [$a->courseId->value => [$c->courseId->value]];
        $editor->save(
            $target->courseId,
            [[$a->courseId->value]],
            $editor->current($target->courseId)['revision'],
            creditOrigin: 'institutional',
            equivalents: $map
        );
        $plan = new \Domain\Registration\ValueObject\RegistrationPlanSelection(
            $student,
            $target->id,
            $s->section()->id,
        );
        $schedule = $s->registrationBatches()->reviewPlan($plan, true);
        self::assertSame('missing', $schedule['sections'][0]['student']['prerequisites']['status']);
        self::assertFalse($s->prerequisitesMet($student, $target->courseId));
        $editor->save(
            $target->courseId,
            [[$a->courseId->value]],
            $editor->current($target->courseId)['revision'],
            creditOrigin: 'transfer',
            equivalents: $map
        );
        $schedule = $s->registrationBatches()->reviewPlan($plan, true);
        self::assertSame('met', $schedule['sections'][0]['student']['prerequisites']['status']);
        $before = $s->sql->one('sis_academic_credits', ['id' => $transfer->credit->value]);
        $selection = new RegistrationBatchSelection($target->id, $student);
        $service = $s->registrationBatches();
        $review = $service->review($selection);
        $service->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token']
        );
        $accepted = $s->registration->enrollment($student, $target->id);
        $events = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($accepted->id->value)));
        $proof = $events[0]->metaParam('prerequisite_evidence');
        self::assertSame('transfer', $proof['support'][0]['origin']);
        self::assertSame($c->courseId->value, $proof['support'][0]['course_id']);
        self::assertSame($transfer->credit->value, $proof['support'][0]['credit_id']);
        self::assertSame($before, $s->sql->one('sis_academic_credits', ['id' => $transfer->credit->value]));
        self::assertSame([], $s->sql->rows('sis_academic_credits', ['course_id' => $a->courseId->value]));
    }

    public function testEquivalenceNeverApprovesCrossPursuitReuse(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $student = $s->student()->id;
        $a = $s->section();
        $c = $s->section(faculty: $s->access->actor);
        $target = $s->section();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 4000, true, false));
        $this->pass($s, $student, $c);
        $this->policy($f, $target->courseId, [$a->courseId->value], [$a->courseId->value => [$c->courseId->value]]);
        self::assertTrue($s->prerequisitesMet($student, $target->courseId));
        $program = EntityId::generate();
        $s->sql->insert('sis_programs', [
            'id' => $program->value, 'code' => 'NEXT.EQUIV', 'title' => 'Fictional next program',
        ]);
        $current = $s->admissions->student($student);
        $revision = hash('sha256', json_encode([$current->id->value, $current->personId->value,
            $current->programId->value, $current->entryTermId->value, $current->active], JSON_THROW_ON_ERROR));
        $change = $s->programAssignmentService()->reviewChange(
            $student,
            $program,
            $s->term,
            $revision,
            'Fictional program change.',
        );
        $s->programAssignmentService()->changeProgram($student, $change['review_token']);
        self::assertFalse($s->prerequisitesMet($student, $target->courseId));
        $review = $s->registrationBatches()->review(new RegistrationBatchSelection($target->id, $student));
        self::assertSame('missing', $review['students'][0]['prerequisites']['status']);
    }
}
