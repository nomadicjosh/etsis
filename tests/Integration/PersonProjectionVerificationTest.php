<?php

declare(strict_types=1);

namespace Tests\Integration;

use CampusFixture;
use Domain\Person\Person;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\SqlAddressBookRepository;
use Infrastructure\Persistence\Repository\SqlEducationBackgroundRepository;
use Infrastructure\Persistence\Repository\SqlEmergencyContactsRepository;
use Infrastructure\Persistence\Repository\SqlPersonContactRepository;
use Infrastructure\Projection\SqlPersonProjectionVerifier;
use PHPUnit\Framework\TestCase;

final class PersonProjectionVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/CampusFixture.php';
    }

    private function service(CampusFixture $c, ?HistoricalEventStore $history = null): SqlPersonProjectionVerifier
    {
        $s = $c->sis;
        return new SqlPersonProjectionVerifier(
            $s->sql,
            $history ?? EventStoreFixture::history($s->connection->queryBuilder()),
            new SqlPersonContactRepository($s->sql, $c->cipher),
            new SqlAddressBookRepository($s->sql, $c->cipher),
            new SqlEmergencyContactsRepository($s->sql, $c->cipher),
            new SqlEducationBackgroundRepository($s->sql, $c->cipher)
        );
    }

    private function person(CampusFixture $c): Person
    {
        $s = $c->sis;
        $person = $s->registrationService()->register(
            EntityId::generate(),
            'Fictional',
            'Profile',
            'profile@example.invalid',
            'fictional_profile',
            \Codefy\Framework\Support\Password::hash('fictional-profile-password-long-enough')
        );
        $s->access->actor = $person->ownerUserId;
        return $person;
    }

    private function fullProfile(CampusFixture $c): Person
    {
        $person = $this->person($c);
        $c->contacts->save('Fictional preferred', '0001234', 'Fictional earlier address', 0);
        $c->addresses->save([[
            'id' => EntityId::generate()->value, 'purpose' => 'mailing', 'lines' => 'Fictional campus',
            'locality' => 'Example', 'region' => 'MA', 'postal_code' => '00123', 'country_code' => 'US',
            'valid_from' => '2026-01-01', 'valid_until' => null,
        ]], 0);
        $c->emergency->save([['name' => 'Fictional contact', 'relationship' => 'Parent',
            'phone' => '0004567', 'email' => 'emergency@example.invalid']], 0);
        $c->education->save([[
            'key' => 'fictional_college', 'institution' => 'Fictional College', 'institution_code' => 'EXAMPLE',
            'start_date' => '2020-08-01', 'end_date' => '2024-05-31', 'major' => 'Arts',
            'degree' => 'Diploma', 'conferred_date' => '2024-06-01', 'gpa' => '3.75', 'gpa_max' => '4.0',
        ]], 0, '');
        return $person;
    }

    private function digest(CampusFixture $c): array
    {
        return array_map($c->sis->sql->rows(...), [
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_persons',
            'sis_person_identifiers', 'sis_portal_contacts', 'sis_person_addresses',
            'sis_person_emergency_contacts', 'sis_person_education', 'users', 'sis_audit',
        ]);
    }

    public function testEveryCurrentProfileComponentVerifiesWithWritesProhibitedAndValueFreeOutput(): void
    {
        $c = new CampusFixture();
        $person = $this->fullProfile($c);
        $before = $this->digest($c);
        $c->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->service($c)->verify($person->id);
        self::assertSame('verified', $result->status);
        self::assertSame(6, $result->events);
        self::assertSame(6, $result->position);
        self::assertFalse($result->earlierHistoryUnknown);
        self::assertSame([], $result->unrecordedComponents);
        self::assertSame($before, $this->digest($c));
        $json = json_encode($result->describe(), JSON_THROW_ON_ERROR);
        foreach (['Fictional', 'Profile', '0001234', '00123', 'emergency@', 'sealed', 'password', '3.75'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testCreatedPersonWithoutOptionalRowsIsKnownEmptyWithoutCreatingAStudent(): void
    {
        $c = new CampusFixture();
        $person = $this->person($c);
        $before = $this->digest($c);
        self::assertSame('verified', $this->service($c)->verify($person->id)->status);
        self::assertSame([], $c->sis->sql->rows('sis_students'));
        self::assertSame($before, $this->digest($c));
    }

    public function testEarlierUntrackedAndObservedProfilesRemainHistoryGaps(): void
    {
        $c = new CampusFixture();
        $s = $c->sis;
        $person = $s->admissions->person($s->student()->personId);
        $s->sql->update(
            'users',
            ['first_name' => $person->givenName, 'last_name' => $person->familyName],
            ['user_id' => $person->ownerUserId->value]
        );
        $before = $this->digest($c);
        $result = $this->service($c)->verify($person->id);
        self::assertSame('history_gaps', $result->status, json_encode($result->describe()));
        self::assertSame(['identity'], $result->unrecordedComponents);
        self::assertTrue($result->earlierHistoryUnknown);
        self::assertSame($before, $this->digest($c));
        $c->contacts->save('Fictional preferred', '', '', 0);
        $before = $this->digest($c);
        $result = $this->service($c)->verify($person->id);
        self::assertSame('history_gaps', $result->status, json_encode($result->describe()));
        self::assertSame([], $result->unrecordedComponents);
        self::assertTrue($result->earlierHistoryUnknown);
        self::assertSame($before, $this->digest($c));
    }

    public function testMissingAndTamperedProjectionsAreNamedWithoutRepairOrRawValues(): void
    {
        foreach (
            [
            'contact' => 'sis_portal_contacts', 'addresses' => 'sis_person_addresses',
            'emergency_contacts' => 'sis_person_emergency_contacts', 'education' => 'sis_person_education',
            ] as $component => $table
        ) {
            $c = new CampusFixture();
            $person = $this->fullProfile($c);
            $c->sis->sql->update($table, ['version' => 999], ['person_id' => $person->id->value]);
            $before = $this->digest($c);
            $result = $this->service($c)->verify($person->id);
            self::assertSame('unverified', $result->status, $component);
            self::assertSame([$component], $result->issues);
            self::assertNull($result->earlierHistoryUnknown);
            self::assertSame($before, $this->digest($c));
            $c->sis->sql->execute('DELETE FROM ' . $table . ' WHERE person_id = ?', [$person->id->value]);
            $before = $this->digest($c);
            self::assertSame([$component], $this->service($c)->verify($person->id)->issues);
            self::assertSame($before, $this->digest($c));
        }
    }

    public function testIdentityPrivacyIdentifierAndLinkedAccountDriftAreIndependentComponents(): void
    {
        foreach (
            [
            ['sis_persons', 'given_name', 'Altered private name', 'identity'],
            ['sis_persons', 'directory_restricted', 0, 'identity'],
            ['sis_person_identifiers', 'institutional_id', 'ALTERED-PRIVATE-ID', 'identity'],
            ['sis_person_identifiers', 'version', 99, 'identity'],
            ['users', 'first_name', 'Altered private name', 'account_link'],
            ] as [$table, $field, $value, $issue]
        ) {
            $c = new CampusFixture();
            $person = $this->person($c);
            $where = match ($table) {
                'sis_persons' => ['id' => $person->id->value],
                'users' => ['user_id' => $person->ownerUserId->value],
                default => ['person_id' => $person->id->value],
            };
            $c->sis->sql->update($table, [$field => $value], $where);
            $before = $this->digest($c);
            $result = $this->service($c)->verify($person->id);
            self::assertSame('unverified', $result->status, $field);
            $expected = $field === 'given_name' ? ['identity', 'account_link'] : [$issue];
            self::assertSame($expected, $result->issues);
            self::assertStringNotContainsString('PRIVATE', json_encode($result->describe()));
            self::assertSame($before, $this->digest($c));
        }
    }

    public function testCorruptCiphertextsAndUnexpectedNewProjectionRowsAreNotAcceptedAsEmpty(): void
    {
        foreach (
            [
            'contact' => ['sis_portal_contacts', 'encrypted_contact'],
            'addresses' => ['sis_person_addresses', 'encrypted_addresses'],
            'emergency_contacts' => ['sis_person_emergency_contacts', 'encrypted_contacts'],
            'education' => ['sis_person_education', 'encrypted_background'],
            ] as $component => [$table, $column]
        ) {
            $c = new CampusFixture();
            $person = $this->fullProfile($c);
            $c->sis->sql->update(
                $table,
                [$column => 'Fictional private damaged payload'],
                ['person_id' => $person->id->value]
            );
            $before = $this->digest($c);
            $result = $this->service($c)->verify($person->id);
            self::assertSame([$component], $result->issues);
            self::assertSame($before, $this->digest($c));
            self::assertStringNotContainsString('damaged payload', json_encode($result->describe()));
        }
        $c = new CampusFixture();
        $person = $this->person($c);
        new SqlEducationBackgroundRepository($c->sis->sql, $c->cipher)->save(
            $person->id,
            \Domain\Person\ValueObject\EducationBackground::fromInput([], 1),
            0
        );
        self::assertSame(['education'], $this->service($c)->verify($person->id)->issues);
    }

    public function testExistingUntrackedContactIsExplicitlyUnknownAndNeverAdopted(): void
    {
        $c = new CampusFixture();
        $person = $this->person($c);
        new SqlPersonContactRepository($c->sis->sql, $c->cipher)->save(
            $person->id,
            new \Domain\Person\ValueObject\ContactDetails('Earlier preferred', '', 'Earlier text', 1),
            0
        );
        $before = $this->digest($c);
        $result = $this->service($c)->verify($person->id);
        self::assertSame('history_gaps', $result->status);
        self::assertSame(['contact'], $result->unrecordedComponents);
        self::assertSame(1, $result->events);
        self::assertFalse($result->earlierHistoryUnknown);
        self::assertSame($before, $this->digest($c));
    }

    public function testMissingPersonDoesNotReplayOrCertifyAnEmptyProfile(): void
    {
        $c = new CampusFixture();
        $store = $this->createMock(HistoricalEventStore::class);
        $store->expects(self::never())->method('page');
        $before = $this->digest($c);
        $result = $this->service($c, $store)->verify(new EntityId('00000000000000000000000000'));
        self::assertSame('unverified', $result->status);
        self::assertSame(['identity'], $result->issues);
        self::assertSame($before, $this->digest($c));
    }

    public function testReplayUsesOneSharedAllowanceAndRejectsInvalidBoundsBeforeStorage(): void
    {
        $c = new CampusFixture();
        $person = $this->person($c);
        for ($n = 0; $n < 20; $n++) {
            $c->contacts->save('Fictional revision ' . $n, '', '', $n);
        }
        $store = $this->createMock(HistoricalEventStore::class);
        $store->expects(self::never())->method('page');
        $before = $this->digest($c);
        $result = $this->service($c, $store)->verify($person->id, 20);
        self::assertSame('limited', $result->status);
        self::assertSame(0, $result->events);
        $history = EventStoreFixture::history($c->sis->connection->queryBuilder());
        $pages = 0;
        $wrapper = $this->createStub(HistoricalEventStore::class);
        $wrapper->method('page')->willReturnCallback(static function (
            $stream,
            $cursor,
            $limit
        ) use (
            $history,
            &$pages
        ) {
            $pages++;
            return $history->page($stream, $cursor, $limit);
        });
        $result = $this->service($c, $wrapper)->verify($person->id, 21);
        self::assertSame('verified', $result->status);
        self::assertSame(21, $result->events);
        self::assertSame(2, $pages);
        self::assertSame($before, $this->digest($c));
        foreach ([0, 2001] as $limit) {
            try {
                $this->service($c, $store)->verify($person->id, $limit);
                self::fail('An invalid replay allowance was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, $this->digest($c));
            }
        }
    }

    public function testConcurrentAcceptedContactEditReturnsChangedWithoutPartialDiagnostics(): void
    {
        $c = new CampusFixture();
        $person = $this->person($c);
        $history = EventStoreFixture::history($c->sis->connection->queryBuilder());
        $wrapper = $this->createStub(HistoricalEventStore::class);
        $wrapper->method('page')->willReturnCallback(static function ($stream, $cursor, $limit) use ($history, $c) {
            $page = $history->page($stream, $cursor, $limit);
            $c->contacts->save('Fictional concurrent edit', '', '', 0);
            return $page;
        });
        $result = $this->service($c, $wrapper)->verify($person->id);
        self::assertSame('changed', $result->status);
        self::assertSame([], $result->issues);
        self::assertSame([], $result->unrecordedComponents);
        self::assertNull($result->earlierHistoryUnknown);
        self::assertSame('verified', $this->service($c)->verify($person->id)->status);
    }

    public function testProjectionOnlyChangeIsDetectedButUnrelatedAndCredentialActivityIsExcluded(): void
    {
        foreach ([false, true] as $selected) {
            $c = new CampusFixture();
            $person = $this->person($c);
            $other = $c->sis->user();
            $history = EventStoreFixture::history($c->sis->connection->queryBuilder());
            $wrapper = $this->createStub(HistoricalEventStore::class);
            $wrapper->method('page')->willReturnCallback(static function (
                $stream,
                $cursor,
                $limit
            ) use (
                $history,
                $c,
                $person,
                $other,
                $selected
            ) {
                $page = $history->page($stream, $cursor, $limit);
                $c->sis->sql->update(
                    'users',
                    ['first_name' => 'Fictional concurrent name'],
                    ['user_id' => ($selected ? $person->ownerUserId : $other)->value]
                );
                $c->sis->sql->update(
                    'users',
                    ['token' => 'fictional-private-token'],
                    ['user_id' => $person->ownerUserId->value]
                );
                return $page;
            });
            $result = $this->service($c, $wrapper)->verify($person->id);
            self::assertSame($selected ? 'changed' : 'verified', $result->status);
            self::assertSame([], $result->issues);
            self::assertStringNotContainsString('private-token', json_encode($result->describe()));
        }
    }

    public function testDamagedHistoryAndPrivateProviderErrorsRemainValueFree(): void
    {
        $c = new CampusFixture();
        $person = $this->person($c);
        $head = $c->sis->sql->rows('sis_history_streams', ['stream_type' => 'person_profile'])[0];
        $c->sis->sql->update(
            'sis_history_events',
            ['sealed_event' => 'Fictional damaged private history'],
            ['stream_id' => $head['id']]
        );
        $before = $this->digest($c);
        self::assertSame(['journal'], $this->service($c)->verify($person->id)->issues);
        self::assertSame($before, $this->digest($c));
        $store = $this->createStub(HistoricalEventStore::class);
        $store->method('page')->willThrowException(new \RuntimeException('Fictional private provider failure'));
        $result = $this->service($c, $store)->verify($person->id);
        self::assertSame(['unavailable'], $result->issues);
        self::assertStringNotContainsString('provider failure', json_encode($result->describe()));
        self::assertSame($before, $this->digest($c));
    }

    public function testOversizedPayloadAndUnfinishedReplayNeverReturnPartialChecks(): void
    {
        $c = new CampusFixture();
        $person = $this->person($c);
        $before = $this->digest($c);
        $store = $this->createStub(HistoricalEventStore::class);
        $store->method('page')->willReturn(new \Domain\Shared\EventSourcing\EventPage([
            new \Domain\Shared\EventSourcing\StoredEvent(
                EntityId::generate(),
                \Domain\Person\Event\PersonProfileWasRecorded::occur(
                    new \Domain\Person\ValueObject\PersonProfileId($person->id->value),
                    [],
                    ['fictional_private_padding' => str_repeat('x', 4_194_305)]
                ),
                new \Domain\Shared\EventSourcing\EventContext(
                    $person->ownerUserId,
                    $c->sis->clock->now(),
                    EntityId::generate()
                ),
                $c->sis->clock->now(),
                str_repeat('0', 64)
            ),
        ], null, 1));
        self::assertSame('limited', $this->service($c, $store)->verify($person->id)->status);
        $empty = $this->createStub(HistoricalEventStore::class);
        $empty->method('page')->willReturn(new \Domain\Shared\EventSourcing\EventPage([], null, 1));
        self::assertSame(['journal'], $this->service($c, $empty)->verify($person->id)->issues);
        self::assertSame($before, $this->digest($c));
    }

    public function testExistingTransactionCannotHideConcurrentChangesBehindASnapshot(): void
    {
        $c = new CampusFixture();
        $person = $this->person($c);
        $before = $this->digest($c);
        $c->sis->connection->pdo->beginTransaction();
        try {
            self::assertSame(['journal'], $this->service($c)->verify($person->id)->issues);
            self::assertSame($before, $this->digest($c));
        } finally {
            $c->sis->connection->pdo->rollBack();
        }
    }
}
