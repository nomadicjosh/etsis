<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\AcademicCalendar\Repository\AcademicCalendarRepository;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\ValueObject\AdmissionDeferralSchema;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Shared current calendar resolution; event snapshots remain immutable. Callers authorize. */
final readonly class AdmissionEntryTerms
{
    public function __construct(private AcademicCalendarRepository $calendar, private CatalogYearRepository $catalogs)
    {
    }
    public function snapshot(EntityId $id): array
    {
        $t = $this->calendar->term($id);
        $year = $t['academic_year_id'] === null ? null : $this->calendar->year(new EntityId($t['academic_year_id']));
        $out = ['id' => $id->value, 'code' => $t['code'], 'name' => $t['name'],
            'starts_on' => $t['starts_on'], 'ends_on' => $t['ends_on'], 'academic_year_id' => $t['academic_year_id'],
            'year_code' => $year['code'] ?? null, 'year_starts_on' => $year['starts_on'] ?? null,
            'year_ends_on' => $year['ends_on'] ?? null];
        AdmissionDeferralSchema::term($out);
        return $out;
    }
    /** Term, program, then year locks follow the existing catalog ordering. */
    public function lock(AdmissionApplication $a, ?EntityId $target = null, ?EntityId $program = null): void
    {
        $terms = [$a->entryTermId->value => $a->entryTermId];
        if ($target !== null) {
            $terms[$target->value] = $target;
        }
        ksort($terms);
        $years = [];
        foreach ($terms as $id) {
            $this->catalogs->lockTerm($id);
            $row = $this->calendar->term($id);
            if ($row['academic_year_id'] !== null) {
                $years[$row['academic_year_id']] = new EntityId($row['academic_year_id']);
            }
        }
        $programs = [$a->programId->value => $a->programId];
        if ($program !== null) {
            $programs[$program->value] = $program;
        }
        ksort($programs);
        foreach ($programs as $id) {
            $this->catalogs->lockProgram($id);
        }
        ksort($years);
        foreach ($years as $year) {
            $this->catalogs->lockYear($year);
        }
    }
    public function verify(AdmissionApplication $a, ?array $approval): void
    {
        if ($approval === null) {
            return;
        }
        if ($approval['after']['id'] !== $a->entryTermId->value) {
            throw new CorruptHistoryException();
        }
        if ($this->snapshot($a->entryTermId) != $approval['after']) {
            throw new ValidationException([['field' => 'entry_term',
                'message' => 'This approved intake changed. '
                    . 'Admissions must review the entry term and issue a fresh offer.']]);
        }
    }
}
