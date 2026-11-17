<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdmissionCaseworkFixture;

final class AdmissionUpdatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
        require_once dirname(__DIR__) . '/Support/AdmissionCaseworkFixture.php';
    }
    private function values(
        string $step = 'Arrange delivery of your transcript.',
        string $note = 'Private staff note.'
    ): array {
        return ['office' => 'Admissions', 'next_step' => $step, 'private_notes' => $note, 'items' => [
            ['key' => 'internal', 'label' => 'Internal staff task', 'instructions' => 'Private task instructions.',
                'needed' => false, 'visible' => false, 'status' => 'pending', 'due_on' => ''],
        ]];
    }
    public function testPublicChangesCoalesceWhilePrivateEditsAndRetriesStaySilent(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, ['private_notes' => 'Only a private note.'], 0, 'me');
        self::assertSame([], $s->sql->rows('sis_admission_updates'));
        $public = $this->values();
        $f->service->save($f->application, $public, 1, 'keep');
        $rows = $s->sql->rows('sis_admission_updates');
        $f->service->save($f->application, $public, 1, 'keep');
        self::assertSame($rows, $s->sql->rows('sis_admission_updates'));
        $public['private_notes'] = 'Another private staff note.';
        $public['items'][0]['status'] = 'complete';
        $f->service->save($f->application, $public, 2, 'keep');
        self::assertSame($rows, $s->sql->rows('sis_admission_updates'));
        $public['next_step'] = 'Your revised delivery instructions are ready.';
        $f->service->save($f->application, $public, 3, 'keep');
        $page = $f->updates->page();
        self::assertCount(1, $page['items']);
        self::assertSame(2, $page['items'][0]['revision']);
        self::assertSame(1, $page['unread_count']);
        $stored = json_encode($s->sql->rows('sis_admission_updates'), JSON_THROW_ON_ERROR);
        foreach (['transcript', 'Private', 'instructions', 'Internal'] as $private) {
            self::assertStringNotContainsString($private, $stored);
        }
        self::assertSame([], $s->sql->rows('sis_history_events'));
    }
    public function testReadAcknowledgementsCannotHideLaterUpdatesOrUndoLaterReadSelections(): void
    {
        $f = new AdmissionCaseworkFixture();
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        self::assertFalse($f->updates->read($notice, 1)['unread']);
        self::assertSame(0, $f->updates->page()['unread_count']);
        $f->service->save($f->application, $this->values('Updated next step.'), 1, 'keep');
        self::assertTrue($f->updates->read($notice, 1)['unread']);
        self::assertSame(1, $f->updates->page()['unread_count']);
        self::assertFalse($f->updates->read($notice, 2)['unread']);
        $audit = $f->campus->sis->sql->rows('sis_audit');
        self::assertFalse($f->updates->read($notice, 1)['unread']);
        self::assertSame($audit, $f->campus->sis->sql->rows('sis_audit'));
        $this->expectException(ConcurrentModificationException::class);
        $f->updates->read($notice, 3);
    }
    public function testCurrentPersonOwnershipRemainsMandatoryEvenForStaffAndAfterOwnershipChanges(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $oldOwner = $s->access->actor;
        $person = $s->admissions->application($f->application)->personId;
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        $newOwner = $s->user();
        $s->sql->update('sis_persons', ['owner_user_id' => $newOwner->value], ['id' => $person->value]);
        try {
            $f->updates->read($notice, 1);
            self::fail('A former owner used staff authority to acknowledge an admission update.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->actor = $newOwner;
        self::assertSame([], $f->updates->page()['items']);
        $f->service->save($f->application, $this->values('New owner next step.'), 1, 'keep');
        self::assertCount(1, $f->updates->page()['items']);
        $s->access->actor = $oldOwner;
        $this->expectException(AccessDeniedException::class);
        $f->updates->read($notice, 1);
    }
    public function testNotificationAndCaseworkRollBackTogetherIfTheirAuditFails(): void
    {
        $audit = $this->createMock(\Domain\Audit\Repository\AuditTrail::class);
        $audit->expects(self::once())->method('record')->willThrowException(
            new \RuntimeException('Fictional audit failure.')
        );
        $f = new AdmissionCaseworkFixture($audit);
        try {
            $f->service->save($f->application, $this->values(), 0, 'keep');
            self::fail('Unaudited casework or its notice was committed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional audit failure.', $error->getMessage());
        }
        self::assertSame([], $f->campus->sis->sql->rows('sis_admission_cases'));
        self::assertSame([], $f->campus->sis->sql->rows('sis_admission_updates'));
    }
    public function testMetadataPagesUseTimestampAndIdCursorsWithoutDuplicatesAtEqualTimestamps(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $person = $s->admissions->application($f->application)->personId;
        for ($index = 0; $index < 24; $index++) {
            $app = $s->legacyApplication($person, $s->program, $s->term);
            $f->service->save($app->id, $this->values(), 0, 'keep');
        }
        $first = $f->updates->page();
        $next = $first['next'];
        $second = $f->updates->page(afterAt: $next['at'], after: new EntityId($next['id']));
        self::assertCount(20, $first['items']);
        self::assertCount(4, $second['items']);
        self::assertSame(24, $first['unread_count']);
        self::assertSame([], array_intersect(
            array_column($first['items'], 'id'),
            array_column($second['items'], 'id')
        ));
        self::assertNull($second['next']);
    }
}
