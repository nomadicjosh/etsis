<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Projection\SqlAcademicProjectionVerifier;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class AcademicProjectionVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function service(SisFixture $s, ?AcademicInputRepository $inputs = null): SqlAcademicProjectionVerifier
    {
        return new SqlAcademicProjectionVerifier(
            $s->sql,
            $inputs ?? new SqlAcademicInputRepository($s->sql),
            $s->inputVerifier(),
        );
    }

    private function digest(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            'sis_history_events', 'sis_history_streams', 'sis_history_operations', 'sis_students',
            'sis_academic_credits', 'sis_enrollments', 'sis_academic_credit_streams', 'sis_registration_streams',
            'sis_transfer_awards', 'sis_transfer_credit_streams', 'sis_audit', 'sis_account_balances',
        ]);
    }

    public function testRecordedRegistrationAndCreditVerifyWithWritesProhibitedAndNoPrivateFacts(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $s->service->register($student, $s->section()->id);
        $before = $this->digest($s);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->service($s)->verify($student);
        self::assertSame('verified', $result->status);
        self::assertSame(1, $result->records);
        self::assertGreaterThan(0, $result->events);
        self::assertSame($before, $this->digest($s));
        $text = json_encode($result->describe(), JSON_THROW_ON_ERROR);
        foreach (['course_code', 'reason', 'sealed', 'amount', 'grade', 'source_id', 'removed'] as $private) {
            self::assertStringNotContainsString($private, $text);
        }
    }

    public function testMissingStudentAndEmptyObservationRemainDistinctWithoutInventingHistory(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $before = $this->digest($s);
        self::assertSame('observed_empty', $this->service($s)->verify($student)->status);
        $missing = $this->service($s)->verify(EntityId::generate());
        self::assertSame('unverified', $missing->status);
        self::assertSame(['student_identity'], $missing->issues);
        self::assertNull($missing->records);
        self::assertSame($before, $this->digest($s));
    }

    public function testProjectionAndJournalDamageReturnNoPartialCountOrRepair(): void
    {
        foreach (['credit', 'enrollment', 'journal'] as $kind) {
            $s = new SisFixture();
            $student = $s->student()->id;
            $enrollment = $s->service->register($student, $s->section()->id);
            if ($kind === 'credit') {
                $s->sql->execute('DELETE FROM sis_academic_credits WHERE source_id = ?', [$enrollment->id->value]);
            } elseif ($kind === 'enrollment') {
                $s->sql->update('sis_enrollments', ['version' => 999], ['id' => $enrollment->id->value]);
            } else {
                $row = $s->sql->rows('sis_history_events', [], 1)[0];
                $s->sql->update(
                    'sis_history_events',
                    ['sealed_event' => 'private-corrupt-event'],
                    ['id' => $row['id']],
                );
            }
            $before = $this->digest($s);
            $result = $this->service($s)->verify($student);
            self::assertSame('unverified', $result->status, $kind);
            self::assertNull($result->records);
            self::assertSame($before, $this->digest($s));
            self::assertStringNotContainsString('private-corrupt-event', json_encode($result->describe()));
        }
    }

    public function testReplayLimitDiscardsPartialEvidenceAndLeavesAllRecordsIntact(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $s->service->register($student, $s->section()->id);
        $before = $this->digest($s);
        $result = $this->service($s)->verify($student, 1);
        self::assertSame('limited', $result->status);
        self::assertNull($result->records);
        self::assertSame($before, $this->digest($s));
    }

    public function testConcurrentNewRegistrationIsChangedRatherThanDamagedOrVerified(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $section = $s->section();
        $inputs = new class ($s, $section->id) implements AcademicInputRepository {
            public function selected(\Domain\Shared\ValueObject\EntityId $credit): ?array
            {
                throw new \LogicException('This fixture supplies only a whole-student inventory.');
            }

            private int $reads = 0;
            public function __construct(private SisFixture $s, private EntityId $section)
            {
            }
            public function inventory(EntityId $student): AcademicInputInventory
            {
                if (++$this->reads === 2) {
                    $this->s->service->register($student, $this->section);
                }
                return new SqlAcademicInputRepository($this->s->sql)->inventory($student);
            }
        };
        $result = $this->service($s, $inputs)->verify($student);
        self::assertSame('changed', $result->status);
        self::assertNull($result->records);
        self::assertSame([], $result->issues);
    }

    public function testOuterTransactionCannotTurnTheOperatorCheckIntoLockingOrWritingReads(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $before = $this->digest($s);
        $result = $s->transactions->run(fn() => $this->service($s)->verify($student));
        self::assertSame('unverified', $result->status);
        self::assertSame($before, $this->digest($s));
    }
    public function testTransferAwardsAndEarlierUnknownsRemainDistinctWithWritesProhibited(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        foreach ([false, true] as $legacy) {
            $s = new SisFixture();
            $transfer = new \TransferAwardFixture($s, $s->dropCipher, legacy: $legacy);
            $before = $this->digest($s);
            $s->connection->pdo->exec('PRAGMA query_only = ON');
            $result = $this->service($s)->verify($transfer->student);
            self::assertSame($legacy ? 'history_gaps' : 'verified', $result->status);
            self::assertSame(1, $result->records);
            self::assertSame($before, $this->digest($s));
        }
    }

    public function testEarlyDropHistoryIsVerifiedWithoutReturningItsRestrictedReferences(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $section = $s->section();
        $s->service->register($student, $section->id);
        $review = $s->drops->review($student, $section->id);
        $s->drops->commit(
            $student,
            $section->id,
            new EntityId($review['enrollment_id']),
            $review['version'],
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['effective_on'],
        );
        $before = $this->digest($s);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->service($s)->verify($student);
        self::assertSame('verified', $result->status);
        self::assertSame(0, $result->records);
        self::assertStringNotContainsString($review['enrollment_id'], json_encode($result->describe()));
        self::assertSame($before, $this->digest($s));
    }

    public function testChangedHeadOnExistingRoutingIsNotReportedAsVerified(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $section = $s->section();
        $s->service->register($student, $section->id);
        $inputs = new class ($s, $section->id) implements AcademicInputRepository {
            public function selected(\Domain\Shared\ValueObject\EntityId $credit): ?array
            {
                throw new \LogicException('This fixture supplies only a whole-student inventory.');
            }

            private int $reads = 0;
            public function __construct(private SisFixture $s, private EntityId $section)
            {
            }
            public function inventory(EntityId $student): AcademicInputInventory
            {
                if (++$this->reads === 2) {
                    $r = $this->s->drops->review($student, $this->section);
                    $this->s->drops->commit(
                        $student,
                        $this->section,
                        new EntityId($r['enrollment_id']),
                        $r['version'],
                        new EntityId($r['operation_id']),
                        $r['revision'],
                        $r['effective_on'],
                    );
                }
                return new SqlAcademicInputRepository($this->s->sql)->inventory($student);
            }
        };
        $result = $this->service($s, $inputs)->verify($student);
        self::assertSame('changed', $result->status);
        self::assertNull($result->records);
    }

    public function testNativeCommandUsesLazyProvidersAndProhibitsReadSideWrites(): void
    {
        $process = new \Symfony\Component\Process\Process([
            PHP_BINARY, '-d', 'memory_limit=96M',
            dirname(__DIR__) . '/Support/ConsoleStatusSmoke.php', 'check-academic-projections',
        ]);
        $process->setTimeout(12);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
    public function testSelectedInstitutionalCheckIsReadOnlyAndNeverLoadsTheCareerInventory(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $enrollment = $s->service->register($student, $s->section()->id);
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        $inputs = new class ($s) implements AcademicInputRepository {
            public function __construct(private SisFixture $s)
            {
            }
            public function inventory(EntityId $student): AcademicInputInventory
            {
                throw new \Domain\Shared\EventSourcing\HistoryReadLimitException();
            }
            public function selected(EntityId $credit): ?array
            {
                return new SqlAcademicInputRepository($this->s->sql)->selected($credit);
            }
        };
        $before = $this->digest($s);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->service($s, $inputs)->credit($credit);
        self::assertSame('verified', $result->status);
        self::assertSame(1, $result->currentRecords);
        self::assertFalse($result->earlierHistoryUnknown);
        self::assertSame(2, $result->events);
        self::assertSame(1, $result->describe()['records_checked']);
        self::assertSame($before, $this->digest($s));
        $text = json_encode($result->describe(), JSON_THROW_ON_ERROR);
        $privateFields = [
            $enrollment->id->value, $student->value, 'course_code', 'reason', 'grade', 'sealed', 'amount',
        ];
        foreach ($privateFields as $private) {
            self::assertStringNotContainsString($private, $text);
        }
    }

    public function testSelectedEarlyDropUsesRetainedReferencesWithoutRecreatingCurrentRows(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $section = $s->section();
        $enrollment = $s->service->register($student, $section->id);
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        $review = $s->drops->review($student, $section->id);
        $s->drops->commit(
            $student,
            $section->id,
            $enrollment->id,
            $review['version'],
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['effective_on']
        );
        $before = $this->digest($s);
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->service($s)->credit($credit);
        self::assertSame('verified', $result->status);
        self::assertSame(0, $result->currentRecords);
        self::assertSame(1, $result->describe()['records_checked']);
        self::assertGreaterThan(2, $result->events);
        self::assertSame($before, $this->digest($s));
    }

    public function testSelectedTransferAwardKeepsObservedEarlierGapsAndVoidCorrectionsDistinct(): void
    {
        require_once dirname(__DIR__) . '/Support/TransferAwardFixture.php';
        foreach (['recorded', 'legacy', 'voided'] as $kind) {
            $s = new SisFixture();
            $t = new \TransferAwardFixture($s, $s->dropCipher, legacy: $kind === 'legacy');
            if ($kind === 'voided') {
                $change = new \Domain\Transfer\ValueObject\AwardChange(
                    'void',
                    null,
                    null,
                    'Fictional support fixture.'
                );
                $service = $t->service();
                $r = $service->preview($t->student, $t->award, $change);
                $service->confirm(
                    $t->student,
                    $t->award,
                    new EntityId($r['operation_id']),
                    $change,
                    $r['revision'],
                    $r['review_token']
                );
            }
            $before = $this->digest($s);
            $s->connection->pdo->exec('PRAGMA query_only = ON');
            $result = $this->service($s)->credit($t->credit);
            self::assertSame($kind === 'legacy' ? 'history_gaps' : 'verified', $result->status, $kind);
            self::assertSame(1, $result->currentRecords);
            self::assertSame($kind === 'legacy', $result->earlierHistoryUnknown);
            self::assertSame($before, $this->digest($s));
            self::assertStringNotContainsString($t->award->value, json_encode($result->describe()));
        }
    }

    public function testSelectedLimitsMissingIdentitiesAndProjectionDamageNeverReturnPartialEvidence(): void
    {
        foreach (['limited', 'missing', 'credit', 'student', 'journal', 'cross_student'] as $kind) {
            $s = new SisFixture();
            $student = $s->student()->id;
            $enrollment = $s->service->register($student, $s->section()->id);
            $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
            if ($kind === 'missing') {
                $credit = EntityId::generate();
            } elseif ($kind === 'credit') {
                $s->sql->update('sis_academic_credits', ['attempted_milli' => 9999], ['id' => $credit->value]);
            } elseif ($kind === 'journal') {
                $head = $s->sql->one('sis_history_streams', ['aggregate_id' => $credit->value]);
                $s->sql->update(
                    'sis_history_events',
                    ['sealed_event' => 'private-damaged-value'],
                    ['stream_id' => $head['id']]
                );
            } elseif ($kind === 'student') {
                $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
                $s->sql->execute('DELETE FROM sis_students WHERE id = ?', [$student->value]);
            } elseif ($kind === 'cross_student') {
                $s->sql->update(
                    'sis_academic_credits',
                    ['student_id' => $s->student()->id->value],
                    ['id' => $credit->value]
                );
            }
            $before = $this->digest($s);
            $s->connection->pdo->exec('PRAGMA query_only = ON');
            $result = $this->service($s)->credit($credit, $kind === 'limited' ? 1 : 200);
            self::assertSame($kind === 'limited' ? 'limited' : 'unverified', $result->status, $kind);
            self::assertNull($result->currentRecords);
            self::assertNull($result->earlierHistoryUnknown);
            self::assertNull($result->describe()['records_checked']);
            self::assertSame($before, $this->digest($s));
            self::assertStringNotContainsString('private-damaged-value', json_encode($result->describe()));
        }
    }

    public function testSelectedProjectionChangesDuringTheReadDiscardTheObservation(): void
    {
        $s = new SisFixture();
        $student = $s->student()->id;
        $enrollment = $s->service->register($student, $s->section()->id);
        $credit = new EntityId($s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value])['id']);
        $inputs = new class ($s) implements AcademicInputRepository {
            private int $reads = 0;
            public function __construct(private SisFixture $s)
            {
            }
            public function inventory(EntityId $student): AcademicInputInventory
            {
                throw new \LogicException('Only a selected source should be read.');
            }
            public function selected(EntityId $credit): ?array
            {
                if (++$this->reads === 2) {
                    $this->s->sql->update('sis_academic_credits', ['version' => 999], ['id' => $credit->value]);
                }
                return new SqlAcademicInputRepository($this->s->sql)->selected($credit);
            }
        };
        $result = $this->service($s, $inputs)->credit($credit);
        self::assertSame('changed', $result->status);
        self::assertSame([], $result->issues);
        self::assertNull($result->currentRecords);
    }
}
