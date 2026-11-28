<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Import\Repository\ImportQueueRepository;
use Domain\Import\ValueObject\ImportPlanFingerprint;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class ImportQueueService
{
    public function __construct(
        private ImportQueueRepository $queue,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** Called with a server-produced preview; no browser-supplied plan is accepted.
     * @param array<string,mixed> $request
     * @param array<string,mixed> $preview
     * @return array<string,mixed> */
    public function stage(
        string $target,
        #[\SensitiveParameter] array $request,
        #[\SensitiveParameter] array $preview,
    ): array {
        $actor = $this->actor($target);
        if (
            !($preview["ready"] ?? false) ||
            ($target !== "people" &&
                !is_string($preview["review_token"] ?? null))
        ) {
            return $preview;
        }
        $plan = $target === "people" ? $preview["rows"] : $preview["plan"];
        if (count($plan) < 1 || count($plan) > 5000) {
            throw new InvalidArgumentException(
                trans("Use between one and 5,000 records per import."),
            );
        }
        $id = EntityId::generate();
        $payload = $this->cipher->seal(
            json_encode(
                [
                    "job_id" => $id->value,
                    "target" => $target,
                    "owner" => $actor->value,
                    "request" => $request,
                    "expected" => array_map(
                        ImportPlanFingerprint::of(...),
                        $plan,
                    ),
                ],
                JSON_THROW_ON_ERROR |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_LINE_TERMINATORS,
            ),
        );
        if (strlen($payload) > 12 * 1024 * 1024) {
            throw new InvalidArgumentException(
                trans(
                    "This reviewed import is too large. Split the source file.",
                ),
            );
        }
        $digest = hash("sha256", $payload);
        $expires = $this->clock->now()->modify("+20 minutes");
        $token = $this->cipher->seal(
            json_encode(
                [
                    "kind" => "import-submission",
                    "job_id" => $id->value,
                    "actor" => $actor->value,
                    "artifact_hash" => $digest,
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
        $this->transactions->run(function () use (
            $id,
            $actor,
            $target,
            $plan,
            $payload,
            $digest,
            $token,
            $expires,
        ): void {
            $this->queue->stage(
                [
                    "id" => $id->value,
                    "owner_user_id" => $actor->value,
                    "target" => $target,
                    "state" => "review",
                    "review_hash" => hash("sha256", $token),
                    "total" => count($plan),
                    "processed" => 0,
                    "imported" => 0,
                    "linked" => 0,
                    "skipped" => 0,
                    "omitted" => 0,
                    "revision" => 0,
                    "created_at" => $this->timestamp(),
                    "expires_at" => $expires
                        ->setTimezone(new DateTimeZone("UTC"))
                        ->format("Y-m-d\TH:i:s.u"),
                ],
                $payload,
                $digest,
            );
            $this->audit->record($actor, "import.review_staged", $id);
            $this->actor($target, $actor);
            $saved = $this->owned($id, $actor);
            $artifact = $this->queue->artifact($id);
            if (
                $saved["state"] !== "review" ||
                $saved["review_hash"] !== hash("sha256", $token) ||
                $artifact === null ||
                $artifact["encrypted_payload"] !== $payload ||
                $artifact["payload_hash"] !== $digest
            ) {
                throw new ConcurrentModificationException(
                    trans("The import review changed before saving completed."),
                );
            }
        });
        return array_replace($preview, [
            "job_id" => $id->value,
            "review_token" => $token,
            "expires_at" => $expires->getTimestamp(),
        ]);
    }

    /** Confirmation queues work; it never creates catalog or person records. */
    public function submit(#[\SensitiveParameter] string $token): array
    {
        $owner = $this->access->authenticatedUserId();
        try {
            if (strlen($token) > 4096) {
                throw new InvalidArgumentException();
            }
            $proof = json_decode(
                $this->cipher->open($token),
                true,
                4,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($proof) ||
                count($proof) !== 4 ||
                ($proof["kind"] ?? null) !== "import-submission" ||
                ($proof["actor"] ?? null) !== $owner->value ||
                !is_string($proof["artifact_hash"] ?? null)
            ) {
                throw new InvalidArgumentException();
            }
            $id = new EntityId($proof["job_id"]);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                trans("Review the import again before queuing it."),
            );
        }
        return $this->transactions->run(function () use (
            $id,
            $owner,
            $token,
            $proof,
        ): array {
            $job = $this->owned($id, $owner);
            $this->actor($job["target"], $owner);
            if (!hash_equals($job["review_hash"], hash("sha256", $token))) {
                throw new AccessDeniedException(
                    trans("This import review belongs to another request."),
                );
            }
            if ($job["state"] !== "review") {
                return self::describe($job);
            }
            if ($job["expires_at"] <= $this->timestamp()) {
                throw new ConcurrentModificationException(
                    trans("The import review expired. Review the file again."),
                );
            }
            $artifact = $this->queue->artifact($id);
            if (
                $artifact === null ||
                !hash_equals(
                    $artifact["payload_hash"],
                    $proof["artifact_hash"],
                ) ||
                !hash_equals(
                    $artifact["payload_hash"],
                    hash("sha256", $artifact["encrypted_payload"]),
                )
            ) {
                throw new ConcurrentModificationException(
                    trans(
                        "The private import source is unavailable. Review the file again.",
                    ),
                );
            }
            $this->queue->update($id, [
                "state" => "queued",
                "submitted_at" => $this->timestamp(),
                "expires_at" => $this->clock
                    ->now()
                    ->modify("+1 day")
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u"),
                "revision" => (int) $job["revision"] + 1,
            ]);
            $this->audit->record($owner, "import.queued", $id);
            $this->actor($job["target"], $owner);
            $saved = $this->owned($id, $owner);
            if (
                $saved["state"] !== "queued" ||
                $saved["review_hash"] !== $job["review_hash"] ||
                $this->queue->artifact($id) !== $artifact
            ) {
                throw new ConcurrentModificationException(
                    trans(
                        "The import queue changed before submission completed.",
                    ),
                );
            }
            return self::describe($saved);
        });
    }

    public function status(EntityId $id): array
    {
        $owner = $this->actor();
        $job = $this->owned($id, $owner);
        $this->actor(null, $owner);
        return self::describe($job);
    }

    /** Stop this immutable owned job at a transaction boundary; accepted records remain facts. */
    public function cancel(EntityId $id): array
    {
        $owner = $this->actor();
        return $this->transactions->run(function () use ($id, $owner): array {
            $job = $this->owned($id, $owner);
            if (!in_array($job['state'], ['review', 'queued', 'running'], true)) {
                $this->actor(null, $owner);
                return self::describe($job);
            }
            $changes = ['state' => 'cancelled', 'lease_token' => null, 'lease_until' => null,
                'finished_at' => $this->timestamp(), 'revision' => (int) $job['revision'] + 1];
            $this->queue->update($id, $changes);
            $this->queue->purgeArtifact($id);
            if ($job['state'] !== 'review') {
                $this->queue->notify(['id' => EntityId::generate()->value, 'owner_user_id' => $owner->value,
                    'job_id' => $id->value, 'target' => $job['target'], 'state' => 'cancelled',
                    'processed' => (int) $job['processed'], 'total' => (int) $job['total'],
                    'recorded_at' => $this->timestamp(), 'read_at' => null]);
            }
            $this->audit->record($owner, 'import.cancelled', $id);
            $this->actor(null, $owner);
            $saved = $this->owned($id, $owner);
            foreach (array_replace($job, $changes) as $field => $expected) {
                if ($expected === null ? $saved[$field] !== null : (string) $saved[$field] !== (string) $expected) {
                    throw new ConcurrentModificationException();
                }
            }
            if ($this->queue->artifact($id) !== null) {
                throw new ConcurrentModificationException();
            }
            return self::describe($saved);
        });
    }

    public function page(?EntityId $after = null): array
    {
        $owner = $this->actor();
        $rows = $this->queue->page($owner, $after);
        $this->actor(null, $owner);
        return [
            "items" => array_map(
                self::describe(...),
                array_slice($rows, 0, 20),
            ),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    public function notices(bool $unread = true, ?EntityId $after = null): array
    {
        $owner = $this->access->authenticatedUserId();
        $rows = $this->queue->notices($owner, $unread, $after);
        if ($this->access->authenticatedUserId()->value !== $owner->value) {
            throw new AccessDeniedException(
                trans("The notification owner changed."),
            );
        }
        return [
            "items" => array_map(
                static fn(array $row): array => [
                    "id" => $row["id"],
                    "job_id" => $row["job_id"],
                    "target" => $row["target"],
                    "state" => $row["state"],
                    "processed" => (int) $row["processed"],
                    "total" => (int) $row["total"],
                    "recorded_at" => $row["recorded_at"] . "Z",
                    "unread" => $row["read_at"] === null,
                ],
                array_slice($rows, 0, 20),
            ),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    public function readNotice(EntityId $notice): void
    {
        $owner = $this->access->authenticatedUserId();
        $this->transactions->run(function () use ($owner, $notice): void {
            $this->queue->readNotice($owner, $notice, $this->timestamp());
            if ($this->access->authenticatedUserId()->value !== $owner->value) {
                throw new AccessDeniedException(
                    trans("The notification owner changed."),
                );
            }
        });
    }

    public function deleteNotice(EntityId $notice): void
    {
        $owner = $this->access->authenticatedUserId();
        $this->transactions->run(function () use ($owner, $notice): void {
            $this->queue->deleteNotice($owner, $notice);
            if ($this->access->authenticatedUserId()->value !== $owner->value) {
                throw new AccessDeniedException(
                    trans("The notification owner changed."),
                );
            }
        });
    }

    private function owned(EntityId $id, EntityId $owner): array
    {
        $row = $this->queue->job($id);
        if ($row === null || $row["owner_user_id"] !== $owner->value) {
            throw new AccessDeniedException(
                trans("This import is unavailable to this account."),
            );
        }
        return $row;
    }

    private function actor(
        ?string $target = null,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->requirePermission("sis:import:manage");
        if (
            $target !== null &&
            !in_array($target, ["people", "photos"], true) &&
            CatalogImportTarget::tryFrom($target) === null
        ) {
            throw new InvalidArgumentException(
                trans("Choose an available import destination."),
            );
        }
        if (
            (in_array($target, ["people", "photos"], true) &&
                $this->access->requirePermission("sis:person:manage")->value !==
                    $actor->value) ||
            ($target === "photos" &&
                $this->access->requirePermission(\Domain\Person\Service\PersonPhotoService::MANAGE)->value
                    !== $actor->value) ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException(
                trans("The import authority changed."),
            );
        }
        return $actor;
    }

    private function timestamp(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s.u");
    }

    /** @return array<string,mixed> */
    public static function describe(array $job): array
    {
        $out = array_intersect_key(
            $job,
            array_flip(["id", "target", "state", "error_code"]),
        );
        $iterationValues = ["total", "processed", "imported", "linked", "skipped", "omitted"];
        foreach ($iterationValues as $field) {
            $out[$field] = (int) $job[$field];
        }
        foreach (["created_at", "submitted_at", "finished_at"] as $field) {
            $out[$field] =
            ($job[$field] ?? null) === null ? null : $job[$field] . "Z";
        }
        return $out;
    }
}
