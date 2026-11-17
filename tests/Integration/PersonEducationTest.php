<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\Service\EducationBackgroundService;
use Domain\Person\Service\PersonProfileAccess;
use Domain\Person\ValueObject\EducationBackground;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class PersonEducationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }

    private function entry(string $key = 'college_one', string $name = 'Fictional College'): array
    {
        return ['key' => $key, 'institution' => $name, 'institution_code' => 'EXAMPLE',
            'start_date' => '2020-08-01', 'end_date' => '2024-05-31', 'major' => 'Arts',
            'degree' => 'Diploma', 'conferred_date' => '2024-06-01', 'gpa' => '3.75', 'gpa_max' => '4.0'];
    }

    private function service(
        CampusFixture $c,
        ?AuditTrail $audit = null,
        ?PersonProfileRepository $rows = null
    ): EducationBackgroundService {
        $f = $c->sis;
        return new EducationBackgroundService(
            new \Infrastructure\Persistence\Repository\SqlEducationBackgroundRepository($f->sql, $c->cipher),
            $rows ?? new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($f->sql),
            $f->profileLedger(),
            $this->authorization($c),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
        );
    }

    private function authorization(CampusFixture $c): PersonProfileAccess
    {
        $f = $c->sis;
        return new PersonProfileAccess(
            new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
                $f->sql,
                $f->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
            ),
            $f->admissions,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($f->sql),
            $f->access,
        );
    }

    public function testOwnerCorrectionCycleRetainsOriginalFactsAndOnlyChangedEntriesNeedReviewAgain(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->student()->personId;
        $owner = $f->access->actor;
        $first = $this->entry();
        $second = $this->entry('college_two', 'Fictional University');
        self::assertSame(['entries' => [], 'version' => 0], $c->education->view($person));
        $c->education->save([$first, $second], 0, '');
        $staff = $f->user();
        $f->access->actor = $staff;
        $c->education->review($person, 'college_one', 'changes_requested', 1, 'Please correct the final date.');
        $c->education->review($person, 'college_two', 'reviewed', 2, 'Background reviewed with applicant.');
        $f->access->actor = $owner;
        $f->access->staff = false;
        self::assertSame('Please correct the final date.', $c->education->view($person)['entries'][0]['feedback']);
        $first['end_date'] = '2024-06-30';
        $c->education->save([$first, $second], 3, 'Corrected the final attendance date as requested.');
        $current = $c->education->view($person);
        self::assertSame(4, $current['version']);
        self::assertSame('unreviewed', $current['entries'][0]['review_status']);
        self::assertSame('', $current['entries'][0]['feedback']);
        self::assertSame('reviewed', $current['entries'][1]['review_status']);
        $events = $f->sql->rows('sis_history_events');
        $c->education->save([$this->entry(), $second], 0, '');
        self::assertSame($current, $c->education->view($person));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        foreach (['sis_person_education', 'sis_history_events', 'sis_history_operations', 'sis_audit'] as $table) {
            $json = json_encode($f->sql->rows($table), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('Fictional College', $json);
            self::assertStringNotContainsString('Please correct the final date', $json);
        }
        self::assertCount(1, $f->sql->rows('sis_history_streams', ['stream_type' => 'person_profile']));
        self::assertSame([], $f->sql->rows('sis_transfer_awards'));
    }

    public function testStaffRolesNeverOverrideOwnerOnlyPortalReadsAndReviewNeedsAdmissionsAuthority(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->student()->personId;
        $c->education->save([$this->entry()], 0, '');
        $f->access->actor = $f->user();
        try {
            $c->education->view($person);
            self::fail('Staff privileges overrode portal ownership.');
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $f->access->staff = false;
        $f->access->grants = ['sis:person:manage'];
        self::assertSame(1, $c->education->staffView($person)['version']);
        $this->expectException(AccessDeniedException::class);
        $c->education->review($person, 'college_one', 'reviewed', 1, 'Fictional review.');
    }

    public function testProjectionDriftIsRejectedAndCanBeReconstructedFromTheSamePersonStream(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->student()->personId;
        $c->education->save([$this->entry()], 0, '');
        $row = $f->sql->rows('sis_person_education')[0];
        $f->sql->update(
            'sis_person_education',
            ['encrypted_background' => $c->cipher->seal('[]')],
            ['id' => $row['id']]
        );
        try {
            $c->education->view($person);
            self::fail('Altered background was accepted.');
        } catch (CorruptHistoryException) {
            self::assertTrue(true);
        }
        $root = \Domain\Person\ProfileHistory::root(new \Domain\Person\ValueObject\PersonProfileId($person->value));
        $page = \EventStoreFixture::history($f->connection->queryBuilder())->page(
            new \Domain\Shared\EventSourcing\StreamReference($root->aggregateId(), 'person_profile'),
            null,
            20
        );
        foreach ($page->events as $event) {
            $root->acceptRecorded($event);
        }
        self::assertSame('Fictional College', $root->education()->entries[0]['institution']);
        $f->sql->update(
            'sis_person_education',
            ['encrypted_background' => $row['encrypted_background']],
            ['id' => $row['id']]
        );
        self::assertSame($root->education()->facts(), $c->education->view($person));
    }

    public function testStaleEditsAndForgedReviewFieldsCannotChangeTheBackground(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->student()->personId;
        $c->education->save([$this->entry()], 0, '');
        $changed = $this->entry();
        $changed['major'] = 'Mathematics';
        try {
            $c->education->save([$changed], 0, 'Another stale change.');
            self::fail('Stale changes were accepted.');
        } catch (ConcurrentModificationException) {
            self::assertTrue(true);
        }
        $changed['review_status'] = 'reviewed';
        try {
            $c->education->save([$changed], 1, 'Attempted review forgery.');
            self::fail('Owner-supplied review fields were accepted.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
        self::assertSame('Arts', $c->education->view($person)['entries'][0]['major']);
    }

    public function testOldReviewRetriesCannotRestoreARequestAfterTheOwnerHasCorrectedTheEntry(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->student()->personId;
        $owner = $f->access->actor;
        $c->education->save([$this->entry()], 0, '');
        $staff = $f->user();
        $f->access->actor = $staff;
        $c->education->review($person, 'college_one', 'changes_requested', 1, 'Please correct the major.');
        $f->access->actor = $owner;
        $changed = array_replace($this->entry(), ['major' => 'Mathematics']);
        $c->education->save([$changed], 2, 'Corrected the major as requested.');
        $events = $f->sql->rows('sis_history_events');
        $f->access->actor = $staff;
        $c->education->review($person, 'college_one', 'changes_requested', 1, 'Please correct the major.');
        self::assertSame('unreviewed', $c->education->staffView($person)['entries'][0]['review_status']);
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }

    public function testAuditFailureRollsBackProfileObservationEducationHistoryProjectionAndReceipt(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $f->student();
        $audit = $this->createMock(AuditTrail::class);
        $audit->expects(self::once())->method('record')
            ->willThrowException(new \RuntimeException('Fictional audit failure.'));
        try {
            $this->service($c, $audit)->save([$this->entry()], 0, '');
            self::fail('An unaudited educational background was saved.');
        } catch (\RuntimeException $error) {
            self::assertSame('Fictional audit failure.', $error->getMessage());
        }
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_person_education'];
        foreach ($tables as $table) {
            self::assertSame([], $f->sql->rows($table));
        }
    }

    public function testStaffAuthorityIsRecheckedAfterWaitingForThePersonLock(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->student()->personId;
        $f->access->actor = $f->user();
        $f->access->staff = false;
        $f->access->grants = ['sis:person:manage'];
        $rows = $this->createMock(PersonProfileRepository::class);
        $rows->expects(self::once())->method('lock')->willReturnCallback(static function () use ($f): void {
            $f->access->grants = [];
        });
        try {
            $this->service($c, rows: $rows)->staffSave($person, [$this->entry()], 0, 'Fictional staff entry.');
            self::fail('Revoked person-management authority was accepted.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows('sis_person_education'));
            self::assertSame([], $f->sql->rows('sis_history_events'));
        }
    }

    public function testEducationSharesThePersonStreamWithoutChangingOtherProfileComponents(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->student()->personId;
        $c->contacts->save('Fictional preferred name', '000123', 'Fictional mailing address', 0);
        $c->education->save([$this->entry()], 0, '');
        $c->emergency->save([['name' => 'Fictional Contact', 'phone' => '000999']], 0);
        self::assertSame('000123', $c->contacts->view($person)['phone']);
        self::assertSame('000999', $c->emergency->view($person)['contacts'][0]['phone']);
        self::assertSame('Fictional College', $c->education->view($person)['entries'][0]['institution']);
        self::assertSame(4, $f->profileLedger()->verify($f->admissions->person($person)));
        self::assertStringNotContainsString('Fictional College', json_encode($c->service->me(), JSON_THROW_ON_ERROR));
    }

    public function testStaffHistoryShowsEducationActionsWithoutExposingSchoolNamesGpaOrFeedback(): void
    {
        $c = new CampusFixture();
        $f = $c->sis;
        $person = $f->student()->personId;
        $c->education->save([$this->entry()], 0, '');
        $c->education->review($person, 'college_one', 'changes_requested', 1, 'Private fictional review request.');
        $actors = $this->createStub(\Domain\Audit\Repository\ActorDirectory::class);
        $actors->method('names')->willReturn([$f->access->actor->value => 'Fictional staff']);
        $history = new \Domain\Person\Service\PersonHistoryReview(
            \EventStoreFixture::history($f->connection->queryBuilder()),
            $this->authorization($c),
            $f->access,
            $actors,
        );
        $page = $history->page($person);
        self::assertSame('Changes requested', $page['items'][0]['action']);
        self::assertSame('Educational background', $page['items'][0]['category']);
        $json = json_encode($page, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Fictional College', $json);
        self::assertStringNotContainsString('Private fictional review request', $json);
        self::assertStringNotContainsString('3.75', $json);
    }
}
