<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Catalog\Course;
use Domain\Registration\Section;
use Domain\Registration\ValueObject\GraduateCourseScope;
use Domain\Registration\ValueObject\GraduateRegistrationRules;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentProgram;

use function Codefy\Framework\Helpers\trans_html;

/** Shared command/readiness gate. It is never consulted when posting an already enrolled student's final grade. */
final readonly class GraduateRegistrationGate
{
    public function __construct(
        private GraduateAcademicContext $academic,
        private GraduateApprovalLedger $approvals,
    ) {
    }

    public function assess(
        StudentProgram $pursuit,
        Section $section,
        Course $course,
        HistoryReadBudget $budget,
        ?RegistrationCourseRead $read = null,
    ): array {
        try {
            $scope = $this->academic->read($pursuit, $section, $course, $budget, $read);
        } catch (\DomainException $failure) {
            return [
                "scope" => null,
                "evidence" => null,
                "required" => true,
                "checks" => [
                    self::check(
                        "graduate_academic",
                        trans_html("Graduate-course academic eligibility"),
                        false,
                        $failure->getMessage(),
                    ),
                ],
            ];
        }
        if ($scope === null) {
            return ["scope" => null, "evidence" => null, "required" => false, "checks" => []];
        }
        $root = $this->approvals->read($pursuit->id, $budget);
        $state = $root->section($section->id);
        $advisor = $state["advisor"];
        $registrar = $state["registrar"];
        $advisorMet =
        $advisor !== null && new GraduateCourseScope($advisor["scope"])->binding() === $scope->binding();
        $registrarMet =
        $advisorMet &&
        $registrar !== null &&
        new GraduateCourseScope($registrar["scope"])->binding() === $scope->binding() &&
        $registrar["advisor_operation_id"] === $advisor["operation_id"];
        $checks = [
            self::check(
                "graduate_academic",
                trans_html("Graduate-course academic eligibility"),
                true,
                trans_html(
                    "The current pursuit meets the registration-time GPA and completed graduate-course grade policy.",
                ),
            ),
            self::check(
                "graduate_advisor",
                trans_html("Advisor approval"),
                $advisorMet,
                $advisorMet
                    ? trans_html("Current advisor approval is recorded.")
                    : trans_html("Ask an assigned advisor to approve this graduate course."),
            ),
            self::check(
                "graduate_registrar",
                trans_html("Registrar override"),
                $registrarMet,
                $registrarMet
                    ? trans_html("The separate registrar override is recorded.")
                    : trans_html("The registrar must authorize an override after advisor approval."),
            ),
        ];
        return [
            "scope" => $scope,
            "required" => true,
            "checks" => $checks,
            "evidence" => !$registrarMet
                ? null
                : [
                    "scope" => $scope->data,
                    "advisor_operation_id" => $advisor["operation_id"],
                    "registrar_operation_id" => $registrar["operation_id"],
                    "approval_version" => $root->playhead(),
                ],
        ];
    }

    public function requireMet(array $assessment): ?array
    {
        foreach ($assessment["checks"] as $check) {
            if ($check["status"] !== "met") {
                throw new \Domain\Shared\Exception\ValidationException([
                    ["field" => "graduate_eligibility", "message" => $check["message"]],
                ]);
            }
        }
        return $assessment["evidence"];
    }

    public static function revision(array $assessment): string
    {
        return GraduateRegistrationRules::hash([
            $assessment["required"],
            $assessment["scope"]?->data,
            $assessment["evidence"],
            $assessment["checks"],
        ]);
    }

    private static function check(string $code, string $label, bool $met, string $message): array
    {
        return [
            "code" => $code,
            "label" => $label,
            "status" => $met ? "met" : "blocked",
            "message" => $message,
        ];
    }
}
