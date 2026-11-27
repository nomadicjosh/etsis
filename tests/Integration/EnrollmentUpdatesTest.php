<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Service\EnrollmentUpdatesService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlEnrollmentUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;
use Tests\Support\EnrollmentReinstatementFixture;

final class EnrollmentUpdatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        require_once dirname(__DIR__) . '/Support/EnrollmentReinstatementFixture.php';
    }

    private function service(SisFixture $s, ?AuditTrail $audit = null): EnrollmentUpdatesService
    {
        return new EnrollmentUpdatesService(
            new SqlEnrollmentUpdatesRepository($s->sql),
            new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
            $s->admissions,
            $s->registration,
            $s->enrollmentLedger(),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
        );
    }

    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_registration_streams', 'sis_registration_decisions', 'sis_enrollments', 'sis_academic_credits',
            'sis_student_accounts', 'sis_account_entries', 'sis_account_balances', 'sis_enrollment_updates']);
    }

    public function testRegistrationCreatesOnlyMinimizedOwnerNoticeAndReadDeletePreserveAllBusinessFacts(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(25, 0);
        $enrollment = $s->service->register($student->id, $section->id);
        $service = $this->service($s);
        $page = $service->page();
        self::assertCount(1, $page['items']);
        $notice = $page['items'][0];
        self::assertSame('registered', $notice['status']);
        self::assertSame(1, $notice['revision']);
        $private = ['student_id', 'person_id', 'owner_user_id', 'decision_id', 'enrollment_id', 'reason', 'fee_minor'];
        foreach ($private as $key) {
            self::assertArrayNotHasKey($key, $notice);
        }
        $before = $this->facts($s);
        self::assertSame(['unread' => false], $service->read(new EntityId($notice['id']), $notice['revision']));
        self::assertSame([], $service->page()['items']);
        self::assertFalse($service->page(false)['items'][0]['unread']);
        $service->delete(new EntityId($notice['id']), $notice['revision']);
        self::assertSame([], $s->sql->rows('sis_enrollment_updates'));
        array_pop($before);
        $after = $this->facts($s);
        array_pop($after);
        self::assertSame($before, $after);
        self::assertSame($enrollment->id->value, $s->service->register($student->id, $section->id)->id->value);
        self::assertSame([], $service->page(false)['items']);
    }

    public function testEarlyDropReplacesNoticeWhileRetainingHistoryAndOriginalRetryDoesNotRecreateIt(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(25, 0);
        $enrollment = $s->service->register($student->id, $section->id);
        $service = $this->service($s);
        $old = $service->page()['items'][0];
        $review = $s->drops->review($student->id, $section->id);
        $confirm = static fn(): array => $s->drops->commit(
            $student->id,
            $section->id,
            $enrollment->id,
            $review['version'],
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['effective_on']
        );
        $result = $confirm();
        self::assertTrue($result['academic_records_removed']);
        $notice = $service->page()['items'][0];
        self::assertSame('dropped', $notice['status']);
        self::assertNotSame($old['id'], $notice['id']);
        self::assertSame(['deleted' => true], $service->delete(new EntityId($old['id']), $old['revision']));
        self::assertCount(1, $service->page()['items']);
        self::assertNull($s->registration->enrollment($student->id, $section->id));
        $service->read(new EntityId($notice['id']), $notice['revision']);
        $service->delete(new EntityId($notice['id']), $notice['revision']);
        $facts = $this->facts($s);
        self::assertSame($result, $confirm());
        self::assertSame($facts, $this->facts($s));
    }

    public function testWithdrawalAndReinstatementCoalesceAndDeletedAcceptedRetriesStaySilent(): void
    {
        $f = new EnrollmentReinstatementFixture();
        $s = $f->sis;
        $service = $this->service($s);
        $staff = $s->access->actor;
        $owner = $s->admissions->person($f->student->personId)->ownerUserId;
        $s->access->actor = $owner;
        $withdrawn = $service->page()['items'][0];
        self::assertSame('withdrawn', $withdrawn['status']);
        $s->access->actor = $staff;
        $review = $f->review();
        $result = $f->confirm($review);
        $s->access->actor = $owner;
        $restored = $service->page()['items'][0];
        self::assertSame('reinstated', $restored['status']);
        self::assertNotSame($withdrawn['id'], $restored['id']);
        $service->read(new EntityId($restored['id']), $restored['revision']);
        $service->delete(new EntityId($restored['id']), $restored['revision']);
        $facts = $this->facts($s);
        $s->access->actor = $staff;
        self::assertSame($result, $f->confirm($review));
        self::assertSame($facts, $this->facts($s));
        $s->access->actor = $owner;
        self::assertSame([], $service->page(false)['items']);
    }

    public function testAnotherAccountAndRelinkingCannotReadOrDeleteOriginalPrivateNotices(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $s->service->register($student->id, $s->section(25, 0)->id);
        $notice = $this->service($s)->page()['items'][0];
        $other = $s->user();
        $otherStudent = $s->student($other);
        $s->access->actor = $other;
        $service = $this->service($s);
        self::assertSame([], $service->page(false)['items']);
        self::assertSame(['deleted' => true], $service->delete(new EntityId($notice['id']), $notice['revision']));
        self::assertCount(1, $s->sql->rows('sis_enrollment_updates'));
        $s->sql->update('sis_persons', ['owner_user_id' => null], ['id' => $otherStudent->personId->value]);
        $s->sql->update('sis_persons', ['owner_user_id' => $other->value], ['id' => $student->personId->value]);
        self::assertSame([], $service->page(false)['items']);
        $this->expectException(AccessDeniedException::class);
        $service->read(new EntityId($notice['id']), $notice['revision']);
    }

    public function testForgedDecisionCannotBeMarkedReadButItsOperationalNoticeCanBeDeleted(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $s->service->register($student->id, $s->section(25, 0)->id);
        $service = $this->service($s);
        $notice = $service->page()['items'][0];
        $s->sql->update(
            'sis_enrollment_updates',
            ['decision_id' => EntityId::generate()->value],
            ['id' => $notice['id']],
        );
        $history = \EventStoreFixture::historyDigest($s->sql);
        try {
            $service->read(new EntityId($notice['id']), $notice['revision']);
            self::fail('A forged source was accepted.');
        } catch (CorruptHistoryException) {
            self::assertTrue(true);
        }
        self::assertNull($s->sql->rows('sis_enrollment_updates')[0]['read_at']);
        $service->delete(new EntityId($notice['id']), $notice['revision']);
        self::assertSame($history, \EventStoreFixture::historyDigest($s->sql));
    }

    public function testRequiredAuditFailureRollsBackAcceptedRegistrationAndNotificationTogether(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(25, 0);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional audit failure.'));
        $before = $this->facts($s);
        try {
            $s->courseRegistrationService($audit)->register($student->id, $section->id);
            self::fail('A partial registration survived.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional audit failure.', $error->getMessage());
        }
        self::assertSame($before, $this->facts($s));
    }

    public function testNotificationChangesDuringRequiredAuditRollBackTheWholeRegistration(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $section = $s->section(25, 0);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s): void {
            $row = $s->sql->rows('sis_enrollment_updates')[0];
            $s->sql->update('sis_enrollment_updates', ['status' => 'withdrawn'], ['id' => $row['id']]);
        });
        $before = $this->facts($s);
        try {
            $s->courseRegistrationService($audit)->register($student->id, $section->id);
            self::fail('Changed notification metadata survived.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        self::assertSame($before, $this->facts($s));
    }

    public function testReadAndDeleteAuditFailurePreserveNoticeAndOriginalFacts(): void
    {
        $s = new SisFixture();
        $student = $s->student();
        $s->service->register($student->id, $s->section(25, 0)->id);
        $notice = $this->service($s)->page()['items'][0];
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Fictional audit failure.'));
        $service = $this->service($s, $audit);
        $before = $this->facts($s);
        foreach (['read', 'delete'] as $action) {
            try {
                $service->{$action}(new EntityId($notice['id']), $notice['revision']);
                self::fail('A partial notification mutation survived.');
            } catch (\RuntimeException $error) {
                self::assertSame('Fictional audit failure.', $error->getMessage());
            }
            self::assertSame($before, $this->facts($s));
        }
    }
}
