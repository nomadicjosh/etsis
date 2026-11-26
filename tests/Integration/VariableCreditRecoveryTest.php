<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Finance\Enum\BillingBasis;
use Domain\Finance\Service\TermBillingService;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Registration\Waitlist\WaitlistLedger;
use Domain\Registration\Waitlist\WaitlistPolicy;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use EventStoreFixture;
use Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OfferingCreditFixture;
use Tests\Support\WorkflowRecoveryFixture;

/** Identical bounded fictional service scenarios on SQLite and opted-in native storage. */
final class VariableCreditRecoveryTest extends TestCase
{
    private WorkflowRecoveryFixture $fixture;

    protected function setUp(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Process-loss qualification requires POSIX signals.');
        }
        require_once dirname(__DIR__) . '/Support/WorkflowRecoveryFixture.php';
        require_once dirname(__DIR__) . '/Support/OfferingCreditFixture.php';
        $this->fixture = new WorkflowRecoveryFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->close();
        }
    }

    private function offering(int $capacity): OfferingCreditFixture
    {
        $s = $this->fixture->sis;
        $f = new OfferingCreditFixture($s, 1250);
        $s->sql->update('sis_sections', ['capacity' => $capacity], ['id' => $f->section->id->value]);
        $f->range();
        $f->bind();
        $f->choose(3000, true);
        $f->select($f->publish());
        $db = $s->connection->queryBuilder();
        new TermBillingService(
            new EventSourcedBillingRuleRepository(
                $s->sql,
                EventStoreFixture::framework($db),
                $s->transactions,
                EventStoreFixture::financialWriter($db)
            ),
            $s->access,
            $s->transactions,
            $s->audit,
            $s->financialCommands()
        )->define(
            EntityId::generate(),
            $s->term,
            null,
            'Fictional variable-credit rate',
            'tuition',
            new Money(101, 'USD'),
            'Fictional exact credit-price publication.',
            BillingBasis::RegisteredCredit
        );
        return $f;
    }

    private function command(EntityId $section, array $students, array $choices = []): array
    {
        $selection = new RegistrationBatchSelection($section, ...$students)->withCreditChoices($choices);
        return ['section' => $section->value,
            'students' => array_map(static fn (EntityId $id): string => $id->value, $selection->students),
            'credit_choices' => $choices,
            'review' => $this->fixture->sis->registrationBatches()->review($selection, true)];
    }

    private function confirm(array $command): array
    {
        return $this->fixture->sis->registrationBatches()->commit(
            new EntityId($command['review']['operation_id']),
            new RegistrationBatchSelection(
                new EntityId($command['section']),
                ...array_map(static fn (string $id): EntityId => new EntityId($id), $command['students'])
            )
                ->withCreditChoices($command['credit_choices']),
            $command['review']['revision'],
            $command['review']['review_token'],
            true
        );
    }

    private function verify(OfferingCreditFixture $offering, array $choices): void
    {
        $s = $this->fixture->sis;
        foreach ($choices as $student => $milli) {
            $id = new EntityId($student);
            $enrollment = $s->registration->enrollment($id, $offering->section->id);
            self::assertNotNull($enrollment);
            $credit = $s->sql->one('sis_academic_credits', ['source_id' => $enrollment->id->value]);
            self::assertSame($milli, (int) $credit['attempted_milli']);
            self::assertSame(0, (int) $credit['completed_milli']);
            self::assertSame($milli, $s->academicCredits()->registrationLoadFacts(
                $enrollment,
                new HistoryReadBudget()
            )['credits_milli']);
            self::assertSame($milli, $s->enrollmentLedger()->summary($enrollment->id)['selected_credits_milli']);
            $account = $s->sql->one('sis_student_accounts', ['student_id' => $student]);
            // 101 minor units per credit, exact half-up rounding plus the fixed section fee.
            self::assertSame(
                1250 + intdiv(101 * $milli + 500, 1000),
                $s->accounts->get(new AccountId($account['id']))->balance()->minor
            );
            self::assertCount(1, $s->inputVerifier()->verify($id, new HistoryReadBudget())->items);
        }
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

    public static function lossPoints(): array
    {
        return ['staged transaction' => [false], 'accepted reply lost' => [true]];
    }

    #[DataProvider('lossPoints')]
    public function testGroupLossKeepsExactChoicesAndOriginalRetriesAfterAmendmentAndDrop(bool $committed): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $f = $this->offering(5);
        $students = $choices = [];
        foreach ([1000, 1500, 2500, 4000, 6000] as $milli) {
            $student = $s->student($s->user())->id;
            $students[] = $student;
            $choices[$student->value] = $milli;
        }
        $command = $this->command($f->section->id, $students, $choices);
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
        }
        $receipt = $this->confirm($command);
        self::assertSame(5, $receipt['created']);
        $this->verify($f, $choices);
        $f->choose(3000, false);
        $f->amend($f->publish());
        $this->drop($students[0], $f->section->id);
        $account = $s->sql->one('sis_student_accounts', ['student_id' => $students[0]->value]);
        self::assertSame(0, $s->accounts->get(new AccountId($account['id']))->balance()->minor);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $later = $r->facts();
        self::assertSame($receipt, $this->confirm($command));
        self::assertSame($later, $r->facts());
        self::assertNull($s->registration->enrollment($students[0], $f->section->id));
        $changed = $command;
        $changed['credit_choices'][$students[0]->value] = 2000;
        try {
            $this->confirm($changed);
            self::fail('A different credit choice reused an accepted group receipt.');
        } catch (ConcurrentModificationException) {
            self::assertSame($later, $r->facts());
        }
    }

    public static function holderOutcomes(): array
    {
        return ['first interrupted' => [false], 'first accepted' => [true]];
    }

    #[DataProvider('holderOutcomes')]
    public function testFinalSeatCompetitorKeepsItsOwnCreditsAfterTheHolderCommitsOrDies(bool $committed): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $f = $this->offering(1);
        $one = $s->student($s->user())->id;
        $two = $s->student($s->user())->id;
        $command = $this->command($f->section->id, [$one], [$one->value => 1500]);
        $before = $r->facts();
        $first = $r->start('confirm_registration', $command, 'registration.batch_completed');
        $r->send($first, 'go');
        $r->await($first, 'staged');
        self::assertSame($before, $r->facts());
        $second = $r->start('register', ['student' => $two->value, 'section' => $f->section->id->value,
            'credits_milli' => 4500]);
        $r->send($second, 'go');
        $r->await($second, 'running');
        $r->awaitLock($second);
        if ($committed) {
            $r->send($first, 'continue');
            self::assertSame('accepted', $r->result($first)['state']);
        } else {
            $r->kill($first);
        }
        $result = $r->result($second);
        self::assertSame($committed ? 'rejected' : 'accepted', $result['state']);
        self::assertSame(1, $s->registration->sectionCount($f->section->id));
        self::assertNull($s->registration->enrollment($committed ? $two : $one, $f->section->id));
        $this->verify($f, [$committed ? $one->value : $two->value => $committed ? 1500 : 4500]);
        self::assertCount(1, $s->sql->rows('sis_academic_credits'));
        self::assertCount(1, $s->sql->rows('sis_student_accounts'));
    }

    #[DataProvider('lossPoints')]
    public function testWaitlistGroupLossPreservesRequestedQuantitiesAndOneOutcomeEach(bool $committed): void
    {
        $r = $this->fixture;
        $s = $r->sis;
        $f = $this->offering(1);
        $filler = $s->student($s->user())->id;
        $s->service->register($filler, $f->section->id, true, creditsMilli: 3000);
        $waitlists = $s->waitlists();
        $policy = $waitlists->reviewPolicy(
            $f->section->id,
            new WaitlistPolicy(true, 50)->describe(),
            0,
            'Fictional variable-credit waiting policy.'
        );
        $waitlists->confirmPolicy(
            $f->section->id,
            new EntityId($policy['operation_id']),
            $policy['policy'],
            $policy['version'],
            $policy['reason'],
            $policy['review_token']
        );
        $students = [$s->student($s->user())->id, $s->student($s->user())->id];
        usort($students, static fn (EntityId $a, EntityId $b): int => strcmp($b->value, $a->value));
        $choices = [$students[0]->value => 1500, $students[1]->value => 4500];
        foreach ($students as $student) {
            $review = $waitlists->review(
                $student,
                $f->section->id,
                'requested',
                true,
                null,
                creditsMilli: $choices[$student->value]
            );
            $waitlists->confirm(
                $student,
                $f->section->id,
                new EntityId($review['operation_id']),
                'requested',
                true,
                $review['revision'],
                $review['review_token'],
                creditsMilli: $choices[$student->value]
            );
        }
        $this->drop($filler, $f->section->id);
        $s->sql->update('sis_sections', ['capacity' => 2], ['id' => $f->section->id->value]);
        $f->amend($f->publish());
        // Deliberately supply no replacement choices: genuine accepted queue requests own their quantities.
        $command = $this->command($f->section->id, $students);
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
            self::assertSame(
                array_map(static fn (EntityId $id): string => $id->value, $students),
                array_column(
                    $s->waitlistLedger()->load($f->section->id, WaitlistLedger::budget())->active(),
                    'student_id'
                )
            );
        }
        $receipt = $this->confirm($command);
        self::assertSame(2, $receipt['created']);
        $this->verify($f, $choices);
        self::assertSame([], $s->waitlistLedger()->load($f->section->id, WaitlistLedger::budget())->active());
        foreach ($students as $student) {
            self::assertCount(1, $s->sql->rows('sis_registration_updates', ['student_id' => $student->value]));
        }
        $after = $r->facts();
        self::assertSame($receipt, $this->confirm($command));
        self::assertSame($after, $r->facts());
    }
}
