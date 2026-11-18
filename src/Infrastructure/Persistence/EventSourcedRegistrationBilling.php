<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Enum\BillingBasis;
use Domain\Finance\ValueObject\RegistrationPrice;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\Repository\BillingRuleRepository;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\TermFeeQuote;
use Domain\Finance\Service\TermFeeBilling;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Registration\Service\RegistrationBilling;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\EventSourcing\EventContext;
use Qubus\Exception\Data\TypeException;

final readonly class EventSourcedRegistrationBilling implements RegistrationBilling, TermFeeBilling
{
    public function __construct(
        private AccountRepository $accounts,
        private SqlRecords $sql,
        private BillingRuleRepository $rules,
        private AdmissionsRepository $admissions,
        private \Domain\Student\Service\StudentPrimaryProgramLedger $primaryPrograms,
    ) {
    }

    public function reverseCourseCharge(
        EntityId $student,
        EntityId $term,
        EntityId $enrollment,
        EventContext $context,
    ): bool {
        $row =
        $this->sql->rows(
            "sis_student_accounts",
            ["student_id" => $student->value, "term_id" => $term->value],
            1,
        )[0] ?? null;
        if ($row === null) {
            return false;
        }
        $account = $this->accounts->get(new AccountId($row["id"]));
        $charges = $account->courseCharges($enrollment);
        if ($charges === []) {
            return false;
        }
        foreach ($charges as $charge) {
            $entry = $account->entry($charge) ?? throw new CorruptHistoryException();
            if ($entry["type"] !== EntryType::Charge->value) {
                throw new \DomainException("The registration source is not a course charge.");
            }
            $account->reverse(
                $charge,
                $context->actor ?? throw new \LogicException("A billing actor is required."),
            );
        }
        $this->accounts->save($account, $context);
        return true;
    }

    /**
     * @throws TypeException
     */
    public function charge(
        EntityId $student,
        EntityId $term,
        EntityId $operation,
        Money $fee,
        int $creditsMilli,
        EventContext $context,
        ?\Domain\Finance\ValueObject\RegistrationChargeQuote $quote = null,
    ): void {
        $quote ??= $this->registrationQuote($student, $term, $fee, $creditsMilli, new HistoryReadBudget());
        if (
            $quote->termQuote->student->value !== $student->value ||
            $quote->termQuote->term->value !== $term->value ||
            $quote->creditsMilli !== $creditsMilli ||
            $quote->sectionFee != $fee
        ) {
            throw new \LogicException("Registration pricing must match its accepted section and student.");
        }
        if ($quote->summary()["problems"] !== []) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "students",
                    "message" => "Resolve the registration pricing shown in the review before confirming.",
                ],
            ]);
        }
        $this->applyQuote($quote->termQuote, $context, $operation, $fee, $creditsMilli);
    }

    public function registrationQuote(
        EntityId $student,
        EntityId $term,
        Money $fee,
        int $creditsMilli,
        HistoryReadBudget $budget,
        ?array $rules = null,
    ): \Domain\Finance\ValueObject\RegistrationChargeQuote {
        $program = $this->admissions->student($student)->programId;
        return new \Domain\Finance\ValueObject\RegistrationChargeQuote(
            $this->quote($student, $term, $rules ?? $this->rules($term, $budget, [$program]), $budget),
            $fee,
            $creditsMilli,
        );
    }

    public function rules(EntityId $term, HistoryReadBudget $budget, array $programs): array
    {
        return $this->rules->forTerm($term, $budget, $programs);
    }

    public function quote(
        EntityId $student,
        EntityId $term,
        array $rules,
        HistoryReadBudget $budget,
    ): TermFeeQuote {
        $studentRecord = $this->admissions->student($student);
        $primaryPosition = $this->primaryPrograms->verify($studentRecord, $budget);
        $rules = array_values(
            array_filter(
                $rules,
                static fn($rule): bool => $rule->programId() === null ||
                    $rule->programId()->value === $studentRecord->programId->value,
            ),
        );
        $existing = $this->sql->rows(
            "sis_student_accounts",
            [
                "student_id" => $student->value,
                "term_id" => $term->value,
            ],
            2,
        );
        if (count($existing) > 1) {
            throw new CorruptHistoryException();
        }
        $account = $existing === [] ? null : $this->accounts->get(new AccountId($existing[0]["id"]), $budget);
        return new TermFeeQuote(
            $student,
            $term,
            $studentRecord->programId,
            $primaryPosition,
            $account,
            $rules,
        );
    }

    public function apply(TermFeeQuote $quote, EventContext $context): array
    {
        return $this->applyQuote($quote, $context, null, null, null);
    }

    /** @return array{student_id:string,account_id:?string,created:bool,posted:int,position:?int} */
    private function applyQuote(
        #[\SensitiveParameter] TermFeeQuote $quote,
        #[\SensitiveParameter] EventContext $context,
        ?EntityId $courseOperation,
        ?Money $courseFee,
        ?int $creditsMilli,
    ): array {
        $actor = $context->actor ?? throw new \LogicException("A billing actor is required.");
        $facts = $quote->facts();
        if ($facts["problems"] !== []) {
            throw new \Domain\Shared\Exception\ValidationException([
                [
                    "field" => "students",
                    "message" => "Resolve the billing configuration shown in the review before confirming.",
                ],
            ]);
        }
        $prices = [];
        if ($courseOperation !== null) {
            foreach ($quote->rules as $rule) {
                if ($rule->basis() !== BillingBasis::RegisteredCredit) {
                    continue;
                }
                $price = new RegistrationPrice(
                    $courseOperation,
                    new EntityId((string) $rule->aggregateId()),
                    $rule->name(),
                    $creditsMilli ?? throw new \LogicException("Registration credits are required."),
                    $rule->amount(),
                );
                if ($price->amount->minor > 0) {
                    $prices[] = $price;
                }
            }
        }
        $hasCourseFee = $courseFee !== null && $courseFee->minor > 0;
        $account = $quote->account;
        $created = false;
        if ($facts["new_charges"] > 0 || $hasCourseFee || $prices !== []) {
            if ($account === null) {
                $currency = $hasCourseFee
                ? $courseFee->currency
                : ($prices[0] ?? null)?->amount->currency ?? $facts["currency"];
                $account = StudentAccount::open(new AccountId(), $quote->student, $quote->term, $currency);
                $created = true;
            }
        } else {
            return [
                "student_id" => $quote->student->value,
                "account_id" => $account === null ? null : (string) $account->aggregateId(),
                "created" => false,
                "posted" => 0,
                "position" => $account?->playhead(),
            ];
        }
        foreach ($quote->rules as $rule) {
            if ($rule->basis() !== BillingBasis::Term) {
                continue;
            }
            $account->post(
                new EntityId((string) $rule->aggregateId()),
                EntryType::Charge,
                $rule->amount(),
                $actor,
            );
        }
        if ($hasCourseFee) {
            $account->post(
                $courseOperation ?? throw new \LogicException("A course charge needs its enrollment."),
                EntryType::Charge,
                $courseFee,
                $actor,
            );
        }
        foreach ($prices as $price) {
            $account->post(
                EntityId::generate(),
                EntryType::Charge,
                $price->amount,
                $actor,
                registrationPrice: $price,
            );
        }
        $this->accounts->save($account, $context);
        return [
            "student_id" => $quote->student->value,
            "account_id" => (string) $account->aggregateId(),
            "created" => $created,
            "posted" => $facts["new_charges"],
            "position" => $account->playhead(),
        ];
    }
}
