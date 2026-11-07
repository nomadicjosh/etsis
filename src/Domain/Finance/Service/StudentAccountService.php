<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Finance\Dto\StudentAccountProfile;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class StudentAccountService
{
    public function __construct(
        private AccountRepository $accounts,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
    ) {
    }

    public function open(EntityId $studentId, EntityId $termId, string $currency): StudentAccount
    {
        $this->access->requirePermission('sis:finance:post');
        $this->admissions->student($studentId);
        $account = StudentAccount::open(new AccountId(), $studentId, $termId, $currency);
        $this->accounts->save($account);

        return $account;
    }

    public function post(AccountId $id, EntityId $operationId, EntryType $type, Money $amount): void
    {
        $actor = $this->access->requirePermission('sis:finance:post');
        $account = $this->accounts->get($id);
        $account->post($operationId, $type, $amount, $actor);
        $this->accounts->save($account);
    }

    public function reverse(AccountId $id, EntityId $operationId): void
    {
        $actor = $this->access->requirePermission('sis:finance:post');
        $account = $this->accounts->get($id);
        $account->reverse($operationId, $actor);
        $this->accounts->save($account);
    }

    public function account(AccountId $id): StudentAccount
    {
        return $this->accountProfile($id)->account;
    }

    public function accountProfile(AccountId $id): StudentAccountProfile
    {
        $this->access->authenticatedUserId();
        $account = $this->accounts->get($id);
        $student = $this->admissions->student($account->studentId());
        $person = $this->admissions->person($student->personId);
        $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:finance:read');

        return new StudentAccountProfile($account, $person);
    }
}
