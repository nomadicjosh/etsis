<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Catalog\Service\PublishedProgramReader;
use Domain\Registration\Section;
use Domain\Registration\ValueObject\ProgramCampusEvidence;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Service\StudentProgramStatusLedger;
use Domain\Student\StudentProgram;

use function Codefy\Framework\Helpers\trans_html;

/** Internal assessment; callers authorize, hold the student/section boundary and share the replay budget. */
final readonly class ProgramCampusEligibility
{
    public function __construct(
        private StudentProgramStatusLedger $pursuits,
        private PublishedProgramReader $programs,
        private RetainedSectionReader $sections,
    ) {
    }

    public function assess(StudentProgram $pursuit, Section $section, HistoryReadBudget $budget): array
    {
        if (!$section->programPolicy->requireCampus) {
            return ['checks' => [], 'evidence' => null];
        }
        if ($section->publicationVersionId === null || $section->campusId === null) {
            return self::result(
                trans_html('Publish and select this section with a campus and its program campus rule.')
            );
        }
        try {
            $offering = $this->sections->publications([['section_id' => $section->id->value,
                'version_id' => $section->publicationVersionId->value]], $budget)
            [$section->id->value][$section->publicationVersionId->value];
            if (
                !($offering->definition->data['require_program_campus'] ?? false)
                || $offering->classifications?->campus?->id->value !== $section->campusId->value
            ) {
                return self::result(
                    trans_html('Publish and select the current program campus rule and section campus.')
                );
            }
            $cohort = $this->pursuits->cohortContext($pursuit, $budget);
            if ($cohort['catalog'] === null || !$cohort['source_recorded']) {
                return self::result(
                    trans_html('Assign a verified published program cohort before checking campus eligibility.'),
                    status: 'unverified'
                );
            }
            $catalog = $cohort['catalog'];
            $program = $this->programs->version(
                $pursuit->programId,
                new EntityId($catalog['year_id']),
                new EntityId($catalog['catalog_id']),
                $budget
            ) ?? throw new CorruptHistoryException();
            $locations = $program['definition']['classifications'];
            if (!self::includes($locations, $section->campusId->value)) {
                return array_key_exists('campus', $locations) && array_key_exists('additional_campuses', $locations)
                ? self::result(trans_html('This section campus is not included in the assigned program cohort.'))
                : self::result(
                    trans_html('The assigned program cohort has incomplete retained campus information.'),
                    status: 'unverified'
                );
            }
            $evidence = new ProgramCampusEvidence([
                'student_id' => $pursuit->studentId->value, 'pursuit_id' => $pursuit->id->value,
                'program_id' => $pursuit->programId->value, 'academic_year_id' => $catalog['year_id'],
                'program_version_id' => $catalog['catalog_id'], 'section_id' => $section->id->value,
                'section_version_id' => $section->publicationVersionId->value, 'campus_id' => $section->campusId->value,
            ]);
            return self::result(trans_html('Check passed.'), $evidence->data, 'met');
        } catch (CorruptHistoryException) {
            return self::result(
                trans_html('Program campus eligibility needs verified publication and cohort records.'),
                status: 'unverified'
            );
        }
    }

    /** Authenticate the original references without consulting today's cohort, library labels or draft rule. */
    public function verify(ProgramCampusEvidence $proof, HistoryReadBudget $budget): void
    {
        $p = $proof->data;
        $offering = $this->sections->publications([['section_id' => $p['section_id'],
            'version_id' => $p['section_version_id']]], $budget)[$p['section_id']][$p['section_version_id']];
        $program = $this->programs->version(
            new EntityId($p['program_id']),
            new EntityId($p['academic_year_id']),
            new EntityId($p['program_version_id']),
            $budget
        );
        if (
            !($offering->definition->data['require_program_campus'] ?? false)
            || $offering->classifications?->campus?->id->value !== $p['campus_id'] || $program === null
            || !self::includes($program['definition']['classifications'], $p['campus_id'])
        ) {
            throw new CorruptHistoryException();
        }
    }

    public static function requireMet(array $assessment): ?array
    {
        foreach ($assessment['checks'] as $check) {
            if ($check['status'] !== 'met') {
                throw new ValidationException([['field' => 'program_campus', 'message' => $check['message']]]);
            }
        }
        return $assessment['evidence'];
    }

    private static function includes(array $locations, string $campus): bool
    {
        return ($locations['campus']['id'] ?? null) === $campus
        || in_array($campus, array_column($locations['additional_campuses'] ?? [], 'id'), true);
    }

    private static function result(string $message, ?array $evidence = null, string $status = 'blocked'): array
    {
        return ['checks' => [['code' => 'program_campus', 'label' => trans_html('Program cohort campus'),
            'status' => $status === 'met' ? 'met' : 'blocked', 'message' => $message,
            'next_step' => $status === 'met' ? null
                : trans_html('Ask the registrar to review the assigned program cohort and section offering.')]],
            'evidence' => $evidence];
    }
}
