<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Person\Service\PersonSsnService;
use Domain\Person\ValueObject\SocialSecurityNumber;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonSsnFixture;

final class PersonSsnTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PersonSsnFixture.php';
    }

    public function testAddCorrectSameLastFourAndClearKeepNumbersOutOfEveryReadAndProjection(): void
    {
        $f = new PersonSsnFixture();
        $service = $f->service();
        self::assertNull($service->view($f->person->id)['masked']);
        $original = $f->sis->sql->rows('sis_persons');
        $first = $f->review();
        self::assertSame('***-**-1234', $first['after']);
        self::assertStringNotContainsString('000001234', json_encode($first));
        $receipt = $f->confirm($first);
        $corrected = $f->review('000991234', 1);
        self::assertSame($corrected['before'], $corrected['after']);
        $f->confirm($corrected, '000991234');
        $stored = \EventStoreFixture::history($f->sis->connection->queryBuilder())->page(
            new \Domain\Shared\EventSourcing\StreamReference(
                new \Domain\Person\ValueObject\PersonSsnId($f->person->id->value),
                'person_ssn'
            )
        );
        self::assertSame(['000001234', '000991234'], array_map(
            static fn($record): string => $record->event->payload()['number'],
            $stored->events
        ));
        self::assertNotSame(
            $stored->events[0]->event->payload()['privacy_nonce'],
            $stored->events[1]->event->payload()['privacy_nonce']
        );
        self::assertArrayNotHasKey('intent', $corrected);
        self::assertSame(['version' => 2, 'masked' => '***-**-1234', 'can_edit' => true,
            'at_limit' => false, 'can_history' => true], $service->view($f->person->id));
        $row = $f->sis->sql->rows('sis_person_ssns')[0];
        $mask = json_decode($f->sis->dropCipher->open($row['sealed_data']), true);
        self::assertSame('1234', $mask['last_four']);
        self::assertArrayNotHasKey('number', $mask);
        self::assertStringNotContainsString('last_four', $row['sealed_data']);
        $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
        $before = $f->facts();
        self::assertSame($receipt, $f->confirm($first));
        self::assertSame($before, $f->facts());
        $clear = $f->review(null, 2);
        $f->confirm($clear, null);
        self::assertNull($service->view($f->person->id)['masked']);
        $history = $service->timeline($f->person->id);
        self::assertCount(3, $history['items']);
        self::assertSame(['recorded', 'recorded', 'cleared'], array_column($history['items'], 'action'));
        self::assertStringNotContainsString('000001234', json_encode($history));
        self::assertStringNotContainsString('000991234', json_encode($f->facts()));
        self::assertSame($original, $f->sis->sql->rows('sis_persons'));
    }

    public function testStaleRevisionAndChangedPrivateInputCannotOverwriteAcceptedCorrections(): void
    {
        $f = new PersonSsnFixture();
        $stale = $f->review();
        $f->confirm($f->review('000005678'), '000005678');
        $before = $f->facts();
        try {
            $f->confirm($stale);
            self::fail('A stale SSN correction committed.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->facts());
        }
        $fresh = $f->review('000009876', 1);
        $this->expectException(ValidationException::class);
        $f->confirm($fresh, '000001234');
    }

    public function testReadWriteHistoryAndEnabledAccountDutiesAreIndependentOfCachedProfileAccess(): void
    {
        $f = new PersonSsnFixture();
        $f->grant(PersonSsnService::MANAGE, false);
        self::assertFalse($f->service()->view($f->person->id)['can_edit']);
        try {
            $f->review();
            self::fail('General profile access permitted SSN correction.');
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sis->sql->rows('sis_person_ssns'));
        }
        $f->grant(PersonSsnService::MANAGE, true);
        $f->confirm($f->review());
        $f->grant('sis:audit:read', false);
        try {
            $f->service()->timeline($f->person->id);
            self::fail('SSN access granted unrelated history authority.');
        } catch (AccessDeniedException) {
            self::assertSame('***-**-1234', $f->service()->view($f->person->id)['masked']);
        }
        $f->grant(PersonSsnService::READ, false);
        try {
            $f->service()->view($f->person->id);
            self::fail('General profile access disclosed the saved SSN mask.');
        } catch (AccessDeniedException) {
            self::assertCount(1, $f->sis->sql->rows('sis_person_ssns'));
        }
        $f->grant(PersonSsnService::READ, true);
        $f->sis->sql->update('users', ['sign_in_enabled' => 0], ['user_id' => $f->sis->access->actor->value]);
        $this->expectException(AccessDeniedException::class);
        $f->service()->view($f->person->id);
    }

    public function testAuditProjectionAuthorityIdentityAndExpiryFailuresRollbackAllWrites(): void
    {
        foreach (['audit', 'projection', 'authority', 'identity', 'expiry'] as $failure) {
            $f = new PersonSsnFixture();
            $review = $f->review();
            $before = $f->facts();
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($f, $failure): void {
                if ($failure === 'audit') {
                    throw new \RuntimeException('Fictional audit failure.');
                }
                if ($failure === 'projection') {
                    $f->sis->sql->update(
                        'sis_person_ssns',
                        ['sealed_data' => 'damaged'],
                        ['id' => $f->person->id->value]
                    );
                }
                if ($failure === 'authority') {
                    $f->grant(PersonSsnService::MANAGE, false);
                }
                if ($failure === 'identity') {
                    $f->sis->sql->update(
                        'sis_person_identifiers',
                        ['version' => 1],
                        ['person_id' => $f->person->id->value]
                    );
                }
                if ($failure === 'expiry') {
                    $f->sis->clock->time = $f->sis->clock->time->modify('+21 minutes');
                }
            });
            try {
                $f->confirm($review, audit: $audit);
                self::fail('An invalid SSN correction committed.');
            } catch (\RuntimeException) {
                self::assertSame($before, $f->facts());
            }
        }
    }

    public function testProjectionDisagreementIsNotRepairedOrDisclosedThroughARead(): void
    {
        $f = new PersonSsnFixture();
        $f->confirm($f->review());
        $f->sis->sql->update('sis_person_ssns', ['version' => 9], ['id' => $f->person->id->value]);
        $before = $f->facts();
        try {
            $f->service()->view($f->person->id);
            self::fail('Damaged SSN projection was used.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $f->facts());
        }
    }

    public function testReasonsCannotPutFullNumbersIntoReadResponses(): void
    {
        $f = new PersonSsnFixture();
        foreach (['Corrected 000001234', 'Corrected 000-00-1234', 'Corrected 000 00 1234'] as $reason) {
            try {
                $f->service()->review($f->person->id, 0, new SocialSecurityNumber('000001234'), $reason);
                self::fail('A full number was accepted as a readable reason.');
            } catch (ValidationException $error) {
                self::assertSame('reason', $error->errors[0]['field']);
                self::assertStringNotContainsString('000001234', json_encode($error->errors));
            }
        }
    }

    public function testCurrentPersonOwnerAndIdentityChangesInvalidateUnacceptedReviews(): void
    {
        foreach (['owner', 'identity'] as $change) {
            $f = new PersonSsnFixture();
            $review = $f->review();
            if ($change === 'owner') {
                $f->sis->sql->update(
                    'sis_persons',
                    ['owner_user_id' => $f->sis->user()->value],
                    ['id' => $f->person->id->value]
                );
            } else {
                $f->sis->sql->update(
                    'sis_person_identifiers',
                    ['version' => 1],
                    ['person_id' => $f->person->id->value]
                );
            }
            $before = $f->facts();
            try {
                $f->confirm($review);
                self::fail('A changed person reused an unaccepted review.');
            } catch (ValidationException) {
                self::assertSame($before, $f->facts());
            }
        }
    }

    public function testUnlinkedAndProtectedSuperOwnersCannotBeReadByOrdinaryStaff(): void
    {
        foreach (['unlinked', 'super'] as $case) {
            $f = new PersonSsnFixture();
            if ($case === 'unlinked') {
                $f->sis->sql->update('sis_persons', ['owner_user_id' => null], ['id' => $f->person->id->value]);
            } else {
                $role = $f->sis->sql->one('sis_access_roles', ['name' => 'super']);
                $f->roles->assign(
                    \Domain\Access\Enum\GrantKind::UserRole,
                    $f->person->ownerUserId,
                    new \Domain\Shared\ValueObject\EntityId($role['id']),
                    true
                );
            }
            try {
                $f->service()->view($f->person->id);
                self::fail('A protected person SSN was read.');
            } catch (AccessDeniedException) {
                self::assertSame([], $f->sis->sql->rows('sis_person_ssns'));
            }
        }
    }

    public function testHistoryUsesOpaquePagingAndDoesNotDiscloseValuesAcrossCorrections(): void
    {
        $f = new PersonSsnFixture();
        for ($version = 0; $version < 21; $version++) {
            $f->confirm($f->review(version: $version));
        }
        $first = $f->service()->timeline($f->person->id);
        self::assertCount(20, $first['items']);
        self::assertNotNull($first['next_cursor']);
        $second = $f->service()->timeline($f->person->id, $first['next_cursor']);
        self::assertSame([21], array_column($second['items'], 'version'));
        self::assertNull($second['next_cursor']);
        self::assertStringNotContainsString('000001234', json_encode([$first, $second]));
    }

    public function testEncryptedMaskForAnotherPersonCannotReplaceTheCurrentProjection(): void
    {
        $f = new PersonSsnFixture();
        $f->confirm($f->review());
        $foreign = $f->sis->legacyPerson($f->sis->user(), 'Fictional', 'Other');
        $sealed = $f->sis->dropCipher->seal(json_encode(['purpose' => 'person.ssn.mask',
            'person_id' => $foreign->id->value, 'version' => 1, 'last_four' => '1234'], JSON_THROW_ON_ERROR));
        $f->sis->sql->update('sis_person_ssns', ['sealed_data' => $sealed], ['id' => $f->person->id->value]);
        $before = $f->facts();
        try {
            $f->service()->view($f->person->id);
            self::fail('An encrypted foreign-person mask was accepted.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $f->facts());
        }
    }
}
