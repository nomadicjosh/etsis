<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Service\AdmissionUpdatesService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdmissionCaseworkFixture;

final class AdmissionNotificationDeletionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/AdmissionCaseworkFixture.php';
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }

    private function values(string $step = 'Fictional applicant next step.'): array
    {
        return ['office' => 'Fictional admissions', 'next_step' => $step, 'private_notes' => 'Private staff facts.'];
    }

    private function service(AdmissionCaseworkFixture $f, AuditTrail $audit): AdmissionUpdatesService
    {
        $s = $f->campus->sis;
        return new AdmissionUpdatesService(
            new SqlAdmissionUpdatesRepository($s->sql),
            new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
            $s->admissions,
            $s->access,
            $s->transactions,
            $audit
        );
    }

    public function testPersonalDeletionPreservesTheApplicationAndDoesNotRecreateOnOriginalRetries(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $values = $this->values();
        $f->service->save($f->application, $values, 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        $tables = ['sis_applications', 'sis_admission_cases', 'sis_persons', 'users',
            'sis_history_events', 'sis_history_streams', 'sis_history_operations'];
        $before = array_map($s->sql->rows(...), $tables);
        self::assertSame(['deleted' => true], $f->updates->delete($notice, 1));
        self::assertSame([], $s->sql->rows('sis_admission_updates'));
        self::assertSame($before, array_map($s->sql->rows(...), $tables));
        $audit = $s->sql->rows('sis_audit');
        self::assertTrue($f->updates->delete($notice, 1)['deleted']);
        self::assertSame($audit, $s->sql->rows('sis_audit'));
        $f->service->save($f->application, $values, 0, 'keep');
        self::assertSame([], $s->sql->rows('sis_admission_updates'));
        $values['private_notes'] = 'Another private-only edit.';
        $f->service->save($f->application, $values, 1, 'keep');
        self::assertSame([], $s->sql->rows('sis_admission_updates'));
        $values['next_step'] = 'A genuinely new public next step.';
        $f->service->save($f->application, $values, 2, 'keep');
        $fresh = $f->updates->page()['items'][0];
        self::assertNotSame($notice->value, $fresh['id']);
        self::assertSame(1, $fresh['revision']);
        self::assertTrue($f->updates->delete($notice, 1)['deleted']);
        self::assertCount(1, $s->sql->rows('sis_admission_updates'));
        $this->expectException(AccessDeniedException::class);
        $f->updates->read($notice, 1);
    }

    public function testDeletingAnOlderRevisionCannotRemoveANewerVisibleUpdate(): void
    {
        $f = new AdmissionCaseworkFixture();
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        $f->service->save($f->application, $this->values('Fictional updated guidance.'), 1, 'keep');
        try {
            $f->updates->delete($notice, 1);
            self::fail('A delayed delete removed the newer notice.');
        } catch (ConcurrentModificationException) {
            self::assertSame(2, $f->updates->detail($notice)['revision']);
            self::assertTrue($f->updates->read($notice, 1)['unread']);
        }
        self::assertTrue($f->updates->delete($notice, 2)['deleted']);
    }

    public function testMetadataAndDeletionWorkForAnApplicantWithoutStudentOrStaffPrivileges(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $s->access->staff = false;
        $s->access->grants = [];
        self::assertSame([], $s->sql->rows('sis_students'));
        $item = $f->updates->page()['items'][0];
        $notice = new EntityId($item['id']);
        self::assertSame($item, $f->updates->detail($notice));
        $json = json_encode($item, JSON_THROW_ON_ERROR);
        $privateFields = ['next_step', 'private_notes', 'Private staff', 'Fictional applicant',
            'person_id', 'owner_user_id'];
        foreach ($privateFields as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertTrue($f->updates->delete($notice, 1)['deleted']);
    }

    public function testForeignStaffCannotReadOrDeleteAnotherAccountsNotice(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        $before = $s->sql->rows('sis_admission_updates');
        $actor = $s->user();
        $s->legacyPerson($actor, 'Fictional', 'Other owner');
        $s->access->actor = $actor;
        self::assertSame([], $f->updates->page()['items']);
        self::assertTrue($f->updates->delete($notice, 1)['deleted']);
        self::assertSame($before, $s->sql->rows('sis_admission_updates'));
        $this->expectException(AccessDeniedException::class);
        $f->updates->detail($notice);
    }

    public static function mutationActions(): array
    {
        return [['read'], ['delete']];
    }

    public function testRelinkingAnApplicationDoesNotTransferAnEarlierAccountsNotice(): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        $person = $s->admissions->application($f->application)->personId;
        $other = $s->user();
        $s->sql->update('sis_persons', ['owner_user_id' => $other->value], ['id' => $person->value]);
        $before = $s->sql->rows('sis_admission_updates');
        try {
            $f->updates->delete($notice, 1);
            self::fail('An unlinked account retained personal notice access.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_admission_updates'));
        }
        $s->access->actor = $other;
        self::assertSame([], $f->updates->page()['items']);
        self::assertTrue($f->updates->delete($notice, 1)['deleted']);
        self::assertSame($before, $s->sql->rows('sis_admission_updates'));
        $this->expectException(AccessDeniedException::class);
        $f->updates->detail($notice);
    }

    #[DataProvider('mutationActions')]
    public function testFinalAccountChangesRollBackNotificationMutation(string $action): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        $before = $s->sql->rows('sis_admission_updates');
        $other = $s->user();
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $other): void {
            $s->access->actor = $other;
        });
        try {
            $this->service($f, $audit)->{$action}($notice, 1);
            self::fail('A changed authenticated account committed a notice mutation.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_admission_updates'));
        }
    }

    #[DataProvider('mutationActions')]
    public function testAuditFailureRollsBackReadAndPhysicalDelete(string $action): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        $before = $s->sql->rows('sis_admission_updates');
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')->willThrowException(
            new \RuntimeException('Fictional failure.')
        );
        try {
            $this->service($f, $audit)->{$action}($notice, 1);
            self::fail('An unaudited personal mutation committed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional failure.', $error->getMessage());
            self::assertSame($before, $s->sql->rows('sis_admission_updates'));
        }
    }

    #[DataProvider('mutationActions')]
    public function testFinalSourceChangesRollBackNotificationMutation(string $action): void
    {
        $f = new AdmissionCaseworkFixture();
        $s = $f->campus->sis;
        $f->service->save($f->application, $this->values(), 0, 'keep');
        $notice = new EntityId($f->updates->page()['items'][0]['id']);
        $before = $s->sql->rows('sis_admission_updates');
        $application = $s->sql->rows('sis_applications');
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($s, $f): void {
            $s->sql->update('sis_applications', ['version' => 99], ['id' => $f->application->value]);
        });
        try {
            $this->service($f, $audit)->{$action}($notice, 1);
            self::fail('A changed application source committed.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_admission_updates'));
            self::assertSame($application, $s->sql->rows('sis_applications'));
        }
    }

    public function testUtcCursorIsIndependentOfTheApplicationDaylightSavingTimezone(): void
    {
        $f = new AdmissionCaseworkFixture();
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/Los_Angeles');
        try {
            self::assertSame([], $f->updates->page(
                afterAt: '2026-03-08T02:30:00.000000Z',
                after: EntityId::generate()
            )['items']);
        } finally {
            date_default_timezone_set($timezone);
        }
    }
}
