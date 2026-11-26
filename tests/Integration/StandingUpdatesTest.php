<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentProgram;
use Infrastructure\Persistence\Repository\SqlStandingUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\StandingUpdatesFixture;

final class StandingUpdatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/StandingUpdatesFixture.php';
    }

    public function testOnlyConfirmationNotifiesAndTheOwnerCanViewReadAndPermanentlyDeleteWithoutChangingHistory(): void
    {
        $f = new StandingUpdatesFixture();
        self::assertSame([], $f->sis->sql->rows('sis_standing_updates'));
        $review = $f->review();
        self::assertSame([], $f->sis->sql->rows('sis_standing_updates'));
        $receipt = $f->commit($review);
        $row = $f->sis->sql->rows('sis_standing_updates')[0];
        foreach (['rows', 'reason', 'policy_reference', 'standing_title'] as $private) {
            self::assertArrayNotHasKey($private, $row);
        }
        $factTables = ['sis_history_events', 'sis_student_standing', 'sis_academic_credits'];
        $facts = array_map($f->sis->sql->rows(...), $factTables);
        $f->owner();
        $updates = $f->updates();
        $page = $updates->page();
        self::assertCount(1, $page['items']);
        self::assertSame('recorded', $page['items'][0]['status']);
        self::assertArrayNotHasKey('pursuit_id', $page['items'][0]);
        $id = new EntityId($row['id']);
        $detail = $updates->detail($id);
        self::assertSame('Fictional good standing', $detail['items'][0]['title']);
        self::assertSame(['code', 'title', 'starts_on', 'ends_on'], array_keys($detail['items'][0]));
        self::assertStringNotContainsString('Fictional private', json_encode($detail));
        $updates->read($id, 1);
        self::assertSame([], $updates->page()['items']);
        self::assertCount(1, $updates->page(false)['items']);
        $updates->delete($id, 1);
        self::assertSame(['deleted' => true], $updates->delete($id, 1));
        self::assertSame([], $f->sis->sql->rows('sis_standing_updates'));
        self::assertSame($facts, array_map($f->sis->sql->rows(...), $factTables));
        $f->staff();
        $f->sis->clock->time = $f->sis->clock->now()->modify('+2 days');
        self::assertSame($receipt, $f->commit($review));
        $f->sis->standingLedger()->load($f->student->id, $f->pursuit);
        self::assertSame([], $f->sis->sql->rows('sis_standing_updates'));
    }

    public function testAChangedDecisionReplacesItsPriorNoticeAndOriginalRetriesNeverOverwriteTheNewOutcome(): void
    {
        $f = new StandingUpdatesFixture();
        $first = $f->review();
        $receipt = $f->commit($first);
        $old = $f->sis->sql->rows('sis_standing_updates')[0];
        $changed = $f->row;
        $changed['ends_on'] = '2026-08-31';
        $f->commit($f->review([$changed]));
        $current = $f->sis->sql->rows('sis_standing_updates');
        self::assertCount(1, $current);
        self::assertNotSame($old['id'], $current[0]['id']);
        self::assertSame(2, $current[0]['revision']);
        self::assertSame($receipt, $f->commit($first));
        self::assertSame($current, $f->sis->sql->rows('sis_standing_updates'));
        $f->owner();
        $f->updates()->delete(new EntityId($old['id']), 1);
        self::assertSame($current, $f->sis->sql->rows('sis_standing_updates'));
    }

    public function testRenamedDefinitionsDoNotNotifyOrRelabelTheRetainedOwnerPresentation(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $rows = $f->sis->sql->rows('sis_standing_updates');
        $f->service->saveDefinition(new EntityId($f->definition['id']), 'GOOD', 'Renamed working definition', true, 1);
        self::assertSame($rows, $f->sis->sql->rows('sis_standing_updates'));
        $f->owner();
        $detail = $f->updates()->detail(new EntityId($rows[0]['id']));
        self::assertSame('Fictional good standing', $detail['items'][0]['title']);
    }

    public function testClearingRecordedPeriodsNotifiesWithoutInferringGoodStandingOrErasingEarlierDecisions(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $before = $f->sis->sql->rows('sis_history_events');
        $f->commit($f->review([]));
        $notice = $f->sis->sql->rows('sis_standing_updates')[0];
        self::assertSame(2, $notice['revision']);
        self::assertCount(count($before) + 1, $f->sis->sql->rows('sis_history_events'));
        $f->owner();
        self::assertSame([], $f->updates()->detail(new EntityId($notice['id']))['items']);
    }

    public function testAuditFailureRollsBackEventProjectionAndNoticeAndAllowsOriginalConfirmation(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $changed = $f->row;
        $changed['ends_on'] = '2026-08-31';
        $review = $f->review([$changed]);
        $before = $f->snapshot();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional required audit failure.'));
        try {
            $f->commit($review, $audit);
            self::fail('A failed required audit committed.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Fictional required audit failure.', $failure->getMessage());
            self::assertSame($before, $f->snapshot());
        }
        self::assertSame(2, $f->commit($review)['version']);
        self::assertSame(2, $f->sis->sql->rows('sis_standing_updates')[0]['revision']);
    }

    public function testNoticeStorageFailureRollsBackItsStandingCommandAndItsOriginalReceiptCanBeRetried(): void
    {
        $f = new StandingUpdatesFixture();
        $review = $f->review();
        $before = $f->snapshot();
        $f->sis->sql->execute("CREATE TRIGGER standing_notice_failure BEFORE INSERT ON sis_standing_updates "
            . "BEGIN SELECT RAISE(ABORT, 'Fictional notice storage failure'); END");
        $failure = null;
        try {
            $f->commit($review);
        } catch (\Throwable $error) {
            $failure = $error;
        }
        self::assertNotNull($failure);
        self::assertSame($before, $f->snapshot());
        $f->sis->sql->execute('DROP TRIGGER standing_notice_failure');
        self::assertSame(1, $f->commit($review)['version']);
        self::assertCount(1, $f->sis->sql->rows('sis_standing_updates'));
    }

    public function testExplicitClosedPursuitCorrectionsNotifyWithoutChangingThePursuitOrOtherAcademicFacts(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $f->sis->transactions->run(fn () => $f->sis->programStatusLedger()->change(
            new SqlStudentRecordRepository($f->sis->sql)->assignment($f->pursuit),
            'closed',
            EntityId::generate(),
            $f->staff,
            'Fictional pursuit closure.',
        ));
        $changed = $f->row;
        $changed['ends_on'] = '2026-08-09';
        $review = $f->service->review(
            $f->student->id,
            $f->pursuit,
            [$changed],
            $f->service->view($f->student->id, $f->pursuit)['revision'],
            'Fictional historical evidence.',
            true
        );
        $f->commit($review);
        $row = $f->sis->sql->rows('sis_standing_updates')[0];
        self::assertSame('corrected', $row['status']);
        $f->owner();
        $detail = $f->updates()->detail(new EntityId($row['id']));
        self::assertSame('closed', $detail['pursuit_status']);
        self::assertSame('2026-08-09', $detail['items'][0]['ends_on']);
        self::assertSame([], $f->sis->sql->rows('sis_academic_credits'));
    }

    public function testAnOlderUnlinkedPersonGetsNoFabricatedAccountOrNotice(): void
    {
        $f = new StandingUpdatesFixture();
        $f->sis->sql->update('sis_persons', ['owner_user_id' => null], ['id' => $f->student->personId->value]);
        $users = $f->sis->sql->rows('users');
        self::assertSame(1, $f->commit($f->review())['version']);
        self::assertSame([], $f->sis->sql->rows('sis_standing_updates'));
        self::assertSame($users, $f->sis->sql->rows('users'));
    }

    public function testFinalOwnerAndNoticeChecksRollBackStandingConfirmation(): void
    {
        foreach (['owner', 'notice'] as $change) {
            $f = new StandingUpdatesFixture();
            $review = $f->review();
            $before = $f->snapshot();
            $other = $f->sis->user();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($f, $change, $other): void {
                if ($change === 'owner') {
                    $f->sis->sql->update(
                        'sis_persons',
                        ['owner_user_id' => $other->value],
                        ['id' => $f->student->personId->value]
                    );
                } else {
                    $f->sis->sql->execute('DELETE FROM sis_standing_updates');
                }
            });
            try {
                $f->commit($review, $audit);
                self::fail('A changed notice or account owner committed.');
            } catch (ConcurrentModificationException) {
                self::assertSame($before, $f->snapshot());
            }
        }
    }

    public function testOtherStudentsAndPrivilegedStaffCannotViewReadOrDeleteSomeoneElsesNotice(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $row = $f->sis->sql->rows('sis_standing_updates')[0];
        $other = $f->sis->student($f->sis->user());
        $f->sis->access->actor = $f->sis->admissions->person($other->personId)->ownerUserId;
        foreach ([false, true] as $privileged) {
            $f->sis->access->staff = $privileged;
            self::assertSame([], $f->updates()->page()['items']);
            foreach (['detail', 'read'] as $action) {
                try {
                    $action === 'detail' ? $f->updates()->detail(new EntityId($row['id']))
                    : $f->updates()->read(new EntityId($row['id']), 1);
                    self::fail('A different person accessed a standing notice.');
                } catch (AccessDeniedException) {
                    self::assertSame([$row], $f->sis->sql->rows('sis_standing_updates'));
                }
            }
            $f->updates()->delete(new EntityId($row['id']), 1);
            self::assertSame([$row], $f->sis->sql->rows('sis_standing_updates'));
        }
    }

    public function testAReplacementSignInAccountDoesNotInheritTheOldNotice(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $old = $f->sis->sql->rows('sis_standing_updates')[0];
        $other = $f->sis->user();
        $f->sis->sql->update('sis_persons', ['owner_user_id' => $other->value], ['id' => $f->student->personId->value]);
        $f->sis->access->actor = $other;
        self::assertSame([], $f->updates()->page()['items']);
        $f->updates()->delete(new EntityId($old['id']), 1);
        self::assertSame([$old], $f->sis->sql->rows('sis_standing_updates'));
        $f->staff();
        $changed = $f->row;
        $changed['ends_on'] = '2026-08-31';
        $f->commit($f->review([$changed]));
        $f->sis->access->actor = $other;
        self::assertCount(1, $f->updates()->page()['items']);
    }

    public function testWrongStudentRoutingHidesNoticeMetadataAndDetails(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $row = $f->sis->sql->rows('sis_standing_updates')[0];
        $other = $f->sis->student($f->sis->user());
        $f->sis->sql->update('sis_standing_updates', ['student_id' => $other->id->value], ['id' => $row['id']]);
        $f->owner();
        self::assertSame([], $f->updates()->page()['items']);
        $this->expectException(AccessDeniedException::class);
        $f->updates()->detail(new EntityId($row['id']));
    }

    public function testCorruptStandingFailsDetailButDoesNotPreventPermanentNotificationDeletion(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $notice = $f->sis->sql->rows('sis_standing_updates')[0];
        $f->sis->sql->update(
            'sis_student_standing',
            ['title' => 'Fictional corrupt projection'],
            ['pursuit_id' => $f->pursuit->value]
        );
        $f->owner();
        try {
            $f->updates()->detail(new EntityId($notice['id']));
            self::fail('A corrupt projection became an owner notification detail.');
        } catch (CorruptHistoryException) {
            self::assertSame([$notice], $f->sis->sql->rows('sis_standing_updates'));
        }
        $facts = $f->sis->sql->rows('sis_history_events');
        $f->updates()->delete(new EntityId($notice['id']), 1);
        self::assertSame([], $f->sis->sql->rows('sis_standing_updates'));
        self::assertSame($facts, $f->sis->sql->rows('sis_history_events'));
    }

    public function testStaleVersionsCannotReadOrDeleteANotice(): void
    {
        $f = new StandingUpdatesFixture();
        $f->commit($f->review());
        $row = $f->sis->sql->rows('sis_standing_updates')[0];
        $f->owner();
        foreach (['read', 'delete'] as $action) {
            try {
                $f->updates()->{$action}(new EntityId($row['id']), 0);
                self::fail('A stale notice mutation was accepted.');
            } catch (ConcurrentModificationException) {
                self::assertSame([$row], $f->sis->sql->rows('sis_standing_updates'));
            }
        }
        $f->sis->sql->update('sis_standing_updates', ['revision' => 2], ['id' => $row['id']]);
        $this->expectException(ConcurrentModificationException::class);
        $f->updates()->detail(new EntityId($row['id']));
    }

    public function testAccountOrReferenceChangesDuringReadAndDeleteRollBackTheMutation(): void
    {
        foreach (['read', 'delete'] as $action) {
            $f = new StandingUpdatesFixture();
            $f->commit($f->review());
            $row = $f->sis->sql->rows('sis_standing_updates')[0];
            $other = $f->sis->user();
            $f->owner();
            $before = $f->snapshot();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($f, $other): void {
                $f->sis->sql->update(
                    'sis_persons',
                    ['owner_user_id' => $other->value],
                    ['id' => $f->student->personId->value]
                );
            });
            try {
                $f->updates($audit)->{$action}(new EntityId($row['id']), 1);
                self::fail('A changed account owner committed a notice mutation.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $f->snapshot());
            }
        }
    }

    public function testPagingIsOwnerScopedAndCompleteCursorsAreValidatedBeforeQueries(): void
    {
        $f = new StandingUpdatesFixture();
        $records = new SqlStudentRecordRepository($f->sis->sql);
        $updates = new SqlStandingUpdatesRepository($f->sis->sql);
        for ($index = 0; $index < 21; $index++) {
            $pursuit = EntityId::generate();
            $program = EntityId::generate();
            $f->sis->sql->insert('sis_programs', ['id' => $program->value,
                'code' => 'FICT.' . $index, 'title' => 'Fictional paging program ' . $index]);
            $records->addAssignment(new StudentProgram($pursuit, $f->student->id, $program, $f->sis->term, 'closed'));
            $updates->replace(['id' => EntityId::generate()->value, 'owner_user_id' => $f->owner->value,
                'person_id' => $f->student->personId->value, 'student_id' => $f->student->id->value,
                'pursuit_id' => $pursuit->value, 'program_id' => $program->value,
                'operation_id' => EntityId::generate()->value, 'revision' => 1, 'status' => 'recorded',
                'recorded_at' => '2026-08-10T12:00:00.000000Z', 'read_at' => null]);
        }
        $f->owner();
        $service = $f->updates();
        $first = $service->page();
        self::assertCount(20, $first['items']);
        $second = $service->page(afterAt: $first['next']['at'], after: new EntityId($first['next']['id']));
        self::assertCount(1, $second['items']);
        self::assertNull($second['next']);
        foreach (
            [[null, EntityId::generate()], ['2026-08-10T12:00:00.000000Z', null],
            ['2026-02-30T12:00:00.000000Z', EntityId::generate()]] as [$at, $after]
        ) {
            try {
                $service->page(afterAt: $at, after: $after);
                self::fail('An incomplete or malformed cursor was accepted.');
            } catch (InvalidArgumentException) {
                self::assertCount(21, $f->sis->sql->rows('sis_standing_updates'));
            }
        }
    }
}
