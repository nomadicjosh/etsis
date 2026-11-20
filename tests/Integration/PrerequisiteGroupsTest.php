<?php

declare(strict_types=1);

namespace Tests\Integration;

use CatalogWorkflowFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Catalog\Service\CoursePrerequisiteService;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Catalog\ValueObject\PrerequisiteGroups;
use Domain\Gradebook\GradeDefinition;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\PrerequisiteDecision;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlCatalogWorkflowRepository;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class PrerequisiteGroupsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CatalogWorkflowFixture.php';
    }

    private function editor(SisFixture $s, ?AuditTrail $audit = null): CoursePrerequisiteService
    {
        return new CoursePrerequisiteService(
            new SqlCatalogRevisionRepository($s->sql),
            new SqlCatalogWorkflowRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit
        );
    }

    private function save(SisFixture $s, EntityId $course, array $groups): array
    {
        $editor = $this->editor($s);
        return $editor->save($course, $groups, $editor->current($course)['revision']);
    }

    private function pass(SisFixture $s, EntityId $student, \Domain\Registration\Section $section): void
    {
        $enrollment = $s->service->register($student, $section->id);
        $grade = new EntityId($s->gradeCatalog()->byCode('P', $student)['id']);
        $review = $s->academicCredits()->review(
            $student,
            $section->id,
            $enrollment->id,
            $grade,
            0,
            reason: 'Fictional passing prerequisite'
        );
        $s->academicCredits()->commit($student, $section->id, $review['review_token']);
    }

    public function testGroupsAreBoundedCanonicalAndDoNotRequireUnchosenAlternatives(): void
    {
        $a = EntityId::generate()->value;
        $b = EntityId::generate()->value;
        $c = EntityId::generate()->value;
        $groups = new PrerequisiteGroups([[$b, $a], [$c]]);
        $ids = [$a, $b, $c];
        sort($ids, SORT_STRING);
        self::assertSame($ids, $groups->courses());
        self::assertSame(0, $groups->missing([$a, $c]));
        self::assertSame(0, $groups->missing([$b, $c]));
        self::assertSame(1, $groups->missing([$a]));
        self::assertSame(1, $groups->missing([$c]));
        self::assertSame(2, $groups->missing([]));
        self::assertSame(0, new PrerequisiteGroups([])->missing([]));
        foreach ([[[]], [[$a, $a]], array_fill(0, 101, [$a]), [['not-an-id']]] as $invalid) {
            try {
                new PrerequisiteGroups($invalid);
                self::fail('Malformed prerequisite groups were accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testIndividualStudentStaffAndBatchReviewsShareGroupedEligibilityAndRetainedEvidence(): void
    {
        $s = new SisFixture();
        $s->legacyGrade(new GradeDefinition(EntityId::generate(), 'P', 0, true, false));
        $student = $s->student();
        $a = $s->section(20, 0, faculty: $s->access->actor);
        $b = $s->section(20, 0);
        $c = $s->section(20, 0, faculty: $s->access->actor);
        $target = $s->section(20, 0);
        $this->pass($s, $student->id, $a);
        $this->pass($s, $student->id, $c);
        $groups = [[$a->courseId->value, $b->courseId->value], [$c->courseId->value]];
        $this->save($s, $target->courseId, $groups);
        $other = $s->student();
        $selection = new RegistrationBatchSelection($target->id, $student->id, $other->id);
        $review = $s->registrationBatches()->review($selection);
        $rows = array_column($review['students'], null, 'id');
        self::assertSame('met', $rows[$student->id->value]['prerequisites']['status']);
        self::assertSame(0, $rows[$student->id->value]['prerequisites']['missing_count']);
        self::assertSame(2, $rows[$other->id->value]['prerequisites']['missing_count']);
        self::assertCount(2, $rows[$student->id->value]['prerequisites']['groups']);
        self::assertCount(3, $review['section']['prerequisite_courses']);
        foreach (['support', 'credit_id', 'academic_version', 'pursuit_version'] as $private) {
            self::assertStringNotContainsString('"' . $private . '"', json_encode($review, JSON_THROW_ON_ERROR));
        }
        $actor = $s->access->actor;
        $s->access->actor = $s->admissions->person($student->personId)->ownerUserId;
        $s->access->staff = false;
        $enrollment = $s->service->register($student->id, $target->id, byStaff: false);
        $s->access->actor = $actor;
        $s->access->staff = true;
        $event = iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->id->value)))[0];
        $evidence = $event->metaParam('prerequisite_evidence');
        self::assertSame(new PrerequisiteGroups($groups)->groups, $evidence['requirement_groups']);
        self::assertCount(2, $evidence['support']);
        self::assertTrue(PrerequisiteDecision::fromRetained($evidence)->isMet());
        $this->save($s, $target->courseId, [[$b->courseId->value]]);
        self::assertSame($enrollment->id->value, $s->service->register($student->id, $target->id)->id->value);
        self::assertSame($evidence, iterator_to_array(\EventStoreFixture::framework($s->connection->queryBuilder())
            ->getAggregateHistoryFor(new EnrollmentHistoryId($enrollment->id->value)))[0]
            ->metaParam('prerequisite_evidence'));
    }

    public function testPublicationAndSectionBindingPreserveChoicesAfterDraftChanges(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $target = $s->section(20, 0);
        $a = $s->section(20, 0);
        $b = $s->section(20, 0);
        $this->save($s, $target->courseId, [[$a->courseId->value, $b->courseId->value]]);
        $review = $f->publications()->review($target->courseId, 'Fictional grouped prerequisites');
        $published = $f->publications()->publish($target->courseId, $review['review_token']);
        $version = new EntityId($published['operation_id']);
        $binding = $f->bindings()->review($target->id, $version, 'Fictional course selection');
        $f->bindings()->commit($target->id, $binding['review_token']);
        $retained = new SqlCourseVersionProjection($s->sql)->get($version);
        self::assertSame($review['definition'], $retained->definition->describe());
        $this->save($s, $target->courseId, []);
        self::assertSame(
            $retained->definition->prerequisiteGroups->groups,
            $s->registration->courseForSection($s->registration->section($target->id))->prerequisiteGroups->groups
        );
        self::assertSame($published, $f->publications()->publish($target->courseId, $review['review_token']));
        self::assertCount(2, $retained->definition->prerequisites);
        // A broken working-draft index cannot replace or invalidate a selected protected publication.
        $s->sql->update('sis_course_prerequisite_groups', ['groups_json' => json_encode(
            [[$a->courseId->value]],
            JSON_THROW_ON_ERROR
        )], ['id' => $target->courseId->value]);
        self::assertSame(
            $retained->definition->prerequisiteGroups->groups,
            $s->registration->courseForSection($s->registration->section($target->id))->prerequisiteGroups->groups
        );
    }

    public function testClonePreservesOnlySelectedAlternativesAndLegacyMutationsKeepGroupsConsistent(): void
    {
        $f = new CatalogWorkflowFixture();
        $s = $f->sis;
        $target = $s->section();
        $a = $s->section();
        $b = $s->section();
        $c = $s->section();
        $this->save($s, $target->courseId, [[$a->courseId->value, $b->courseId->value], [$c->courseId->value]]);
        $review = $f->clones()->review($target->courseId);
        $clone = new Course(EntityId::generate(), 'FICTIONAL.CLONE', 'Fictional clone', 3000, false);
        $f->clones()->commit($target->courseId, $clone, [$a->courseId], $review['revision']);
        $repository = new SqlCatalogWorkflowRepository($s->sql);
        self::assertSame([[$a->courseId->value]], $repository->prerequisiteGroups($clone->id)->groups);
        $s->registration->addPrerequisite($clone->id, $b->courseId);
        self::assertSame(
            PrerequisiteGroups::all([$a->courseId->value, $b->courseId->value])->groups,
            $repository->prerequisiteGroups($clone->id)->groups
        );
        $s->registration->removePrerequisite($clone->id, $a->courseId);
        self::assertSame([[$b->courseId->value]], $repository->prerequisiteGroups($clone->id)->groups);
    }

    public function testEditorRejectsStaleSavesSelfRequirementsAndRollsBackAuditFailure(): void
    {
        $s = new SisFixture();
        $target = $s->section();
        $a = $s->section();
        $b = $s->section();
        $editor = $this->editor($s);
        $old = $editor->current($target->courseId);
        $this->save($s, $target->courseId, [[$a->courseId->value]]);
        try {
            $editor->save($target->courseId, [[$b->courseId->value]], $old['revision']);
            self::fail('A stale editor replaced newer choices.');
        } catch (ConcurrentModificationException) {
            self::assertSame([[$a->courseId->value]], $editor->current($target->courseId)['groups']);
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(
            static fn() => throw new \RuntimeException('Fictional audit failure')
        );
        try {
            $current = $editor->current($target->courseId);
            $this->editor($s, $audit)->save($target->courseId, [[$b->courseId->value]], $current['revision']);
            self::fail('A draft survived a failed required audit.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional audit failure', $error->getMessage());
            self::assertSame($current, $editor->current($target->courseId));
        }
        $this->expectException(\Domain\Shared\Exception\ValidationException::class);
        $this->save($s, $target->courseId, [[$target->courseId->value]]);
    }

    public function testOldDefinitionsAndEvidenceKeepTheirExactAllRequiredMeaning(): void
    {
        $id = EntityId::generate()->value;
        $old = new CourseDefinition('OLD', 'Fictional earlier definition', 3000, [$id])->describe();
        self::assertArrayNotHasKey('prerequisite_groups', $old);
        self::assertSame($old, CourseDefinition::fromArray($old)->describe());
        $s = new SisFixture();
        $assignment = $s->workPursuits()->activePursuit($s->student()->id);
        $oldEvidence = ['assignment_id' => $assignment->value, 'required_course_ids' => [], 'support' => []];
        self::assertSame($oldEvidence, PrerequisiteDecision::fromRetained($oldEvidence)->retained());
        $this->expectException(CorruptHistoryException::class);
        PrerequisiteDecision::fromRetained($oldEvidence + ['requirement_groups' => [[$id]]]);
    }
    public function testMigrationUsesPortableDraftAndRetainedDefinitionsWithoutChangingRecordedFacts(): void
    {
        $s = new SisFixture();
        $definitions = [];
        $schema = $this->createStub(\Qubus\Expressive\Schema::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->method('getColumns')->willReturn(['id']);
        $schema->method('create')->willReturnCallback(static function (
            string $name,
            callable $callback,
        ) use (&$definitions): void {
            $table = new \Qubus\Expressive\Schema\CreateTable($name);
            $callback($table);
            $definitions['create'] = $table;
        });
        $schema->method('alter')->willReturnCallback(static function (
            string $name,
            callable $callback,
        ) use (&$definitions): void {
            $table = new \Qubus\Expressive\Schema\AlterTable($name);
            $callback($table);
            $definitions['alter'] = $table;
        });
        $connection = $this->createStub(\Qubus\Expressive\Connection::class);
        $connection->method('getSchema')->willReturn($schema);
        $migration = new \CreateSisPrerequisiteGroups('prerequisite-compiler');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'fixture')]));
        $before = $s->sql->rows('sis_history_events');
        $migration->up();
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $driver) {
            $dialect = $this->createStub(\Qubus\Expressive\Connection::class);
            $dialect->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $class = 'Qubus\\Expressive\\Schema\\Compiler\\' . $driver;
            $compiler = new $class($dialect);
            $sql = json_encode(
                [...$compiler->create($definitions['create']), ...$compiler->alter($definitions['alter'])],
                JSON_THROW_ON_ERROR
            );
            self::assertStringContainsString('scpg_course_fk', $sql);
            self::assertStringContainsString('prerequisite_groups', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |UPDATE |INSERT |DELETE /i', $sql);
        }
        self::assertSame($before, $s->sql->rows('sis_history_events'));
    }
}
