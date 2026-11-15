<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\ActorDirectory;
use Domain\Person\Service\PersonContactService;
use Domain\Person\Service\PersonHistoryReview;
use Domain\Person\Service\PersonProfileAccess;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\HistoricalSchemaRegistry;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\EventSourcing\SqlHistoricalEventStore;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlPersonContactRepository;
use Infrastructure\Persistence\Repository\SqlPersonProfileRepository;
use Infrastructure\Persistence\SqlActorDirectory;
use PersonManagementFixture;
use EventStoreFixture;
use PHPUnit\Framework\TestCase;

final class PersonHistoryReviewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PersonManagementFixture.php';
    }
    private function access(PersonManagementFixture $f): PersonProfileAccess
    {
        $s = $f->sis;
        return new PersonProfileAccess(new SqlPersonAccountRepository(
            $s->sql,
            $s->admissions,
            new RandomInstitutionalIdGenerator()
        ), $s->admissions, $f->access, $s->access);
    }
    private function store(PersonManagementFixture $f): SqlHistoricalEventStore
    {
        $s = $f->sis;
        return new SqlHistoricalEventStore(
            $s->sql,
            $s->transactions,
            $s->clock,
            new ProtectedEventCodec(EventStoreFixture::config($s->connection->queryBuilder())),
            new HistoricalSchemaRegistry()
        );
    }
    private function review(
        PersonManagementFixture $f,
        ?HistoricalEventStore $history = null,
        ?ActorDirectory $actors = null
    ): PersonHistoryReview {
        return new PersonHistoryReview(
            $history ?? $this->store($f),
            $this->access($f),
            $f->sis->access,
            $actors ?? new SqlActorDirectory($f->sis->sql)
        );
    }
    private function contacts(PersonManagementFixture $f): PersonContactService
    {
        $s = $f->sis;
        return new PersonContactService(
            new SqlPersonContactRepository($s->sql, $s->dropCipher),
            new SqlPersonProfileRepository($s->sql),
            $s->profileLedger(),
            $this->access($f),
            $s->transactions,
            $s->audit,
            new \Infrastructure\Persistence\Repository\SqlAddressBookRepository($s->sql, $s->dropCipher),
            $s->clock
        );
    }
    public function testReviewSummarizesChangedFieldsWithoutValuesReasonsCredentialsOrWrites(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $s = $f->sis;
        $review = $this->review($f);
        $events = $s->sql->rows('sis_history_events');
        self::assertSame(['items' => [], 'through_version' => 0, 'next_after' => null,
            'earlier_history_unknown' => true], $review->page($p->id));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
        $f->profiles->save($p->id, 'PrivateGiven', 'PrivateFamily', false, $f->profiles->view($p->id)['revision']);
        $contacts = $this->contacts($f);
        $contacts->staffSave($p->id, 'Private preferred', '000secret', 'Private mailing address', 0);
        $contacts->staffSave($p->id, 'Private preferred', '000changed', 'Private mailing address', 1);
        $emergency = new \Domain\Person\Service\EmergencyContactsService(
            new \Infrastructure\Persistence\Repository\SqlEmergencyContactsRepository($s->sql, $s->dropCipher),
            new SqlPersonProfileRepository($s->sql),
            $s->profileLedger(),
            $this->access($f),
            $s->transactions,
            $s->audit
        );
        $emergency->staffSave($p->id, [['name' => 'Private Relative', 'phone' => '000relative']], 0);
        // The User credential stream is deliberately outside this metadata review.
        $f->accounts->email($p->id, 'private-new@example.invalid', $f->accounts->view($p->id)['version']);
        $s->sql->update(
            'users',
            ['first_name' => 'Fictional', 'last_name' => 'Reviewer'],
            ['user_id' => $s->access->actor->value]
        );
        $tables = ['sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_audit',
            'sis_persons', 'users', 'sis_portal_contacts', 'sis_person_emergency_contacts'];
        $before = array_map(fn($t) => $s->sql->rows($t), $tables);
        $page = $review->page($p->id);
        self::assertSame($before, array_map(fn($t) => $s->sql->rows($t), $tables));
        self::assertSame([5, 4, 3, 2, 1], array_column($page['items'], 'sequence'));
        self::assertSame(['Emergency contact details or priority order'], $page['items'][0]['fields']);
        self::assertSame(['Phone number'], $page['items'][1]['fields']);
        self::assertSame(['Given name', 'Family name', 'Directory privacy'], $page['items'][3]['fields']);
        self::assertSame('Fictional Reviewer', $page['items'][0]['recorded_by']);
        self::assertTrue($page['items'][4]['earlier_details_unknown']);
        self::assertSame('Existing details first recorded', $page['items'][4]['action']);
        self::assertSame([], $page['items'][4]['fields']);
        self::assertTrue($page['earlier_history_unknown']);
        $json = json_encode($page, JSON_THROW_ON_ERROR);
        $account = $s->sql->one('users', ['user_id' => $p->ownerUserId->value]);
        foreach (
            ['PrivateGiven', 'PrivateFamily', 'Private preferred', 'Private mailing address',
            'Private Relative', '000relative', '000secret', '000changed', 'private-new@example.invalid',
            'Current profile observed', 'payload', 'intent', 'operation_id', 'aggregate_id',
            $account['password'], $account['token'], $p->id->value] as $private
        ) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertStringEndsWith('Z', $page['items'][0]['recorded_at']);
        self::assertStringEndsWith('Z', $page['items'][0]['effective_at']);
    }
    public function testNewestFirstPagesKeepTheirOriginalBoundaryAfterLaterChanges(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $contacts = $this->contacts($f);
        for ($version = 0; $version < 24; $version++) {
            $contacts->staffSave($p->id, '', 'fictional-' . $version, '', $version);
        }
        $reader = $this->review($f);
        $first = $reader->page($p->id);
        self::assertSame(25, $first['through_version']);
        self::assertSame(range(25, 6), array_column($first['items'], 'sequence'));
        self::assertSame(20, $first['next_after']);
        $contacts->staffSave($p->id, '', 'fictional-later', '', 24);
        $last = $reader->page($p->id, $first['next_after'], $first['through_version']);
        self::assertSame(range(5, 1), array_column($last['items'], 'sequence'));
        self::assertNull($last['next_after']);
        self::assertSame(25, $last['through_version']);
        self::assertSame(26, $reader->page($p->id)['through_version']);
    }
    public function testOwnershipOrEitherIndividualPermissionDoesNotAuthorizeAHistoryReview(): void
    {
        foreach ([[], ['sis:person:manage'], ['sis:audit:read']] as $grants) {
            $f = new PersonManagementFixture();
            $p = $f->person();
            $f->sis->access->actor = $p->ownerUserId;
            $f->sis->access->staff = false;
            $f->sis->access->grants = $grants;
            $history = $this->createMock(HistoricalEventStore::class);
            $history->expects(self::never())->method('page');
            try {
                $this->review($f, $history)->page($p->id);
                self::fail('Partial authority reached protected history.');
            } catch (AccessDeniedException) {

            }
        }
    }
    public function testSuperPersonProtectionAppliesEvenWithBothHistoryPermissions(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $f->access->assign(
            \Domain\Access\Enum\GrantKind::UserRole,
            $p->ownerUserId,
            $f->access->roleNamed('super')->id,
            true
        );
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::never())->method('page');
        $this->expectException(AccessDeniedException::class);
        $this->review($f, $history)->page($p->id);
    }
    public function testAuthorityAndActorAreRecheckedAfterReadingCurrentRecorderNames(): void
    {
        foreach (['actor', 'permission'] as $case) {
            $f = new PersonManagementFixture();
            $p = $f->person();
            $f->profiles->save($p->id, 'Changed', 'Example', true, $f->profiles->view($p->id)['revision']);
            $actors = $this->createStub(ActorDirectory::class);
            $actors->method('names')->willReturnCallback(static function () use ($f, $case): array {
                if ($case === 'actor') {
                    $f->sis->access->actor = EntityId::generate();
                } else {
                    $f->sis->access->staff = false;
                    $f->sis->access->grants = ['sis:person:manage'];
                }
                return [];
            });
            try {
                $this->review($f, actors: $actors)->page($p->id);
                self::fail('A stale reviewer received private history.');
            } catch (AccessDeniedException) {
                self::assertTrue(true);
            }
        }
    }
    public function testNewlyCreatedPeopleHaveRealCreationEvidenceRatherThanUnknownEarlierHistory(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->sis->registrationService()->staff(
            EntityId::generate(),
            'Fictional',
            'Applicant',
            'new@example.invalid'
        );
        $page = $this->review($f)->page($p->id);
        self::assertFalse($page['earlier_history_unknown']);
        self::assertCount(1, $page['items']);
        self::assertSame('Created', $page['items'][0]['action']);
    }
    public function testMissingRecorderNamesRemainExplicitlyUnknown(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $f->profiles->save($p->id, 'Changed', 'Example', true, $f->profiles->view($p->id)['revision']);
        $actors = $this->createStub(ActorDirectory::class);
        $actors->method('names')->willReturn([]);
        self::assertSame(
            'Unavailable account',
            $this->review($f, actors: $actors)->page($p->id)['items'][0]['recorded_by']
        );
    }
    public function testMalformedPagingAndOversizedHistoriesStopWithoutPartialResults(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        foreach ([[1, null], [20, null], [0, -1], [0, 2001], [20, 0]] as [$after, $through]) {
            try {
                $this->review($f)->page($p->id, $after, $through);
                self::fail('Invalid paging was accepted.');
            } catch (\InvalidArgumentException) {

            }
        }
        $history = $this->createMock(HistoricalEventStore::class);
        $history->expects(self::once())->method('page')->willReturn(
            new \Domain\Shared\EventSourcing\EventPage([], null, 2001)
        );
        $this->expectException(\Domain\Shared\EventSourcing\HistoryReadLimitException::class);
        $this->review($f, $history)->page($p->id);
    }
    public function testChangedCurrentIdentityCannotBypassTheProtectedProfileBoundary(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $f->profiles->save($p->id, 'Changed', 'Example', true, $f->profiles->view($p->id)['revision']);
        $f->sis->sql->update('sis_persons', ['given_name' => 'Fictional drift'], ['id' => $p->id->value]);
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $this->review($f)->page($p->id);
    }
    public function testMissingTrustedAttributionCannotBecomeAHistorySummary(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $f->profiles->save($p->id, 'Changed', 'Example', true, $f->profiles->view($p->id)['revision']);
        $page = $this->store($f)->page(new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\Person\ValueObject\PersonProfileId($p->id->value),
            'person_profile'
        ));
        $valid = $page->events[0];
        $invalid = new \Domain\Shared\EventSourcing\StoredEvent(
            $valid->id,
            $valid->event,
            new \Domain\Shared\EventSourcing\EventContext(
                null,
                $valid->context->effectiveAt,
                $valid->context->correlation,
                origin: 'framework'
            ),
            $valid->recordedAt,
            $valid->hash
        );
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method('page')->willReturn(new \Domain\Shared\EventSourcing\EventPage([$invalid], null, 1));
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $this->review($f, $history)->page($p->id);
    }
    public function testModifiedProtectedEventsCannotBecomeAHistorySummary(): void
    {
        $f = new PersonManagementFixture();
        $p = $f->person();
        $f->profiles->save($p->id, 'Changed', 'Example', true, $f->profiles->view($p->id)['revision']);
        $f->sis->sql->execute(
            "UPDATE sis_history_events SET sealed_event = ? WHERE stream_id = ?",
            ['fictional-tampered-envelope', $f->sis->sql->one(
                'sis_history_streams',
                ['stream_type' => 'person_profile', 'aggregate_id' => $p->id->value]
            )['id']]
        );
        $this->expectException(\Domain\Shared\EventSourcing\CorruptHistoryException::class);
        $this->review($f)->page($p->id);
    }
}
