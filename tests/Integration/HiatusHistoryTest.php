<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/Support/HiatusFixture.php';

use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Enum\HiatusCode;
use Domain\Student\StudentHiatus;
use PHPUnit\Framework\TestCase;

final class HiatusHistoryTest extends TestCase
{
    private function hiatus(
        EntityId $student,
        string $from = '2026-08-01',
        ?string $to = null,
        HiatusCode $code = HiatusCode::Leave
    ): StudentHiatus {
        return new StudentHiatus(
            EntityId::generate(),
            $student,
            $code,
            new DateRange(new DateTimeImmutable($from), $to === null ? null : new DateTimeImmutable($to))
        );
    }

    public function testCommentsAndAmendmentsAreEncryptedAndRetrySafeWithoutChangingAcademicOrFinancialRecords(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $section = $f->section();
        $f->service->register($student->id, $section->id);
        $credits = $f->sql->rows('sis_academic_credits');
        $events = $f->sql->rows('sis_history_events');
        $hiatus = $this->hiatus($student->id);
        $note = 'Fictional sensitive comment <script>example</script>.';
        $h->history->create($hiatus, $note);
        $h->history->create($hiatus, $note);
        $view = $h->history->view($student->id, $hiatus->id);
        $operation = EntityId::generate();
        $h->history->end($student->id, $hiatus->id, $operation, new DateTimeImmutable('2026-08-31'), $view['revision'], 'Fictional return date');
        $h->history->end($student->id, $hiatus->id, $operation, new DateTimeImmutable('2026-08-31'), $view['revision'], 'Fictional return date');
        self::assertSame($note, $view['comment']);
        self::assertCount(1, $f->sql->rows('sis_student_hiatuses'));
        self::assertCount(2, $f->sql->rows('sis_student_hiatus_actions'));
        $raw = json_encode($f->sql->rows('sis_student_hiatus_actions'), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($note, $raw);
        self::assertStringNotContainsString('Fictional return date', $raw);
        $history = $h->history->history($student->id, $hiatus->id)['items'];
        self::assertSame('Fictional return date', $history[0]['note']);
        self::assertNull($history[0]['previous_ends_on']);
        self::assertSame('2026-08-31', $history[0]['new_ends_on']);
        self::assertSame($credits, $f->sql->rows('sis_academic_credits'));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
        self::assertTrue($f->admissions->student($student->id)->active);
        $metadata = $h->history->page($student->id)['items'][0];
        self::assertArrayNotHasKey('comment', $metadata);
        self::assertArrayNotHasKey('encrypted_content', $metadata);
        self::assertStringNotContainsString('Fictional', json_encode($f->audit->forSubject($hiatus->id), JSON_THROW_ON_ERROR));
    }

    public function testSameTypeDateOverlapIsRejectedIncludingTheEndDayButVoidedRecordsDoNotBlockReplacement(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $a = $this->hiatus($student->id, '2026-08-01', '2026-08-10');
        $h->history->create($a, 'Fictional first');
        try {
            $h->history->create($this->hiatus($student->id, '2026-08-10', '2026-08-20'), 'Fictional overlapping');
            self::fail('The inclusive end day was treated as an available start day.');
        } catch (ValidationException) {
            self::assertCount(1, $f->sql->rows('sis_student_hiatuses'));
        }
        $h->history->create($this->hiatus($student->id, '2026-08-11', '2026-08-20'), 'Fictional later');
        $h->history->create($this->hiatus($student->id, '2026-08-05', '2026-08-15', HiatusCode::StudyAbroad), 'Fictional separate type');
        $view = $h->history->view($student->id, $a->id);
        $h->history->void($student->id, $a->id, EntityId::generate(), $view['revision'], 'Fictional correction');
        $h->history->create($this->hiatus($student->id, '2026-08-01', '2026-08-10'), 'Fictional replacement');
        self::assertCount(4, $f->sql->rows('sis_student_hiatuses'));
        self::assertSame('voided', $h->history->view($student->id, $a->id)['state']);
    }

    public function testDateCorrectionsUseVersionsAndRetainEveryReason(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $hiatus = $this->hiatus($student->id, '2026-08-01', '2026-08-10');
        $h->history->create($hiatus, 'Fictional initial');
        $first = $h->history->view($student->id, $hiatus->id);
        $h->history->end($student->id, $hiatus->id, EntityId::generate(), new DateTimeImmutable('2026-08-20'), $first['revision'], 'Fictional extension');
        $next = $h->history->view($student->id, $hiatus->id);
        $h->history->end($student->id, $hiatus->id, EntityId::generate(), new DateTimeImmutable('2026-08-10'), $next['revision'], 'Fictional date correction');
        self::assertNotSame($first['revision'], $h->history->view($student->id, $hiatus->id)['revision']);
        try {
            $h->history->end($student->id, $hiatus->id, EntityId::generate(), new DateTimeImmutable('2026-08-09'), $first['revision'], 'Fictional stale edit');
            self::fail('A stale token worked after dates changed back.');
        } catch (ConcurrentModificationException) {
            self::assertCount(3, $h->history->history($student->id, $hiatus->id)['items']);
        }
        $fresh = $h->history->view($student->id, $hiatus->id);
        $this->expectException(ValidationException::class);
        $h->history->end($student->id, $hiatus->id, EntityId::generate(), new DateTimeImmutable('2026-07-31'), $fresh['revision'], 'Fictional invalid date');
    }

    public function testVoidingAndReplayingOldOperationsCannotRestoreAcademicEffects(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $hiatus = $this->hiatus($student->id);
        $h->history->create($hiatus, 'Fictional initial');
        $view = $h->history->view($student->id, $hiatus->id);
        $operation = EntityId::generate();
        $h->history->void($student->id, $hiatus->id, $operation, $view['revision'], 'Fictional void reason');
        $h->history->void($student->id, $hiatus->id, $operation, $view['revision'], 'Fictional void reason');
        $h->history->create($hiatus, 'Fictional initial');
        self::assertSame('voided', $h->history->view($student->id, $hiatus->id)['state']);
        self::assertCount(2, $h->history->history($student->id, $hiatus->id)['items']);
        $fresh = $h->history->view($student->id, $hiatus->id);
        $this->expectException(DomainException::class);
        $h->history->end($student->id, $hiatus->id, EntityId::generate(), new DateTimeImmutable('2026-08-15'), $fresh['revision'], 'Fictional restoration');
    }

    public function testStudentOwnershipAndReadPermissionDoNotExposePrivateHistory(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $hiatus = $this->hiatus($student->id);
        $h->history->create($hiatus, 'Fictional private information');
        $f->access->staff = false;
        $f->access->grants = ['sis:student:read'];
        foreach (['page', 'view', 'history', 'create', 'void'] as $action) {
            try {
                match ($action) {
                    'page' => $h->history->page($student->id), 'view' => $h->history->view($student->id, $hiatus->id),
                    'history' => $h->history->history($student->id, $hiatus->id),
                    'create' => $h->history->create($hiatus, 'Fictional private information'),
                    'void' => $h->history->void($student->id, $hiatus->id, EntityId::generate(), '', 'Fictional denied'),
                };
                self::fail('Ownership exposed private hiatus ' . $action);
            } catch (AccessDeniedException) {
                self::assertCount(1, $f->sql->rows('sis_student_hiatus_actions'));
            }
        }
    }

    public function testStudentPathMustMatchTheHiatusBeforeDecryptingOrChangingIt(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $a = $f->student();
        $b = $f->student();
        $hiatus = $this->hiatus($a->id);
        $h->history->create($hiatus, 'Fictional private comment');
        foreach (['view', 'history', 'end'] as $action) {
            try {
                match ($action) {
                    'view' => $h->history->view($b->id, $hiatus->id),
                    'history' => $h->history->history($b->id, $hiatus->id),
                    'end' => $h->history->end($b->id, $hiatus->id, EntityId::generate(), new DateTimeImmutable('2026-08-10'), '', 'Fictional wrong student'),
                };
                self::fail('A mismatched student path was accepted.');
            } catch (RecordNotFoundException) {
                self::assertCount(1, $f->sql->rows('sis_student_hiatus_actions'));
            }
        }
    }

    public function testHistoryAndRecordPagesAreBoundedAndDoNotDecryptMoreThanOnePage(): void
    {
        $h = new HiatusFixture();
        $f = $h->sis;
        $student = $f->student();
        $hiatus = $this->hiatus($student->id);
        $h->history->create($hiatus, 'Fictional initial');
        for ($i = 0; $i < 52; ++$i) {
            $current = $h->history->view($student->id, $hiatus->id);
            $h->history->end($student->id, $hiatus->id, EntityId::generate(), new DateTimeImmutable('2026-08-20'), $current['revision'], 'Fictional amendment ' . $i);
        }
        $first = $h->history->history($student->id, $hiatus->id);
        $second = $h->history->history($student->id, $hiatus->id, new EntityId($first['next_after']));
        self::assertCount(50, $first['items']);
        self::assertCount(3, $second['items']);
        self::assertNull($second['next_after']);
        self::assertCount(53, array_unique(array_column([...$first['items'], ...$second['items']], 'id')));
        for ($i = 1; $i <= 51; ++$i) {
            $date = new DateTimeImmutable('2027-01-01')->modify('+' . $i . ' days')->format('Y-m-d');
            $h->history->create($this->hiatus($student->id, $date, $date), 'Fictional dated record');
        }
        $page = $h->history->page($student->id);
        $next = $h->history->page($student->id, new EntityId($page['next_after']));
        self::assertCount(50, $page['items']);
        self::assertCount(2, $next['items']);
        self::assertNull($next['next_after']);
    }

    public function testStateUsesInclusiveInstitutionalCalendarDaysAndCanonicalLegacyCodes(): void
    {
        $student = EntityId::generate();
        $hiatus = $this->hiatus($student, '2026-08-10', '2026-08-12');
        self::assertSame('scheduled', $hiatus->state(new DateTimeImmutable('2026-08-09T23:59:59-07:00')));
        self::assertSame('active', $hiatus->state(new DateTimeImmutable('2026-08-12T23:59:59-07:00')));
        self::assertSame('ended', $hiatus->state(new DateTimeImmutable('2026-08-13T00:00:00-07:00')));
        self::assertSame(HiatusCode::Illness, HiatusCode::parse('illn'));
        self::assertSame(HiatusCode::Leave, HiatusCode::parse(' loa '));
        $this->expectException(ValidationException::class);
        HiatusCode::parse('not-a-code');
    }
}
