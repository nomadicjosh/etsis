<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\Support\AdmissionCaseworkFixture;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;

final class AdmissionCaseworkTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
        require_once dirname(__DIR__) . '/Support/AdmissionCaseworkFixture.php';
    }
    private function item(string $key = 'transcript', bool $visible = true): array
    {
        return ['key' => $key, 'label' => $visible ? 'Previous transcript' : 'Private staff task',
            'instructions' => $visible ? 'Arrange delivery through admissions.' : 'Fictional private casework.',
            'needed' => true, 'visible' => $visible, 'status' => 'pending', 'due_on' => '2026-01-01'];
    }
    public function testWithdrawalPausesFollowUpsWithoutResettingTheWorkingCase(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, ['items' => [$this->item()]], 0, 'me');
        $cases = $s->sql->rows('sis_admission_cases');
        self::assertCount(1, $f->service->queue()['items']);
        $state = $s->reviewStateService();
        $state->commit($f->application, $state->review($f->application, true, 'Fictional withdrawal.')['review_token']);
        self::assertSame([], $f->service->queue()['items']);
        self::assertSame($cases, $s->sql->rows('sis_admission_cases'));
        $state->commit(
            $f->application,
            $state->review($f->application, false, 'Fictional reconsideration.')['review_token']
        );
        self::assertCount(1, $f->service->queue()['items']);
        self::assertSame($cases, $s->sql->rows('sis_admission_cases'));
    }
    public function testOwnerGuidanceIsCuratedAndCaseworkDoesNotChangeFormalAdmissionsOrCreateEvents(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $before = $s->sql->one('sis_applications', ['id' => $f->application->value]);
        $values = ['office' => 'Admissions', 'next_step' => 'Arrange your previous transcript.',
            'private_notes' => 'Fictional private staff note.',
            'items' => [$this->item(), $this->item('private_task', false)]];
        $f->service->save($f->application, $values, 0, 'me');
        $staff = $f->service->view($f->application);
        self::assertSame($s->access->actor->value, $staff['case']['assigned_to']);
        self::assertSame('Fictional private staff note.', $staff['case']['private_notes']);
        $owner = $f->service->applicant($f->application);
        self::assertCount(1, $owner['items']);
        self::assertSame('Admissions', $owner['office']);
        foreach (['assigned_to', 'private_notes', 'version', 'work_status'] as $key) {
            self::assertArrayNotHasKey($key, $owner);
        }
        self::assertStringNotContainsString('Private staff task', json_encode($owner, JSON_THROW_ON_ERROR));
        self::assertSame($before, $s->sql->one('sis_applications', ['id' => $f->application->value]));
        self::assertSame([], $s->sql->rows('sis_history_events'));
        self::assertStringNotContainsString(
            'Fictional private staff note',
            json_encode($s->sql->rows('sis_admission_cases'), JSON_THROW_ON_ERROR)
        );
    }
    public function testCurrentRetriesAreNoOpsAndOldRetriesCannotOverwriteLaterCasework(): void
    {
        $f = new AdmissionCaseworkFixture();
        $values = ['items' => [$this->item()]];
        $f->service->save($f->application, $values, 0, 'me');
        $rows = $f->campus->sis->sql->rows('sis_audit');
        $f->service->save($f->application, $values, 0, 'me');
        self::assertSame($rows, $f->campus->sis->sql->rows('sis_audit'));
        $f->service->save($f->application, $values + ['next_step' => 'Updated next step.'], 1, 'keep');
        $this->expectException(ConcurrentModificationException::class);
        $f->service->save($f->application, $values, 0, 'me');
    }
    public function testStaffPermissionNeverOverridesOwnerOnlyReadAndOwnersCannotWriteStaffCasework(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $s->access->actor = $s->user();
        try {
            $f->service->applicant($f->application);
            self::fail('Staff authority exposed another applicant\'s next steps.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->staff = false;
        $this->expectException(AccessDeniedException::class);
        $f->service->save($f->application, ['items' => []], 0, 'me');
    }
    public function testPresetsAreProgramScopedVersionedAndDoNotChangeExistingWorkingCopies(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $id = EntityId::generate();
        $fields = ['key', 'label', 'instructions', 'needed', 'visible'];
        $definition = array_intersect_key($this->item(), array_flip($fields));
        $values = ['name' => 'Fictional admissions checklist', 'program_id' => $s->program->value,
            'enabled' => true, 'items' => [$definition]];
        $f->service->savePreset($id, $values, 0);
        self::assertCount(1, $f->service->presets($f->application)['items']);
        $f->service->save($f->application, ['items' => [$this->item()]], 0, 'keep');
        $values['items'][0]['label'] = 'Renamed future checklist item';
        $f->service->savePreset($id, $values, 1);
        self::assertSame('Previous transcript', $f->service->view($f->application)['case']['items'][0]['label']);
        $values['enabled'] = false;
        $f->service->savePreset($id, $values, 2);
        $s->access->staff = false;
        $s->access->grants = ['sis:admissions:manage'];
        self::assertSame([], $f->service->presets($f->application)['items']);
        $this->expectException(AccessDeniedException::class);
        $f->service->savePreset($id, $values, 3);
    }
    public function testQueueUsesCurrentAssignmentAndFollowUpDatesWithoutReadingPrivatePayloads(): void
    {
        $f = new AdmissionCaseworkFixture();
        $f->service->save($f->application, ['office' => 'Admissions', 'items' => [$this->item()]], 0, 'me');
        self::assertCount(1, $f->service->queue('mine', true)['items']);
        self::assertSame([], $f->service->queue('unassigned')['items']);
        $item = $this->item();
        $item['status'] = 'complete';
        $f->service->save($f->application, ['items' => [$item], 'work_status' => 'done'], 1, 'keep');
        self::assertSame([], $f->service->queue()['items']);
    }

    public function testAuditFailureRollsBackCaseworkAndItsRetryFingerprint(): void
    {
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method('record')
            ->willThrowException(new \RuntimeException('Fictional audit failure.'));
        $f = new AdmissionCaseworkFixture($audit);
        try {
            $f->service->save($f->application, ['items' => [$this->item()]], 0, 'me');
            self::fail('Unaudited casework was committed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional audit failure.', $error->getMessage());
        }
        self::assertSame([], $f->campus->sis->sql->rows('sis_admission_cases'));
        self::assertSame([], $f->campus->sis->sql->rows('sis_history_events'));
    }

    public function testQueuePagingStaysBoundedAndUsesExclusiveCursors(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $person = $s->admissions->application($f->application)->personId;
        for ($index = 0; $index < 24; $index++) {
            $application = $s->legacyApplication($person, $s->program, $s->term);
            $f->service->save($application->id, ['items' => [$this->item()]], 0, 'keep');
        }
        $first = $f->service->queue();
        $second = $f->service->queue(after: new EntityId($first['next_after']));
        self::assertCount(20, $first['items']);
        self::assertCount(4, $second['items']);
        $overlap = array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id'));
        self::assertSame([], $overlap);
        self::assertNull($second['next_after']);
    }

    public function testMalformedOrAlteredEncryptedCaseworkIsRejected(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, ['items' => [$this->item()]], 0, 'keep');
        $row = $s->sql->rows('sis_admission_cases')[0];
        $s->sql->update(
            'sis_admission_cases',
            ['encrypted_payload' => $f->campus->cipher->seal('{}')],
            ['id' => $row['id']]
        );
        $this->expectException(\UnexpectedValueException::class);
        $f->service->view($f->application);
    }

    public function testAuthorityLostWhileWaitingForTheApplicationLockCannotWriteCasework(): void
    {
        $admissions = $this->createMock(\Domain\Admissions\Repository\AdmissionsRepository::class);
        $f = new AdmissionCaseworkFixture(admissions: $admissions);
        $s = $f->campus->sis;
        $admissions->expects(self::once())->method('application')
            ->willReturn($s->admissions->application($f->application));
        $admissions->expects(self::once())->method('lockPerson');
        $admissions->expects(self::once())->method('lockApplication')
            ->willReturnCallback(static function () use ($s): void {
                $s->access->staff = false;
                $s->access->grants = [];
            });
        try {
            $f->service->save($f->application, ['items' => [$this->item()]], 0, 'me');
            self::fail('Casework used authority that was revoked while waiting.');
        } catch (AccessDeniedException) {
            self::assertSame([], $s->sql->rows('sis_admission_cases'));
        }
    }
}
