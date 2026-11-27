<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Communications\Service\MessageCipher;
use Domain\Person\Service\AccountSetupRetention;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAccountSetupRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AccountSetupRetentionTest extends TestCase
{
    private SisFixture $sis;
    private SqlAccountSetupRepository $repository;
    private AccountSetupRetention $retention;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    protected function setUp(): void
    {
        $this->sis = new SisFixture();
        $this->repository = new SqlAccountSetupRepository($this->sis->sql);
        $this->retention = $this->service($this->sis->dropCipher);
    }

    private function service(MessageCipher $cipher): AccountSetupRetention
    {
        return new AccountSetupRetention($this->repository, $cipher, $this->sis->transactions, $this->sis->clock);
    }

    private function invitation(): array
    {
        $person = $this->sis->registrationService()->staff(
            EntityId::generate(),
            'Fictional',
            'Person',
            EntityId::generate()->value . '@example.invalid'
        );
        return $this->sis->sql->one('sis_account_setup', ['person_id' => $person->id->value]);
    }

    public function testPreviewIsReadOnlyAndUsesTheExactUtcExpiryPlusGraceBoundary(): void
    {
        $this->sis->clock->time = new DateTimeImmutable('2026-10-29T10:30:00Z');
        $this->invitation();
        $this->sis->clock->time = $this->sis->clock->time->modify('+1 microsecond');
        $this->invitation();
        $this->sis->clock->time = new DateTimeImmutable('2026-10-30T11:30:00Z');
        $this->invitation();
        // The DST transition is an elapsed 24-hour grace, not a local calendar day.
        $this->sis->clock->time = new DateTimeImmutable('2026-11-01T02:30:00-08:00');
        $before = $this->sis->sql->rows('sis_account_setup');
        $this->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->retention->run();
        self::assertSame('2026-10-31T10:30:00.000000Z', $result['cutoff_utc']);
        self::assertSame([1, 1, 0], [$result['examined'], $result['eligible'], $result['cleared']]);
        self::assertSame($before, $this->sis->sql->rows('sis_account_setup'));
        self::assertFalse($this->sis->connection->pdo->inTransaction());
    }

    public function testApplyPreservesBindingsPeopleAccountsAndAllHistoricalFacts(): void
    {
        $operation = EntityId::generate();
        $creator = $this->sis->registrationService();
        $person = $creator->staff($operation, 'Fictional', 'Person', 'fictional@example.invalid');
        $before = $this->sis->sql->one('sis_account_setup', ['person_id' => $person->id->value]);
        $tables = ['sis_persons', 'users', 'sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_audit'];
        $facts = array_map($this->sis->sql->rows(...), $tables);
        $this->sis->clock->time = $this->sis->clock->time->modify('+73 hours');
        $result = $this->retention->run(apply: true);
        self::assertSame([1, 1, 0, 0], [
            $result['eligible'], $result['cleared'], $result['invalid'], $result['changed'],
        ]);
        self::assertSame(
            array_replace($before, ['encrypted_notice' => null, 'state' => 'expired']),
            $this->sis->sql->one('sis_account_setup', ['id' => $before['id']])
        );
        self::assertSame($facts, array_map($this->sis->sql->rows(...), $tables));
        self::assertSame(
            $person->id->value,
            $creator->staff($operation, 'Fictional', 'Person', 'fictional@example.invalid')->id->value
        );
        self::assertSame(0, $this->retention->run(apply: true)['examined']);
        self::assertNull($this->sis->sql->one('sis_account_setup', ['id' => $before['id']])['encrypted_notice']);
        self::assertSame($facts, array_map($this->sis->sql->rows(...), $tables));
    }

    public function testInterruptedDeliveryIsMarkedUncertainAndCannotBeClaimedOrFinishedAgain(): void
    {
        $row = $this->invitation();
        $id = new EntityId($row['id']);
        self::assertTrue($this->repository->claim($id));
        $this->sis->clock->time = $this->sis->clock->time->modify('+73 hours');
        self::assertSame(1, $this->retention->run(apply: true)['cleared']);
        $expected = array_replace($row, ['encrypted_notice' => null, 'state' => 'uncertain']);
        self::assertSame($expected, $this->repository->forPerson(new EntityId($row['person_id'])));
        self::assertFalse($this->repository->claim($id));
        self::assertSame([], $this->repository->pending(10));
        $this->repository->delivery($id, $row['token_hash'], 'sent');
        self::assertSame($expected, $this->repository->forPerson(new EntityId($row['person_id'])));
    }

    public static function invalidBindings(): array
    {
        return [
            'corrupt seal' => ['sealed_grant', 'fictional corrupt grant'],
            'oversized seal' => ['sealed_grant', str_repeat('x', 9000)],
            'changed token' => ['token_hash', str_repeat('b', 64)],
            'changed expiry' => ['expires_at', '2026-01-01T00:00:00.000000Z'],
            'malformed date' => ['expires_at', '2026-02-31T00:00:00.000000Z'],
            'consumed but queued' => ['consumed_at', '2026-08-11T00:00:00.000000Z'],
        ];
    }

    #[DataProvider('invalidBindings')]
    public function testUnverifiableRowsAreRetainedWithoutGuessing(string $field, string $value): void
    {
        $row = $this->invitation();
        $this->sis->sql->update('sis_account_setup', [$field => $value], ['id' => $row['id']]);
        $before = $this->sis->sql->rows('sis_account_setup');
        $this->sis->clock->time = $this->sis->clock->time->modify('+73 hours');
        $result = $this->retention->run(apply: true);
        self::assertSame([1, 0, 1], [$result['examined'], $result['cleared'], $result['invalid']]);
        self::assertSame($before, $this->sis->sql->rows('sis_account_setup'));
    }

    public function testOnlyTheSmallBindingIsReadOrDecryptedEvenForALargeDamagedNotice(): void
    {
        $row = $this->invitation();
        $this->sis->sql->update(
            'sis_account_setup',
            ['encrypted_notice' => str_repeat('x', 262144)],
            ['id' => $row['id']]
        );
        $metadata = $this->repository->retentionRecord(new EntityId($row['id']));
        self::assertArrayNotHasKey('encrypted_notice', $metadata);
        self::assertSame(1, $metadata['has_notice']);
        self::assertLessThan(8192, strlen(json_encode($metadata, JSON_THROW_ON_ERROR)));
        $cipher = $this->createMock(MessageCipher::class);
        $cipher->expects(self::once())->method('open')->with($row['sealed_grant'])
            ->willReturn($this->sis->dropCipher->open($row['sealed_grant']));
        $cipher->expects(self::never())->method('seal');
        $this->sis->clock->time = $this->sis->clock->time->modify('+73 hours');
        self::assertSame(1, $this->service($cipher)->run(apply: true)['cleared']);
    }

    public function testAuthenticatedButInvalidStoredDatesAreRetained(): void
    {
        $row = $this->invitation();
        $this->sis->clock->time = $this->sis->clock->time->modify('+73 hours');
        foreach (
            [
                ['requested_at' => '2026-02-31T00:00:00.000000Z'],
                ['expires_at' => '2026-02-31T00:00:00.000000Z'],
                ['requested_at' => $row['expires_at']],
                ['expires_at' => '2026-08-10T00:00:00Z'],
            ] as $dates
        ) {
            $changed = array_replace($row, $dates);
            $changed['sealed_grant'] = $this->sis->dropCipher->seal(json_encode(
                \Domain\Person\ValueObject\AccountSetupGrant::claims($changed),
                JSON_THROW_ON_ERROR
            ));
            $this->sis->sql->update('sis_account_setup', $changed, ['id' => $row['id']]);
            self::assertSame(1, $this->retention->run(apply: true)['invalid']);
            self::assertSame($changed, $this->sis->sql->one('sis_account_setup', ['id' => $row['id']]));
        }
    }

    public function testBoundedCursorCanContinuePastAnInvalidRow(): void
    {
        for ($index = 0; $index < 3; ++$index) {
            $this->invitation();
        }
        $rows = $this->sis->sql->fetch('SELECT id FROM sis_account_setup ORDER BY id');
        $this->sis->sql->update(
            'sis_account_setup',
            ['sealed_grant' => 'fictional bad grant'],
            ['id' => $rows[0]['id']]
        );
        $this->sis->clock->time = $this->sis->clock->time->modify('+73 hours');
        $first = $this->retention->run(apply: true, limit: 1);
        self::assertSame([1, 1, $rows[0]['id']], [$first['examined'], $first['invalid'], $first['next_after']]);
        $second = $this->retention->run(apply: true, limit: 1, after: new EntityId($first['next_after']));
        self::assertSame([1, $rows[1]['id']], [$second['cleared'], $second['next_after']]);
        $third = $this->retention->run(apply: true, limit: 1, after: new EntityId($second['next_after']));
        self::assertSame([1, null], [$third['cleared'], $third['next_after']]);
        self::assertNotNull($this->sis->sql->one('sis_account_setup', ['id' => $rows[0]['id']])['encrypted_notice']);
    }

    public function testAChangeAfterRedactionRollsBackTheEntireRow(): void
    {
        $row = $this->invitation();
        $this->sis->connection->pdo->exec('CREATE TRIGGER alter_binding ' .
            'AFTER UPDATE OF encrypted_notice ON sis_account_setup WHEN NEW.encrypted_notice IS NULL ' .
            "BEGIN UPDATE sis_account_setup SET credential_hash = 'fictional change' " .
            'WHERE id = NEW.id; END');
        $this->sis->clock->time = $this->sis->clock->time->modify('+73 hours');
        self::assertSame(1, $this->retention->run(apply: true)['changed']);
        self::assertSame($row, $this->sis->sql->one('sis_account_setup', ['id' => $row['id']]));
    }

    public function testLaterStorageFailurePreservesEarlierCompletedRowsAndRollsBackTheFailedRow(): void
    {
        $this->invitation();
        $this->invitation();
        $rows = $this->sis->sql->fetch('SELECT * FROM sis_account_setup ORDER BY id');
        $this->sis->connection->pdo->exec('CREATE TRIGGER reject_notice ' .
            'BEFORE UPDATE OF encrypted_notice ON sis_account_setup ' .
            'WHEN NEW.encrypted_notice IS NULL AND NEW.id = ' . $this->sis->connection->pdo->quote($rows[1]['id']) .
            " BEGIN SELECT RAISE(ABORT, 'fictional storage failure'); END");
        $this->sis->clock->time = $this->sis->clock->time->modify('+73 hours');
        try {
            $this->retention->run(apply: true);
            self::fail('The fictional storage failure was ignored.');
        } catch (\PDOException) {
            self::assertSame(
                array_replace($rows[0], ['encrypted_notice' => null, 'state' => 'expired']),
                $this->sis->sql->one('sis_account_setup', ['id' => $rows[0]['id']])
            );
            self::assertSame($rows[1], $this->sis->sql->one('sis_account_setup', ['id' => $rows[1]['id']]));
        }
    }

    public function testInvalidBoundsNeverReachStorage(): void
    {
        $this->sis->connection->pdo->exec('DROP TABLE sis_account_setup');
        foreach ([[0, 24], [101, 24], [1, 0], [1, 8761]] as [$limit, $hours]) {
            try {
                $this->retention->run(apply: true, limit: $limit, graceHours: $hours);
                self::fail('Invalid bounds reached storage.');
            } catch (\InvalidArgumentException) {
                self::assertFalse($this->sis->connection->pdo->inTransaction());
            }
        }
    }
}
