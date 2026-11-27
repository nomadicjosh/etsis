<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\AcademicRecord\Service\WorkPursuitService;
use Domain\DegreeAudit\Service\AcademicInputVerifier;
use Domain\Registration\Repository\CoRequisiteRepository;
use Domain\Registration\ValueObject\CoRequisiteDecision;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;

/** Internal assessments; calling commands authorize the student and own the student lock. */
final readonly class CoRequisiteService
{
    public function __construct(
        private CoRequisiteRepository $records,
        private AcademicInputVerifier $inputs,
        private WorkPursuitService $pursuits,
        private EnrollmentLedger $enrollments,
        private \Domain\Registration\Repository\RegistrationRepository $registration,
    ) {
    }
    public function policy(): array
    {
        return $this->records->policy();
    }
    /** @param list<string> $required
     * @param list<string> $exclude Sections proposed for removal. */
    public function evaluate(
        EntityId $student,
        EntityId $assignment,
        EntityId $term,
        array $required,
        HistoryReadBudget $budget,
        ?CoRequisitePlan $plan = null,
        array $exclude = [],
        ?array $policy = null,
    ): ?CoRequisiteDecision {
        if ($required === []) {
            return null;
        }
        $policy ??= $this->records->policy();
        $inputs = $plan?->scopedInputs($student, $assignment, $this->inputs, $this->pursuits, $budget) ??
        $this->pursuits->forPursuit($this->inputs->verify($student, $budget), $student, $assignment, $budget);
        return $this->assess($student, $assignment, $term, $required, $inputs, $policy, $plan, $exclude);
    }
    private function assess(
        EntityId $student,
        EntityId $assignment,
        EntityId $term,
        array $required,
        \Domain\DegreeAudit\ValueObject\AcademicInputSet $inputs,
        array $policy,
        ?CoRequisitePlan $plan = null,
        array $exclude = [],
    ): CoRequisiteDecision {
        $references = array_column($inputs->references, null, 'credit_id');
        $support = [];
        foreach ($inputs->items as $work) {
            $ref = $references[$work['id']] ?? null;
            if (
                ($work['basis'] ?? null) === 'audit' || !in_array($work['course_id'], $required, true) ||
                !$work['history_recorded'] || $ref === null ||
                !in_array($work['status'], ['A', 'N'], true) || in_array($ref['section_id'], $exclude, true)
            ) {
                continue;
            }
            $completed = $work['grade_recorded'] && ($work['grade']['passes'] ?? false);
            $kind = $policy['prior_completion'] && $completed ? 'completed' :
            ($ref['origin'] === 'institutional' && $work['registration_term_id'] === $term->value &&
            $ref['registration_version'] > 0 && (!$work['grade_recorded'] || $completed) ? 'concurrent' : null);
            if ($kind === null) {
                continue;
            }
            $candidate = ['course_id' => $work['course_id'], 'kind' => $kind, 'origin' => $ref['origin'],
                'source_id' => $ref['source_id'], 'credit_id' => $work['id'], 'section_id' => $ref['section_id'],
                'academic_version' => $ref['academic_version'], 'registration_version' => $ref['registration_version'],
                'pursuit_version' => $ref['pursuit_version'], 'operation_id' => null];
            $old = $support[$work['course_id']] ?? null;
            if (
                $old === null || ($kind === 'completed' && $old['kind'] !== 'completed') ||
                ($kind === $old['kind'] && strcmp($work['id'], $old['credit_id']) < 0)
            ) {
                $support[$work['course_id']] = $candidate;
            }
        }
        if ($plan !== null) {
            if ($plan->selection->student->value !== $student->value) {
                throw new CorruptHistoryException();
            }
            foreach ($required as $id) {
                $candidate = $plan->support($id, $term->value);
                if (
                    !isset($support[$id]) && $candidate !== null &&
                    !in_array($candidate['section_id'], $exclude, true)
                ) {
                    $support[$id] = $candidate;
                }
            }
        }
        ksort($support, SORT_STRING);
        return new CoRequisiteDecision(['student_id' => $student->value, 'assignment_id' => $assignment->value,
            'term_id' => $term->value, 'required' => $required, 'policy' => $policy,
            'support' => array_values($support)]);
    }
    /** Selected student's bounded recorded/current book. Closed roots are excluded by the routing query. */
    public function book(EntityId $student, HistoryReadBudget $budget): array
    {
        $ids = $this->records->activeBook($student);
        if (count($ids) > 100) {
            throw new HistoryReadLimitException('This student requires a separate registration dependency review.');
        }
        $book = [];
        foreach ($ids as $row) {
            $read = $this->enrollments->evaluationFacts(new EntityId($row['id']), $student, $budget);
            if (in_array($read['facts']['status'], ['A', 'N'], true)) {
                $book[] = $read;
            }
        }
        return $book;
    }
    /** A coordinated registration is complete only after every promised enrollment actually exists. */
    public function verifyAccepted(
        \Domain\Registration\Enrollment $enrollment,
        HistoryReadBudget $budget,
        ?CoRequisitePlan $readContext = null
    ): void {
        $read = $this->enrollments->evaluationFacts($enrollment->id, $enrollment->studentId, $budget);
        $old = $read['corequisites'] ?? null;
        if ($old === null) {
            return;
        }
        CoRequisiteDecision::fromRetained($old);
        if ($old['policy'] != $this->records->policy()) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException(
                'The co-requisite policy changed. Review registration again.',
            );
        }
        $assignment = new EntityId($old['assignment_id']);
        $inputs = $readContext?->scopedInputs(
            $enrollment->studentId,
            $assignment,
            $this->inputs,
            $this->pursuits,
            $budget,
        ) ??
        $this->pursuits->forPursuit(
            $this->inputs->verify($enrollment->studentId, $budget),
            $enrollment->studentId,
            $assignment,
            $budget
        );
        $work = array_column($inputs->items, null, 'id');
        foreach ($old['support'] as $accepted) {
            $matching = array_values(array_filter($inputs->references, static fn(array $ref): bool =>
                $accepted['kind'] === 'coordinated' ? $ref['origin'] === 'institutional' &&
                    $ref['source_id'] === $accepted['source_id'] && $ref['section_id'] === $accepted['section_id'] :
                    $ref['credit_id'] === $accepted['credit_id']));
            $ref = $matching[0] ?? null;
            if ($ref === null || !isset($work[$ref['credit_id']])) {
                throw new CorruptHistoryException();
            }
            $single = new \Domain\DegreeAudit\ValueObject\AcademicInputSet(
                $inputs->status,
                [$work[$ref['credit_id']]],
                [$ref]
            );
            $fresh = $this->assess(
                $enrollment->studentId,
                $assignment,
                new EntityId($old['term_id']),
                [$accepted['course_id']],
                $single,
                $old['policy']
            );
            $this->requireMet($fresh);
            if ($accepted['kind'] !== 'coordinated' && $fresh->facts['support'][0] != $accepted) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException(
                    'Co-requisite support changed before completion. Review again.',
                );
            }
        }
    }
    public function requireMet(?CoRequisiteDecision $decision): void
    {
        if ($decision !== null && !$decision->isMet()) {
            throw new \Domain\Shared\Exception\ValidationException([[
                'field' => 'corequisites', 'message' => 'Select the required co-requisite sections together, ' .
                    'or ask the registrar to review qualifying completed coursework.',
            ]]);
        }
    }
    /** Compare removal against the dependent enrollment's retained rules, never today's catalog. */
    public function dropping(
        EntityId $student,
        array $sections,
        HistoryReadBudget $budget,
        ?CoRequisitePlan $replacement = null
    ): \Domain\Registration\ValueObject\CoRequisiteDropDecision {
        $sections = array_values(array_unique($sections));
        sort($sections, SORT_STRING);
        $policy = $this->records->policy();
        $courses = $terms = [];
        foreach ($sections as $section) {
            $source = $this->registration->section(new EntityId($section));
            $courses[] = $source->courseId->value;
            $terms[] = $source->termId->value;
        }
        $book = $this->book($student, $budget);
        $affected = array_filter($book, static fn(array $entry): bool =>
            isset($entry['corequisites']) && !in_array($entry['facts']['section_id'], $sections, true) &&
            in_array($entry['corequisites']['term_id'], $terms, true) &&
            array_intersect($entry['corequisites']['required'], $courses) !== []);
        $checks = $scopes = [];
        // A single verification per assessment phase; no cache survives a command or its final checks.
        $inputs = $affected === [] ? null : $this->inputs->verify($student, $budget);
        $workBySource = [];
        if ($inputs !== null) {
            $byCredit = array_column($inputs->items, null, 'id');
            foreach ($inputs->references as $ref) {
                if (isset($byCredit[$ref['credit_id']])) {
                    $workBySource[$ref['source_id']] = $byCredit[$ref['credit_id']];
                }
            }
        }
        foreach ($affected as $entry) {
            // Once a final outcome is recorded, preserve that academic decision rather than enforce a live timetable.
            if ($workBySource[$entry['facts']['id']]['grade_recorded'] ?? false) {
                continue;
            }
            $old = CoRequisiteDecision::fromRetained($entry['corequisites'])->facts;
            $assignment = new EntityId($old['assignment_id']);
            $scopes[$assignment->value] ??= $this->pursuits->forPursuit($inputs, $student, $assignment, $budget);
            $read = $this->assess(
                $student,
                $assignment,
                new EntityId($old['term_id']),
                $old['required'],
                $scopes[$assignment->value],
                $old['policy'],
                $replacement,
                $sections
            );
            $checks[] = ['enrollment_id' => $entry['facts']['id'], 'section_id' => $entry['facts']['section_id'],
                'registration_version' => $entry['history_version'], 'assessment' => $read->facts];
        }
        return new \Domain\Registration\ValueObject\CoRequisiteDropDecision([
            'student_id' => $student->value, 'policy' => $policy, 'dropping' => $sections, 'checks' => $checks,
        ]);
    }
}
