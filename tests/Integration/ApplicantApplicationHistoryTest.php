<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Admissions\Service\ApplicantApplicationHistoryService;
use Domain\Admissions\Service\AdmissionProgramCorrectionService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Identity\RandomInstitutionalIdGenerator;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlAdmissionUpdatesRepository;
use Infrastructure\Persistence\Repository\SqlApplicationFormRepository;
use Infrastructure\Persistence\Repository\SqlPersonAccountRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class ApplicantApplicationHistoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function fixture(bool $tracked = true): array
    {
        $f = new SisFixture();
        $f->clock->time = new \DateTimeImmutable('2026-07-01T10:00:00-07:00');
        $person = $f->legacyPerson($f->access->actor, 'Fictional', 'Applicant');
        $a = $tracked ? $f->submissionService()->apply(EntityId::generate(), $person->id, $f->program, $f->term)
        : $f->legacyApplication($person->id, $f->program, $f->term);
        return [$f, $a->id, $person];
    }

    private function service(SisFixture $f, ?AuditTrail $audit = null): ApplicantApplicationHistoryService
    {
        return new ApplicantApplicationHistoryService(
            $f->admissions,
            new SqlPersonAccountRepository($f->sql, $f->admissions, new RandomInstitutionalIdGenerator()),
            new SqlStudentRecordRepository($f->sql),
            $f->decisionLedger(),
            \EventStoreFixture::history($f->connection->queryBuilder()),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock
        );
    }

    private function programs(SisFixture $f): AdmissionProgramCorrectionService
    {
        return new AdmissionProgramCorrectionService(
            $f->admissions,
            $f->decisionLedger(),
            $f->applicationContext(),
            $f->entryTerms(),
            $f->withdrawalRepository(),
            $f->applicationRevisions(),
            new SqlApplicationFormRepository($f->sql),
            new SqlAdmissionUpdatesRepository($f->sql),
            new SqlAccessRepository($f->sql),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->dropCipher,
            $f->clock
        );
    }

    private function target(SisFixture $f): EntityId
    {
        $target = EntityId::generate();
        $f->sql->insert('sis_programs', ['id' => $target->value, 'code' => 'ARTS-UG', 'title' => 'Fictional Arts']);
        return $target;
    }

    public function testOwnerReadsOriginalAndPlannedProgramsWithoutStaffReasonsOrEventIdentities(): void
    {
        [$f, $id] = $this->fixture();
        $target = $this->target($f);
        $programs = $this->programs($f);
        $review = $programs->review($id, $target, 'Fictional confidential correction reason.');
        $programs->commit($id, $review['review_token']);
        $f->sql->update('sis_programs', ['title' => 'Fictional current Arts title'], ['id' => $target->value]);
        $before = $f->sql->rows('sis_history_events');
        $f->access->staff = false;
        $view = $this->service($f)->page($id);
        self::assertSame('submitted', $view['current']['status']);
        self::assertSame('Fictional current Arts title', $view['current']['program']['title']);
        self::assertSame('Fictional Arts', $view['items'][1]['program']['after']['title']);
        self::assertNotSame($view['original']['program']['code'], $view['current']['program']['code']);
        self::assertSame(['submission', 'program_correction'], array_column($view['items'], 'kind'));
        self::assertSame($before, $f->sql->rows('sis_history_events'));
        $encoded = json_encode($view, JSON_THROW_ON_ERROR);
        foreach (
            ['Fictional confidential', 'decision_id', 'person_id', 'actor', 'fingerprint',
            'previous_decision', 'form_id', 'checklist', 'evidence', 'shared_form', 'owner_id', 'playhead'] as $private
        ) {
            self::assertStringNotContainsString($private, $encoded);
        }
        foreach ($before as $row) {
            self::assertStringNotContainsString($row['id'], $encoded);
        }
    }

    public function testOriginalIntakeAndCalendarChangesKeepTheirRecordedDates(): void
    {
        [$f, $id] = $this->fixture();
        require_once dirname(__DIR__) . '/Support/AcademicCalendarFixture.php';
        $calendar = new \AcademicCalendarFixture($f);
        $calendar->type();
        $year = $calendar->year(2027);
        $term = new EntityId($f->sql->rows('sis_term_calendar', ['academic_year_id' => $year['id']], 1)[0]['id']);
        $s = $f->deferralService();
        $s->commit($id, $s->review($id, $term, 'correct', 'Fictional private intake reason.')['review_token']);
        $view = $this->service($f)->page($id);
        self::assertSame('2026FA', $view['original']['entry_term']['code']);
        self::assertSame('27/FA', $view['current']['entry_term']['code']);
        self::assertSame('2027-08-15', $view['items'][1]['entry']['after']['starts_on']);
        self::assertSame('2027-2028', $view['items'][1]['entry']['after']['academic_year']);
        self::assertStringNotContainsString($term->value, json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function testStaffPrivilegesDoNotReplaceCurrentOwnership(): void
    {
        [$f, $id] = $this->fixture();
        $before = $f->sql->rows('sis_history_events');
        $f->access->actor = $f->user();
        self::assertTrue($f->access->staff);
        $this->expectException(AccessDeniedException::class);
        try {
            $this->service($f)->page($id);
        } finally {
            self::assertSame($before, $f->sql->rows('sis_history_events'));
        }
    }

    public function testAnUntrackedApplicationRemainsUnknownAndOpeningHistoryDoesNotObserveIt(): void
    {
        [$f, $id] = $this->fixture(false);
        $before = $f->sql->rows('sis_history_events');
        $view = $this->service($f)->page($id);
        self::assertTrue($view['earlier_history_unknown']);
        self::assertNull($view['original']);
        self::assertSame([], $view['items']);
        self::assertNull($view['next_cursor']);
        self::assertSame('submitted', $view['current']['status']);
        self::assertSame($before, $f->sql->rows('sis_history_events'));
    }

    public function testUnknownOriginalDecisionTimingIsNeverPresentedAsAnEffectiveDate(): void
    {
        [$f, $id] = $this->fixture(false);
        $programs = $this->programs($f);
        $review = $programs->review($id, $this->target($f), 'Fictional current correction.');
        $programs->commit($id, $review['review_token']);
        $view = $this->service($f)->page($id);
        self::assertTrue($view['earlier_history_unknown']);
        self::assertNull($view['original']);
        self::assertSame('observation', $view['items'][0]['kind']);
        self::assertNull($view['items'][0]['effective_at']);
        self::assertNotNull($view['items'][0]['recorded_at']);
    }

    public function testHistoryCursorFreezesEarlierChangesAndCannotReadAnotherApplication(): void
    {
        [$f, $id, $person] = $this->fixture();
        $target = $this->target($f);
        $programs = $this->programs($f);
        for ($i = 0; $i < 12; $i++) {
            $program = $i % 2 === 0 ? $target : $f->program;
            $programs->commit($id, $programs->review($id, $program, 'Fictional correction ' . $i)['review_token']);
        }
        $s = $this->service($f);
        $first = $s->page($id);
        self::assertCount(10, $first['items']);
        self::assertNotNull($first['next_cursor']);
        $programs->commit($id, $programs->review($id, $target, 'Fictional later correction.')['review_token']);
        $second = $s->page($id, $first['next_cursor']);
        self::assertCount(3, $second['items']);
        self::assertNull($second['next_cursor']);
        $other = $f->submissionService()->apply(EntityId::generate(), $person->id, $f->program, $f->term)->id;
        $before = $f->sql->rows('sis_history_events');
        try {
            $s->page($other, $first['next_cursor']);
            self::fail('A history cursor read another application.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $f->sql->rows('sis_history_events'));
        }
    }

    public function testOfferLettersAndStaffEvidenceStayOutsideOwnerHistoryIncludingEmptyPages(): void
    {
        [$f, $id] = $this->fixture();
        $decision = $f->decisionService();
        $decision->commit($id, $decision->review($id, true, 'Fictional private decision reason.')['review_token']);
        $offers = $f->offerService();
        for ($i = 0; $i < 19; $i++) {
            $review = $offers->review($id, ['action' => 'issue', 'title' => 'Fictional private letter ' . $i,
                'message' => 'Fictional confidential addressed body.', 'reason' => 'Fictional letter reason.',
                'respond_by' => ''], false);
            $offers->commit($id, $review['review_token'], false);
        }
        $s = $this->service($f);
        $first = $s->page($id);
        self::assertCount(2, $first['items']);
        $middle = $s->page($id, $first['next_cursor']);
        self::assertSame([], $middle['items']);
        self::assertNotNull($middle['next_cursor']);
        $last = $s->page($id, $middle['next_cursor']);
        self::assertSame([], $last['items']);
        self::assertNull($last['next_cursor']);
        $encoded = json_encode([$first, $middle, $last], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Fictional private', $encoded);
        self::assertStringNotContainsString('confidential', json_encode([$first, $middle, $last], JSON_THROW_ON_ERROR));
    }

    public function testMatriculationShowsVerifiedCurrentStatusWithoutInventingAnAdmissionsEvent(): void
    {
        [$f, $id] = $this->fixture();
        $d = $f->decisionService();
        $d->commit($id, $d->review($id, true, 'Fictional acceptance.')['review_token']);
        $f->matriculate($id);
        $view = $this->service($f)->page($id);
        self::assertSame('matriculated', $view['current']['status']);
        self::assertSame(['submission', 'decision'], array_column($view['items'], 'kind'));
        self::assertSame('accepted', $view['items'][1]['status']);
    }

    public function testFinalOwnershipActorSourceAndAuditChangesFailWithoutPartialOutput(): void
    {
        foreach (['owner', 'actor', 'labels', 'decision', 'journal', 'audit'] as $change) {
            [$f, $id, $person] = $this->fixture();
            $other = $f->user();
            $audit = new class ($f, $person->id, $other, $id, $change) implements AuditTrail {
                public int $calls = 0;
                public function __construct(
                    private SisFixture $f,
                    private EntityId $person,
                    private EntityId $other,
                    private EntityId $application,
                    private string $change
                ) {
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($action !== 'admissions.owner_history_read') {
                        return;
                    }
                    $this->calls++;
                    if ($this->change === 'owner') {
                        $this->f->sql->update(
                            'sis_persons',
                            ['owner_user_id' => $this->other->value],
                            ['id' => $this->person->value]
                        );
                    } elseif ($this->change === 'actor') {
                        $this->f->access->actor = $this->other;
                    } elseif ($this->change === 'labels') {
                        $this->f->sql->update(
                            'sis_programs',
                            ['title' => 'Fictional concurrent title'],
                            ['id' => $this->f->program->value]
                        );
                    } elseif ($this->change === 'journal') {
                        $head = $this->f->sql->rows(
                            'sis_history_streams',
                            ['aggregate_id' => $this->application->value],
                            1
                        )[0];
                        $this->f->sql->update(
                            'sis_history_events',
                            ['sealed_event' => 'Fictional damaged current head'],
                            ['stream_id' => $head['id']]
                        );
                    } elseif ($this->change === 'decision') {
                        $d = $this->f->decisionService();
                        $d->commit(
                            $this->application,
                            $d->review($this->application, true, 'Fictional competing decision.')['review_token']
                        );
                    } else {
                        throw new \RuntimeException('Fictional required read-audit failure.');
                    }
                }
            };
            $before = $f->sql->rows('sis_history_events');
            try {
                $this->service($f, $audit)->page($id);
                self::fail('A stale private history was returned.');
            } catch (AccessDeniedException | ConcurrentModificationException | \RuntimeException) {
                self::assertSame(1, $audit->calls);
                self::assertSame($before, $f->sql->rows('sis_history_events'));
                self::assertSame('submitted', $f->admissions->application($id)->status->value);
            }
        }
    }

    public function testDamagedHistoryFailsClosedWithoutReturningAnAnswerOrRepairingEvents(): void
    {
        [$f, $id] = $this->fixture();
        $head = $f->sql->rows('sis_history_streams', ['aggregate_id' => $id->value], 1)[0];
        $f->sql->update(
            'sis_history_events',
            ['sealed_event' => 'Fictional protected damaged payload'],
            ['stream_id' => $head['id']]
        );
        $before = $f->sql->rows('sis_history_events');
        $this->expectException(CorruptHistoryException::class);
        try {
            $this->service($f)->page($id);
        } finally {
            self::assertSame($before, $f->sql->rows('sis_history_events'));
        }
    }
}
