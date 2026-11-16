<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Enum\EntryType;
use Domain\Finance\Enum\AccountEntryAction;
use Domain\Finance\Repository\AccountRepository;
use Domain\Finance\ValueObject\AccountId;
use Domain\Finance\ValueObject\Money;
use Domain\Finance\ValueObject\PaymentDetails;
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
 *     source_operation_id?:string,type?:string,payment_details?:array{method:?string,reference:?string}}
 */
final readonly class AccountEntryReviewService
{
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
        return $this->review(AccountEntryAction::Correction, $id, $source, null, $amount, $position, $reason);
    }

    /** @return array<string,mixed> */
    public function reviewPosting(
        AccountId $id,
        EntryType $type,
        Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] ?PaymentDetails $paymentDetails = null,
    ): array {
        return $this->review(
            AccountEntryAction::Posting,
            $id,
            null,
            $type,
            $amount,
            $position,
            $reason,
            $paymentDetails,
        );
    }

    /** Private receipt information is intentionally separate from owner-visible account entries.
     * @return array<string,mixed>
     */
    public function receipt(AccountId $id, EntityId $entry): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($id, $entry, $actor): array {
            $account = $this->accounts->get($id, new HistoryReadBudget());
            if (!$account->canCorrectPaymentDetails($entry)) {
                throw new ValidationException([
                    [
                        "field" => "source_operation_id",
                        "message" =>
                            "Receipt details are available for explicitly staff-recorded payments and refunds.",
                    ],
                ]);
            }
            $this->audit->record($actor, "account.receipt_read", new EntityId((string) $id));
            $this->actor($actor);
            return [
                "payment_details" => $account->paymentDetails($entry)?->values(),
                "position" => $account->playhead(),
                "entry_operation_id" => $entry->value,
            ];
        });
    }

    /** @return array<string,mixed> */
    public function reviewPaymentDetails(
        AccountId $id,
        EntityId $entry,
        #[\SensitiveParameter] PaymentDetails $details,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        return $this->review(
            AccountEntryAction::PaymentDetails,
            $id,
            $entry,
            null,
            null,
            $position,
            $reason,
            $details,
        );
    }

    /** @return array<string,mixed> */
    public function commitPaymentDetails(
        AccountId $id,
        EntityId $entry,
        #[\SensitiveParameter] string $token,
    ): array {
        return $this->commit(AccountEntryAction::PaymentDetails, $id, $entry, $token);
    }

    /** @return array<string,mixed> */
    public function reviewReversal(
        AccountId $id,
        EntityId $entry,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        return $this->review(AccountEntryAction::Reversal, $id, $entry, null, null, $position, $reason);
    }

    /** @return array<string,mixed> */
    public function commitReversal(
        AccountId $id,
        EntityId $entry,
        #[\SensitiveParameter] string $token,
    ): array {
        return $this->commit(AccountEntryAction::Reversal, $id, $entry, $token);
    }

    /** @return array<string,mixed> */
    public function reviewChargeCredit(
        AccountId $id,
        EntityId $source,
        Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
    ): array {
        return $this->review(
            AccountEntryAction::ChargeCredit,
            $id,
            $source,
            null,
            $amount,
            $position,
            $reason,
        );
    }

    /** @return array<string,mixed> */
    public function commitChargeCredit(
        AccountId $id,
        EntityId $source,
        #[\SensitiveParameter] string $token,
    ): array {
        return $this->commit(AccountEntryAction::ChargeCredit, $id, $source, $token);
    }

    /** @return array<string,mixed> */
    public function reviewRefund(
        AccountId $id,
        EntityId $payment,
        Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] ?PaymentDetails $details = null,
    ): array {
        return $this->review(
            AccountEntryAction::Refund,
            $id,
            $payment,
            null,
            $amount,
            $position,
            $reason,
            $details,
        );
    }

    /** @return array<string,mixed> */
    public function commitRefund(
        AccountId $id,
        EntityId $payment,
        #[\SensitiveParameter] string $token,
    ): array {
        return $this->commit(AccountEntryAction::Refund, $id, $payment, $token);
    }

    /** @return array<string,mixed> */
    private function review(
        AccountEntryAction $action,
        AccountId $id,
        ?EntityId $source,
        ?EntryType $type,
        ?Money $amount,
        int $position,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] ?PaymentDetails $paymentDetails = null,
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
            $paymentDetails,
            $action,
        ): array {
            $account = $this->accounts->get($id, new HistoryReadBudget());
            $this->commands->assertPosition($account->playhead(), $position);
            if ($action === AccountEntryAction::PaymentDetails) {
                $source ??= throw new \LogicException("A receipt correction requires an entry.");
                $paymentDetails ??= throw new \LogicException("A receipt correction requires details.");
                $account->assertPaymentDetailsChange($source, $paymentDetails);
                $entry =
                    $account->entry($source) ?? throw new \LogicException("The receipt entry is missing.");
                $amount = new Money($entry["minor"], $entry["currency"]);
                $balance = $account->balance();
                $intent = [
                    "source_operation_id" => $source->value,
                    "payment_details" => $paymentDetails->values(),
                ];
                $details = [
                    "type" => $entry["type"],
                    "amount_minor" => $entry["minor"],
                    "previous_payment_details" => $account->paymentDetails($source)?->values(),
                    "payment_details" => $paymentDetails->values(),
                ];
            } elseif ($action === AccountEntryAction::Refund) {
                $source ??= throw new \LogicException("A linked refund requires its payment.");
                $amount ??= throw new \LogicException("A linked refund requires an amount.");
                try {
                    $balance = $account->postingBalance(EntryType::Refund, $amount, $source);
                } catch (DomainException | InvalidArgumentException $error) {
                    throw new ValidationException([
                        ["field" => "amount_minor", "message" => $error->getMessage()],
                    ]);
                }
                $entry =
                    $account->entry($source) ?? throw new \LogicException("The source payment is missing.");
                $summary = $account->paymentRefundSummary($source);
                $intent =
                    ["source_operation_id" => $source->value] +
                    ($paymentDetails === null || $paymentDetails->isEmpty()
                        ? []
                        : ["payment_details" => $paymentDetails->values()]);
                $details = [
                    "type" => EntryType::Refund->value,
                    "amount_minor" => $amount->minor,
                    "source_payment_operation_id" => $source->value,
                    "payment_minor" => $entry["minor"],
                    "linked_refund_minor" => $summary["linked_refund_minor"],
                    "remaining_payment_minor" => $summary["remaining_refundable_minor"] - $amount->minor,
                    "unlinked_refund_minor" => $account->unlinkedRefundMinor(),
                    "source_payment_details" => $account->paymentDetails($source)?->values(),
                    "payment_details" => $intent["payment_details"] ?? null,
                ];
            } elseif ($action === AccountEntryAction::Posting) {
                $amount ??= throw new \LogicException("A posting requires an amount.");
                if (
                    $paymentDetails !== null &&
                    !$paymentDetails->isEmpty() &&
                    in_array($type, [EntryType::Charge, EntryType::ChargeCredit], true)
                ) {
                    throw new ValidationException([
                        [
                            "field" => "payment_method",
                            "message" => "Receipt details apply to payments and refunds.",
                        ],
                    ]);
                }
                $type ??= throw new \LogicException("A posting requires a transaction type.");
                try {
                    $balance = $account->postingBalance($type, $amount);
                } catch (DomainException | InvalidArgumentException $error) {
                    // These aggregate/Money checks return fixed validation wording, without personal values.
                    throw new ValidationException([
                        ["field" => "amount_minor", "message" => $error->getMessage()],
                    ]);
                }
                $intent =
                    ["type" => $type->value] +
                    ($paymentDetails === null || $paymentDetails->isEmpty()
                        ? []
                        : ["payment_details" => $paymentDetails->values()]);
                $details = [
                    "type" => $type->value,
                    "amount_minor" => $amount->minor,
                    "payment_details" => $intent["payment_details"] ?? null,
                ];
            } elseif ($action === AccountEntryAction::Reversal) {
                $source ??= throw new \LogicException("A reversal requires an entry.");
                $balance = $account->reversalBalance($source);
                $entry =
                    $account->entry($source) ?? throw new \LogicException("The reversal source is missing.");
                $amount = new Money($entry["minor"], $entry["currency"]);
                $intent = ["source_operation_id" => $source->value, "operation_id" => $source->value];
                $details = [
                    "source_operation_id" => $source->value,
                    "type" => $entry["type"],
                    "amount_minor" => $entry["minor"],
                    "payment_details" => $account->paymentDetails($source)?->values(),
                    "reversed_credit_minor" => $entry["credited_minor"] ?? 0,
                    "reversed_credit_count" => $entry["linked_credit_count"] ?? 0,
                    "linked_refund_minor" => $entry["linked_refund_minor"] ?? 0,
                    "linked_refund_count" => $entry["linked_refund_count"] ?? 0,
                ];
            } elseif ($action === AccountEntryAction::ChargeCredit) {
                $source ??= throw new \LogicException("A charge credit requires its source.");
                $amount ??= throw new \LogicException("A charge credit requires an amount.");
                $balance = $account->creditBalance($source, $amount);
                $entry =
                    $account->entry($source) ?? throw new \LogicException("The charge source is missing.");
                $intent = ["source_operation_id" => $source->value];
                $details = [
                    "source_operation_id" => $source->value,
                    "type" => EntryType::ChargeCredit->value,
                    "amount_minor" => $amount->minor,
                    "charge_minor" => $entry["minor"],
                    "credited_minor" =>
                        $entry["credited_minor"] ??
                        throw new \LogicException("The charge credit total is missing."),
                    "remaining_charge_minor" =>
                        ($entry["remaining_charge_minor"] ??
                            throw new \LogicException("The remaining charge is missing.")) -
                        $amount->minor,
                ];
            } else {
                $source ??= throw new \LogicException("An amount correction requires an entry.");
                $amount ??= throw new \LogicException("An amount correction requires an amount.");
                $balance = $account->correctedBalance($source, $amount);
                $entry =
                    $account->entry($source) ??
                    throw new \LogicException("The correction source is missing.");
                $intent = ["source_operation_id" => $source->value];
                $details = [
                    "source_operation_id" => $source->value,
                    "type" => $entry["type"],
                    "original_minor" => $entry["minor"],
                    "corrected_minor" => $amount->minor,
                    "payment_details" => $account->paymentDetails($source)?->values(),
                ];
            }
            $this->actor($actor);
            $now = $this->clock->now()->setTimezone(new DateTimeZone("UTC"));
            $proof =
                [
                    "purpose" => $action->value,
                    "actor_id" => $actor->value,
                    "account_id" => (string) $id,
                    "operation_id" => $intent["operation_id"] ?? EntityId::generate()->value,
                    "position" => $position,
                    "minor" => $amount->minor,
                    "currency" => $amount->currency,
                    "reason" => $reason,
                    "issued_at" => $now->format("Y-m-d\TH:i:s.uP"),
                    "expires_at" => $now->modify("+20 minutes")->format("Y-m-d\TH:i:s.uP"),
                ] + $intent;
            $token = $this->cipher->seal(json_encode($proof, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->audit->record($actor, $action->reviewAudit(), new EntityId((string) $id));
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
    public function commitCorrection(
        AccountId $id,
        EntityId $source,
        #[\SensitiveParameter] string $token,
    ): array {
        return $this->commit(AccountEntryAction::Correction, $id, $source, $token);
    }

    /** @return array<string,mixed> */
    public function commitPosting(AccountId $id, #[\SensitiveParameter] string $token): array
    {
        return $this->commit(AccountEntryAction::Posting, $id, null, $token);
    }

    /** @return array<string,mixed> */
    private function commit(
        AccountEntryAction $action,
        AccountId $id,
        ?EntityId $source,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $proof = $this->proof($token, $actor, $action);
        if (
            $proof["account_id"] !== (string) $id ||
            ($proof["source_operation_id"] ?? null) !== $source?->value
        ) {
            throw new AccessDeniedException("This review belongs to another account entry.");
        }
        $operation = new EntityId($proof["operation_id"]);
        $hash = hash("sha256", $token);
        return $this->transactions->run(function () use (
            $id,
            $source,
            $proof,
            $operation,
            $hash,
            $actor,
            $action,
        ): array {
            $account = $this->accounts->get($id, new HistoryReadBudget());
            $this->actor($actor);
            $accepted = match ($action) {
                AccountEntryAction::Posting, AccountEntryAction::Refund => $account->postingResult(
                    $operation,
                    $actor,
                    $hash,
                ),
                AccountEntryAction::Correction => $account->correctionResult($operation, $actor, $hash),
                AccountEntryAction::PaymentDetails => $account->paymentDetailsResult(
                    $operation,
                    $actor,
                    $hash,
                ),
                AccountEntryAction::Reversal => $account->reversalResult($operation, $actor, $hash),
                AccountEntryAction::ChargeCredit => $account->chargeCreditResult($operation, $actor, $hash),
            };
            if ($accepted !== null) {
                return $accepted;
            }
            $this->fresh($proof);
            $this->commands->assertPosition($account->playhead(), $proof["position"]);
            $amount = new Money($proof["minor"], $proof["currency"]);
            $result = match ($action) {
                AccountEntryAction::Refund => $account->postReviewed(
                    $operation,
                    EntryType::Refund,
                    $amount,
                    $actor,
                    $hash,
                    isset($proof["payment_details"])
                        ? PaymentDetails::fromRecorded($proof["payment_details"])
                        : null,
                    sourcePayment: $source ??
                        throw new \LogicException("A linked refund requires its payment."),
                ),
                AccountEntryAction::Posting => $account->postReviewed(
                    $operation,
                    EntryType::from($proof["type"] ?? ""),
                    $amount,
                    $actor,
                    $hash,
                    isset($proof["payment_details"])
                        ? PaymentDetails::fromRecorded($proof["payment_details"])
                        : null,
                ),
                AccountEntryAction::Correction => $account->correct(
                    $source ?? throw new \LogicException("An amount correction requires an entry."),
                    $operation,
                    $amount,
                    $actor,
                    $hash,
                ),
                AccountEntryAction::PaymentDetails => $account->changePaymentDetails(
                    $source ?? throw new \LogicException("A receipt correction requires an entry."),
                    $operation,
                    PaymentDetails::fromRecorded($proof["payment_details"] ?? []),
                    $actor,
                    $hash,
                ),
                AccountEntryAction::Reversal => $account->reverseReviewed($operation, $actor, $hash),
                AccountEntryAction::ChargeCredit => $account->creditCharge(
                    $source ?? throw new \LogicException("A charge credit requires its source."),
                    $operation,
                    $amount,
                    $actor,
                    $hash,
                ),
            };
            $this->actor($actor);
            $this->accounts->save(
                $account,
                $this->commands->context($actor, $operation, $proof["reason"], $source),
            );
            $this->audit->record($actor, $action->commitAudit(), $operation);
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
    private function proof(
        #[\SensitiveParameter] string $token,
        EntityId $actor,
        AccountEntryAction $action,
    ): array {
        try {
            if ($token === "" || strlen($token) > self::MAX_TOKEN_BYTES) {
                throw new InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) ||
                ($p["purpose"] ?? null) !== $action->value ||
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
            if ($action !== AccountEntryAction::Posting) {
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
            if (array_key_exists("payment_details", $p)) {
                if (
                    !is_array($p["payment_details"]) ||
                    in_array(
                        $action,
                        [
                            AccountEntryAction::Correction,
                            AccountEntryAction::Reversal,
                            AccountEntryAction::ChargeCredit,
                        ],
                        true,
                    )
                ) {
                    throw new InvalidArgumentException();
                }
                $details = PaymentDetails::fromRecorded($p["payment_details"]);
                if (
                    in_array($action, [AccountEntryAction::Posting, AccountEntryAction::Refund], true) &&
                    (($action === AccountEntryAction::Posting && $p["type"] === "charge") ||
                        $details->isEmpty())
                ) {
                    throw new InvalidArgumentException();
                }
            } elseif ($action === AccountEntryAction::PaymentDetails) {
                throw new InvalidArgumentException();
            }
            if (
                $action === AccountEntryAction::Reversal &&
                $p["operation_id"] !== $p["source_operation_id"]
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
            throw new ConcurrentModificationException(
                "This account review expired. Review the account again.",
            );
        }
    }
}
