<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/ProgramPublicationFixture.php';

use Domain\Catalog\Service\ProgramSpecializationService;
use Domain\Catalog\Service\SpecializationPolicyService;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Catalog\ValueObject\ProgramSpecializationSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlCatalogRevisionRepository;
use Infrastructure\Persistence\Repository\SqlProgramDefinitionRepository;
use Infrastructure\Persistence\Repository\SqlProgramSpecializationRepository;
use Infrastructure\Persistence\Repository\SqlSpecializationPolicyRepository;
use PHPUnit\Framework\TestCase;

final class SpecializationPolicyTest extends TestCase
{
    private function policies(
        ProgramPublicationFixture $p,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): SpecializationPolicyService {
        $f = $p->sis;
        return new SpecializationPolicyService(
            new SqlSpecializationPolicyRepository($f->sql),
            new SqlProgramSpecializationRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
        );
    }
    private function choices(ProgramPublicationFixture $p): ProgramSpecializationService
    {
        $f = $p->sis;
        return new ProgramSpecializationService(
            new SqlProgramSpecializationRepository($f->sql),
            new SqlCatalogRevisionRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
        );
    }
    private function offer(ProgramPublicationFixture $p, string $code = 'DATA'): array
    {
        return $this->choices($p)->save(
            $p->sis->program,
            null,
            null,
            $code,
            'Fictional ' . $code,
            'Retained curriculum description.',
            true,
        );
    }
    private function review(ProgramPublicationFixture $p): array
    {
        $year =
            $p->sis->sql->rows('sis_academic_years', ['start_year' => 2028], 1)[0] ??
            $p->calendar->year(2028);
        return $p->service->review(
            $p->sis->program,
            new EntityId($year['id']),
            'Fictional specialization approval.',
        );
    }
    public function testInstitutionBoundaryAndProgramRestrictionsAreIndependentAndNeedSeparateAuthority(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $s = $this->policies($p);
        $f->access->staff = false;
        $f->access->grants = ['sis:catalog:manage'];
        $settings = $s->settings($f->program);
        self::assertSame(['minimum' => 0, 'maximum' => 1], $settings['effective']);
        self::assertFalse($settings['can_manage_institution']);
        try {
            $s->saveInstitution(0, 3, 1);
            self::fail('Catalog authority changed an institutional boundary.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        try {
            $s->saveProgram($f->program, 0, 0, 2);
            self::fail('A program exceeded the institutional ceiling.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $f->access->grants[] = 'sis:catalog:policy';
        $s->saveInstitution(0, 3, 1);
        self::assertSame(1, $s->settings($f->program)['effective']['maximum']);
        $s->saveProgram($f->program, 0, 1, 2);
        self::assertSame(['minimum' => 1, 'maximum' => 2], $s->settings($f->program)['effective']);
        self::assertSame(1, $s->settings($f->program)['settings']['revision']);
        $s->saveInstitution(1, 1, 1);
        self::assertNull($s->settings($f->program)['effective']);
        self::assertNotEmpty($s->settings($f->program)['errors']);
        $this->expectException(ValidationException::class);
        $this->review($p);
    }
    public function testPublicationRetainsDefinitionsPolicyAndOriginalReceiptsAfterCurrentChoicesChange(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $s = $this->policies($p);
        $choice = $this->offer($p);
        $this->offer($p, 'ART');
        $s->saveInstitution(0, 3, 1);
        $s->saveProgram($f->program, 0, 1, 2);
        $review = $this->review($p);
        $receipt = $p->service->publish($f->program, $review['review_token']);
        $before = $f->sql->rows('sis_history_events');
        $retained = new SqlProgramDefinitionRepository($f->sql)->retained(
            new EntityId($receipt['operation_id']),
        );
        self::assertSame(['maximum' => 2, 'minimum' => 1], $retained['specializations']['effective']);
        self::assertCount(2, $retained['specializations']['offered']);
        $this->choices($p)->save(
            $f->program,
            new EntityId($choice['id']),
            1,
            'DATA',
            'Changed current name',
            null,
            false,
        );
        $s->saveInstitution(1, 1, 1); // The old program draft is now incompatible; accepted retry must still work.
        self::assertSame($receipt, $p->service->publish($f->program, $review['review_token']));
        self::assertSame(
            $retained,
            new SqlProgramDefinitionRepository($f->sql)->retained(new EntityId($receipt['operation_id'])),
        );
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $s->saveProgram($f->program, 1, 0, null);
        $next = $this->review($p);
        $p->service->publish($f->program, $next['review_token']);
        $history = $p->service->versions($f->program)['items'];
        self::assertCount(2, $history);
        self::assertSame(2, $history[0]['specializations']['effective']['maximum']);
        self::assertSame(1, $history[1]['specializations']['effective']['maximum']);
        self::assertCount(1, $history[1]['specializations']['offered']);
        self::assertSame(
            'Fictional DATA',
            array_column($history[0]['specializations']['offered'], 'title', 'code')['DATA'],
        );
    }
    public function testReviewIsInvalidatedByPolicyOrChoiceEditsEvenWhenTheValuesReturn(): void
    {
        foreach (['institution', 'program', 'choice'] as $kind) {
            $p = new ProgramPublicationFixture();
            $f = $p->sis;
            $s = $this->policies($p);
            $choice = $this->offer($p);
            $review = $this->review($p);
            if ($kind === 'institution') {
                $s->saveInstitution(0, 2, 1);
                $s->saveInstitution(1, 1, 1);
            } elseif ($kind === 'program') {
                $s->saveProgram($f->program, 0, 0, 1);
                $s->saveProgram($f->program, 1, 0, null);
            } else {
                $this->choices($p)->save(
                    $f->program,
                    new EntityId($choice['id']),
                    1,
                    'DATA',
                    'Changed',
                    null,
                    true,
                );
                $this->choices($p)->save(
                    $f->program,
                    new EntityId($choice['id']),
                    2,
                    'DATA',
                    $choice['title'],
                    $choice['description'],
                    true,
                );
            }
            $before = $f->sql->rows('sis_history_events');
            try {
                $p->service->publish($f->program, $review['review_token']);
                self::fail('An outdated program review was accepted.');
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $f->sql->rows('sis_history_events'));
                self::assertSame([], $f->sql->rows('sis_program_specialization_contexts'));
            }
        }
    }
    public function testRequiredSelectionsNeedOfferedChoicesAndZeroCanDisableAProgram(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $s = $this->policies($p);
        $s->saveProgram($f->program, 0, 1, 1);
        try {
            $this->review($p);
            self::fail('A required unavailable specialization was published.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        $s->saveProgram($f->program, 1, 0, 0);
        $review = $this->review($p);
        self::assertSame(0, $review['specializations']['effective']['maximum']);
        self::assertSame([], $review['specializations']['offered']);
        $p->service->publish($f->program, $review['review_token']);
        self::assertSame([], $f->sql->rows('sis_student_requirements'));
    }
    public function testOversizedChoicesReturnAnActionableReviewErrorWithoutPublishing(): void
    {
        $p = new ProgramPublicationFixture();
        foreach (range(1, 5) as $number) {
            $this->choices($p)->save(
                $p->sis->program,
                null,
                null,
                'CHOICE' . $number,
                'Fictional choice ' . $number,
                str_repeat('x', 10000),
                true,
            );
        }
        $events = $p->sis->sql->rows('sis_history_events');
        try {
            $this->review($p);
            self::fail('An oversized specialization publication was reviewed.');
        } catch (ValidationException $error) {
            self::assertSame('specializations', $error->errors[0]['field']);
            self::assertStringContainsString('Shorten', $error->errors[0]['message']);
            self::assertSame($events, $p->sis->sql->rows('sis_history_events'));
            self::assertSame([], $p->sis->sql->rows('sis_program_specialization_contexts'));
        }
    }
    public function testStaleAndFailedPolicySavesLeaveTheirPriorSettingsAndAuditIntact(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $s = $this->policies($p);
        $s->saveInstitution(0, 3, 1);
        try {
            $s->saveInstitution(0, 2, 1);
            self::fail('A stale institutional edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame(3, $s->settings($f->program)['institution']['maximum']);
        }
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new RuntimeException('Fictional audit failure.');
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $before = $s->settings($f->program);
        try {
            $this->policies($p, $audit)->saveProgram($f->program, 0, 1, 2);
            self::fail('A failed audit committed program settings.');
        } catch (RuntimeException) {
            self::assertSame($before, $s->settings($f->program));
        }
    }
    public function testRetainedContextCorruptionIsRejectedAndExactReconstructionChangesNoEvents(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $this->offer($p);
        $review = $this->review($p);
        $receipt = $p->service->publish($f->program, $review['review_token']);
        $id = new EntityId($receipt['operation_id']);
        $rows = $f->sql->rows('sis_history_events');
        $repository = new SqlProgramDefinitionRepository($f->sql);
        $original = $repository->retained($id);
        $f->sql->update(
            'sis_program_specialization_contexts',
            ['stream_version' => 99],
            ['id' => $id->value],
        );
        try {
            $repository->retained($id);
            self::fail('A wrong context position was trusted.');
        } catch (CorruptHistoryException) {
            self::assertTrue(true);
        }
        $f->sql->execute('DELETE FROM sis_program_specialization_contexts WHERE id = ?', [$id->value]);
        $repository->retain(
            $f->program,
            $id,
            $review['academic_year_code'],
            $receipt['last_version'],
            ProgramDefinition::fromArray($review['definition']),
            new ProgramSpecializationSnapshot($review['specializations']),
        );
        self::assertSame($original, $repository->retained($id));
        self::assertSame($rows, $f->sql->rows('sis_history_events'));
    }
    public function testComponentSharingIsRetainedByPublicationAndChangesInvalidatePendingReviews(): void
    {
        $p = new ProgramPublicationFixture();
        $f = $p->sis;
        $service = $this->policies($p);
        self::assertSame('one_component', $service->settings($f->program)['settings']['component_sharing']);
        $this->offer($p);
        $f->access->staff = false;
        $f->access->grants = ['sis:catalog:manage'];
        $service->saveProgram($f->program, 0, 0, null, 'multiple_components');
        $review = $this->review($p);
        self::assertSame('multiple_components', $review['specializations']['component_sharing']);
        $receipt = $p->service->publish($f->program, $review['review_token']);
        $before = $f->sql->rows('sis_history_events');
        $service->saveProgram($f->program, 1, 0, null, 'one_component');
        $retained = new SqlProgramDefinitionRepository($f->sql)->retained(new EntityId($receipt['operation_id']));
        self::assertSame('multiple_components', $retained['specializations']['component_sharing']);
        self::assertSame($receipt, $p->service->publish($f->program, $review['review_token']));
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $pending = $this->review($p);
        $service->saveProgram($f->program, 2, 0, null, 'multiple_components');
        $service->saveProgram($f->program, 3, 0, null, 'one_component');
        try {
            $p->service->publish($f->program, $pending['review_token']);
            self::fail('A changed-and-restored sharing policy accepted a stale review.');
        } catch (ConcurrentModificationException) { self::assertSame($before, $f->sql->rows('sis_history_events')); }
        $this->expectException(ValidationException::class);
        $service->saveProgram($f->program, 4, 0, null, 'infer_from_academic_level');
    }
    public function testEarlierSpecializationSnapshotsRemainWithoutAnInferredSharingPolicy(): void
    {
        $p = new ProgramPublicationFixture();
        $snapshot = $this->policies($p)->snapshot($p->sis->program)->data;
        self::assertSame('one_component', $snapshot['component_sharing']);
        unset($snapshot['component_sharing']);
        $earlier = new ProgramSpecializationSnapshot($snapshot)->data;
        self::assertArrayNotHasKey('component_sharing', $earlier);
        self::assertSame($snapshot, $earlier);
        $this->expectException(\InvalidArgumentException::class);
        new ProgramSpecializationSnapshot($earlier + ['component_sharing' => null]);
    }

}
