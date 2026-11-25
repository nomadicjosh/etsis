<?php

declare(strict_types=1);

namespace Domain\Admissions;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

use function Codefy\Framework\Helpers\trans;

/** Private working follow-up. A request or closure never changes an application target or outcome. */
final class AdmissionChangeRequest
{
    private function __construct(#[\SensitiveParameter] private array $data)
    {
    }

    public static function message(
        #[\SensitiveParameter] string $message,
    ): string {
        $message = trim($message);
        if (
            !mb_check_encoding($message, "UTF-8") ||
            mb_strlen($message) < 1 ||
            mb_strlen($message) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)
        ) {
            throw new ValidationException([
                [
                    "field" => "message",
                    "message" => trans(
                        "Enter a message of 1–1,000 characters.",
                    ),
                ],
            ]);
        }
        return $message;
    }

    public static function create(
        EntityId $id,
        EntityId $application,
        EntityId $person,
        EntityId $owner,
        int $applicationRevision,
        string $area,
        #[\SensitiveParameter] string $message,
        string $at,
    ): self {
        if (
            !in_array($area, ["program", "entry_term"], true) ||
            $applicationRevision < 0
        ) {
            throw new ValidationException([
                [
                    "field" => "area",
                    "message" => trans("Choose planned program or entry term."),
                ],
            ]);
        }
        $request = new self([
            "purpose" => "admissions.change_request",
            "id" => $id->value,
            "application_id" => $application->value,
            "person_id" => $person->value,
            "owner_user_id" => $owner->value,
            "area" => $area,
            "application_revision" => $applicationRevision,
            "status" => "requested",
            "version" => 0,
            "message" => self::message($message),
            "resolution" => null,
            "created_at" => $at,
            "resolved_at" => null,
            "receipts" => [],
        ]);
        $request->remember(
            "request",
            $owner,
            $applicationRevision,
            $request->intent("request", 0, $applicationRevision, $message),
        );
        return $request;
    }

    public function intent(
        string $action,
        int $version,
        int $applicationRevision,
        #[\SensitiveParameter] string $message,
    ): string {
        return hash(
            "sha256",
            json_encode(
                [
                    $this->data["id"],
                    $this->data["application_id"],
                    $this->data["person_id"],
                    $this->data["owner_user_id"],
                    $this->data["area"],
                    $action,
                    $version,
                    $applicationRevision,
                    self::message($message),
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    public function retry(
        string $action,
        EntityId $actor,
        string $intent,
    ): ?array {
        $receipt = $this->data["receipts"][$action] ?? null;
        if ($receipt === null) {
            return null;
        }
        if (
            $receipt["actor_id"] !== $actor->value ||
            !hash_equals($receipt["intent"], $intent)
        ) {
            throw new ConcurrentModificationException(
                trans(
                    "This follow-up action was already accepted differently.",
                ),
            );
        }
        return $receipt["result"];
    }

    public function finish(
        string $action,
        EntityId $actor,
        int $version,
        int $applicationRevision,
        #[\SensitiveParameter] string $message,
        string $at,
    ): array {
        if (
            !in_array($action, ["close", "cancel"], true) ||
            $applicationRevision < 0 ||
            ($action === "cancel" &&
                $actor->value !== $this->data["owner_user_id"])
        ) {
            throw new CorruptHistoryException();
        }
        $intent = $this->intent(
            $action,
            $version,
            $applicationRevision,
            $message,
        );
        if (($accepted = $this->retry($action, $actor, $intent)) !== null) {
            return $accepted;
        }
        if (
            $this->data["status"] !== "requested" ||
            $version !== 0 ||
            $this->data["version"] !== 0
        ) {
            throw new ConcurrentModificationException(
                trans("Refresh this follow-up before responding."),
            );
        }
        $this->data["status"] = $action === "close" ? "closed" : "cancelled";
        $this->data["version"] = 1;
        $this->data["resolution"] = self::message($message);
        $this->data["resolved_at"] = $at;
        return $this->remember($action, $actor, $applicationRevision, $intent);
    }

    private function remember(
        string $action,
        EntityId $actor,
        int $applicationRevision,
        string $intent,
    ): array {
        $result = array_intersect_key(
            $this->data,
            array_flip(["id", "status", "version"]),
        );
        $this->data["receipts"][$action] = [
            "actor_id" => $actor->value,
            "intent" => $intent,
            "application_revision" => $applicationRevision,
            "result" => $result,
        ];
        return $result;
    }

    public function facts(): array
    {
        return $this->data;
    }

    public function view(int $applicationRevision, bool $ownerChanged): array
    {
        return array_intersect_key(
            $this->data,
            array_flip([
                "id",
                "area",
                "status",
                "version",
                "message",
                "resolution",
                "created_at",
                "resolved_at",
            ]),
        ) + [
            "application_changed" =>
                $applicationRevision !== $this->data["application_revision"],
            "owner_changed" => $ownerChanged,
        ];
    }

    public static function restore(#[\SensitiveParameter] array $data): self
    {
        try {
            $fields = [
                "purpose",
                "id",
                "application_id",
                "person_id",
                "owner_user_id",
                "area",
                "application_revision",
                "status",
                "version",
                "message",
                "resolution",
                "created_at",
                "resolved_at",
                "receipts",
            ];
            if (
                count($data) !== count($fields) ||
                array_diff($fields, array_keys($data)) !== [] ||
                $data["purpose"] !== "admissions.change_request" ||
                !is_array($data["receipts"]) ||
                count($data["receipts"]) < 1 ||
                count($data["receipts"]) > 2 ||
                !is_int($data["application_revision"]) ||
                $data["application_revision"] < 0
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["created_at", "resolved_at"] as $key) {
                if ($key === "resolved_at" && $data[$key] === null) {
                    continue;
                }
                $date = is_string($data[$key])
                ? DateTimeImmutable::createFromFormat(
                    "!Y-m-d\TH:i:s\Z",
                    $data[$key],
                    new DateTimeZone("UTC"),
                )
                : false;
                if (
                    $date === false ||
                    $date->format("Y-m-d\TH:i:s\Z") !== $data[$key]
                ) {
                    throw new CorruptHistoryException();
                }
            }
            // Rebuild only the working envelope. This never appends or replays business events.
            $record = self::create(
                new EntityId($data["id"]),
                new EntityId($data["application_id"]),
                new EntityId($data["person_id"]),
                new EntityId($data["owner_user_id"]),
                $data["application_revision"],
                $data["area"],
                $data["message"],
                $data["created_at"],
            );
            if (in_array($data["status"], ["closed", "cancelled"], true)) {
                $action = $data["status"] === "closed" ? "close" : "cancel";
                $receipt = $data["receipts"][$action];
                $record->finish(
                    $action,
                    new EntityId($receipt["actor_id"]),
                    0,
                    $receipt["application_revision"],
                    $data["resolution"],
                    $data["resolved_at"],
                );
            }
            if ($record->facts() !== $data) {
                throw new CorruptHistoryException();
            }
            return $record;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function __debugInfo(): array
    {
        return ["application_follow_up" => "[private working request]"];
    }
}
