<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Finance\Enum\EntryType;
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
        $entry = $account->entry($enrollment);
        if ($entry === null) {
            return false;
        }
        if ($entry["type"] !== EntryType::Charge->value) {
            throw new \DomainException("The registration source is not a course charge.");
        }
        $account->reverse($enrollment, $context->actor ?? throw new \LogicException('A billing actor is required.'));
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
        EventContext $context,
    ): void {
        $budget = new HistoryReadBudget();
        $program = $this->admissions->student($student)->programId;
        $quote = $this->quote($student, $term, $this->rules($term, $budget, [$program]), $budget);
        $this->applyQuote($quote, $context, $operation, $fee);
    }

    public function rules(EntityId $term, HistoryReadBudget $budget, array $programs): array
    {
        return $this->rules->forTerm($term, $budget, $programs);
    }

    public function quote(EntityId $student, EntityId $term, array $rules, HistoryReadBudget $budget): TermFeeQuote
    {
        $studentRecord = $this->admissions->student($student);
        $primaryPosition = $this->primaryPrograms->verify($studentRecord, $budget);
        $rules = array_values(
            array_filter(
                $rules,
                static fn($rule): bool => $rule->programId() === null ||
                    $rule->programId()->value === $studentRecord->programId->value,
            ),
        );
        $existing = $this->sql->rows("sis_student_accounts", [
            "student_id" => $student->value,
            "term_id" => $term->value,
        ], 2);
        if (count($existing) > 1) {
            throw new CorruptHistoryException();
        }
        $account = $existing === [] ? null : $this->accounts->get(new AccountId($existing[0]['id']), $budget);
        return new TermFeeQuote($student, $term, $studentRecord->programId, $primaryPosition, $account, $rules);
    }

    public function apply(TermFeeQuote $quote, EventContext $context): array
    {
        return $this->applyQuote($quote, $context, null, null);
    }

    /** @return array{student_id:string,account_id:?string,created:bool,posted:int,position:?int} */
    private function applyQuote(
        #[\SensitiveParameter] TermFeeQuote $quote,
        #[\SensitiveParameter] EventContext $context,
        ?EntityId $courseOperation,
        ?Money $courseFee,
    ): array {
        $actor = $context->actor ?? throw new \LogicException('A billing actor is required.');
        $facts = $quote->facts();
        if ($facts['problems'] !== []) {
            throw new \Domain\Shared\Exception\ValidationException([['field' => 'students',
                'message' => 'Resolve the billing configuration shown in the review before confirming.']]);
        }
        $hasCourseFee = $courseFee !== null && $courseFee->minor > 0;
        $account = $quote->account;
        $created = false;
        if ($facts['new_charges'] > 0 || $hasCourseFee) {
            if ($account === null) {
                $currency = $hasCourseFee ? $courseFee->currency : $facts['currency'];
                $account = StudentAccount::open(new AccountId(), $quote->student, $quote->term, $currency);
                $created = true;
            }
        } else {
            return ['student_id' => $quote->student->value, 'account_id' => $account === null ? null
                : (string) $account->aggregateId(), 'created' => false, 'posted' => 0,
                'position' => $account?->playhead()];
        }
        foreach ($quote->rules as $rule) {
            $account->post(
                new EntityId((string) $rule->aggregateId()),
                EntryType::Charge,
                $rule->amount(),
                $actor,
            );
        }
        if ($hasCourseFee) {
            $account->post(
                $courseOperation ?? throw new \LogicException('A course charge needs its enrollment.'),
                EntryType::Charge,
                $courseFee,
                $actor
            );
        }
        $this->accounts->save($account, $context);
        return ['student_id' => $quote->student->value, 'account_id' => (string) $account->aggregateId(),
            'created' => $created, 'posted' => $facts['new_charges'], 'position' => $account->playhead()];
    }
}
