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
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

final readonly class StudentAccountService
{
    public function __construct(
        private AccountRepository $accounts,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private Clock $clock,
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
            $this->actor($actor);
            $this->accounts->save($account, $this->context($actor, $id, 'Student billing account opened by staff.'));
            $this->audit->record($actor, 'account.opened', $id);
            $this->actor($actor);
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
        $reason = $this->reason($reason);
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
                $this->version($account, $position);
            }
            $this->actor($actor);
            $account->post($operationId, $type, $amount, $actor);
            if (!$existing) {
                $this->accounts->save($account, $this->context($actor, $operationId, $reason));
                $this->audit->record($actor, 'account.entry_posted', $operationId);
            }
            $this->actor($actor);
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
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use ($id, $operationId, $position, $reason, $actor): array {
            $account = $this->accounts->get($id);
            $existing = $account->entry($operationId)['reversed'] ?? false;
            if (!$existing) {
                $this->version($account, $position);
            }
            $this->actor($actor);
            $account->reverse($operationId, $actor);
            if (!$existing) {
                $this->accounts->save($account, $this->context($actor, $operationId, $reason));
                $this->audit->record($actor, 'account.entry_reversed', $operationId);
            }
            $this->actor($actor);
            return ['status' => $existing ? 'already_recorded' : 'reversed'];
        });
    }

    private function context(
        EntityId $actor,
        EntityId $operation,
        #[\SensitiveParameter] string $reason,
    ): EventContext {
        return new EventContext(
            $actor,
            $this->clock->now(),
            $operation,
            $operation,
            reason: $reason
        );
    }

    private function actor(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:finance:post')->value !== $actor->value) {
            throw new AccessDeniedException('The financial actor changed.');
        }
    }

    private function version(StudentAccount $account, int $position): void
    {
        if ($position < 1 || $account->playhead() !== $position) {
            throw new ConcurrentModificationException(
                'This account changed. Reload its statement before posting or reversing an entry.'
            );
        }
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || !mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > 2000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ['field' => 'reason', 'message' => 'Enter a reason of up to 2,000 characters.'],
            ]);
        }
        return $reason;
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
