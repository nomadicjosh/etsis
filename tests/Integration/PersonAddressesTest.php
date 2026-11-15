<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Person\ValueObject\AddressBook;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAddressBookRepository;
use PHPUnit\Framework\TestCase;

final class PersonAddressesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }
    private function row(string $purpose = 'mailing', string $from = '2026-08-10', ?string $until = null): array
    {
        return ['id' => EntityId::generate()->value, 'purpose' => $purpose, 'lines' => "Fictional campus\nBuilding 2",
            'locality' => 'Example town', 'region' => 'MA', 'postal_code' => '00123', 'country_code' => 'US',
            'valid_from' => $from, 'valid_until' => $until];
    }
    public function testAddressesAreEncryptedPersonFactsAndMailingHasOneAuthoritativeSource(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $users = $s->sql->rows('users');
        $initial = $c->addresses->view($person);
        self::assertSame([], $initial['addresses']);
        self::assertCount(10, $initial['new_ids']);
        self::assertSame('Dominican Republic', $initial['countries']['DO']);
        self::assertSame([], $s->sql->rows('sis_history_events'));
        $c->contacts->save('Preferred', '001234', 'Earlier unstructured address', 0);
        $home = $this->row('home');
        $c->addresses->save([$home], 0);
        self::assertFalse($c->addresses->view($person)['mailing_managed']);
        self::assertSame('Earlier unstructured address', $c->contacts->view($person)['address']);
        $c->contacts->save('Preferred', '001234', 'Earlier changed address', 1);
        $before = $s->sql->rows('sis_history_events');
        $mailing = $this->row();
        $c->addresses->save([$home, $mailing], 1);
        $current = $c->addresses->view($person);
        self::assertTrue($current['mailing_managed']);
        self::assertSame('', $current['earlier_mailing_address']);
        self::assertSame("Fictional campus\nBuilding 2\nExample town, MA 00123\nUnited States", $current['mailing_address']);
        self::assertSame($current['mailing_address'], $c->contacts->view($person)['address']);
        $c->contacts->save('Later preferred', '005678', $current['mailing_address'], 3);
        $rawContact = new \Infrastructure\Persistence\Repository\SqlPersonContactRepository($s->sql, $c->cipher)->current($person);
        self::assertSame('', $rawContact->address);
        $afterHandoff = $s->sql->rows('sis_history_events');
        $c->contacts->save('Preferred', '001234', 'Earlier changed address', 1);
        self::assertSame($afterHandoff, $s->sql->rows('sis_history_events'));
        self::assertSame('Later preferred', $c->contacts->view($person)['preferred_name']);

        self::assertSame(2, $c->addresses->view($person)['version']);
        foreach ($before as $row) {
            self::assertContains($row, $s->sql->rows('sis_history_events'));
        }
        foreach (['sis_person_addresses', 'sis_history_events', 'sis_audit'] as $table) {
            $json = json_encode($s->sql->rows($table), JSON_THROW_ON_ERROR);
            foreach (['Fictional campus', '00123', 'Example town'] as $private) {
                self::assertStringNotContainsString($private, $json);
            }
        }
        self::assertSame($users, $s->sql->rows('users'));
        $this->expectException(ValidationException::class);
        $c->contacts->save('Later preferred', '005678', 'Competing unstructured address', 4);
    }
    public function testDatedMailingChangesOnCalendarBoundariesWithoutWritingEventsOrResurrectingOldText(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $c->contacts->save('Preferred', '', 'Earlier unstructured address', 0);
        $current = $this->row('mailing', '2026-08-10', '2026-08-31');
        $future = $this->row('mailing', '2026-09-01');
        $future['lines'] = 'Fictional next home';
        $c->addresses->save([$current, $future], 0);
        $before = $s->sql->rows('sis_history_events');
        $s->clock->time = $s->clock->time->modify('2026-08-31');
        self::assertStringContainsString('Building 2', $c->contacts->view($person)['address']);
        $s->clock->time = $s->clock->time->modify('2026-09-01');
        self::assertStringContainsString('Fictional next home', $c->contacts->view($person)['address']);
        self::assertSame($before, $s->sql->rows('sis_history_events'));
        $c->addresses->save([], 1);
        self::assertTrue($c->addresses->view($person)['mailing_managed']);
        self::assertSame('', $c->contacts->view($person)['address']);
        $events = $s->sql->rows('sis_history_events');
        $c->addresses->save([$current, $future], 0);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertSame([], $c->addresses->view($person)['addresses']);
    }
    public function testContactRetryAfterAChangedAddressReturnsItsOriginalResultAndKeepsTheNewAddress(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $first = $this->row();
        $c->addresses->save([$first], 0);
        $mail = $c->contacts->view($person)['address'];
        $c->contacts->save('Preferred', '0001', $mail, 0);
        $later = $first;
        $later['lines'] = 'Fictional later address';
        $c->addresses->save([$later], 1);
        $events = $s->sql->rows('sis_history_events');
        $c->contacts->save('Preferred', '0001', $mail, 0);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        self::assertStringContainsString('Fictional later address', $c->contacts->view($person)['address']);
    }
    public function testOverlapsDuplicatesInvalidDatesAndInvalidCountriesAreRejected(): void
    {
        $row = $this->row();
        $other = $this->row();
        foreach (
            [[$row, $other], [$row, $row], [array_replace($row, ['valid_from' => '2026-02-29'])],
            [array_replace($row, ['valid_until' => '2026-08-09'])],
            [array_replace($row, ['country_code' => 'ZZ'])],
            [array_replace($row, ['lines' => "bad\0value"])],
            array_fill(0, 11, $row)] as $input
        ) {
            try {
                AddressBook::fromInput($input, 1, false);
                self::fail('Invalid addresses were accepted.');
            } catch (ValidationException $e) {
                self::assertNotEmpty($e->errors);
            }
        }
        $home = $other;
        $home['purpose'] = 'home';
        self::assertCount(2, AddressBook::fromInput([$row, $home], 1, false)->addresses);
        $until = $row;
        $until['valid_until'] = '2026-08-10';
        $next = $other;
        $next['valid_from'] = '2026-08-11';
        self::assertCount(2, AddressBook::fromInput([$until, $next], 1, false)->addresses);
    }
    public function testStaleEditsFailAndUnchangedSavesOrEmptyInitialSavesWriteNothing(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $c->addresses->save([], 0);
        self::assertSame([], $s->sql->rows('sis_history_events'));
        $row = $this->row();
        $c->addresses->save([$row], 0);
        $events = $s->sql->rows('sis_history_events');
        $c->addresses->save([$row], 1);
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        $row['lines'] = 'Fictional changed';
        $this->expectException(ConcurrentModificationException::class);
        $c->addresses->save([$row], 0);
    }
    public function testOwnerRoutesNeverBorrowStaffAuthorityAndStaffRoutesRequireExplicitPermission(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student($s->user())->personId;
        try {
            $c->addresses->view($person);
            self::fail('Another owner was read.');
        } catch (AccessDeniedException) {
            self::assertSame([], $s->sql->rows('sis_person_addresses'));
        }
        $s->access->staff = false;
        $s->access->grants = ['sis:person:manage'];
        $c->addresses->save([$this->row()], 0, $person);
        self::assertCount(1, $c->addresses->view($person, true)['addresses']);
        $s->access->grants = ['admin:edit:user'];
        $this->expectException(AccessDeniedException::class);
        $c->addresses->view($person, true);
    }
    public function testSuperRecordsKeepTheirProtection(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $student = $s->student($s->user());
        $p = $s->admissions->person($student->personId);
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        $roles->assign(\Domain\Access\Enum\GrantKind::UserRole, $p->ownerUserId, $roles->roleNamed('super')->id, true);
        $this->expectException(AccessDeniedException::class);
        $c->addresses->view($p->id, true);
    }
    public function testProjectionDeletionAndCrossPersonEnvelopeSubstitutionFailClosed(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $p = $s->student()->personId;
        $other = $s->student($s->user())->personId;
        $c->addresses->save([$this->row()], 0);
        $c->addresses->save([$this->row('home')], 0, $other);
        $a = $s->sql->one('sis_person_addresses', ['person_id' => $p->value]);
        $b = $s->sql->one('sis_person_addresses', ['person_id' => $other->value]);
        $s->sql->update('sis_person_addresses', ['encrypted_addresses' => $b['encrypted_addresses']], ['person_id' => $p->value]);
        try {
            $c->addresses->view($p);
            self::fail('A foreign address envelope was accepted.');
        } catch (CorruptHistoryException) {
            self::assertNotSame($a['encrypted_addresses'], $b['encrypted_addresses']);
        }
        $s->sql->execute('DELETE FROM sis_person_addresses WHERE person_id = ?', [$p->value]);
        $this->expectException(CorruptHistoryException::class);
        $c->contacts->view($p);
    }
    public function testEarlierUntrackedMailingTextIsObservedNowWithoutInventingEarlierDates(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $s->sql->insert('sis_portal_contacts', ['id' => EntityId::generate()->value, 'person_id' => $person->value,
            'version' => 7, 'encrypted_contact' => $c->cipher->seal(json_encode(['preferred_name' => 'Earlier',
                'phone' => '001234', 'address' => 'Earlier untracked text'], JSON_THROW_ON_ERROR))]);
        $c->addresses->save([$this->row()], 0);
        $db = $s->connection->queryBuilder();
        $history = new \Infrastructure\EventSourcing\SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            new \Infrastructure\EventSourcing\ProtectedEventCodec(\EventStoreFixture::config($db)),
            new \Infrastructure\EventSourcing\HistoricalSchemaRegistry()
        );
        $events = $history->page(new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\Person\ValueObject\PersonProfileId($person->value),
            'person_profile'
        ))->events;
        self::assertCount(4, $events);
        self::assertSame('observed', $events[0]->event->payload()['provenance']);
        self::assertSame('observed', $events[1]->event->payload()['provenance']);
        self::assertSame('Earlier untracked text', $events[1]->event->payload()['facts']['address']);
        self::assertSame(8, $c->contacts->view($person)['version']);
        self::assertSame('2026-08-10', $events[3]->event->payload()['facts']['addresses'][0]['valid_from']);
    }
    public function testAuditFailureOrRevokedAuthorityRollsBackAllCoupledFacts(): void
    {
        foreach (['audit', 'permission'] as $case) {
            $c = new CampusFixture();
            $s = $c->sis;
            $person = $s->student()->personId;
            $c->contacts->save('Preferred', '0001', 'Fictional earlier address', 0);
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($case, $s): void {
                if ($case === 'audit') {
                    throw new \RuntimeException('Fictional audit failure');
                }
                $s->access->staff = false;
                $s->access->grants = [];
            });
            $access = new \Domain\Person\Service\PersonProfileAccess(
                new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
                    $s->sql,
                    $s->admissions,
                    new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
                ),
                $s->admissions,
                new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
                $s->access
            );
            $service = new \Domain\Person\Service\PersonAddressService(
                new SqlAddressBookRepository($s->sql, $c->cipher),
                new \Infrastructure\Persistence\Repository\SqlPersonContactRepository($s->sql, $c->cipher),
                new \Infrastructure\Persistence\Repository\SqlPersonProfileRepository($s->sql),
                $s->profileLedger(),
                $access,
                $s->transactions,
                $audit,
                $s->clock
            );
            $tables = ['sis_portal_contacts', 'sis_person_addresses', 'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_audit'];
            $before = array_map(fn($t) => $s->sql->rows($t), $tables);
            try {
                $service->save([$this->row()], 0, $person);
                self::fail('An incomplete address change was committed.');
            } catch (\RuntimeException $e) {
                self::assertNotEmpty($e->getMessage());
            }
            self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
        }
    }
    public function testMaximumUnicodeAddressesRemainReadableAfterEncryptedPersistence(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->student()->personId;
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $date = '2026-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '-01';
            $row = $this->row('mailing', $date, $date);
            $row['lines'] = str_repeat('🗺', 500);
            $row['locality'] = str_repeat('🗺', 120);
            $row['region'] = str_repeat('🗺', 120);
            $row['postal_code'] = str_repeat('🗺', 32);
            $rows[] = $row;
        }
        try {
            $c->addresses->save($rows, 0);
            self::fail('An oversized address list was committed.');
        } catch (ValidationException) {
            self::assertSame([], $s->sql->rows('sis_history_events'));
        }
        foreach ($rows as &$row) {
            $row['lines'] = str_repeat('🗺', 150);
        }
        unset($row);
        $c->addresses->save($rows, 0);
        self::assertCount(10, $c->addresses->view($person)['addresses']);
        $book = new SqlAddressBookRepository($s->sql, $c->cipher)->current($person);
        self::assertSame($rows, $book->addresses);
    }
}
