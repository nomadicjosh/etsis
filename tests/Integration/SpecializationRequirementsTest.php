<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/ReusableGroupFixture.php';
require_once dirname(__DIR__) . '/Support/ProgramPublicationFixture.php';
require_once dirname(__DIR__) . '/Support/StudentRequirementsFixture.php';

use Domain\Catalog\Service\ProgramSpecializationService;
use Domain\DegreeAudit\ValueObject\AuditRequirementsV3;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository;
use Infrastructure\Persistence\Repository\SqlRequirementsRepository;
use PHPUnit\Framework\TestCase;

final class SpecializationRequirementsTest extends TestCase
{
    private function choice(ReusableGroupFixture $r): array
    {
        $s = $r->sis;
        return new ProgramSpecializationService(
            new SqlProgramSpecializationRepository($s->sql),
            new SqlCatalogRevisionRepository($s->sql),
            $s->access, $s->transactions, $s->audit,
        )->save($s->program, null, null, 'DATA', 'Fictional data specialization', 'Original description.', true);
    }
    private function program(ReusableGroupFixture $r, ProgramPublicationFixture $p): array
    {
        $review = $p->service->review($r->sis->program, $r->year, 'Fictional offered specialization approval.');
        return $p->service->publish($r->sis->program, $review['review_token']);
    }
    private function curriculumFixture(bool $publishProgram = true): array
    {
        $r = new ReusableGroupFixture();
        $p = new ProgramPublicationFixture($r->sis);
        $choice = $this->choice($r);
        $publication = $publishProgram ? $this->program($r, $p) : null;
        $scope = $r->degrees->open($r->sis->program, $r->year, new EntityId($choice['id']));
        $d = $r->definition;
        $d['name'] = 'Data specialization requirements';
        $d['min_credits_milli'] = 12000;
        $d['min_resident_milli'] = 0;
        $scope = $r->degrees->save(new EntityId($scope['id']), 0, new AuditRequirementsV3($d));
        return [$r, $p, $choice, $scope, $publication];
    }
    private function publish(ReusableGroupFixture $r, array $scope): array
    {
        $id = new EntityId($scope['id']);
        $review = $r->degrees->review($id, 'Fictional specialization requirements approval.');
        return $r->degrees->publish($id, $review['review_token']);
    }

    public function testOneBuilderKeepsDegreeAndSpecializationScopesDistinctAndReusesTheSameGroups(): void
    {
        [$r, , $choice, $scope] = $this->curriculumFixture();
        $repo = new SqlRequirementsRepository($r->sis->sql);
        self::assertSame($r->degree->value, $repo->find($r->sis->program, $r->year)['id']);
        self::assertSame($scope['id'], $repo->find($r->sis->program, $r->year, new EntityId($choice['id']))['id']);
        self::assertSame($scope['id'], $r->degrees->open($r->sis->program, $r->year, new EntityId($choice['id']))['id']);
        self::assertSame([$r->degree->value], array_column($r->degrees->page($r->sis->program)['items'], 'id'));
        self::assertSame([$scope['id']], array_column($r->degrees->page($r->sis->program, specialization: new EntityId($choice['id']))['items'], 'id'));
        self::assertSame($r->group->value, $scope['definition']['groups'][0]['group_id']);
        self::assertSame($r->groupVersion->value, $scope['definition']['groups'][0]['version_id']);
        self::assertCount(1, $r->sis->sql->rows('sis_course_groups'));
        self::assertNull($r->degrees->view($r->degree)['specialization_id']);
    }

    public function testPublicationNeedsAnOfferedProgramRevisionAndRetainsItsOriginalSpecializationLabels(): void
    {
        [$r, $p, $choice, $scope] = $this->curriculumFixture(false);
        $id = new EntityId($scope['id']);
        try {
            $r->degrees->review($id, 'Fictional missing program approval.');
            self::fail('A specialization without an offered program publication was published.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('offered choice', $e->getMessage());
        }
        $program = $this->program($r, $p);
        $review = $r->degrees->review($id, 'Fictional retained approval.');
        self::assertSame($choice['id'], $review['specialization']['id']);
        $r->sis->sql->update('sis_program_specializations', ['title' => 'Later editable name', 'active' => 0], ['id' => $choice['id']]);
        $receipt = $r->degrees->publish($id, $review['review_token']);
        $version = new EntityId($receipt['operation_id']);
        $this->program($r, $p);
        foreach ([$r->degrees->version($id, $version), new \Domain\DegreeAudit\Service\PublishedRequirementsReader($r->history)->version($id, $version)] as $retained) {
            self::assertSame($choice['id'], $retained['specialization_id']);
            self::assertSame('Fictional data specialization', $retained['specialization']['title']);
            self::assertSame($program['operation_id'], $retained['program_version_id']);
            self::assertSame($r->groupVersion->value, $retained['definition']['groups'][0]['version_id']);
        }
        self::assertSame($receipt, $r->degrees->publish($id, $review['review_token']));
    }

    public function testChangedProgramOrDraftAndExpiredReviewsCannotPublishAndRetriesKeepTheirOriginalResult(): void
    {
        [$r, $p, , $scope] = $this->curriculumFixture();
        $id = new EntityId($scope['id']);
        $review = $r->degrees->review($id, 'Fictional pending review.');
        $this->program($r, $p);
        try {
            $r->degrees->publish($id, $review['review_token']);
            self::fail('A changed program revision accepted a stale specialization review.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        $review = $r->degrees->review($id, 'Fictional accepted review.');
        $first = $r->degrees->publish($id, $review['review_token']);
        $draft = $scope['definition'];
        $draft['min_credits_milli'] = 15000;
        $r->degrees->save($id, 1, new AuditRequirementsV3($draft));
        $pending = $r->degrees->review($id, 'Fictional expiry check.');
        $r->sis->clock->time = $r->sis->clock->time->modify('+20 minutes');
        $before = $r->sis->sql->rows('sis_history_events');
        try {
            $r->degrees->publish($id, $pending['review_token']);
            self::fail('An expired specialization review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $r->sis->sql->rows('sis_history_events'));
        }
        $later = $this->publish($r, $scope);
        self::assertSame($first, $r->degrees->publish($id, $review['review_token']));
        self::assertSame($later['operation_id'], $r->degrees->view($id)['published_version_id']);
    }

    public function testCatalogAuthorityAndProgramOwnershipAreRequiredAndStorageIdentityCollisionsAreRejected(): void
    {
        [$r, , $choice, $scope] = $this->curriculumFixture();
        $r->sis->access->staff = false;
        $r->sis->access->grants = ['sis:student:read'];
        try {
            $r->degrees->view(new EntityId($scope['id']));
            self::fail('Student reads exposed specialization authoring.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $r->sis->access->staff = true;
        try {
            $r->degrees->open(EntityId::generate(), $r->year, new EntityId($choice['id']));
            self::fail('A foreign program accepted a specialization.');
        } catch (RecordNotFoundException) {
            self::assertTrue(true);
        }
        $repo = new SqlRequirementsRepository($r->sis->sql);
        $r->sis->sql->update('sis_degree_audit_templates', ['id' => $scope['id']], ['id' => $r->degree->value]);
        $this->expectException(CorruptHistoryException::class);
        $repo->get(new EntityId($scope['id']));
    }

    public function testClonePreservesSpecializationScopeAndNeverOverwritesDegreeOrExistingDrafts(): void
    {
        [$r, , $choice, $scope] = $this->curriculumFixture();
        $version = new EntityId($this->publish($r, $scope)['operation_id']);
        $calendar = new AcademicCalendarFixture($r->sis);
        foreach ($r->sis->sql->rows('sis_term_types') as $type) {
            $r->sis->sql->update('sis_term_types', ['active' => 0], ['id' => $type['id']]);
        }
        $calendar->type('CLONE');
        $year = new EntityId($calendar->year(2030)['id']);
        $copy = $r->degrees->cloneTo(new EntityId($scope['id']), $version, $r->sis->program, $year, new EntityId($choice['id']));
        self::assertSame($choice['id'], $copy['specialization_id']);
        self::assertSame(
            \Infrastructure\EventSourcing\ProtectedEventCodec::canonical($scope['definition']),
            \Infrastructure\EventSourcing\ProtectedEventCodec::canonical($copy['definition']),
        );
        self::assertNull($copy['published_version_id']);
        self::assertNull(new SqlRequirementsRepository($r->sis->sql)->find($r->sis->program, $year));
        try {
            $r->degrees->cloneTo(new EntityId($scope['id']), $version, $r->sis->program, $year);
            self::fail('Specialization cloning created a degree baseline.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(ConcurrentModificationException::class);
        $r->degrees->cloneTo(new EntityId($scope['id']), $version, $r->sis->program, $year, new EntityId($choice['id']));
    }

    public function testSpecializationPublicationCannotReplaceTheStudentsDegreeRequirements(): void
    {
        $student = new StudentRequirementsFixture();
        $r = $student->f;
        $choice = $this->choice($r);
        $this->program($r, new ProgramPublicationFixture($r->sis));
        $scope = $r->degrees->open($r->sis->program, $r->year, new EntityId($choice['id']));
        $r->degrees->save(new EntityId($scope['id']), 0, new AuditRequirementsV3($r->definition));
        $version = new EntityId($this->publish($r, $scope)['operation_id']);
        $before = $r->sis->sql->rows('sis_student_requirements');
        try {
            $student->service->review($student->student, $student->assignment, new EntityId($scope['id']), $version,
                new \Domain\DegreeAudit\ValueObject\PursuitStart('2026-01-31'), 'Fictional wrong baseline.');
            self::fail('Specialization requirements replaced a degree baseline.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('cannot replace', $e->getMessage());
            self::assertSame($before, $r->sis->sql->rows('sis_student_requirements'));
        }
    }

    public function testAnAuditFailureRollsBackTheSpecializationEventProjectionAndReceipt(): void
    {
        [$r, , , $scope] = $this->curriculumFixture();
        $id = new EntityId($scope['id']);
        $review = $r->degrees->review($id, 'Fictional rollback review.');
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException('Fictional audit failure.');
            }
            public function forSubject(EntityId $subject): array { return []; }
        };
        $tables = ['sis_specialization_audit_templates', 'sis_history_events', 'sis_history_streams', 'sis_history_operations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $r->sis->sql->rows($table);
        }
        try {
            $r->degreeService($audit)->publish($id, $review['review_token']);
            self::fail('Specialization publication committed without its required audit.');
        } catch (RuntimeException) {
            foreach ($before as $table => $rows) {
                self::assertSame($rows, $r->sis->sql->rows($table), $table);
            }
        }
    }

    public function testReplayedRequirementsCannotChangeTheirSpecializationScope(): void
    {
        [$r, , , $scope] = $this->curriculumFixture();
        $this->publish($r, $scope);
        $stream = new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\DegreeAudit\ValueObject\RequirementsPublicationId($scope['id']), 'degree_audit_requirements',
        );
        $event = $r->history->page($stream)->events[0]->event;
        $root = \Domain\DegreeAudit\RequirementsPublication::root($stream->aggregateId);
        $root->acceptHistorical($event);
        $payload = $event->payload();
        $payload['previous_version_id'] = $payload['version_id'];
        $payload['version_id'] = EntityId::generate()->value;
        $foreign = \Domain\DegreeAudit\Event\RequirementsWerePublished::occur($stream->aggregateId, $payload, [
            '__aggregate_type' => 'degree_audit_requirements', 'schema_version' => 4,
            'curriculum_version_id' => $event->metaParam('curriculum_version_id'),
            'specialization_id' => EntityId::generate()->value,
        ])->withPlayhead(2);
        $this->expectException(CorruptHistoryException::class);
        $root->acceptHistorical($foreign);
    }
}
