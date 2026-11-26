<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Person\Service\PersonSsnReader;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlPersonSsnRepository;
use Infrastructure\Projection\SqlPersonSsnProjectionVerifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonSsnFixture;

final class PersonSsnProjectionVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/PersonSsnFixture.php';
    }

    private function verifier(
        PersonSsnFixture $f,
        ?HistoricalEventStore $history = null
    ): SqlPersonSsnProjectionVerifier {
        return new SqlPersonSsnProjectionVerifier($f->sis->sql, new PersonSsnReader(
            new SqlPersonSsnRepository($f->sis->sql),
            $history ?? \EventStoreFixture::history($f->sis->connection->queryBuilder()),
            $f->sis->dropCipher
        ));
    }

    public function testGenuineCorrectionsAndClearingVerifyReadOnlyWithoutAnyNumberOrMask(): void
    {
        $f = new PersonSsnFixture();
        $f->confirm($f->review());
        $f->confirm($f->review('000991234', 1), '000991234');
        $before = $f->facts();
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verifier($f)->verify($f->person->id);
        self::assertSame('verified', $result->status);
        self::assertSame(2, $result->events);
        self::assertSame(2, $result->position);
        self::assertSame($before, $f->facts());
        $json = json_encode($result->describe());
        $secrets = ['000001234', '000991234', '1234', 'Fictional', 'last_four', 'number', 'nonce', 'intent', 'sealed'];
        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        $f->sis->connection->pdo->exec('PRAGMA query_only = OFF');
        $f->confirm($f->review(null, 2), null);
        self::assertSame('verified', $this->verifier($f)->verify($f->person->id)->status);
    }

    public function testEmptyEvidenceIsAnObservationAndMissingOrUnlinkedPeopleAreNotCertified(): void
    {
        $f = new PersonSsnFixture();
        $before = $f->facts();
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $empty = $this->verifier($f)->verify($f->person->id);
        self::assertSame('observed_empty', $empty->status);
        self::assertSame(0, $empty->events);
        self::assertSame(0, $empty->position);
        $missing = $this->verifier($f)->verify(EntityId::generate());
        self::assertSame(['identity'], $missing->issues);
        self::assertSame($before, $f->facts());
        $f->sis->connection->pdo->exec('PRAGMA query_only = OFF');
        $f->sis->sql->update('sis_persons', ['owner_user_id' => null], ['id' => $f->person->id->value]);
        self::assertSame(['identity'], $this->verifier($f)->verify($f->person->id)->issues);
    }

    public function testDamagedMissingAndForeignMaskProjectionsFailWithoutRepair(): void
    {
        foreach (['version', 'cipher', 'missing', 'foreign', 'extra'] as $damage) {
            $f = new PersonSsnFixture();
            $f->confirm($f->review());
            $packet = PersonSsnReader::projection($f->person->id, 1, '1234');
            if ($damage === 'missing') {
                $f->sis->sql->execute('DELETE FROM sis_person_ssns WHERE id = ?', [$f->person->id->value]);
            } else {
                if ($damage === 'foreign') {
                    $packet['person_id'] = EntityId::generate()->value;
                }
                if ($damage === 'extra') {
                    $packet['number'] = '000001234';
                }
                $f->sis->sql->update('sis_person_ssns', $damage === 'version' ? ['version' => 99]
                    : ['sealed_data' => $damage === 'cipher' ? 'damaged private input'
                        : $f->sis->dropCipher->seal(json_encode($packet))], ['id' => $f->person->id->value]);
            }
            $before = $f->facts();
            $result = $this->verifier($f)->verify($f->person->id);
            self::assertSame('unverified', $result->status);
            self::assertSame(['ssn_projection'], $result->issues);
            self::assertNull($result->position);
            self::assertSame($before, $f->facts());
        }
    }

    public function testAnUnrecordedProjectionIsNotAdoptedAsAnEmptyHistory(): void
    {
        $f = new PersonSsnFixture();
        $packet = PersonSsnReader::projection($f->person->id, 1, '1234');
        $f->sis->sql->insert('sis_person_ssns', ['id' => $f->person->id->value, 'version' => 1,
            'sealed_data' => $f->sis->dropCipher->seal(json_encode($packet))]);
        $before = $f->facts();
        self::assertSame(['ssn_projection'], $this->verifier($f)->verify($f->person->id)->issues);
        self::assertSame($before, $f->facts());
    }

    public function testConcurrentAcceptedEditsAndRoutingChangesReturnIncompleteObservations(): void
    {
        foreach (['accepted', 'owner', 'identity'] as $change) {
            $f = new PersonSsnFixture();
            $f->confirm($f->review());
            $history = \EventStoreFixture::history($f->sis->connection->queryBuilder());
            $wrapper = $this->createStub(HistoricalEventStore::class);
            $wrapper->method('page')->willReturnCallback(static function (
                $stream,
                $cursor,
                $limit
            ) use (
                $history,
                $f,
                $change
) {
                $page = $history->page($stream, $cursor, $limit);
                if ($change === 'accepted') {
                    $f->confirm($f->review('000005678', 1), '000005678');
                }
                if ($change === 'owner') {
                    $f->sis->sql->update(
                        'sis_persons',
                        ['owner_user_id' => $f->sis->user()->value],
                        ['id' => $f->person->id->value]
                    );
                }
                if ($change === 'identity') {
                    $f->sis->sql->update(
                        'sis_person_identifiers',
                        ['version' => 1],
                        ['person_id' => $f->person->id->value]
                    );
                }
                return $page;
            });
            $result = $this->verifier($f, $wrapper)->verify($f->person->id);
            self::assertSame('changed', $result->status);
            self::assertSame([], $result->issues);
            self::assertNull($result->position);
        }
    }

    public function testInvalidAndExceededAllowancesDoNotStartUnboundedReplay(): void
    {
        $f = new PersonSsnFixture();
        $f->confirm($f->review());
        $f->confirm($f->review(version: 1));
        $store = $this->createMock(HistoricalEventStore::class);
        $store->expects(self::never())->method('page');
        self::assertSame('limited', $this->verifier($f, $store)->verify($f->person->id, 1)->status);
        foreach ([0, 501] as $limit) {
            try {
                $this->verifier($f, $store)->verify($f->person->id, $limit);
                self::fail('An invalid SSN check allowance was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testCorruptAndUnavailableHistoryRemainDistinctAndDoNotExposeExceptions(): void
    {
        foreach ([new CorruptHistoryException(), new \RuntimeException('000001234 private failure')] as $error) {
            $f = new PersonSsnFixture();
            $f->confirm($f->review());
            $history = $this->createStub(HistoricalEventStore::class);
            $history->method('page')->willThrowException($error);
            $before = $f->facts();
            $result = $this->verifier($f, $history)->verify($f->person->id);
            self::assertSame([$error instanceof CorruptHistoryException ? 'journal' : 'unavailable'], $result->issues);
            self::assertStringNotContainsString('000001234', json_encode($result->describe()));
            self::assertSame($before, $f->facts());
        }
    }

    public function testSnapshotTransactionsAreRefusedWithoutTakingWriteLocks(): void
    {
        $f = new PersonSsnFixture();
        $f->sis->connection->pdo->beginTransaction();
        self::assertSame(['journal'], $this->verifier($f)->verify($f->person->id)->issues);
        $f->sis->connection->pdo->rollBack();
    }

    public function testActualProtectedEventDamageCannotBeCertifiedOrRepaired(): void
    {
        $f = new PersonSsnFixture();
        $f->confirm($f->review());
        $f->sis->sql->execute('UPDATE sis_history_events SET sealed_event = ?', ['damaged private ciphertext']);
        $before = $f->facts();
        $result = $this->verifier($f)->verify($f->person->id);
        self::assertSame('unverified', $result->status);
        self::assertSame(['journal'], $result->issues);
        self::assertSame($before, $f->facts());
    }
}
