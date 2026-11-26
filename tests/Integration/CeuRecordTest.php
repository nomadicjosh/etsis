<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\Service\CeuRecordService;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Person\Person;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAcademicCreditRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\EarnedCeuFixture;

final class CeuRecordTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/EarnedCeuFixture.php';
    }

    public function testOwnerReadsOnlyVerifiedMinimizedCeusAndZeroRemainsAnAward(): void
    {
        $f = new EarnedCeuFixture(withdrawn: true);
        $f->confirm($f->review(0));
        $this->owner($f);
        $before = $this->snapshot($f);
        $record = $f->records()->record($f->student);
        self::assertCount(1, $record);
        self::assertSame(['course_code', 'course_title', 'term_code', 'earned_ceus'], array_keys($record[0]));
        self::assertSame(['earned_milli' => 0, 'completed_on' => '2026-08-01'], $record[0]['earned_ceus']);
        self::assertSame($before, $this->snapshot($f));
        $f->sis->access->actor = $f->sis->user();
        $this->expectException(AccessDeniedException::class);
        $f->records()->record($f->student);
    }

    public function testForgedProjectionOrEnrollmentCannotBecomeAnOwnerCeuRecord(): void
    {
        foreach (['award', 'label', 'enrollment'] as $failure) {
            $f = new EarnedCeuFixture(withdrawn: true);
            $f->confirm($f->review());
            if ($failure === 'award') {
                $award = json_decode(
                    $f->sis->sql->one('sis_academic_credit_streams', ['id' => $f->credit->value])['earned_ceus'],
                    true,
                    flags: JSON_THROW_ON_ERROR,
                );
                $award['earned_milli'] = 1;
                $f->sis->sql->update('sis_academic_credit_streams', ['earned_ceus' => json_encode($award)], [
                    'id' => $f->credit->value,
                ]);
            } elseif ($failure === 'label') {
                $f->sis->sql->update('sis_academic_credits', ['course_title' => 'Fictional forged label.'], [
                    'id' => $f->credit->value,
                ]);
            } else {
                $f->sis->sql->update('sis_enrollments', ['status' => 'A'], ['id' => $f->enrollment->value]);
            }
            $this->owner($f);
            $before = $this->snapshot($f);
            try {
                $f->records()->record($f->student);
                self::fail('A forged projection became a CEU record.');
            } catch (CorruptHistoryException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testFinalOwnerOrActorChangeWithholdsTheWholeRecord(): void
    {
        foreach (['actor', 'owner'] as $failure) {
            $f = new EarnedCeuFixture(withdrawn: true);
            $f->confirm($f->review());
            $person = $this->owner($f);
            $other = $f->sis->user();
            $calls = 0;
            $admissions = $this->createStub(AdmissionsRepository::class);
            $admissions->method('student')->willReturn($f->sis->admissions->student($f->student));
            $admissions->method('person')->willReturnCallback(static function () use (
                &$calls,
                $failure,
                $f,
                $person,
                $other,
            ): Person {
                if (++$calls === 2) {
                    if ($failure === 'actor') {
                        $f->sis->access->actor = $other;
                    } else {
                        return new Person(
                            id: $person->id,
                            ownerUserId: $other,
                            givenName: $person->givenName,
                            familyName: $person->familyName,
                            institutionalId: $person->institutionalId,
                            directoryRestricted: $person->directoryRestricted,
                            identityVersion: $person->identityVersion,
                        );
                    }
                }
                return $person;
            });
            $service = new CeuRecordService(
                $f->sis->inputVerifier(),
                new SqlAcademicCreditRepository($f->sis->sql),
                $admissions,
                $f->sis->access,
                $f->sis->transactions,
            );
            $before = $this->snapshot($f);
            try {
                $service->record($f->student);
                self::fail('A changed identity received a CEU record.');
            } catch (AccessDeniedException) {
                self::assertSame($before, $this->snapshot($f));
                self::assertSame(2, $calls);
            }
        }
    }

    public function testSourceChangedDuringMinimizationIsWithheldAndItsMutationRollsBack(): void
    {
        $f = new EarnedCeuFixture(withdrawn: true);
        $f->confirm($f->review());
        $this->owner($f);
        $real = new SqlAcademicCreditRepository($f->sis->sql);
        $records = $this->createMock(AcademicCreditRepository::class);
        $records->method('lockStudent')->willReturnCallback($real->lockStudent(...));
        $records->expects(self::once())->method('term')->willReturnCallback(static function (EntityId $term) use (
            $f,
            $real,
        ): array {
            $f->sis->sql->update('sis_academic_credits', ['course_title' => 'Fictional changed source.'], [
                'id' => $f->credit->value,
            ]);
            return $real->term($term);
        });
        $service = new CeuRecordService(
            $f->sis->inputVerifier(),
            $records,
            $f->sis->admissions,
            $f->sis->access,
            $f->sis->transactions,
        );
        $before = $this->snapshot($f);
        try {
            $service->record($f->student);
            self::fail('A changed source received a CEU record.');
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    private function owner(EarnedCeuFixture $f): Person
    {
        $person = $f->sis->admissions->person($f->sis->admissions->student($f->student)->personId);
        $f->sis->access->staff = false;
        $f->sis->access->grants = [];
        $f->sis->access->actor = $person->ownerUserId;
        return $person;
    }

    private function snapshot(EarnedCeuFixture $f): array
    {
        return array_map($f->sis->sql->rows(...), [
            'sis_history_events', 'sis_history_streams', 'sis_history_operations',
            'sis_academic_credits', 'sis_academic_credit_streams', 'sis_enrollments', 'sis_audit',
        ]);
    }
}
