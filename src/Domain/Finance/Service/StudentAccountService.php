<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Dto\StudentAccountProfile;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class StudentAccountService
{
    public function __construct(
        private AccountRepository $accounts,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private FinancialCommandPolicy $commands,
        private AuditTrail $audit,
    ) {
    }

    public function open(EntityId $studentId, EntityId $termId, string $currency): StudentAccount
    {
        $actor = $this->access->requirePermission('sis:finance:post');
        return $this->transactions->run(function () use ($studentId, $termId, $currency, $actor): StudentAccount {
            $this->admissions->student($studentId);
            $account = StudentAccount::open(new AccountId(), $studentId, $termId, $currency);
            $id = new EntityId((string) $account->aggregateId());
            $this->commands->assertActor($actor, 'sis:finance:post');
            $this->accounts->save($account, $this->commands->context(
                $actor,
                $id,
                'Student billing account opened by staff.'
            ));
            $this->audit->record($actor, 'account.opened', $id);
            $this->commands->assertActor($actor, 'sis:finance:post');
            return $account;
        });
    }

    /**
     * A repeated monetary operation returns the original posting; it cannot replace its original reason.
     *
     * @return array{status:string}
     */
    public function post(
        AccountId $id,
        EntityId $operationId,
        EntryType $type,
        Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission('sis:finance:post');
        $reason = $this->commands->reason($reason);
        return $this->transactions->run(function () use (
            $id,
            $operationId,
            $type,
            $amount,
            $position,
            $reason,
            $actor,
        ): array {
            $account = $this->accounts->get($id);
            $existing = $account->entry($operationId) !== null;
            if (!$existing) {
                $this->commands->assertPosition($account->playhead(), $position);
            }
            $this->commands->assertActor($actor, 'sis:finance:post');
            $account->post($operationId, $type, $amount, $actor);
            if (!$existing) {
                $this->accounts->save($account, $this->commands->context($actor, $operationId, $reason));
                $this->audit->record($actor, 'account.entry_posted', $operationId);
            }
            $this->commands->assertActor($actor, 'sis:finance:post');
            return ['status' => $existing ? 'already_recorded' : 'posted'];
        });
    }

    /** @return array{status:string} */
    public function reverse(
        AccountId $id,
        EntityId $operationId,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission('sis:finance:post');
        $reason = $this->commands->reason($reason);
        return $this->transactions->run(function () use ($id, $operationId, $position, $reason, $actor): array {
            $account = $this->accounts->get($id);
            $existing = $account->entry($operationId)['reversed'] ?? false;
            if (!$existing) {
                $this->commands->assertPosition($account->playhead(), $position);
            }
            $this->commands->assertActor($actor, 'sis:finance:post');
            $account->reverse($operationId, $actor);
            if (!$existing) {
                $this->accounts->save($account, $this->commands->context($actor, $operationId, $reason));
                $this->audit->record($actor, 'account.entry_reversed', $operationId);
            }
            $this->commands->assertActor($actor, 'sis:finance:post');
            return ['status' => $existing ? 'already_recorded' : 'reversed'];
        });
    }

    public function account(AccountId $id): StudentAccount
    {
        return $this->accountProfile($id)->account;
    }

    public function accountProfile(AccountId $id): StudentAccountProfile
    {
        $actor = $this->access->authenticatedUserId();
        $account = $this->accounts->get($id);
        $student = $this->admissions->student($account->studentId());
        $person = $this->admissions->person($student->personId);
        $reader = $this->access->requireOwnerOrPermission($person->ownerUserId, 'sis:finance:read');
        if ($reader->value !== $actor->value) {
            throw new AccessDeniedException('The financial reader changed.');
        }

        return new StudentAccountProfile($account, $person);
    }
}
