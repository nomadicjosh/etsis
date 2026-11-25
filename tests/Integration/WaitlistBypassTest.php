<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Waitlist\WaitlistBypass;
use Domain\Registration\Waitlist\WaitlistLedger;
use Domain\Registration\Waitlist\WaitlistService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class WaitlistBypassTest extends TestCase
{
    private function fixture(bool $seat = true): array
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
        $f = new SisFixture();
        $section = $f->section(capacity: 1);
        $filler = $f->student($f->user());
        $f->service->register($filler->id, $section->id, true);
        $waitlists = $f->waitlists();
        $policy = $waitlists->reviewPolicy($section->id, [
            'enabled' => true, 'maximum_active' => 50,
            'ordering' => 'accepted_fifo', 'allocation' => 'oldest_may_register',
        ], 0, 'Fictional policy approval');
        $waitlists->confirmPolicy(
            $section->id,
            new EntityId($policy['operation_id']),
            $policy['policy'],
            $policy['version'],
            $policy['reason'],
            $policy['review_token']
        );
        $students = $entries = [];
        for ($i = 0; $i < 3; $i++) {
            $students[] = $student = $f->student($f->user());
            $r = $waitlists->review($student->id, $section->id, 'requested', true, null);
            $entries[] = $waitlists->confirm(
                $student->id,
                $section->id,
                new EntityId($r['operation_id']),
                $r['action'],
                true,
                $r['revision'],
                $r['review_token'],
                $r['reason']
            )['entry_id'];
        }
        if ($seat) {
            $r = $f->drops->review($filler->id, $section->id);
            $f->drops->commit(
                $filler->id,
                $section->id,
                new EntityId($r['enrollment_id']),
                $r['version'],
                new EntityId($r['operation_id']),
                $r['revision'],
                $r['effective_on']
            );
        }
        return [$f, $section->id, $students, $entries];
    }

    private function input(EntityId $section, array $r): WaitlistBypass
    {
        return new WaitlistBypass(
            $section,
            new EntityId($r['student_id']),
            new EntityId($r['person_id']),
            new EntityId($r['entry_id']),
            new EntityId($r['operation_id']),
            $r['version'],
            $r['assessment_revision'],
            $r['reason'],
            $r['review_token']
        );
    }

    private function facts(SisFixture $f): array
    {
        return array_map($f->sql->rows(...), ['sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_waitlist_state', 'sis_waitlist_entries', 'sis_enrollments', 'sis_academic_credits',
            'sis_student_accounts', 'sis_account_entries', 'sis_audit', 'sis_sections', 'sis_courses', 'sis_students']);
    }

    public function testReviewedLaterRequestRegistersAtomicallyWithoutRemovingEarlierPlacesAndRetrySurvivesDrop(): void
    {
        [$f, $section, $students, $entries] = $this->fixture();
        $service = $f->waitlistBypasses();
        $before = $this->facts($f);
        $r = $service->review(
            $students[1]->id,
            $section,
            new EntityId($entries[1]),
            'Fictional <b>priority</b> reason'
        );
        self::assertTrue($r['can_confirm']);
        self::assertSame(1, $r['earlier_requests']);
        self::assertSame($before, $this->facts($f));
        $input = $this->input($section, $r);
        $result = $service->confirm($input);
        self::assertSame($entries[1], $result['entry_id']);
        self::assertSame($result['enrollment_id'], $f->registration->enrollment($students[1]->id, $section)->id->value);
        self::assertCount(1, $f->sql->rows('sis_academic_credits', ['student_id' => $students[1]->id->value]));
        $account = $f->sql->one('sis_student_accounts', ['student_id' => $students[1]->id->value]);
        self::assertCount(1, $f->sql->rows('sis_account_entries', ['account_id' => $account['id']]));
        $root = $f->waitlistLedger()->load($section, WaitlistLedger::budget());
        self::assertSame([$entries[0], $entries[2]], array_column($root->active(), 'id'));
        $history = \EventStoreFixture::history($f->connection->queryBuilder());
        $stored = $history->latest(new \Domain\Shared\EventSourcing\StreamReference(
            new \Domain\Registration\Waitlist\WaitlistId($section->value),
            'section_waitlist'
        ));
        self::assertSame([$entries[0]], $stored->event->payload()['priority_bypass']['earlier_entry_ids']);
        self::assertSame($r['reason'], $stored->context->reason);
        self::assertSame($result['enrollment_id'], $stored->context->causation->value);
        self::assertSame($r['version'], $stored->event->payload()['priority_bypass']['queue_version']);
        $replayed = \Domain\Registration\Waitlist\SectionWaitlist::reconstituteFromEventStream(
            \EventStoreFixture::framework($f->connection->queryBuilder())->getAggregateHistoryFor(
                new \Domain\Registration\Waitlist\WaitlistId($section->value)
            )
        );
        self::assertSame($root->projection(), $replayed->projection());
        $owner = $f->admissions->person($students[1]->personId)->ownerUserId;
        $f->access->actor = $owner;
        $f->access->staff = false;
        $mine = $f->waitlists()->mine($students[1]->id);
        self::assertSame('registered', $mine['items'][0]['status']);
        self::assertStringNotContainsString('priority_bypass', json_encode($mine, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($r['reason'], json_encode($mine, JSON_THROW_ON_ERROR));
        $f->access->actor = $stored->context->actor;
        $f->access->staff = true;
        $f->clock->time = $f->clock->time->modify('+21 minutes');
        self::assertSame($result, $service->confirm($input));
        $drop = $f->drops->review($students[1]->id, $section);
        $f->drops->commit(
            $students[1]->id,
            $section,
            new EntityId($drop['enrollment_id']),
            $drop['version'],
            new EntityId($drop['operation_id']),
            $drop['revision'],
            $drop['effective_on']
        );
        $after = $this->facts($f);
        self::assertSame($result, $service->confirm($input));
        self::assertSame($after, $this->facts($f));
        self::assertNull($f->registration->enrollment($students[1]->id, $section));
        $history = $service->history($section);
        self::assertSame($r['reason'], $history['items'][0]['reason']);
        self::assertSame($result['enrollment_id'], $history['items'][0]['enrollment_id']);
        self::assertArrayNotHasKey('priority_bypass', $history['items'][0]);
        self::assertStringNotContainsString('priority', json_encode($input, JSON_THROW_ON_ERROR));
    }

    public function testBypassNeedsAllThreeDutiesAndNeverGrantsOwnerQueueAccess(): void
    {
        [$f, $section, $students, $entries] = $this->fixture();
        $f->access->staff = false;
        $duties = [WaitlistBypass::DUTY, WaitlistService::DUTY, 'sis:registration:manage'];
        foreach ($duties as $missing) {
            $f->access->grants = array_values(array_diff($duties, [$missing]));
            try {
                $f->waitlistBypasses()->review(
                    $students[1]->id,
                    $section,
                    new EntityId($entries[1]),
                    'Fictional reason'
                );
                self::fail('Incomplete bypass authority was accepted.');
            } catch (AccessDeniedException) {
                self::assertCount(3, $f->sql->rows('sis_waitlist_entries', ['status' => 'active']));
            }
        }
        $f->access->grants = $duties;
        $r = $f->waitlistBypasses()->review($students[1]->id, $section, new EntityId($entries[1]), 'Fictional reason');
        self::assertTrue($r['can_confirm']);
    }

    public function testOldestOutsiderClosedEntryAndChangedQueueCannotUseAnEarlierReview(): void
    {
        [$f, $section, $students, $entries] = $this->fixture();
        foreach ([$students[0]->id, $f->student($f->user())->id] as $student) {
            try {
                $f->waitlistBypasses()->review($student, $section, new EntityId($entries[0]), 'Fictional reason');
                self::fail('A bypass was offered without an active later request.');
            } catch (ConcurrentModificationException) {
                self::assertCount(3, $f->waitlists()->listing($section)['items']);
            }
        }
        $r = $f->waitlistBypasses()->review($students[1]->id, $section, new EntityId($entries[1]), 'Fictional reason');
        $remove = $f->waitlists()->review(
            $students[0]->id,
            $section,
            'removed',
            true,
            new EntityId($entries[0]),
            'Fictional removal'
        );
        $f->waitlists()->confirm(
            $students[0]->id,
            $section,
            new EntityId($remove['operation_id']),
            'removed',
            true,
            $remove['revision'],
            $remove['review_token'],
            $remove['reason']
        );
        $before = $this->facts($f);
        try {
            $f->waitlistBypasses()->confirm($this->input($section, $r));
            self::fail('A changed queue retained an unaccepted bypass review.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($f));
        }
    }

    public function testFullCapacityAndOtherEligibilityFailuresRemainBlocking(): void
    {
        [$f, $section, $students, $entries] = $this->fixture(seat: false);
        $r = $f->waitlistBypasses()->review($students[1]->id, $section, new EntityId($entries[1]), 'Fictional reason');
        self::assertFalse($r['can_confirm']);
        $before = $this->facts($f);
        try {
            $f->waitlistBypasses()->confirm($this->input($section, $r));
            self::fail('A priority bypass exceeded section capacity.');
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($f));
        }
    }

    public function testExpiredAndTamperedReviewsWriteNothing(): void
    {
        [$f, $section, $students, $entries] = $this->fixture();
        $r = $f->waitlistBypasses()->review($students[1]->id, $section, new EntityId($entries[1]), 'Fictional reason');
        $before = $this->facts($f);
        foreach (['reason', 'student_id', 'entry_id', 'assessment_revision'] as $key) {
            $wrong = $r;
            $wrong[$key] = match ($key) {
                'reason' => 'Changed reason', 'student_id' => $students[2]->id->value,
                'entry_id' => $entries[2], default => str_repeat('a', 64),
            };
            try {
                $f->waitlistBypasses()->confirm($this->input($section, $wrong));
                self::fail('A tampered bypass proof was accepted.');
            } catch (ValidationException | ConcurrentModificationException) {
                self::assertSame($before, $this->facts($f));
            }
        }
        $f->clock->time = $f->clock->time->modify('+20 minutes');
        try {
            $f->waitlistBypasses()->confirm($this->input($section, $r));
            self::fail('An unaccepted expired review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->facts($f));
        }
    }

    public function testInactiveStudentsAndMissingPrerequisitesRemainOrdinaryRegistrationIssues(): void
    {
        foreach (['student', 'prerequisite'] as $block) {
            [$f, $section, $students, $entries] = $this->fixture();
            if ($block === 'student') {
                $f->sql->update('sis_students', ['active' => 0], ['id' => $students[1]->id->value]);
            } else {
                $course = $f->registration->section($section)->courseId;
                $required = $f->registration->section($f->section()->id)->courseId;
                $f->sql->insert('sis_prerequisites', [
                    'id' => EntityId::generate()->value, 'course_id' => $course->value,
                    'required_course_id' => $required->value,
                ]);
            }
            $r = $f->waitlistBypasses()->review(
                $students[1]->id,
                $section,
                new EntityId($entries[1]),
                'Fictional reason'
            );
            self::assertFalse($r['can_confirm']);
            self::assertNotEmpty($r['student']['issues']);
            $before = $this->facts($f);
            try {
                $f->waitlistBypasses()->confirm($this->input($section, $r));
                self::fail('A bypass waived an ordinary eligibility failure.');
            } catch (ValidationException) {
                self::assertSame($before, $this->facts($f));
            }
        }
    }

    public function testFailureAfterAcademicBillingAndQueueWritesRollsBackAndKeepsTheOriginalReviewUsable(): void
    {
        [$f, $section, $students, $entries] = $this->fixture();
        $r = $f->waitlistBypasses()->review(
            $students[1]->id,
            $section,
            new EntityId($entries[1]),
            'Fictional reason'
        );
        $before = $this->facts($f);
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(function ($actor, $action, $subject) use ($f): void {
            $f->audit->record($actor, $action, $subject);
            if ($action === 'registration.waitlist_registered') {
                throw new \RuntimeException('Fictional late audit failure');
            }
        });
        $original = $f->service;
        $f->service = new \Domain\Registration\Service\RegistrationService(
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->termFees(),
            $audit,
            $f->clock,
            $f->drops,
            $f->academicCredits(),
            $f->enrollmentLedger(),
            $f->activityLedger(),
            $f->primaryProgramLedger(),
            $f->workPursuits(),
            $f->prerequisites(),
            $f->schedules(),
            $f->corequisites(),
            $f->windowPolicies(),
            $f->waitlistGuard($audit),
            $f->graduateGate(),
        );
        try {
            $f->waitlistBypasses()->confirm($this->input($section, $r));
            self::fail('A partial bypass registration survived a failed required audit.');
        } catch (\RuntimeException $failure) {
            self::assertSame('Fictional late audit failure', $failure->getMessage());
            self::assertSame($before, $this->facts($f));
        }
        $f->service = $original;
        self::assertSame($entries[1], $f->waitlistBypasses()->confirm($this->input($section, $r))['entry_id']);
    }

    public function testFinalAuditSourceAndAuthorityFailuresRollBackEveryRegistrationEffect(): void
    {
        foreach (['audit', 'authority', 'capacity', 'fee', 'expiry'] as $change) {
            [$f, $section, $students, $entries] = $this->fixture();
            $r = $f->waitlistBypasses()->review(
                $students[1]->id,
                $section,
                new EntityId($entries[1]),
                'Fictional reason'
            );
            $before = $this->facts($f);
            $audit = $this->createStub(AuditTrail::class);
            $audit->method('record')->willReturnCallback(function () use ($f, $section, $change): void {
                match ($change) {
                    'audit' => throw new \RuntimeException('Fictional audit failure'),
                    'authority' => $f->access->staff = false,
                    'capacity' => $f->sql->update('sis_sections', ['capacity' => 2], ['id' => $section->value]),
                    'fee' => $f->sql->update('sis_sections', ['fee_minor' => 11000], ['id' => $section->value]),
                    'expiry' => $f->clock->time = $f->clock->time->modify('+20 minutes'),
                };
            });
            try {
                $f->waitlistBypasses($audit)->confirm($this->input($section, $r));
                self::fail('A final bypass check failed without rollback.');
            } catch (
                AccessDeniedException | ConcurrentModificationException | ValidationException | \RuntimeException
            ) {
                self::assertSame($before, $this->facts($f));
            }
        }
    }

    public function testChangedPricesActorAndSectionCannotReuseTheSelectedProof(): void
    {
        foreach (['price', 'actor', 'section'] as $change) {
            [$f, $section, $students, $entries] = $this->fixture();
            $r = $f->waitlistBypasses()->review(
                $students[1]->id,
                $section,
                new EntityId($entries[1]),
                'Fictional reason'
            );
            if ($change === 'price') {
                $f->sql->update('sis_sections', ['fee_minor' => 12000], ['id' => $section->value]);
            } elseif ($change === 'actor') {
                $f->access->actor = $f->user();
            } else {
                $section = $f->section()->id;
            }
            $before = $this->facts($f);
            try {
                $f->waitlistBypasses()->confirm($this->input($section, $r));
                self::fail('A selected review was used with a changed source or scope.');
            } catch (ValidationException | ConcurrentModificationException | AccessDeniedException) {
                self::assertSame($before, $this->facts($f));
            }
        }
    }

    public function testReplayRejectsBypassEvidenceThatDoesNotMatchTheActualEarlierQueue(): void
    {
        [$f, $section, $students, $entries] = $this->fixture();
        $r = $f->waitlistBypasses()->review(
            $students[1]->id,
            $section,
            new EntityId($entries[1]),
            'Fictional reason'
        );
        $f->waitlistBypasses()->confirm($this->input($section, $r));
        $page = \EventStoreFixture::history($f->connection->queryBuilder())->page(
            new \Domain\Shared\EventSourcing\StreamReference(
                new \Domain\Registration\Waitlist\WaitlistId($section->value),
                'section_waitlist'
            )
        );
        $last = $page->events[array_key_last($page->events)];
        foreach (['earlier_entry_ids' => [$entries[2]], 'queue_version' => 1] as $key => $value) {
            $root = \Domain\Registration\Waitlist\SectionWaitlist::root(
                new \Domain\Registration\Waitlist\WaitlistId($section->value)
            );
            foreach (array_slice($page->events, 0, -1) as $stored) {
                $root->accept($stored);
            }
            $payload = $last->event->payload();
            $payload['priority_bypass'][$key] = $value;
            $payload['intent'] = \Domain\Registration\Waitlist\SectionWaitlist::intent(
                $payload,
                $last->context->reason
            );
            $event = \Domain\Registration\Waitlist\WaitlistPlaceWasChanged::occur(
                new \Domain\Registration\Waitlist\WaitlistId($section->value),
                $payload,
                ['__aggregate_type' => 'section_waitlist']
            )->withPlayhead($last->event->playhead());
            try {
                $root->applyAccepted($event, $last->context);
                self::fail('Replay accepted inconsistent bypass evidence.');
            } catch (\Domain\Shared\EventSourcing\CorruptHistoryException) {
                self::assertSame($r['version'], $root->playhead());
            }
        }
    }

    public function testRestrictedHistoryPagesFreezeTheirBoundaryAcrossLaterBypasses(): void
    {
        [$f, $section, $students, $entries] = $this->fixture();
        for ($i = 0; $i < 20; $i++) {
            $students[] = $student = $f->student($f->user());
            $r = $f->waitlists()->review($student->id, $section, 'requested', true, null);
            $entries[] = $f->waitlists()->confirm(
                $student->id,
                $section,
                new EntityId($r['operation_id']),
                $r['action'],
                true,
                $r['revision'],
                $r['review_token'],
                $r['reason']
            )['entry_id'];
        }
        $f->sql->update('sis_sections', ['capacity' => 22], ['id' => $section->value]);
        $service = $f->waitlistBypasses();
        for ($i = 1; $i <= 21; $i++) {
            $r = $service->review(
                $students[$i]->id,
                $section,
                new EntityId($entries[$i]),
                'Fictional reason ' . $i
            );
            $service->confirm($this->input($section, $r));
        }
        $first = $service->history($section);
        self::assertCount(20, $first['items']);
        self::assertSame('Fictional reason 21', $first['items'][0]['reason']);
        $r = $service->review($students[22]->id, $section, new EntityId($entries[22]), 'Fictional newest reason');
        $service->confirm($this->input($section, $r));
        $last = $service->history($section, $first['next_before'], $first['through']);
        self::assertCount(1, $last['items']);
        self::assertSame('Fictional reason 1', $last['items'][0]['reason']);
        self::assertSame($first['through'], $last['through']);
        self::assertSame($first['items'], $service->history($section, through: $first['through'])['items']);
        self::assertSame('Fictional newest reason', $service->history($section)['items'][0]['reason']);
        $f->access->staff = false;
        $f->access->grants = ['sis:registration:manage', WaitlistService::DUTY];
        $this->expectException(AccessDeniedException::class);
        $service->history($section);
    }
}
