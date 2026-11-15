<?php

declare(strict_types=1);

use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Event\PersonContactWasRecorded;
use Domain\Person\Event\PersonProfileWasRecorded;
use Domain\Person\ProfileHistory;
use Domain\Person\Repository\PersonContactRepository;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\Service\PersonContactService;
use Domain\Person\ValueObject\ContactDetails;
use Domain\Person\ValueObject\PersonProfileId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonContactRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/CampusFixture.php';

final class PersonContactHistoryTest extends TestCase
{
    /** @return list<\Domain\Shared\EventSourcing\StoredEvent> */
    private function events(CampusFixture $c, EntityId $person): array
    {
        $s = $c->sis;
        $history = new SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            new ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())),
            new HistoricalSchemaRegistry()
        );
        return $history->page(new StreamReference(new PersonProfileId($person->value), 'person_profile'))->events;
    }

    private function service(
        CampusFixture $c,
        ?AuditTrail $audit = null,
        ?PersonContactRepository $contacts = null,
        ?PersonProfileRepository $rows = null,
    ): PersonContactService {
        $s = $c->sis;
        return new PersonContactService(
            $contacts ?? new SqlPersonContactRepository($s->sql, $c->cipher),
            $rows ?? new SqlPersonProfileRepository($s->sql),
            $s->profileLedger(),
            new \Domain\Person\Service\PersonProfileAccess(
                new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
                $s->admissions,
                new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
                $s->access
            ),
            $s->transactions,
            $audit ?? $s->audit,
            new \Infrastructure\Persistence\Repository\SqlAddressBookRepository($s->sql, $c->cipher),
            $s->clock
        );
    }

    public function testARegisteredProspectUsesTheExistingParentStreamWithoutNeedingAStudentRecord(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->registrationService()->register(
            EntityId::generate(),
            'Fictional',
            'Prospect',
            'contact@example.invalid',
            'fictional_prospect',
            \Codefy\Framework\Support\Password::hash('fictional-campus-password-long-enough')
        );
        $s->access->actor = $person->ownerUserId;
        $s->access->staff = false;
        $before = $this->events($c, $person->id)[0]->event;
        $c->contacts->save('Preferred', '', 'Fictional prospect address', 0);
        $events = $this->events($c, $person->id);
        self::assertCount(2, $events);
        self::assertSame('created', $events[0]->event->payload()['provenance']);
        self::assertSame($before->eventId()->__toString(), $events[0]->event->eventId()->__toString());
        self::assertSame('created', $events[1]->event->payload()['provenance']);
        self::assertSame([], $s->sql->rows('sis_students'));
        self::assertSame('Fictional prospect address', $c->service->me()['contact']['address']);
    }

    public function testAuthorizedStaffEditTheSamePersonContactWithoutReceivingCredentialAccess(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $actor = $s->access->actor;
        $person = $s->student($s->user())->personId;
        $s->access->staff = false;
        $s->access->grants = ['sis:person:manage'];
        $users = $s->sql->rows('users');
        self::assertSame(0, $c->contacts->staffView($person)['version']);
        $c->contacts->staffSave($person, 'Preferred', '001234', 'Fictional staff-entered address', 0);
        $events = $this->events($c, $person);
        self::assertSame($actor->value, $events[1]->context->actor->value);
        self::assertSame('Preferred', $c->contacts->staffView($person)['preferred_name']);
        self::assertSame($users, $s->sql->rows('users'));
        $s->access->actor = $s->admissions->person($person)->ownerUserId;
        $s->access->grants = [];
        self::assertSame('Fictional staff-entered address', $c->contacts->view($person)['address']);
    }

    public function testAccountEditingPermissionAloneDoesNotAuthorizeStaffContactAccess(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student($s->user())->personId;
        $s->access->staff = false;
        $s->access->grants = ['admin:edit:user'];
        foreach (['read', 'write'] as $action) {
            try {
                $action === 'read' ? $c->contacts->staffView($person)
                : $c->contacts->staffSave($person, 'Fictional', '', '', 0);
                self::fail('Credential administration allowed person-contact access.');
            } catch (AccessDeniedException) {
                self::assertSame([], $s->sql->rows('sis_portal_contacts'));
                self::assertSame([], $s->sql->rows('sis_history_events'));
            }
        }
    }

    public function testStaffAndOwnerEditsShareRevisionsAndRetriesKeepLaterValues(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $actor = $s->access->actor;
        $s->legacyPerson($actor, 'Fictional', 'Staff');
        $person = $s->student($s->user())->personId;
        $s->access->staff = false;
        $s->access->grants = ['sis:person:manage'];
        $c->contacts->staffSave($person, 'Staff entry', '', 'Fictional first address', 0);
        $owner = $s->admissions->person($person)->ownerUserId;
        $s->access->actor = $owner;
        $s->access->grants = [];
        $c->contacts->save('Owner entry', '', 'Fictional later address', 1);
        $s->access->actor = $actor;
        $s->access->grants = ['sis:person:manage'];
        $before = $s->sql->rows('sis_history_events');
        $c->contacts->staffSave($person, 'Staff entry', '', 'Fictional first address', 0);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        self::assertSame('Fictional later address', $c->contacts->staffView($person)['address']);
        try {
            $c->contacts->view($person);
            self::fail('A portal read used staff permission to override ownership.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        try {
            $c->contacts->staffSave($person, 'Competing edit', '', '', 0);
            self::fail('Accepted a stale competing staff edit.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
        $s->access->grants = [];
        $this->expectException(AccessDeniedException::class);
        $c->contacts->staffSave($person, 'Staff entry', '', 'Fictional first address', 0);
    }

    public function testOnlyASuperAdministratorCanReadOrEditASuperPersonsContactsThroughStaffRoutes(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student($s->user())->personId;
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $s->admissions->person($person)->ownerUserId,
            $roles->roleNamed('super')->id,
            true
        );
        $s->access->staff = false;
        $s->access->grants = ['sis:person:manage'];
        foreach (['read', 'write'] as $action) {
            try {
                $action === 'read' ? $c->contacts->staffView($person)
                : $c->contacts->staffSave($person, 'Fictional', '', '', 0);
                self::fail('A regular administrator accessed protected super contact details.');
            } catch (AccessDeniedException) {
                self::assertSame([], $s->sql->rows('sis_portal_contacts'));
            }
        }
        $roles->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $s->access->actor,
            $roles->roleNamed('super')->id,
            true
        );
        $c->contacts->staffSave($person, 'Protected', '', '', 0);
        self::assertSame('Protected', $c->contacts->staffView($person)['preferred_name']);
    }

    public function testRevokedStaffAuthorityWhileWaitingOrAfterWritesRollsBackTheWholeChange(): void
    {
        foreach (['lock', 'audit'] as $case) {
            $c = new CampusFixture();
            $s = $c->sis;
            $person = $s->student($s->user())->personId;
            $s->access->staff = false;
            $s->access->grants = ['sis:person:manage'];
            $audit = null;
            $rows = null;
            $revoke = static function () use ($s): void {
                $s->access->grants = [];
            };
            if ($case === 'lock') {
                $rows = $this->createStub(PersonProfileRepository::class);
                $rows->method('lock')->willReturnCallback($revoke);
            } else {
                $audit = $this->createStub(AuditTrail::class);
                $audit->method('record')->willReturnCallback($revoke);
            }
            try {
                $this->service($c, $audit, rows: $rows)->staffSave($person, 'Fictional', '', '', 0);
                self::fail('A staff update committed after its permission was revoked.');
            } catch (AccessDeniedException) {
                foreach (['sis_portal_contacts', 'sis_history_events', 'sis_history_operations', 'sis_history_streams'] as $table) {
                    self::assertSame([], $s->sql->rows($table));
                }
            }
        }
    }

    public function testAStaffReadRechecksPermissionAfterLoadingProtectedContactDetails(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student($s->user())->personId;
        $s->access->staff = false;
        $s->access->grants = ['sis:person:manage'];
        $contacts = $this->createStub(PersonContactRepository::class);
        $contacts->method('current')->willReturnCallback(static function () use ($s): ?ContactDetails {
            $s->access->grants = [];
            return null;
        });
        $this->expectException(AccessDeniedException::class);
        $this->service($c, contacts: $contacts)->staffView($person);
    }

    public function testNewContactFactsShareThePersonStreamAndDoNotChangeItsIdentityOrOtherRecords(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $student = $s->student();
        $person = $student->personId;
        $users = $s->sql->rows('users');
        $students = $s->sql->rows('sis_students');
        $s->access->staff = false;
        $c->contacts->save('Fictional', '+1 555 0100', "Example campus\nFictional town", 0);
        $events = $this->events($c, $person);
        self::assertCount(2, $events);
        self::assertInstanceOf(PersonProfileWasRecorded::class, $events[0]->event);
        self::assertSame('observed', $events[0]->event->payload()['provenance']);
        self::assertInstanceOf(PersonContactWasRecorded::class, $events[1]->event);
        self::assertSame('created', $events[1]->event->payload()['provenance']);
        self::assertSame($s->access->actor->value, $events[1]->context->actor->value);
        self::assertSame($s->clock->now()->format('c'), $events[1]->context->effectiveAt->format('c'));
        $root = ProfileHistory::root(new PersonProfileId($person->value));
        foreach ($events as $stored) {
            $root->acceptHistorical($stored->event);
        }
        self::assertSame($person->value, $root->person()->id->value);
        self::assertSame(1, $root->contact()->version);
        self::assertSame("Example campus\nFictional town", $root->contact()->address);
        self::assertSame($users, $s->sql->rows('users'));
        self::assertSame($students, $s->sql->rows('sis_students'));
        foreach (['sis_portal_contacts', 'sis_history_events', 'sis_history_operations', 'sis_audit'] as $table) {
            self::assertStringNotContainsString('Fictional town', json_encode($s->sql->rows($table), JSON_THROW_ON_ERROR));
        }
    }

    public function testExistingContactIsObservedOnlyAtAnActualChangeAndNoOpReadsAndSavesWriteNothing(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $s->sql->insert('sis_portal_contacts', ['id' => EntityId::generate()->value, 'person_id' => $person->value,
            'version' => 7, 'encrypted_contact' => $c->cipher->seal(json_encode([
                'preferred_name' => 'Earlier', 'phone' => '555-0101', 'address' => 'Fictional former address',
            ], JSON_THROW_ON_ERROR))]);
        $before = $s->sql->rows('sis_history_events');
        self::assertSame(7, $c->contacts->view($person)['version']);
        $c->contacts->save(' Earlier ', '555-0101', 'Fictional former address', 7);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $c->contacts->save('Current', '555-0102', 'Fictional current address', 7);
        $events = $this->events($c, $person);
        self::assertSame(['observed', 'observed', 'changed'], array_map(
            static fn($e) => $e->event->payload()['provenance'],
            $events
        ));
        self::assertSame(7, $events[1]->event->payload()['facts']['version']);
        self::assertSame('Fictional former address', $events[1]->event->payload()['facts']['address']);
        self::assertSame(8, $events[2]->event->payload()['facts']['version']);
        self::assertSame('Fictional current address', $c->contacts->view($person)['address']);
    }

    public function testOldRetriesCannotOverwriteLaterContactOrInstitutionalIdChanges(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $c->contacts->save('First', '', 'Fictional first address', 0);
        $c->contacts->save('Later', '', 'Fictional later address', 1);
        $s->admissionsService()->updateInstitutionalId($person, 'PERSON-RETAINED', 0);
        $before = $s->sql->rows('sis_history_events');
        $c->contacts->save('First', '', 'Fictional first address', 0);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        self::assertSame('Fictional later address', $c->contacts->view($person)['address']);
        self::assertSame('PERSON-RETAINED', $s->admissions->person($person)->institutionalId->value);
        try {
            $c->contacts->save('Conflicting', '', '', 0);
            self::fail('A stale competing edit was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
    }

    public function testClearingContactPreservesEarlierFactsAndCoreProfileEditsKeepContactState(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $c->contacts->save('Fictional', '', 'Fictional address', 0);
        $c->contacts->save('', '', '', 1);
        self::assertSame('', $c->contacts->view($person)['address']);
        self::assertSame(2, $c->contacts->view($person)['version']);
        $events = $this->events($c, $person);
        self::assertSame('Fictional address', $events[1]->event->payload()['facts']['address']);
        $s->admissionsService()->updateInstitutionalId($person, 'SAME-PERSON', 0);
        self::assertSame(2, $c->contacts->view($person)['version']);
        self::assertSame(4, $s->profileLedger()->verify($s->admissions->person($person)));
    }

    public function testFailedAuditRollsBackProjectionEventsHeadsAndReceipts(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $s->student();
        $tables = ['sis_portal_contacts', 'sis_history_events', 'sis_history_streams', 'sis_history_operations'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $s->sql->rows($table);
        }
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willThrowException(new RuntimeException('Fictional audit failure'));
        try {
            $this->service($c, $audit)->save('Fictional', '', 'Fictional address', 0);
            self::fail('Committed without its required audit.');
        } catch (RuntimeException) {
            foreach ($before as $table => $rows) {
                self::assertSame($rows, $s->sql->rows($table));
            }
        }
        $c->contacts->save('Fictional', '', 'Fictional address', 0);
        self::assertCount(1, $s->sql->rows('sis_portal_contacts'));
    }

    public function testProjectionFailureCannotLeaveHistoricalEventsCommitted(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $s->student();
        $contacts = $this->createStub(PersonContactRepository::class);
        $contacts->method('save')->willThrowException(new RuntimeException('Fictional projection failure'));
        $before = $s->sql->rows('sis_history_events');
        try {
            $this->service($c, contacts: $contacts)->save('Fictional', '', '', 0);
            self::fail('Committed a partial profile update.');
        } catch (RuntimeException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
            self::assertSame([], $s->sql->rows('sis_history_operations'));
        }
    }

    public function testProjectionDriftIncludingRemovalIsRejectedOnReadAndWrite(): void
    {
        foreach (['altered', 'leading_zero', 'removed'] as $case) {
            $c = new CampusFixture();
            $s = $c->sis;
            $person = $s->student()->personId;
            $c->contacts->save('Recorded', '001234', '', 0);
            if ($case !== 'removed') {
                $s->sql->update('sis_portal_contacts', ['encrypted_contact' => $c->cipher->seal(json_encode([
                    'preferred_name' => $case === 'altered' ? 'Altered' : 'Recorded',
                    'phone' => $case === 'leading_zero' ? '1234' : '001234', 'address' => '',
                ], JSON_THROW_ON_ERROR))], ['person_id' => $person->value]);
            } else {
                $q = $s->sql->quote(...);
                $s->sql->execute(
                    'DELETE FROM ' . $q('sis_portal_contacts') . ' WHERE ' . $q('person_id') . ' = ?',
                    [$person->value]
                );
            }
            $before = $s->sql->rows('sis_history_events');
            foreach (['read', 'write'] as $action) {
                try {
                    $action === 'read' ? $c->contacts->view($person) : $c->contacts->save('Next', '', '', 1);
                    self::fail('Accepted an inconsistent contact projection.');
                } catch (CorruptHistoryException) {
                    self::assertSame($before, $s->sql->rows('sis_history_events'));
                }
            }
        }
    }

    public function testOwnershipIsRecheckedAfterWaitingAndAfterWritesAndStaffCannotReadSomeoneElse(): void
    {
        foreach (['lock', 'audit', 'read'] as $case) {
            $c = new CampusFixture();
            $s = $c->sis;
            $person = $s->student()->personId;
            $other = $s->user();
            $before = $s->sql->rows('sis_history_events');
            $rows = null;
            $audit = null;
            if ($case === 'lock') {
                $rows = $this->createStub(PersonProfileRepository::class);
                $rows->method('lock')->willReturnCallback(static function () use ($s, $other): void {
                    $s->access->actor = $other;
                });
            } elseif ($case === 'audit') {
                $audit = $this->createStub(AuditTrail::class);
                $audit->method('record')->willReturnCallback(static function () use ($s, $other): void {
                    $s->access->actor = $other;
                });
            } else {
                $s->access->actor = $other;
                $s->student();
            }
            try {
                $service = $this->service($c, $audit, rows: $rows);
                $case === 'read' ? $service->view($person) : $service->save('Fictional', '', '', 0);
                self::fail('Used another account\'s person record.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $s->sql->rows('sis_history_events'));
                self::assertSame([], $s->sql->rows('sis_portal_contacts'));
            }
        }
    }

    public function testInvalidValuesAndVersionsDoNotWriteAnyProfileEvents(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $s->student();
        foreach ([-1, PHP_INT_MAX, 0] as $version) {
            try {
                $c->contacts->save(str_repeat('X', 81), '', '', $version);
                self::fail('Accepted invalid contact information.');
            } catch (ValidationException) {
                self::assertSame([], $s->sql->rows('sis_history_events'));
            }
        }
    }

    public function testOwnershipCannotChangeDuringAnUnchangedSave(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $s->student();
        $c->contacts->save('Recorded', '', '', 0);
        $other = $s->user();
        $before = $s->sql->rows('sis_history_events');
        $contacts = $this->createStub(PersonContactRepository::class);
        $contacts->method('current')->willReturnCallback(static function () use ($s, $other): ContactDetails {
            $s->access->actor = $other;
            return new ContactDetails('Recorded', '', '', 1);
        });
        try {
            $this->service($c, contacts: $contacts)->save('Recorded', '', '', 1);
            self::fail('An unchanged save returned success after the signed-in owner changed.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $s->sql->rows('sis_history_events'));
        }
    }

    public function testAnEditCannotCommitHistoryThatWouldMakeTheNextProfileReadExceedItsBudget(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $c->contacts->save('Recorded', '', '', 0);
        $events = $this->events($c, $person);
        $previous = $events[1]->event->payload()['operation_id'];
        for ($version = 2; $version <= 1999; ++$version) {
            $operation = \Domain\Shared\ValueObject\DerivedIdentity::from($person, 'fictional:' . $version);
            $event = PersonContactWasRecorded::occur(new PersonProfileId($person->value), [
                'operation_id' => $operation->value, 'previous_operation_id' => $previous,
                'provenance' => 'changed', 'facts' => new ContactDetails('Recorded ' . $version, '', '', $version)->facts(),
                'intent' => str_repeat('a', 64),
            ], ['__aggregate_type' => 'person_profile'])->withPlayhead($version + 1);
            $events[] = new \Domain\Shared\EventSourcing\StoredEvent(
                $operation,
                $event,
                new \Domain\Shared\EventSourcing\EventContext($s->access->actor, $s->clock->now(), $operation),
                $s->clock->now(),
                str_repeat('0', 64)
            );
            $previous = $operation->value;
        }
        $history = $this->createMock(\Domain\Shared\EventSourcing\HistoricalEventStore::class);
        $history->expects(self::never())->method('append');
        $history->method('page')->willReturnCallback(
            static function (StreamReference $stream, ?string $cursor, int $limit) use ($events): \Domain\Shared\EventSourcing\EventPage {
                $offset = $cursor === null ? 0 : (int) $cursor;
                $next = $offset + $limit;
                return new \Domain\Shared\EventSourcing\EventPage(
                    array_slice($events, $offset, $limit),
                    $next < count($events) ? (string) $next : null,
                    count($events)
                );
            }
        );
        $ledger = new \Domain\Person\Service\ProfileLedger($history, $s->clock);
        $this->expectException(\Domain\Shared\EventSourcing\HistoryReadLimitException::class);
        $ledger->recordContact(
            $s->admissions->person($person),
            new ContactDetails('Recorded 1999', '', '', 1999),
            new ContactDetails('Final edit', '', '', 2000),
            EntityId::generate(),
            $s->access->actor,
            str_repeat('b', 64)
        );
    }
}
