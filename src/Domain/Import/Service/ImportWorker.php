<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Import\Repository\ImportQueueRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Durable server worker; no browser, login session or source payload is needed in a job message. */
final readonly class ImportWorker
{
    public function __construct(
        private ImportQueueRepository $queue,
        private ImportBatchProcessor $processor,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    public function runOne(): string
    {
        foreach ($this->queue->abandoned($this->timestamp()) as $review) {
            $this->transactions->run(function () use ($review): void {
                $id = new EntityId($review["id"]);
                $current = $this->queue->job($id);
                if (
                    $current !== null &&
                    $current["state"] === "review" &&
                    $current["expires_at"] <= $this->timestamp()
                ) {
                    $this->queue->discardReview($id);
                }
            });
        }
        foreach ($this->queue->pending($this->timestamp()) as $candidate) {
            $id = new EntityId($candidate["id"]);
            $token = bin2hex(random_bytes(16));
            $claimed = $this->transactions->run(function () use (
                $candidate,
                $id,
                $token,
            ): bool {
                $row = $this->queue->job($id);
                if (
                    $row === null ||
                    !in_array($row["state"], ["queued", "running"], true) ||
                    ($row["state"] === "running" &&
                        $row["lease_until"] > $this->timestamp()) ||
                    (int) $row["revision"] !== (int) $candidate["revision"]
                ) {
                    return false;
                }
                return $this->queue->claim(
                    $id,
                    (int) $row["revision"],
                    $token,
                    $this->clock
                        ->now()
                        ->modify("+2 minutes")
                        ->setTimezone(new DateTimeZone("UTC"))
                        ->format("Y-m-d\TH:i:s.u"),
                );
            });
            if (!$claimed) {
                continue;
            }
            try {
                return $this->transactions->run(function () use (
                    $id,
                    $token,
                ): string {
                    $this->roles->lock();
                    $job = $this->queue->job($id);
                    if (
                        $job === null ||
                        $job["state"] !== "running" ||
                        $job["lease_token"] !== $token
                    ) {
                        return "idle";
                    }
                    if ($job["expires_at"] <= $this->timestamp()) {
                        return $this->finish(
                            $job,
                            "needs_attention",
                            "expired",
                        );
                    }
                    if (
                        (int) $job["total"] < 1 ||
                        (int) $job["total"] > 5000 ||
                        (int) $job["processed"] < 0 ||
                        (int) $job["processed"] !==
                            (int) $job["imported"] +
                                (int) $job["linked"] +
                                (int) $job["skipped"] +
                                (int) $job["omitted"]
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $artifact = $this->queue->artifact($id);
                    if (
                        $artifact === null ||
                        strlen($artifact["encrypted_payload"]) >
                            12 * 1024 * 1024 ||
                        !hash_equals(
                            $artifact["payload_hash"],
                            hash("sha256", $artifact["encrypted_payload"]),
                        )
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $payload = json_decode(
                        $this->cipher->open($artifact["encrypted_payload"]),
                        true,
                        16,
                        JSON_THROW_ON_ERROR,
                    );
                    if (
                        !is_array($payload) ||
                        !is_array($payload["request"] ?? null) ||
                        !is_array($payload["expected"] ?? null) ||
                        ($payload["job_id"] ?? null) !== $job["id"] ||
                        $payload["target"] !== $job["target"] ||
                        $payload["owner"] !== $job["owner_user_id"] ||
                        !array_is_list($payload["expected"]) ||
                        count($payload["expected"]) !== (int) $job["total"]
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $remaining = (int) $job["total"] - (int) $job["processed"];
                    $limit = min(
                        $job["target"] === "people" ? 10 : 100,
                        $remaining,
                    );
                    if ($limit < 1) {
                        throw new CorruptHistoryException();
                    }
                    $counts = $this->processor->execute(
                        $job["target"],
                        $payload["request"],
                        array_slice(
                            $payload["expected"],
                            (int) $job["processed"],
                            $limit,
                        ),
                        (int) $job["processed"],
                        $limit,
                        new EntityId($job["owner_user_id"]),
                    );
                    if (array_sum($counts) !== $limit) {
                        throw new CorruptHistoryException();
                    }
                    $current = $this->queue->job($id);
                    if (
                        $current !== $job ||
                        $this->queue->artifact($id) !== $artifact ||
                        $job["expires_at"] <= $this->timestamp()
                    ) {
                        throw new ConcurrentModificationException();
                    }
                    $changes = [
                        "processed" => (int) $job["processed"] + $limit,
                        "revision" => (int) $job["revision"] + 1,
                        "lease_token" => null,
                        "lease_until" => null,
                    ];
                    $iterationValues = ["imported", "linked", "skipped", "omitted"];
                    foreach ($iterationValues as $field) {
                        if (!is_int($counts[$field]) || $counts[$field] < 0) {
                            throw new CorruptHistoryException();
                        }
                        $changes[$field] = (int) $job[$field] + $counts[$field];
                    }
                    $next = array_replace($job, $changes);
                    if ($changes["processed"] === (int) $job["total"]) {
                        $state = $this->finish($next, "completed", null);
                        $this->processor->authorize(
                            new EntityId($job["owner_user_id"]),
                            $job["target"],
                        );
                        return $state;
                    }
                    $changes["state"] = "queued";
                    $this->queue->update($id, $changes);
                    $this->processor->authorize(
                        new EntityId($job["owner_user_id"]),
                        $job["target"],
                    );
                    return "processed";
                });
            } catch (Throwable $error) {
                return $this->transactions->run(function () use (
                    $id,
                    $token,
                    $error,
                ): string {
                    $job = $this->queue->job($id);
                    if (
                        $job === null ||
                        $job["state"] !== "running" ||
                        $job["lease_token"] !== $token
                    ) {
                        return "idle";
                    }
                    $code = match (true) {
                        $error instanceof AccessDeniedException => "permission",
                        $error instanceof ValidationException,
                        $error instanceof ConcurrentModificationException
                            => "conflict",
                        $error instanceof CorruptHistoryException
                            => "source_unavailable",
                        default => "processing_error",
                    };
                    return $this->finish($job, "needs_attention", $code);
                });
            }
        }
        return "idle";
    }

    private function finish(array $job, string $state, ?string $code): string
    {
        $id = new EntityId($job["id"]);
        $job = array_replace($job, [
            "state" => $state,
            "error_code" => $code,
            "finished_at" => $this->timestamp(),
            "lease_token" => null,
            "lease_until" => null,
            "revision" => (int) $job["revision"] + 1,
        ]);
        $this->queue->update(
            $id,
            array_diff_key(
                $job,
                array_flip([
                    "id",
                    "owner_user_id",
                    "target",
                    "review_hash",
                    "created_at",
                ]),
            ),
        );
        $this->queue->purgeArtifact($id);
        $this->queue->notify([
            "id" => EntityId::generate()->value,
            "owner_user_id" => $job["owner_user_id"],
            "job_id" => $id->value,
            "target" => $job["target"],
            "state" => $state,
            "processed" => (int) $job["processed"],
            "total" => (int) $job["total"],
            "recorded_at" => $this->timestamp(),
            "read_at" => null,
        ]);
        $this->audit->record(
            new EntityId($job["owner_user_id"]),
            "import." . $state,
            $id,
        );
        $saved = $this->queue->job($id);
        $iterationValues = [
            "state",
            "processed",
            "total",
            "revision",
            "owner_user_id",
            "target",
            "review_hash",
            "error_code",
            ];
        foreach ($iterationValues as $field) {
            if ((string) $saved[$field] !== (string) $job[$field]) {
                throw new ConcurrentModificationException();
            }
        }
        if ($this->queue->artifact($id) !== null) {
            throw new ConcurrentModificationException();
        }
        return $state;
    }

    private function timestamp(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s.u");
    }
}
