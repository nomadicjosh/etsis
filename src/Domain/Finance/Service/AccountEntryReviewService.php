<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Enum\EntryType;
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
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * One review/confirmation boundary for staff postings and corrections; it performs no external settlement.
 *
 * @phpstan-type EntryProof array{purpose:string,actor_id:string,account_id:string,operation_id:string,
 *     position:int,minor:int,currency:string,reason:string,issued_at:string,expires_at:string,
 *     source_operation_id?:string,type?:string}
 */
final readonly class AccountEntryReviewService
{
    private const string CORRECTION = "finance.account-correction";
    private const string POSTING = "finance.account-posting";
    private const int MAX_TOKEN_BYTES = 65536;

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
    public function reviewCorrection(
        AccountId $id,
        EntityId $source,
        Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        return $this->review($id, $source, null, $amount, $position, $reason);
    }

    /** @return array<string,mixed> */
    public function reviewPosting(
        AccountId $id,
        EntryType $type,
        Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        return $this->review($id, null, $type, $amount, $position, $reason);
    }

    /** @return array<string,mixed> */
    private function review(
        AccountId $id,
        ?EntityId $source,
        ?EntryType $type,
        Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->commands->reason($reason);
        return $this->transactions->run(function () use (
            $id,
            $source,
            $type,
            $amount,
            $position,
            $reason,
            $actor,
        ): array {
            $account = $this->accounts->get($id, new HistoryReadBudget());
            $this->commands->assertPosition($account->playhead(), $position);
            if ($source === null) {
                $type ??= throw new \LogicException("A posting requires a transaction type.");
                try {
                    $balance = $account->postingBalance($type, $amount);
                } catch (DomainException | InvalidArgumentException $error) {
                    // These aggregate/Money checks return fixed validation wording, without personal values.
                    throw new ValidationException([["field" => "amount_minor", "message" => $error->getMessage()]]);
                }
                $intent = ["type" => $type->value];
                $details = ["type" => $type->value, "amount_minor" => $amount->minor];
                $purpose = self::POSTING;
            } else {
                $balance = $account->correctedBalance($source, $amount);
                $entry = $account->entry($source) ?? throw new \LogicException("The correction source is missing.");
                $intent = ["source_operation_id" => $source->value];
                $details = [
                    "source_operation_id" => $source->value,
                    "type" => $entry["type"],
                    "original_minor" => $entry["minor"],
                    "corrected_minor" => $amount->minor,
                ];
                $purpose = self::CORRECTION;
            }
            $this->actor($actor);
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $proof =
                [
                    "purpose" => $purpose,
                    "actor_id" => $actor->value,
                    "account_id" => (string) $id,
                    "operation_id" => EntityId::generate()->value,
                    "position" => $position,
                    "minor" => $amount->minor,
                    "currency" => $amount->currency,
                    "reason" => $reason,
                    "issued_at" => $now->format("Y-m-d\TH:i:s.uP"),
                    "expires_at" => $now->modify("+20 minutes")->format("Y-m-d\TH:i:s.uP"),
                ] + $intent;
            $token = $this->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->audit->record(
                $actor,
                $source === null ? "account.posting_reviewed" : "account.correction_reviewed",
                new EntityId((string) $id),
            );
            $this->actor($actor);
            $this->fresh($proof);
            return $details + [
                "review_token" => $token,
                "operation_id" => $proof["operation_id"],
                "currency" => $amount->currency,
                "balance_minor" => $account->balance()->minor,
                "resulting_balance_minor" => $balance->minor,
                "available_credit_minor" => max(0, -$account->balance()->minor),
                "remaining_credit_minor" => max(0, -$balance->minor),
                "reason" => $reason,
                "expires_at" => $proof["expires_at"],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function commitCorrection(AccountId $id, EntityId $source, #[\SensitiveParameter] string $token): array
    {
        return $this->commit($id, $source, $token);
    }

    /** @return array<string,mixed> */
    public function commitPosting(AccountId $id, #[\SensitiveParameter] string $token): array
    {
        return $this->commit($id, null, $token);
    }

    /** @return array<string,mixed> */
    private function commit(AccountId $id, ?EntityId $source, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        $proof = $this->proof($token, $actor, $source === null ? self::POSTING : self::CORRECTION);
        if ($proof["account_id"] !== (string) $id || ($proof["source_operation_id"] ?? null) !== $source?->value) {
            throw new AccessDeniedException("This review belongs to another account entry.");
        }
        $operation = new EntityId($proof["operation_id"]);
        $hash = hash("sha256", $token);
        return $this->transactions->run(function () use ($id, $source, $proof, $operation, $hash, $actor): array {
            $account = $this->accounts->get($id, new HistoryReadBudget());
            $this->actor($actor);
            $accepted =
                $source === null
                    ? $account->postingResult($operation, $actor, $hash)
                    : $account->correctionResult($operation, $actor, $hash);
            if ($accepted !== null) {
                return $accepted;
            }
            $this->fresh($proof);
            $this->commands->assertPosition($account->playhead(), $proof["position"]);
            $amount = new Money($proof["minor"], $proof["currency"]);
            $result =
                $source === null
                    ? $account->postReviewed(
                        $operation,
                        EntryType::from($proof["type"] ?? ""),
                        $amount,
                        $actor,
                        $hash,
                    )
                    : $account->correct($source, $operation, $amount, $actor, $hash);
            $this->actor($actor);
            $this->accounts->save(
                $account,
                $this->commands->context($actor, $operation, $proof["reason"], $source),
            );
            $this->audit->record(
                $actor,
                $source === null ? "account.entry_posted" : "account.entry_corrected",
                $operation,
            );
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

    /** @return EntryProof */
    private function proof(#[\SensitiveParameter] string $token, EntityId $actor, string $purpose): array
    {
        try {
            if ($token === "" || strlen($token) > self::MAX_TOKEN_BYTES) {
                throw new InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                ($p["purpose"] ?? null) !== $purpose ||
                !is_string($p["actor_id"] ?? null) ||
                !is_string($p["account_id"] ?? null) ||
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
                throw new InvalidArgumentException();
            }
            if ($purpose === self::CORRECTION) {
                if (!is_string($p["source_operation_id"] ?? null)) {
                    throw new InvalidArgumentException();
                }
                new EntityId($p["source_operation_id"]);
            } elseif (
                !is_string($p["type"] ?? null) ||
                EntryType::tryFrom($p["type"]) === null ||
                array_key_exists("source_operation_id", $p)
            ) {
                throw new InvalidArgumentException();
            }
            new EntityId($p["actor_id"]);
            new AccountId($p["account_id"]);
            new EntityId($p["operation_id"]);
            new Money($p["minor"], $p["currency"]);
            $this->commands->reason($p["reason"]);
        } catch (Throwable) {
            throw new ValidationException([
                ["field" => "review_token", "message" => "Review this account entry again."],
            ]);
        }
        if ($p["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This account review belongs to another operator.");
        }
        return $p;
    }

    /** @param EntryProof $proof */
    private function fresh(#[\SensitiveParameter] array $proof): void
    {
        try {
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
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new ConcurrentModificationException("This account review expired. Review the account again.");
        }
    }
}
