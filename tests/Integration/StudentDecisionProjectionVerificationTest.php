<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\LoadDeterminationLedger;
use Domain\Student\Service\StudentStandingLedger;
use Infrastructure\Persistence\Repository\SqlLoadDeterminationRepository;
use Infrastructure\Persistence\Repository\SqlStudentStandingRepository;
use Infrastructure\Projection\SqlStudentDecisionProjectionVerifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\StudentDecisionFixture;

final class StudentDecisionProjectionVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/StudentDecisionFixture.php';
    }

    private function verifier(
        StudentDecisionFixture $f,
        HistoricalEventStore $history,
    ): SqlStudentDecisionProjectionVerifier {
        $s = $f->sis;
        return new SqlStudentDecisionProjectionVerifier(
            $s->sql,
            new StudentStandingLedger(new SqlStudentStandingRepository($s->sql), $history),
            new LoadDeterminationLedger(new SqlLoadDeterminationRepository($s->sql), $history)
        );
    }

    public function testPopulatedDecisionsVerifyWithWritesProhibitedAndNoPrivateValues(): void
    {
        $f = new StudentDecisionFixture();
        $s = $f->sis;
        $before = $f->facts();
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $verifier = $s->studentDecisionVerifier();
        foreach ([$verifier->standing($f->pursuit), $verifier->load($f->determination)] as $result) {
            self::assertSame('verified', $result->status);
            self::assertSame(1, $result->position);
            self::assertSame(1, $result->records);
            self::assertSame(1, $result->events);
            $json = json_encode($result->describe(), JSON_THROW_ON_ERROR);
            $secrets = ['Private', 'Fictional', 'credits_milli', 'reason', 'definition', 'student_id', 'policy'];
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $json);
            }
            self::assertFalse($result->describe()['records_changed']);
        }
        self::assertSame($before, $f->facts());
    }

    public function testEarlierStandingAbsenceIsUnknownAndMissingIdentitiesNeverVerify(): void
    {
        $f = new StudentDecisionFixture();
        $s = $f->sis;
        $other = $s->student();
        $p = new EntityId($s->sql->rows('sis_student_programs', ['student_id' => $other->id->value], 1)[0]['id']);
        $v = $s->studentDecisionVerifier();
        $empty = $v->standing($p);
        self::assertSame('observed_empty', $empty->status);
        self::assertSame(0, $empty->records);
        self::assertSame(['pursuit_identity'], $v->standing(EntityId::generate())->issues);
        self::assertSame(['load_decision'], $v->load(EntityId::generate())->issues);
        $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
        $s->sql->update('sis_student_programs', ['student_id' => EntityId::generate()->value], ['id' => $p->value]);
        self::assertSame('unverified', $v->standing($p)->status);
    }

    public function testStandingMismatchAndMissingLoadRowsFailWithoutRepair(): void
    {
        $f = new StudentDecisionFixture();
        $s = $f->sis;
        $s->sql->update(
            'sis_student_standing',
            ['title' => 'Private changed label'],
            ['pursuit_id' => $f->pursuit->value],
        );
        $s->sql->execute('DELETE FROM sis_load_determinations WHERE id = ?', [$f->determination->value]);
        $before = $f->facts();
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        self::assertSame(['standing'], $s->studentDecisionVerifier()->standing($f->pursuit)->issues);
        self::assertSame(['load_decision'], $s->studentDecisionVerifier()->load($f->determination)->issues);
        self::assertSame($before, $f->facts());
    }

    public function testEveryLoadProjectionComponentIsComparedWithTheRetainedDecision(): void
    {
        $f = new StudentDecisionFixture();
        $s = $f->sis;
        $row = $s->sql->rows('sis_load_determinations', ['id' => $f->determination->value], 1)[0];
        $s->connection->pdo->exec('PRAGMA foreign_keys = OFF');
        $changes = ['student_id' => EntityId::generate()->value, 'pursuit_id' => EntityId::generate()->value,
            'term_id' => EntityId::generate()->value, 'term_code' => 'OTHER', 'program_code' => 'OTHER',
            'program_title' => 'Other retained program', 'determined_on' => '2026-08-09',
            'recorded_at' => '2026-08-10 17:00:01', 'registered_credits_milli' => 4000,
            'enrollment_count' => 3, 'classification_code' => 'OTHER', 'classification_title' => 'Other',
            'policy_version_id' => EntityId::generate()->value, 'policy_source' => 'program'];
        foreach ($changes as $field => $value) {
            $s->sql->update('sis_load_determinations', [$field => $value], ['id' => $f->determination->value]);
            $result = $s->studentDecisionVerifier()->load($f->determination);
            self::assertSame('unverified', $result->status, $field);
            self::assertNull($result->records, $field);
            $s->sql->update('sis_load_determinations', [$field => $row[$field]], ['id' => $f->determination->value]);
        }
        self::assertSame('verified', $s->studentDecisionVerifier()->load($f->determination)->status);
    }

    public function testCurrentSetupAndPursuitChangesPreserveAcceptedDecisions(): void
    {
        $f = new StudentDecisionFixture();
        $s = $f->sis;
        $definition = $s->sql->rows('sis_standing_definitions', [], 1)[0];
        $s->sql->update('sis_standing_definitions', ['title' => 'Renamed', 'active' => 0], ['id' => $definition['id']]);
        $s->sql->update('sis_student_programs', ['status' => 'closed'], ['id' => $f->pursuit->value]);
        $s->sql->update('sis_enrollments', ['status' => 'dropped'], ['student_id' => $f->student->value]);
        // Selected retained decisions compare their original evidence, not these current working projections.
        $v = $s->studentDecisionVerifier();
        self::assertSame('verified', $v->standing($f->pursuit)->status);
        self::assertSame('verified', $v->load($f->determination)->status);
    }

    public function testReplayLimitNeverReturnsPartialStandingCounts(): void
    {
        $f = new StudentDecisionFixture();
        $s = $f->sis;
        $service = $s->standingService();
        $view = $service->view($f->student, $f->pursuit);
        $rows = $view['rows'];
        $rows[0]['ends_on'] = '2026-08-31';
        $r = $service->review($f->student, $f->pursuit, $rows, $view['revision'], 'Fictional end correction');
        $service->commit(
            $f->student,
            $f->pursuit,
            new EntityId($r['operation_id']),
            $r['rows'],
            $r['revision'],
            $r['reason'],
            $r['review_token']
        );
        $result = $s->studentDecisionVerifier()->standing($f->pursuit, 1);
        self::assertSame('limited', $result->status);
        self::assertSame(0, $result->events);
        self::assertNull($result->records);
        self::assertSame('verified', $s->studentDecisionVerifier()->standing($f->pursuit, 2)->status);
    }

    public function testConcurrentSourceOrRoutingChangesDiscardTheComparison(): void
    {
        foreach (['standing', 'load'] as $kind) {
            $f = new StudentDecisionFixture();
            $s = $f->sis;
            $real = \EventStoreFixture::history($s->connection->queryBuilder());
            $history = $this->createStub(HistoricalEventStore::class);
            $history->method('page')->willReturnCallback(function (...$args) use ($real, $s, $f, $kind) {
                $page = $real->page(...$args);
                $s->sql->update(
                    $kind === 'standing' ? 'sis_student_standing' : 'sis_load_determinations',
                    $kind === 'standing' ? ['title' => 'Private concurrent correction'] : ['enrollment_count' => 3],
                    $kind === 'standing' ? ['pursuit_id' => $f->pursuit->value] : ['id' => $f->determination->value]
                );
                return $page;
            });
            $v = $this->verifier($f, $history);
            $result = $kind === 'standing' ? $v->standing($f->pursuit) : $v->load($f->determination);
            self::assertSame('changed', $result->status);
            self::assertSame([], $result->issues);
            self::assertNull($result->records);
        }
    }

    public function testSnapshotTransactionsAndProviderFailuresReturnOnlyUnavailable(): void
    {
        $f = new StudentDecisionFixture();
        $s = $f->sis;
        $history = $this->createStub(HistoricalEventStore::class);
        $history->method('page')->willThrowException(new \RuntimeException('PRIVATE protected provider failure'));
        $v = $this->verifier($f, $history);
        self::assertSame(['unavailable'], $v->standing($f->pursuit)->issues);
        self::assertSame(['unavailable'], $v->load($f->determination)->issues);
        $s->transactions->run(function () use ($s, $f): void {
            self::assertSame(['unavailable'], $s->studentDecisionVerifier()->standing($f->pursuit)->issues);
            self::assertSame(['unavailable'], $s->studentDecisionVerifier()->load($f->determination)->issues);
        });
    }

    public function testDamagedProtectedHistoryIsNotCertifiedOrRepaired(): void
    {
        foreach (['student_standing', 'enrollment_load_determination'] as $scope) {
            $f = new StudentDecisionFixture();
            $s = $f->sis;
            $id = $scope === 'student_standing' ? $f->pursuit : $f->determination;
            $head = $s->sql->rows(
                'sis_history_streams',
                ['stream_type' => $scope, 'aggregate_id' => $id->value],
                1,
            )[0];
            self::assertSame(1, $s->sql->update(
                'sis_history_events',
                ['sealed_event' => 'PRIVATE damaged event'],
                ['stream_id' => $head['id']]
            ));
            $before = $f->facts();
            $s->connection->pdo->exec('PRAGMA query_only = ON');
            $v = $s->studentDecisionVerifier();
            $result = $scope === 'student_standing' ? $v->standing($id) : $v->load($id);
            self::assertSame('unverified', $result->status);
            self::assertNull($result->records);
            self::assertStringNotContainsString('PRIVATE', json_encode($result->describe()));
            self::assertSame($before, $f->facts());
        }
    }

    public function testInvalidAllowancesAreRejectedBeforeVerification(): void
    {
        $f = new StudentDecisionFixture();
        $before = $f->facts();
        $v = $f->sis->studentDecisionVerifier();
        foreach ([0, 2001] as $limit) {
            foreach (['standing' => $f->pursuit, 'load' => $f->determination] as $method => $id) {
                try {
                    $v->$method($id, $limit);
                    self::fail('An invalid selected replay allowance was accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertSame($before, $f->facts());
                }
            }
        }
    }

    public function testOnlyStaffHistoryExposesTheSelectedDeterminationReference(): void
    {
        $f = new StudentDecisionFixture();
        $s = $f->sis;
        $service = $f->loads();
        $staff = $service->listing($f->student, $f->pursuit, $s->term, 'staff')['items'][0];
        self::assertSame($f->determination->value, $staff['id']);
        $s->access->actor = $s->admissions->person($s->admissions->student($f->student)->personId)->ownerUserId;
        $owner = $service->listing($f->student, $f->pursuit, $s->term, 'owner')['items'][0];
        foreach (['id', 'items', 'reason', 'sources'] as $private) {
            self::assertArrayNotHasKey($private, $owner);
        }
        self::assertArrayNotHasKey('version_id', $owner['policy']);
    }
}
