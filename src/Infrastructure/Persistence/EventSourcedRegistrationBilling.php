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
use Domain\Registration\Service\RegistrationBilling;
use Domain\Shared\ValueObject\EntityId;
use Qubus\Exception\Data\TypeException;

final readonly class EventSourcedRegistrationBilling implements RegistrationBilling
{
    public function __construct(
        private AccountRepository $accounts,
        private SqlRecords $sql,
        private BillingRuleRepository $rules,
        private AdmissionsRepository $admissions,
    ) {
    }

    public function reverseCourseCharge(EntityId $student, EntityId $term, EntityId $enrollment, EntityId $actor): bool
    {
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
        $account->reverse($enrollment, $actor);
        $this->accounts->save($account);
        return true;
    }

    /**
     * @throws TypeException
     */
    public function charge(EntityId $student, EntityId $term, EntityId $operation, Money $fee, EntityId $actor): void
    {
        $studentRecord = $this->admissions->student($student);
        $rules = array_values(
            array_filter(
                $this->rules->forTerm($term),
                static fn($rule): bool => $rule->programId() === null ||
                    $rule->programId()->value === $studentRecord->programId->value,
            ),
        );
        if ($fee->minor === 0 && $rules === []) {
            return;
        }
        $currency = $fee->minor > 0 ? $fee->currency : $rules[0]->amount()->currency;
        $existing = $this->sql->rows("sis_student_accounts", [
            "student_id" => $student->value,
            "term_id" => $term->value,
        ]);
        $account =
        $existing === []
        ? StudentAccount::open(new AccountId(), $student, $term, $currency)
        : $this->accounts->get(new AccountId($existing[0]["id"]));
        foreach ($rules as $rule) {
            $account->post(new EntityId((string) $rule->aggregateId()), EntryType::Charge, $rule->amount(), $actor);
        }
        if ($fee->minor > 0) {
            $account->post($operation, EntryType::Charge, $fee, $actor);
        }
        $this->accounts->save($account);
    }
}
