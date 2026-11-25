<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\Waitlist\WaitlistRepository;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WaitlistVerificationFixture;

final class WaitlistProjectionVerificationTest extends TestCase
{
    private function fixture(): WaitlistVerificationFixture
    {
        require_once dirname(__DIR__) . '/Support/WaitlistVerificationFixture.php';
        return new WaitlistVerificationFixture();
    }

    public static function outcomes(): array
    {
        return ['active' => [null], 'cancelled' => ['cancelled'], 'removed' => ['removed'],
            'registered' => ['registered']];
    }

    #[DataProvider('outcomes')]
    public function testActiveAndClosedRequestsVerifyWithWritesProhibited(?string $action): void
    {
        $f = $this->fixture();
        if ($action !== null) {
            $f->close($action);
        }
        $before = $f->facts();
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $f->verifier()->verify($f->entry)->describe();
        self::assertSame('verified', $result['status']);
        self::assertSame(1, $result['records_checked']);
        self::assertSame($action === null ? 2 : 3, $result['events_checked']);
        self::assertSame($result['events_checked'], $result['position']);
        self::assertSame($before, $f->facts());
        self::assertSame(['status', 'scope', 'waitlist_reference', 'events_checked', 'position', 'records_checked',
            'issues', 'records_changed'], array_keys($result));
        foreach (['student_id', 'person_id', 'reason', 'enrollment_id', 'rank', 'Fictional', 'USD'] as $private) {
            self::assertStringNotContainsString($private, json_encode($result, JSON_THROW_ON_ERROR));
        }
    }

    public function testRegisteredRequestSurvivesLaterEnrollmentRemovalAndOwnerNoticeDeletion(): void
    {
        $f = $this->fixture();
        $f->close('registered');
        $s = $f->sis;
        $review = $s->drops->review($f->student, $f->section);
        $s->drops->commit(
            $f->student,
            $f->section,
            new EntityId($review['enrollment_id']),
            $review['version'],
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['effective_on'],
        );
        $s->access->actor = $s->admissions->person($s->admissions->student($f->student)->personId)->ownerUserId;
        $updates = new \Domain\Registration\Service\RegistrationUpdatesService(
            new \Infrastructure\Persistence\Repository\SqlRegistrationUpdatesRepository($s->sql),
            new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
                $s->sql,
                $s->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator(),
            ),
            $s->admissions,
            $s->waitlistLedger(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock,
        );
        $notice = $updates->page()['items'][0];
        $updates->delete(new EntityId($notice['id']), $notice['revision']);
        $before = $f->facts();
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        self::assertSame('verified', $f->verifier()->verify($f->entry)->status);
        self::assertNull($s->registration->enrollment($f->student, $f->section));
        self::assertSame([], $s->sql->rows('sis_registration_updates'));
        self::assertSame($before, $f->facts());
    }

    public static function damage(): array
    {
        return array_map(
            static fn(string $kind): array => [$kind],
            ['missing', 'closed_row', 'active_row', 'person', 'head', 'event', 'state']
        );
    }

    #[DataProvider('damage')]
    public function testMissingOrDamagedEvidenceNeverReturnsPartialSuccess(string $kind): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        if ($kind === 'closed_row') {
            $f->close('removed');
        }
        match ($kind) {
            'missing' => $s->sql->execute('DELETE FROM sis_waitlist_entries WHERE id = ?', [$f->entry->value]),
            'closed_row', 'active_row' => $s->sql->update('sis_waitlist_entries', ['policy_position' => 2],
                ['id' => $f->entry->value]),
            'person' => $s->sql->update(
                'sis_waitlist_entries',
                ['person_id' => $s->legacyPerson($s->user(), 'Other', 'Fictional')->id->value],
                ['id' => $f->entry->value]
            ),
            'head' => $s->sql->update('sis_history_streams', ['version' => 1],
                ['stream_type' => 'section_waitlist', 'aggregate_id' => $f->section->value]),
            'event' => $s->sql->execute(
                'DELETE FROM sis_history_events WHERE stream_id = ?',
                [$s->sql->one('sis_history_streams', ['stream_type' => 'section_waitlist',
                'aggregate_id' => $f->section->value])['id']]
            ),
            'state' => $s->sql->update(
                'sis_waitlist_state',
                ['encrypted_state' => 'PRIVATE damaged source'],
                ['section_id' => $f->section->value]
            ),
        };
        $before = $f->facts();
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $f->verifier()->verify($f->entry)->describe();
        self::assertSame('unverified', $result['status']);
        self::assertNull($result['records_checked']);
        self::assertNotEmpty($result['issues']);
        self::assertSame($before, $f->facts());
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
    }

    public function testLimitsAndEnclosingTransactionsFailClosed(): void
    {
        $f = $this->fixture();
        $before = $f->facts();
        $result = $f->verifier()->verify($f->entry, 1)->describe();
        self::assertSame('limited', $result['status']);
        self::assertNull($result['records_checked']);
        self::assertSame(0, $result['events_checked']);
        $f->sis->connection->pdo->beginTransaction();
        try {
            self::assertSame(['unavailable'], $f->verifier()->verify($f->entry)->issues);
        } finally {
            $f->sis->connection->pdo->rollBack();
        }
        self::assertSame($before, $f->facts());
        foreach ([0, 2001] as $limit) {
            try {
                $f->verifier()->verify($f->entry, $limit);
                self::fail('An invalid replay allowance was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, $f->facts());
            }
        }
    }

    public function testLongerQueueHistoryUsesOneExactMultiPageAllowance(): void
    {
        $f = $this->fixture();
        $s = $f->sis;
        $service = $s->waitlists();
        for ($index = 0; $index < 24; $index++) {
            $review = $service->reviewPolicy(
                $f->section,
                new \Domain\Registration\Waitlist\WaitlistPolicy(true, 50 + ($index % 2))->describe(),
                2 + $index,
                'Fictional later policy decision.'
            );
            $service->confirmPolicy(
                $f->section,
                new EntityId($review['operation_id']),
                $review['policy'],
                $review['version'],
                $review['reason'],
                $review['review_token']
            );
        }
        $before = $f->facts();
        $s->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $f->verifier()->verify($f->entry, 26)->describe();
        self::assertSame('verified', $result['status']);
        self::assertSame(26, $result['events_checked']);
        self::assertSame(26, $result['position']);
        self::assertSame(1, $result['records_checked']);
        self::assertSame('limited', $f->verifier()->verify($f->entry, 25)->status);
        self::assertSame($before, $f->facts());
    }

    public function testUnavailableProvidersReturnNoPrivateExceptionOrPartialRecordCount(): void
    {
        $f = $this->fixture();
        $before = $f->facts();
        $records = $this->createStub(WaitlistRepository::class);
        $records->method('active')->willReturnCallback($f->records()->active(...));
        $records->method('entry')->willThrowException(new \RuntimeException('PRIVATE unavailable provider'));
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $f->verifier($records)->verify($f->entry)->describe();
        self::assertSame('unverified', $result['status']);
        self::assertSame(['unavailable'], $result['issues']);
        self::assertNull($result['records_checked']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        self::assertSame($before, $f->facts());
    }

    public function testConcurrentRoutingChangesTakePrecedenceOverACompletedReplay(): void
    {
        $f = $this->fixture();
        $real = $f->records();
        $records = $this->createStub(WaitlistRepository::class);
        $records->method('entry')->willReturnCallback(function (EntityId $entry) use ($real, $f): ?array {
            // Change routing during the verification read, between its two complete boundaries.
            $f->sis->sql->update(
                'sis_persons',
                ['owner_user_id' => $f->sis->user()->value],
                ['id' => $f->sis->admissions->student($f->student)->personId->value]
            );
            return $real->entry($entry);
        });
        $records->method('active')->willReturnCallback($real->active(...));
        $result = $f->verifier($records)->verify($f->entry)->describe();
        self::assertSame('changed', $result['status']);
        self::assertNull($result['records_checked']);
        self::assertSame([], $result['issues']);
    }
}
