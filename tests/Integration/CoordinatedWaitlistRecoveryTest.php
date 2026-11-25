<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\ValueObject\AccountId;
use Domain\Registration\Service\RegistrationUpdatesService;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Registration\Waitlist\WaitlistLedger;
use Domain\Registration\Waitlist\WaitlistPolicy;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlRegistrationUpdatesRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WorkflowRecoveryFixture;

/** The same fictional service/transaction scenarios run on SQLite and explicitly opted-in native storage. */
final class CoordinatedWaitlistRecoveryTest extends TestCase
{
    private WorkflowRecoveryFixture $fixture;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('The process-loss checks require POSIX signals.');
        }
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
        $this->fixture = new WorkflowRecoveryFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
    }

    private function queue(int $count): array
    {
        $s = $this->fixture->sis;
        $section = $s->section(1, 1250);
        $filler = $s->student($s->user());
        $s->service->register($filler->id, $section->id, true);
        $waitlists = $s->waitlists();
        $review = $waitlists->reviewPolicy(
            $section->id,
            new WaitlistPolicy(true, 50)->describe(),
            0,
            'Fictional coordinated recovery policy.'
        );
        $waitlists->confirmPolicy(
            $section->id,
            new EntityId($review['operation_id']),
            $review['policy'],
            $review['version'],
            $review['reason'],
            $review['review_token']
        );
        $students = $entries = [];
        for ($index = 0; $index < $count; $index++) {
            $students[] = $s->student($s->user());
        }
        // Real accepted queue order deliberately differs from canonical student-lock/proof order.
        usort($students, static fn($a, $b): int => strcmp($b->id->value, $a->id->value));
        foreach ($students as $student) {
            $review = $waitlists->review($student->id, $section->id, 'requested', true, null);
            $entries[] = $waitlists->confirm(
                $student->id,
                $section->id,
                new EntityId($review['operation_id']),
                $review['action'],
                true,
                $review['revision'],
                $review['review_token'],
                $review['reason']
            )['entry_id'];
        }
        $this->drop($filler->id, $section->id);
        // Ordinary fictional capacity setup, then a genuine publication for the future group.
        $s->sql->update('sis_sections', ['capacity' => $count], ['id' => $section->id->value]);
        return [$section, $students, $entries, $this->fixture->publishOffering($section)];
    }

    private function review(EntityId $section, array $students): array
    {
        $selection = new RegistrationBatchSelection(
            $section,
            ...array_map(static fn($s): EntityId => $s->id, $students),
        );
        return [
            'section' => $section->value,
            'students' => array_map(static fn(EntityId $id): string => $id->value, $selection->students),
            'review' => $this->fixture->sis->registrationBatches()->review($selection, true),
        ];
    }

    private function confirm(array $command): array
    {
        return $this->fixture->sis->registrationBatches()->commit(
            new EntityId($command['review']['operation_id']),
            new RegistrationBatchSelection(
                new EntityId($command['section']),
                ...array_map(static fn(string $id): EntityId => new EntityId($id), $command['students'])
            ),
            $command['review']['revision'],
            $command['review']['review_token'],
            true
        );
    }

    private function drop(EntityId $student, EntityId $section): void
    {
        $service = $this->fixture->sis->drops;
        $review = $service->review($student, $section);
        $service->commit(
            $student,
            $section,
            new EntityId($review['enrollment_id']),
            $review['version'],
            new EntityId($review['operation_id']),
            $review['revision'],
            $review['effective_on']
        );
    }

    private function active(EntityId $section): array
    {
        return array_column(
            $this->fixture->sis->waitlistLedger()->load($section, WaitlistLedger::budget())->active(),
            'student_id'
        );
    }

    private function ids(array $students): array
    {
        return array_map(static fn($student): string => $student->id->value, $students);
    }

    private function verifyAccepted(array $receipt, array $students, array $publications): void
    {
        $s = $this->fixture->sis;
        self::assertSame($this->ids($students), array_column($receipt['registrations'], 'student_id'));
        self::assertSame(count($students), $receipt['created']);
        foreach ($students as $student) {
            $account = $s->sql->one('sis_student_accounts', ['student_id' => $student->id->value]);
            self::assertSame(1250, $s->accounts->get(new AccountId($account['id']))->balance()->minor);
            $inputs = $s->inputVerifier()->verify($student->id, new HistoryReadBudget());
            self::assertCount(1, $inputs->items);
            $credit = $s->sql->one('sis_academic_credits', ['student_id' => $student->id->value]);
            self::assertSame(
                $publications['course'],
                $s->sql->one('sis_credit_course_versions', ['id' => $credit['id']])['course_version_id']
            );
            self::assertSame(
                $publications['section'],
                $s->sql->one('sis_enrollment_section_versions', ['id' => $credit['source_id']])['publication_id']
            );
            self::assertCount(1, $s->sql->rows('sis_registration_updates', ['student_id' => $student->id->value]));
        }
    }

    public static function lossPoints(): array
    {
        return ['staged transaction' => [false], 'accepted reply lost' => [true]];
    }

    #[DataProvider('lossPoints')]
    public function testProcessLossPreservesTheEntireFiveStudentQueueAndAcceptedRetry(bool $committed): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        [$section, $students, , $publications] = $this->queue(5);
        $command = $this->review($section->id, $students);
        $before = $r->facts();
        $worker = $r->start(
            'confirm_registration',
            $command,
            $committed ? null : 'registration.batch_completed',
            $committed
        );
        $r->send($worker, 'go');
        $r->await($worker, $committed ? 'committed' : 'staged');
        if (!$committed) {
            self::assertSame($before, $r->facts());
        }
        $r->kill($worker);
        if (!$committed) {
            self::assertSame($before, $r->facts());
            self::assertSame($this->ids($students), $this->active($section->id));
        }
        $receipt = $this->confirm($command);
        $this->verifyAccepted($receipt, $students, $publications);
        self::assertSame([], $this->active($section->id));
        $this->drop($students[0]->id, $section->id);
        $actor = $s->access->actor;
        $s->access->actor = $s->admissions->person($students[0]->personId)->ownerUserId;
        $updates = new RegistrationUpdatesService(
            new SqlRegistrationUpdatesRepository($s->sql),
            new SqlPersonAccountRepository($s->sql, $s->admissions, new RandomInstitutionalIdGenerator()),
            $s->admissions,
            $s->waitlistLedger(),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->clock
        );
        $notice = $updates->page()['items'][0];
        $updates->delete(new EntityId($notice['id']), $notice['revision']);
        $s->access->actor = $actor;
        $replacement = $s->student($s->user());
        $s->service->register($replacement->id, $section->id, true);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $later = $r->facts();
        self::assertSame($receipt, $this->confirm($command));
        self::assertSame($later, $r->facts());
        self::assertNull($s->registration->enrollment($students[0]->id, $section->id));
        self::assertCount(4, $s->sql->rows('sis_registration_updates'));
    }

    public static function competingOutcomes(): array
    {
        return ['first interrupted' => [false], 'first accepted' => [true]];
    }

    public function testConcurrentRetriesReturnOneFifoGroupReceiptWithoutRepeatingAnyEffects(): void
    {
        $r = $this->fixture;
        [$section, $students, , $publications] = $this->queue(3);
        $command = $this->review($section->id, $students);
        $before = $r->facts();
        $first = $r->start('confirm_registration', $command, 'registration.batch_completed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts());
        $second = $r->start('confirm_registration', $command);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        $r->send($first, 'continue');
        $one = $r->result($first);
        $two = $r->result($second);
        self::assertSame('accepted', $one['state']);
        self::assertSame('accepted', $two['state']);
        self::assertSame($one['result'], $two['result']);
        $this->verifyAccepted($one['result'], $students, $publications);
        self::assertSame([], $this->active($section->id));
    }

    public function testWorkerUsesPrivateTranslationBootstrapDespiteAnUnavailableApplicationPath(): void
    {
        $r = $this->fixture;
        [$section, $students, , $publications] = $this->queue(2);
        $command = $this->review($section->id, $students);
        $original = getenv('APP_BASE_PATH');
        try {
            putenv('APP_BASE_PATH=/fictional/unavailable-application');
            $worker = $r->start('confirm_registration', $command);
            $r->send($worker, 'go');
            $result = $r->result($worker);
        } finally {
            putenv($original === false ? 'APP_BASE_PATH' : 'APP_BASE_PATH=' . $original);
        }
        self::assertSame('accepted', $result['state']);
        $this->verifyAccepted($result['result'], $students, $publications);
    }

    #[DataProvider('competingOutcomes')]
    public function testOverlappingPrefixesRecheckTheQueueAfterTheFirstGroupCommitsOrDies(bool $committed): void
    {
        $r = $this->fixture;
        [$section, $students, , $publications] = $this->queue(3);
        $small = $this->review($section->id, array_slice($students, 0, 2));
        $large = $this->review($section->id, $students);
        $before = $r->facts();
        $first = $r->start('confirm_registration', $small, 'registration.batch_completed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts());
        $second = $r->start('confirm_registration', $large);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($committed) {
            $r->send($first, 'continue');
            $one = $r->result($first);
            self::assertSame('accepted', $one['state']);
        } else {
            $r->kill($first);
        }
        $two = $r->result($second);
        self::assertSame($committed ? 'rejected' : 'accepted', $two['state']);
        if ($committed) {
            $this->verifyAccepted($one['result'], array_slice($students, 0, 2), $publications);
            self::assertSame([$students[2]->id->value], $this->active($section->id));
            self::assertNull($r->sis->registration->enrollment($students[2]->id, $section->id));
        } else {
            $this->verifyAccepted($two['result'], $students, $publications);
            self::assertSame([], $this->active($section->id));
            $after = $r->facts();
            try {
                $this->confirm($small);
                self::fail('The interrupted smaller prefix replaced the accepted larger group.');
            } catch (ConcurrentModificationException) {
                self::assertSame($after, $r->facts());
            }
        }
    }

    #[DataProvider('competingOutcomes')]
    public function testOrdinaryLaterRequestCannotBypassAnInterruptedPrefix(bool $committed): void
    {
        $r = $this->fixture;
        [$section, $students] = $this->queue(3);
        $group = $this->review($section->id, array_slice($students, 0, 2));
        $first = $r->start('confirm_registration', $group, 'registration.batch_completed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('register', ['student' => $students[2]->id->value, 'section' => $section->id->value]);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($committed) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame($committed ? 'accepted' : 'rejected', $r->result($second)['state']);
        self::assertSame($committed ? [] : $this->ids($students), $this->active($section->id));
        self::assertSame($committed ? 3 : 0, $r->sis->registration->sectionCount($section->id));
        self::assertCount($committed ? 3 : 0, $r->sis->sql->rows('sis_registration_updates'));
    }

    #[DataProvider('competingOutcomes')]
    public function testReviewedPriorityBypassKeepsItsOwnQueueBoundaryWhenAGroupCompetes(bool $committed): void
    {
        $r = $this->fixture;
        [$section, $students, $entries] = $this->queue(3);
        $group = $this->review($section->id, array_slice($students, 0, 2));
        $bypass = $r->sis->waitlistBypasses()->review(
            student: $students[2]->id,
            section: $section->id,
            entry: new EntityId($entries[2]),
            reason: 'Fictional independently authorized priority exception.',
        );
        $first = $r->start('confirm_registration', $group, 'registration.batch_completed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        $second = $r->start('waitlist_bypass', ['section' => $section->id->value, 'review' => $bypass]);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($committed) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        self::assertSame($committed ? 'rejected' : 'accepted', $r->result($second)['state']);
        $root = $r->sis->waitlistLedger()->load($section->id, WaitlistLedger::budget());
        self::assertSame(
            $committed ? [$students[2]->id->value] : $this->ids(array_slice($students, 0, 2)),
            array_column($root->active(), 'student_id')
        );
        self::assertCount($committed ? 0 : 1, $root->bypasses());
        self::assertCount($committed ? 2 : 1, $r->sis->sql->rows('sis_registration_updates'));
    }
}
