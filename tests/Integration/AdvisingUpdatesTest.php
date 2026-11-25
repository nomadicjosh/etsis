<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvisingUpdatesFixture;

final class AdvisingUpdatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/AdvisingUpdatesFixture.php';
    }

    public function testNewResponseAndResolutionUseOneDisposableNoticeWithoutChangingAcademicHistory(): void
    {
        $f = new AdvisingUpdatesFixture();
        $s = $f->sis;
        $events = \EventStoreFixture::historyDigest($s->sql);
        $id = EntityId::generate();
        $f->record($id);
        $f->asOwner();
        $page = $f->updates()->page();
        self::assertCount(1, $page['items']);
        $notice = $page['items'][0];
        self::assertSame('open', $notice['status']);
        self::assertSame(0, $notice['revision']);
        self::assertSame($f->pursuit->value, $notice['pursuit_id']);
        foreach (
            ['protected note', 'student-visible', 'next_step',
            'receipts', 'actor_id', 'owner_user_id'] as $private
        ) {
            self::assertStringNotContainsString($private, json_encode($page, JSON_THROW_ON_ERROR));
        }
        $f->checkins()->act($f->student, $f->pursuit, 'owner', $id, 'respond', 0, 'Fictional response.');
        self::assertSame([], $f->updates()->page()['items']);
        self::assertCount(1, $f->updates()->page(false)['items']);
        $f->asStaff();
        $closed = $f->checkins()->act($f->student, $f->pursuit, 'staff', $id, 'close', 1, 'Fictional closure.');
        $f->asOwner();
        $notice = $f->updates()->page()['items'][0];
        self::assertSame('closed', $notice['status']);
        self::assertSame(2, $notice['revision']);
        self::assertCount(1, $s->sql->rows('sis_advising_updates'));
        $result = $f->checkins()->listing($f->student, $f->pursuit, 'owner', selected: $id);
        self::assertSame([$id->value], array_column($result['items'], 'id'));
        self::assertSame('Fictional closure.', $result['items'][0]['resolution']);
        self::assertArrayNotHasKey('private_note', $result['items'][0]);
        self::assertFalse($result['can_record']);
        $f->updates()->delete(new EntityId($notice['id']), 2);
        self::assertSame([], $s->sql->rows('sis_advising_updates'));
        $f->asStaff();
        self::assertSame($closed, $f->checkins()->act(
            $f->student,
            $f->pursuit,
            'staff',
            $id,
            'close',
            1,
            'Fictional closure.'
        ));
        $f->record($id);
        self::assertSame([], $s->sql->rows('sis_advising_updates'));
        self::assertSame($events, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testDeletedOpenNoticeIsNotRecreatedByRetryOrAnOwnerResponseButNewOutcomeCreatesOne(): void
    {
        $f = new AdvisingUpdatesFixture();
        $id = EntityId::generate();
        $f->record($id);
        $f->asOwner();
        $first = $f->updates()->page()['items'][0];
        $f->updates()->delete(new EntityId($first['id']), 0);
        $f->record($id);
        self::assertSame([], $f->sis->sql->rows('sis_advising_updates'));
        $f->asOwner();
        $f->checkins()->act($f->student, $f->pursuit, 'owner', $id, 'respond', 0, 'Fictional response.');
        self::assertSame([], $f->sis->sql->rows('sis_advising_updates'));
        $f->asStaff();
        $f->checkins()->act($f->student, $f->pursuit, 'staff', $id, 'cancel', 1, 'Fictional cancellation.');
        $f->asOwner();
        $new = $f->updates()->page()['items'][0];
        self::assertNotSame($first['id'], $new['id']);
        self::assertSame('cancelled', $new['status']);
        $at = $f->sis->sql->rows('sis_advising_updates')[0];
        $f->updates()->read(new EntityId($new['id']), 2);
        $read = $f->sis->sql->rows('sis_advising_updates')[0];
        self::assertNotSame($at['read_at'], $read['read_at']);
        $f->updates()->read(new EntityId($new['id']), 2);
        self::assertSame($read, $f->sis->sql->rows('sis_advising_updates')[0]);
    }

    public function testOwnerIsolationAndRelinkingNeitherExposeNorTransferNotices(): void
    {
        $f = new AdvisingUpdatesFixture();
        $id = EntityId::generate();
        $f->record($id);
        $f->asOwner();
        $notice = $f->updates()->page()['items'][0];
        $foreign = $f->sis->user();
        $f->sis->student($foreign);
        $f->sis->access->actor = $foreign;
        $f->sis->access->staff = true;
        self::assertSame([], $f->updates()->page()['items']);
        self::assertSame(['deleted' => true], $f->updates()->delete(new EntityId($notice['id']), 0));
        self::assertCount(1, $f->sis->sql->rows('sis_advising_updates'));
        $newOwner = $f->sis->user();
        $f->sis->sql->update('sis_persons', ['owner_user_id' => $newOwner->value], ['id' => $f->person->value]);
        $f->asOwner();
        try {
            $f->updates()->page();
            self::fail('The earlier canonical account retained advising updates.');
        } catch (AccessDeniedException) {
            self::assertCount(1, $f->sis->sql->rows('sis_advising_updates'));
        }
        $f->sis->access->actor = $newOwner;
        self::assertSame([], $f->updates()->page()['items']);
        try {
            $f->checkins()->listing($f->student, $f->pursuit, 'owner', selected: $id);
            self::fail('Selected follow-up bypassed its original owner pin.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->asStaff();
        $f->checkins()->act($f->student, $f->pursuit, 'staff', $id, 'cancel', 0, 'Earlier account link was replaced.');
        $f->sis->access->actor = $newOwner;
        self::assertSame([], $f->updates()->page()['items']);
    }

    public function testUnreadAndDatedPagingAreBoundedAndDoNotMarkOrBackfillOnRead(): void
    {
        $f = new AdvisingUpdatesFixture();
        for ($i = 0; $i < 23; $i++) {
            $id = EntityId::generate();
            $f->record($id);
            $f->checkins()->act($f->student, $f->pursuit, 'staff', $id, 'close', 0, 'Fictional finished follow-up.');
        }
        $f->asOwner();
        $before = $f->sis->sql->rows('sis_advising_updates');
        $first = $f->updates()->page();
        self::assertCount(20, $first['items']);
        $next = $f->updates()->page(afterAt: $first['next']['at'], after: new EntityId($first['next']['id']));
        self::assertCount(3, $next['items']);
        self::assertNull($next['next']);
        self::assertSame([], array_intersect(array_column($first['items'], 'id'), array_column($next['items'], 'id')));
        self::assertSame($before, $f->sis->sql->rows('sis_advising_updates'));
        $this->expectException(\InvalidArgumentException::class);
        $f->updates()->page(after: EntityId::generate());
    }

    public static function failures(): array
    {
        return ['required audit failure' => ['audit'], 'final actor loss' => ['actor'],
            'notification drift' => ['notification'], 'sealed source drift' => ['source']];
    }

    #[DataProvider('failures')]
    public function testNewCheckInAndNotificationRollBackOnFinalFailure(string $kind): void
    {
        $f = new AdvisingUpdatesFixture();
        $id = EntityId::generate();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f, $kind): void {
            match ($kind) {
                'audit' => throw new \RuntimeException('Fictional required audit failure.'),
                'actor' => $f->sis->access->staff = false,
                'notification' => $f->sis->sql->execute('DELETE FROM sis_advising_updates'),
                'source' => $f->sis->sql->execute(
                    'UPDATE sis_advising_checkins SET sealed_data = ?',
                    ['fictional invalid source']
                ),
            };
        });
        $before = array_map($f->sis->sql->rows(...), [
            'sis_advising_checkins', 'sis_advising_updates', 'sis_history_events',
        ]);
        try {
            $f->checkins($audit)->record(
                $f->student,
                $f->pursuit,
                'staff',
                $id,
                '2026-08-10',
                null,
                'Fictional next step.',
                'Fictional private note.'
            );
            self::fail('Failed final checks committed an advising conversation or notice.');
        } catch (\Throwable $failure) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $failure);
            self::assertSame($before, array_map(
                $f->sis->sql->rows(...),
                ['sis_advising_checkins', 'sis_advising_updates', 'sis_history_events']
            ));
        }
    }

    public function testDamagedSourceAndWrongRevisionCannotBeAcknowledged(): void
    {
        $f = new AdvisingUpdatesFixture();
        $f->record();
        $f->asOwner();
        $notice = $f->updates()->page()['items'][0];
        try {
            $f->updates()->read(new EntityId($notice['id']), 1);
            self::fail('A wrong notice revision was acknowledged.');
        } catch (ConcurrentModificationException) {
            self::assertNull($f->sis->sql->rows('sis_advising_updates')[0]['read_at']);
        }
        $f->sis->sql->update(
            'sis_advising_checkins',
            ['sealed_data' => 'fictional invalid envelope'],
            ['id' => $notice['checkin_id']]
        );
        try {
            $f->updates()->read(new EntityId($notice['id']), 0);
            self::fail('A damaged source was acknowledged.');
        } catch (CorruptHistoryException) {
            self::assertNull($f->sis->sql->rows('sis_advising_updates')[0]['read_at']);
        }
        $f->updates()->delete(new EntityId($notice['id']), 0);
        self::assertSame([], $f->sis->sql->rows('sis_advising_updates'));
    }

    public function testNotificationWriteFailureRollsBackTheNewConversation(): void
    {
        $f = new AdvisingUpdatesFixture();
        $f->sis->sql->execute('CREATE TRIGGER fictional_notice_failure BEFORE INSERT ON sis_advising_updates '
            . "BEGIN SELECT RAISE(ABORT, 'fictional notification failure'); END");
        $before = array_map($f->sis->sql->rows(...), [
            'sis_advising_checkins', 'sis_advising_updates', 'sis_history_events',
        ]);
        try {
            $f->record();
            self::fail('Notification failure left a separately committed conversation.');
        } catch (\Throwable $failure) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $failure);
            self::assertSame($before, array_map(
                $f->sis->sql->rows(...),
                ['sis_advising_checkins', 'sis_advising_updates', 'sis_history_events']
            ));
        }
    }

    public static function noticeActions(): array
    {
        return ['read audit' => ['read', false], 'delete audit' => ['delete', false],
            'read owner changed' => ['read', true], 'delete owner changed' => ['delete', true]];
    }

    #[DataProvider('noticeActions')]
    public function testNotificationActionsRollBackAfterAuditOrOwnerFailures(string $action, bool $relink): void
    {
        $f = new AdvisingUpdatesFixture();
        $f->record();
        $f->asOwner();
        $notice = $f->updates()->page()['items'][0];
        $other = $f->sis->user();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f, $other, $relink): void {
            if ($relink) {
                $f->sis->sql->update('sis_persons', ['owner_user_id' => $other->value], ['id' => $f->person->value]);
            } else {
                throw new \RuntimeException('Fictional required read/delete audit failure.');
            }
        });
        $before = $f->sis->sql->rows('sis_advising_updates');
        try {
            $f->updates($audit)->{$action}(new EntityId($notice['id']), 0);
            self::fail('Failed notification authorization/audit committed its mutation.');
        } catch (\Throwable $failure) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $failure);
            self::assertSame($before, $f->sis->sql->rows('sis_advising_updates'));
            self::assertSame($f->owner->value, $f->sis->admissions->person($f->person)->ownerUserId->value);
        }
    }

    public function testUnnotifiedEarlierCheckInDoesNotProduceAnInferredNotification(): void
    {
        $f = new AdvisingUpdatesFixture();
        $id = EntityId::generate();
        $beforeIntegration = \Domain\Advising\AdvisingCheckIn::create(
            $id,
            $f->student,
            $f->pursuit,
            $f->owner,
            $f->staff,
            '2026-08-10',
            null,
            'Fictional earlier private note.',
            'Fictional earlier next step.',
            '2026-08-10T12:00:00Z'
        );
        $f->records->save($beforeIntegration, null);
        $f->asOwner();
        self::assertSame([], $f->updates()->page()['items']);
        self::assertSame(
            $id->value,
            $f->checkins()->listing($f->student, $f->pursuit, 'owner', selected: $id)['items'][0]['id']
        );
        self::assertSame([], $f->sis->sql->rows('sis_advising_updates'));
    }

    public function testActualProvidersPrivateRoutesSelectedHandoffAndCsrf(): void
    {
        $process = new \Symfony\Component\Process\Process([
            PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-advising',
        ]);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
