<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/**
 * Correct explicitly staff-recorded amounts; never alter registration, billing or provider-owned sources.
 *
 * @phpstan-type CorrectionProof array{purpose:string,actor_id:string,account_id:string,source_operation_id:string,
 *     operation_id:string,position:int,minor:int,currency:string,reason:string,issued_at:string,expires_at:string}
 */
final readonly class AccountCorrectionService
{
    private const string PURPOSE = "finance.account-correction";

    public function __construct(
        private AccountRepository $accounts,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private FinancialCommandPolicy $commands,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function review(
        AccountId $id,
        EntityId $source,
        Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->commands->reason($reason);
        return $this->transactions->run(function () use ($id, $source, $amount, $position, $reason, $actor): array {
            $account = $this->accounts->get($id, new HistoryReadBudget());
            $this->commands->assertPosition($account->playhead(), $position);
            $balance = $account->correctedBalance($source, $amount);
            $entry = $account->entry($source) ?? throw new \LogicException("The correction source is missing.");
            $this->actor($actor);
            $now = $this->clock->now();
            $proof = [
                "purpose" => self::PURPOSE,
                "actor_id" => $actor->value,
                "account_id" => (string) $id,
                "source_operation_id" => $source->value,
                "operation_id" => EntityId::generate()->value,
                "position" => $position,
                "minor" => $amount->minor,
                "currency" => $amount->currency,
                "reason" => $reason,
                "issued_at" => $now->format("Y-m-d\TH:i:s.uP"),
                "expires_at" => $now->modify("+20 minutes")->format("Y-m-d\TH:i:s.uP"),
            ];
            $token = $this->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR));
            $this->audit->record($actor, "account.correction_reviewed", new EntityId((string) $id));
            $this->actor($actor);
            $this->fresh($proof);
            return [
                "review_token" => $token,
                "source_operation_id" => $source->value,
                "operation_id" => $proof["operation_id"],
                "type" => $entry["type"],
                "original_minor" => $entry["minor"],
                "corrected_minor" => $amount->minor,
                "currency" => $amount->currency,
                "balance_minor" => $account->balance()->minor,
                "resulting_balance_minor" => $balance->minor,
                "reason" => $reason,
                "expires_at" => $proof["expires_at"],
            ];
        });
    }

    /** @return array{status:string,operation_id:string,source_operation_id:string,balance_minor:int,position:int} */
    public function commit(AccountId $id, EntityId $source, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        $proof = $this->proof($token, $actor);
        if ($proof["account_id"] !== (string) $id || $proof["source_operation_id"] !== $source->value) {
            throw new AccessDeniedException("This correction review belongs to another account entry.");
        }
        $operation = new EntityId($proof["operation_id"]);
        $hash = hash("sha256", $token);
        return $this->transactions->run(function () use ($id, $source, $proof, $operation, $hash, $actor): array {
            $account = $this->accounts->get($id, new HistoryReadBudget());
            $this->actor($actor);
            if (($accepted = $account->correctionResult($operation, $actor, $hash)) !== null) {
                return $accepted;
            }
            $this->fresh($proof);
            $this->commands->assertPosition($account->playhead(), $proof["position"]);
            $result = $account->correct(
                $source,
                $operation,
                new Money($proof["minor"], $proof["currency"]),
                $actor,
                $hash,
            );
            $this->actor($actor);
            $this->accounts->save(
                $account,
                $this->commands->context($actor, $operation, $proof["reason"], $source),
            );
            $this->audit->record($actor, "account.entry_corrected", $operation);
            $this->actor($actor);
            $this->fresh($proof);
            return $result;
        });
    }

    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission("sis:finance:post");
        $this->actor($actor);
        return $actor;
    }

    private function actor(EntityId $actor): void
    {
        $this->commands->assertActor($actor, "sis:finance:post");
        $this->commands->assertActor($actor, "sis:finance:read");
    }

    /** @return CorrectionProof */
    private function proof(#[\SensitiveParameter] string $token, EntityId $actor): array
    {
        try {
            if ($token === "" || strlen($token) > 16384) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                ($p["purpose"] ?? null) !== self::PURPOSE ||
                !is_string($p["actor_id"] ?? null) ||
                !is_string($p["account_id"] ?? null) ||
                !is_string($p["source_operation_id"] ?? null) ||
                !is_string($p["operation_id"] ?? null) ||
                !is_int($p["position"] ?? null) ||
                $p["position"] < 1 ||
                !is_int($p["minor"] ?? null) ||
                $p["minor"] < 1 ||
                !is_string($p["currency"] ?? null) ||
                !is_string($p["reason"] ?? null) ||
                !is_string($p["issued_at"] ?? null) ||
                !is_string($p["expires_at"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            new EntityId($p["actor_id"]);
            new AccountId($p["account_id"]);
            new EntityId($p["source_operation_id"]);
            new EntityId($p["operation_id"]);
            new Money($p["minor"], $p["currency"]);
            $this->commands->reason($p["reason"]);
        } catch (Throwable) {
            throw new ValidationException([
                ["field" => "review_token", "message" => "Review this correction again."],
            ]);
        }
        if ($p["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This correction review belongs to another operator.");
        }
        return $p;
    }

    /** @param CorrectionProof $proof */
    private function fresh(#[\SensitiveParameter] array $proof): void
    {
        $issued = DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $proof["issued_at"]);
        $expires = DateTimeImmutable::createFromFormat("Y-m-d\TH:i:s.uP", $proof["expires_at"]);
        $now = $this->clock->now();
        if (
            $issued === false ||
            $expires === false ||
            $issued > $now ||
            $expires <= $now ||
            $expires->getTimestamp() - $issued->getTimestamp() !== 1200
        ) {
            throw new ConcurrentModificationException("This correction review expired. Review the account again.");
        }
    }
}
