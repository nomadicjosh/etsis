<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\ValueObject\RegistrationChargeQuote;
use Domain\Registration\Repository\DropPolicyRepository;
use Domain\Registration\Repository\RegistrationBatchRepository;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Registration\Repository\SectionChangeSearch;
use Domain\Registration\ValueObject\SectionChangeSelection;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

/** One atomic early drop and replacement registration. No new event store or academic aggregate. */
final readonly class SectionChangeService
{
    public function __construct(
        private RegistrationRepository $repository,
        private AdmissionsRepository $admissions,
        private RegistrationBatchService $assessments,
        private RegistrationService $registration,
        private CourseDropService $drops,
        private DropPolicyRepository $policies,
        private DropPolicyService $dropPolicy,
        private SectionChangeSearch $search,
        private RegistrationBilling $billing,
        private RegistrationBatchRepository $receipts,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }

    /** Current setup choices only; exact publication, eligibility and seats are checked on review.
     * @return array<string,mixed> */
    public function choices(
        EntityId $student,
        EntityId $from,
        bool $byStaff,
        string $search = "",
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize($student, $byStaff);
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1f\x7f]/u', $search)
        ) {
            throw new ValidationException([["field" => "search", "message" => "Enter up to 80 characters."]]);
        }
        $source = $this->drops->review($student, $from);
        $this->early($source);
        $section = $this->repository->section($from);
        $rows = $this->search->page($section->courseId, $section->termId, $from, trim($search), $after);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $this->sameActor($student, $byStaff, $actor);
        return [
            "items" => $rows,
            "next_after" => $more ? $rows[19]["id"] : null,
            "from_code" => $section->code,
            "grace_ends_on" => $source["outcome"]["grace_ends_on"],
        ];
    }

    /** @return array<string,mixed> */
    public function review(SectionChangeSelection $selection, bool $byStaff): array
    {
        $actor = $this->authorize($selection->student, $byStaff);
        $routing = $this->routing($selection);
        return $this->transactions->run(function () use ($selection, $byStaff, $actor, $routing): array {
            $this->lock($selection, $routing);
            $quote = null;
            $context = $this->context($selection, $byStaff, new HistoryReadBudget(), $quote);
            $this->verifyRouting($selection, $routing);
            $this->sameActor($selection->student, $byStaff, $actor);
            $operation = EntityId::generate();
            $revision = $this->revision($context);
            unset($context["drop"]);
            return $context + [
                "operation_id" => $operation->value,
                "revision" => $revision,
                "review_token" => $this->proofs->issue(
                    "section-change",
                    $byStaff ? "staff" : "student",
                    $selection->student,
                    $actor,
                    $operation,
                    $revision,
                ),
                "expires_at" => $this->clock
                    ->now()
                    ->modify("+20 minutes")
                    ->format(DATE_ATOM),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function commit(
        EntityId $operation,
        SectionChangeSelection $selection,
        string $revision,
        #[\SensitiveParameter] string $token,
        bool $byStaff,
    ): array {
        $actor = $this->authorize($selection->student, $byStaff);
        $hash = $selection->requestHash($revision, $byStaff);
        $receipt = $this->receipts->receipt($operation);
        $this->proof($selection, $byStaff, $actor, $operation, $revision, $token, $receipt !== null);
        if ($receipt !== null) {
            return $this->replay($receipt, $actor, $hash);
        }
        $routing = $this->routing($selection);
        return $this->transactions->run(function () use (
            $operation,
            $selection,
            $revision,
            $token,
            $byStaff,
            $actor,
            $hash,
            $routing,
        ): array {
            $this->lock($selection, $routing);
            $receipt = $this->receipts->receipt($operation);
            $this->proof($selection, $byStaff, $actor, $operation, $revision, $token, $receipt !== null);
            if ($receipt !== null) {
                return $this->replay($receipt, $actor, $hash);
            }
            $budget = new HistoryReadBudget();
            $quote = null;
            $context = $this->context($selection, $byStaff, $budget, $quote);
            if (!hash_equals($this->revision($context), $revision)) {
                throw new ConcurrentModificationException(
                    "The selected records changed. Review the section change again.",
                );
            }
            if (!$context["can_confirm"]) {
                throw new ValidationException([
                    [
                        "field" => "to_section_id",
                        "message" =>
                            "Resolve the replacement section’s readiness, seat, timetable or pricing issues. " .
                            "Nothing was changed.",
                    ],
                ]);
            }
            $drop = $context["drop"];
            $removed = $this->drops->commit(
                $selection->student,
                $selection->from,
                new EntityId($drop["enrollment_id"]),
                $drop["version"],
                $operation,
                $drop["revision"],
                $drop["effective_on"],
                budget: $budget,
                replacement: new CoRequisitePlan(new \Domain\Registration\ValueObject\RegistrationPlanSelection(
                    $selection->student,
                    $selection->to
                ), $this->repository),
            );
            $fresh = $this->quote($selection, $budget);
            if (
                $fresh->definitionFingerprint() !== $quote->definitionFingerprint() ||
                $fresh->summary()["total_minor"] !== $context["financial"]["new_charge_minor"]
            ) {
                throw new ConcurrentModificationException("The replacement charges changed. Review again.");
            }
            $enrollment = $this->registration->register(
                $selection->student,
                $selection->to,
                $byStaff,
                budget: $budget,
                chargeQuote: $fresh,
            );
            if ($this->drops->dependencies($selection->student, [$selection->from->value], $budget)->blocked()) {
                throw new ConcurrentModificationException(
                    'The replacement leaves co-requisites unsupported. Review again.',
                );
            }
            $financialRevision = $this->quote($selection, $budget)->fingerprint();
            $result = [
                "change_id" => $operation->value,
                "student_id" => $selection->student->value,
                "from_section_id" => $selection->from->value,
                "to_section_id" => $selection->to->value,
                "removed_enrollment_id" => $drop["enrollment_id"],
                "enrollment_id" => $enrollment->id->value,
                "status" => $enrollment->status->value,
                "effective_on" => $drop["effective_on"],
                "course_charge" => $removed["course_charge"],
                "financial" => $context["financial"],
                "cash_refund_issued" => false,
            ];
            $this->receipts->save(
                $operation,
                $actor,
                $selection->to,
                $hash,
                $result,
                $this->clock->now()->format(DATE_ATOM),
            );
            $this->audit->record($actor, "registration.section_changed", $operation);
            $dependencies = $this->drops->dependencies($selection->student, [$selection->from->value], $budget);
            if (
                $dependencies->blocked() || $dependencies->summary()['policy_revision'] !==
                $context['drop']['corequisites']['policy_revision']
            ) {
                throw new ConcurrentModificationException(
                    'Co-requisite support changed before completion. Review again.',
                );
            }
            $this->registration->verifyCompletion(
                $selection->student,
                $selection->to,
                $actor,
                $byStaff,
                $budget,
                scheduleRevision: $context["target"]["students"][0]["schedule"]["revision"],
                prerequisiteRevision: $context["target"]["students"][0]["prerequisites"]["revision"],
            );
            $this->assessments->verifyReviewedSection(
                $selection->student,
                $selection->to,
                $context["target"]["section"],
                $quote,
                $budget,
            );
            $this->registration->verifyChangeSource(
                $selection->student,
                new EntityId($drop["enrollment_id"]),
                new EntityId($drop["credit"]["id"]),
                $budget,
                removed: true,
            );
            if (
                $this->repository->enrollment($selection->student, $selection->from) !== null ||
                $this->clock->now()->format("Y-m-d") !== $drop["effective_on"] ||
                $this->dropPolicy->outcome(
                    new EntityId($context["target"]["section"]["term_id"]),
                    $drop["effective_on"],
                    section: $selection->from,
                ) !== $drop["outcome"] ||
                $this->quote($selection, $budget)->fingerprint() !== $financialRevision
            ) {
                throw new ConcurrentModificationException(
                    "The selected records changed before completion. Review again.",
                );
            }
            $this->verifyRouting($selection, $routing);
            $this->sameActor($selection->student, $byStaff, $actor);
            $this->proof($selection, $byStaff, $actor, $operation, $revision, $token);
            return $result;
        });
    }

    private function quote(
        SectionChangeSelection $selection,
        HistoryReadBudget $budget,
    ): RegistrationChargeQuote {
        $section = $this->repository->section($selection->to);
        $course = $this->repository->courseForSection($section);
        return $this->billing->registrationQuote(
            $selection->student,
            $section->termId,
            $section->fee,
            $course->registrationCreditsMilli(),
            $budget,
        );
    }

    private function context(
        SectionChangeSelection $selection,
        bool $byStaff,
        HistoryReadBudget $budget,
        ?RegistrationChargeQuote &$quote,
    ): array {
        $drop = $this->drops->review(
            $selection->student,
            $selection->from,
            budget: $budget,
            replacement: new CoRequisitePlan(
                new \Domain\Registration\ValueObject\RegistrationPlanSelection(
                    $selection->student,
                    $selection->to
                ),
                $this->repository
            )
        );
        $this->early($drop);
        unset($drop["operation_id"]);
        $history = $this->registration->verifyChangeSource(
            $selection->student,
            new EntityId($drop["enrollment_id"]),
            new EntityId($drop["credit"]["id"]),
            $budget,
        );
        $target = $this->assessments->assessSectionChange(
            $selection->student,
            $selection->from,
            $selection->to,
            $byStaff,
            $budget,
            $quote,
        );
        if ($quote === null || $target["students"][0]["enrollment_id"] !== null) {
            throw new ValidationException([
                [
                    "field" => "to_section_id",
                    "message" =>
                        "This student already has an enrollment in that section. Choose another section.",
                ],
            ]);
        }
        $financial = $quote->sectionChangeSummary(new EntityId($drop["enrollment_id"]));
        $target["students"][0]["charges"]["total_minor"] = $financial["new_charge_minor"];
        $target["students"][0]["charges"]["problems"] = $financial["problems"];
        $row = $target["students"][0];
        $can =
        !$drop["corequisites"]["blocked"] &&
        $target["section"]["active"] &&
        $target["section"]["seats_remaining"] > 0 &&
        $row["issues"] === [] &&
        !$row["schedule"]["blocked"] &&
        $row["prerequisites"]["status"] !== "missing" &&
        $financial["problems"] === [];
        return [
            "drop" => $drop,
            "source_revision" => hash("sha256", json_encode($history, JSON_THROW_ON_ERROR)),
            "from" => [
                "section_id" => $selection->from->value,
                "code" => $this->repository->section($selection->from)->code,
                "enrollment_id" => $drop["enrollment_id"],
                "effective_on" => $drop["effective_on"],
                "grace_ends_on" => $drop["outcome"]["grace_ends_on"],
            ],
            "target" => $target,
            "financial" => $financial,
            "can_confirm" => $can,
        ];
    }

    private function early(array $source): void
    {
        if (!$source["outcome"]["delete_records"]) {
            throw new ValidationException([
                [
                    "field" => "from_section_id",
                    "message" =>
                        "Section changes are available during add/drop only. " .
                        "Ask the registrar to review a later change.",
                ],
            ]);
        }
    }

    private function authorize(EntityId $student, bool $byStaff): EntityId
    {
        if ($byStaff) {
            return $this->access->requirePermission("sis:registration:manage");
        }
        $actor = $this->access->authenticatedUserId();
        $person = $this->admissions->student($student)->personId;
        if ($this->admissions->person($person)->ownerUserId?->value !== $actor->value) {
            throw new AccessDeniedException("This student account belongs to another user.");
        }
        return $actor;
    }

    private function sameActor(EntityId $student, bool $byStaff, EntityId $actor): void
    {
        if ($this->authorize($student, $byStaff)->value !== $actor->value) {
            throw new AccessDeniedException("The signed-in account changed.");
        }
    }

    private function routing(SectionChangeSelection $selection): array
    {
        $person = $this->admissions->student($selection->student)->personId;
        $section = $this->repository->section($selection->from);
        return [
            "person" => $person,
            "owner" => $this->admissions->person($person)->ownerUserId?->value,
            "course" => $section->courseId,
            "term" => $section->termId,
        ];
    }

    private function lock(SectionChangeSelection $selection, array $routing): void
    {
        $this->repository->lockStudent($selection->student);
        $this->repository->lockPerson($routing["person"]);
        $sections = [$selection->from, $selection->to];
        usort($sections, static fn(EntityId $a, EntityId $b): int => strcmp($a->value, $b->value));
        foreach ($sections as $section) {
            $this->repository->lockSection($section);
        }
        $this->repository->lockCourse($routing["course"]);
        $this->policies->lockTerm($routing["term"]);
        $this->verifyRouting($selection, $routing);
    }

    private function verifyRouting(SectionChangeSelection $selection, array $routing): void
    {
        $section = $this->repository->section($selection->from);
        if (
            $this->admissions->student($selection->student)->personId->value !== $routing["person"]->value ||
            $this->admissions->person($routing["person"])->ownerUserId?->value !== $routing["owner"] ||
            $section->courseId->value !== $routing["course"]->value ||
            $section->termId->value !== $routing["term"]->value
        ) {
            throw new ConcurrentModificationException("The student or section setup changed. Review again.");
        }
    }

    private function revision(array $context): string
    {
        return hash("sha256", json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function proof(
        SectionChangeSelection $selection,
        bool $byStaff,
        EntityId $actor,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $token,
        bool $accepted = false,
    ): void {
        $this->proofs->validate(
            $token,
            "section-change",
            $byStaff ? "staff" : "student",
            $selection->student,
            $actor,
            $operation,
            $revision,
            accepted: $accepted,
        );
    }

    private function replay(array $receipt, EntityId $actor, string $hash): array
    {
        if ($receipt["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This confirmation belongs to another account.");
        }
        if (!hash_equals($receipt["request_hash"], $hash)) {
            throw new ConcurrentModificationException("This confirmation belongs to another section change.");
        }
        return $receipt["result"];
    }
}
