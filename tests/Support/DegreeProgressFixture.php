<?php

declare(strict_types=1);

use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\Service\AcademicInputService;
use Domain\DegreeAudit\Service\AcademicPolicyEvaluator;
use Domain\DegreeAudit\Service\AllocationGapAnalyzer;
use Domain\DegreeAudit\Service\CourseAllocationPlanner;
use Domain\DegreeAudit\Service\CourseMatchEvaluator;
use Domain\DegreeAudit\Service\DegreeMinimumEvaluator;
use Domain\DegreeAudit\Service\DegreeProgressService;
use Domain\DegreeAudit\Service\DegreeWorkReview;
use Domain\DegreeAudit\Service\GroupAllocationEvaluator;
use Domain\DegreeAudit\Service\PublishedRequirementsReader;
use Domain\DegreeAudit\Service\RequirementAdjustmentService;
use Domain\DegreeAudit\Service\RetainedAttemptReader;
use Domain\DegreeAudit\Service\RuleAllocationCompiler;
use Domain\Registration\Service\RetainedSectionReader;
use Infrastructure\Persistence\Repository\SqlAcademicInputRepository;
use Infrastructure\Persistence\Repository\SqlReplacementCourseRepository;
use Infrastructure\Persistence\Repository\SqlRequirementAdjustmentRepository;
use Infrastructure\Persistence\Repository\SqlStudentRecordRepository;

final class DegreeProgressFixture
{
    public static function service(
        \SisFixture $s,
        \Domain\Shared\EventSourcing\HistoricalEventStore $history,
        \Domain\DegreeAudit\Service\StudentRequirementsService $requirements,
        \Domain\Communications\Service\MessageCipher $cipher,
        ?\Domain\DegreeAudit\Repository\AcademicInputRepository $repository = null,
        int $steps = 100000,
        ?\Domain\Student\Service\StudentSpecializationService $specializations = null,
    ): \Domain\DegreeAudit\Service\DegreeProgressService {
        $adjustments = new RequirementAdjustmentService(
            new SqlRequirementAdjustmentRepository($s->sql), $requirements,
            new SqlStudentRecordRepository($s->sql), $history, $s->access, $s->transactions, $s->audit,
            $cipher, $s->clock, new PublishedRequirementsReader($history), new SqlReplacementCourseRepository($s->sql),
        );
        $students = new SqlStudentRecordRepository($s->sql);
        $inputs = new AcademicInputService(
            $repository ?? new SqlAcademicInputRepository($s->sql),
            $s->academicCredits(),
            $s->transferCredits(),
            $s->enrollmentLedger(),
            $students,
            $requirements,
            $s->transactions,
        );
        $evaluator = new \Domain\DegreeAudit\Service\DegreeProgressEvaluator(
                new AcademicPolicyEvaluator(),
                new RetainedAttemptReader(new RetainedSectionReader($history)),
                new DegreeMinimumEvaluator(),
                new GroupAllocationEvaluator(
                    new CourseMatchEvaluator(),
                    new AcademicPolicyEvaluator(),
                    new CourseAllocationPlanner($steps),
                    new AllocationGapAnalyzer(),
                    new RuleAllocationCompiler(new CourseMatchEvaluator()),
                    new \Domain\DegreeAudit\Service\AllocationCoverageReview(),
                ),
                new DegreeWorkReview(),
                new \Domain\DegreeAudit\Service\SavedCourseRulePreview(new \Domain\Catalog\Service\PublishedCourseReader($history)),
                new \Domain\DegreeAudit\Service\GradingRepublicationVerifier(
                    new \Domain\Gradebook\Service\RetainedGradingReader($history),
                ),
            );
        return new \Domain\DegreeAudit\Service\DegreeProgressService(
            $inputs,
            $requirements,
            $adjustments,
            $evaluator,
            $students,
            $s->transactions,
            $specializations ?? $s->specializations($history),
            new \Domain\DegreeAudit\Service\CombinedAllocationEvaluator(
                $evaluator, new CourseAllocationPlanner($steps), new \Domain\DegreeAudit\Service\AllocationCoverageReview(),
            ),
        );
    }
}
