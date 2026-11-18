<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Shared short-lived review grant; command services still recheck live context and authority. */
final readonly class HiatusReview
{
    public function __construct(
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    public function issue(
        string $purpose,
        EntityId $scope,
        EntityId $actor,
        EntityId $operation,
        string $revision,
    ): string {
        return $this->cipher->seal(
            json_encode(
                [
                    "kind" => "hiatus.review",
                    "purpose" => $purpose,
                    "scope" => $scope->value,
                    "actor" => $actor->value,
                    "operation" => $operation->value,
                    "revision" => $revision,
                    "issued_at" => $this->clock->now()->getTimestamp(),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function validate(
        #[\SensitiveParameter] string $token,
        string $purpose,
        EntityId $scope,
        EntityId $actor,
        EntityId $operation,
        string $revision,
        bool $accepted = false,
    ): void {
        try {
            if (strlen($token) > 8192) {
                throw new \InvalidArgumentException();
            }
            $data = json_decode(
                $this->cipher->open($token),
                true,
                8,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($data) ||
                count($data) !== 7 ||
                ($data["kind"] ?? null) !== "hiatus.review" ||
                ($data["purpose"] ?? null) !== $purpose ||
                ($data["scope"] ?? null) !== $scope->value ||
                ($data["operation"] ?? null) !== $operation->value ||
                ($data["revision"] ?? null) !== $revision ||
                !is_int($data["issued_at"] ?? null) ||
                !is_string($data["actor"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new ValidationException([
                [
                    "field" => "review_token",
                    "message" => "Review this case again before confirming.",
                ],
            ]);
        }
        if ($data["actor"] !== $actor->value) {
            throw new AccessDeniedException(
                "This review belongs to another staff member.",
            );
        }
        $age = $this->clock->now()->getTimestamp() - $data["issued_at"];
        if (!$accepted && ($age < 0 || $age >= 1200)) {
            throw new ConcurrentModificationException(
                "This review expired. Review the current case again.",
            );
        }
    }
}
