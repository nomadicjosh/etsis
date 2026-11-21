<?php

declare(strict_types=1);

namespace Domain\Advising;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Encrypted working check-in; never an academic decision or an appointment. */
final class AdvisingCheckIn implements \JsonSerializable
{
    private function __construct(#[\SensitiveParameter] private array $data)
    {
    }

    public static function message(
        #[\SensitiveParameter] string $message,
        bool $allowEmpty = false,
    ): string {
        if (
            !mb_check_encoding($message, "UTF-8") ||
            (!$allowEmpty && mb_strlen(trim($message)) < 1) ||
            mb_strlen($message) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)
        ) {
            throw new ValidationException([
                ["field" => "message", "message" => "Enter a message of 1–1,000 characters."],
            ]);
        }
        return trim($message);
    }

    public static function create(
        EntityId $id,
        EntityId $student,
        EntityId $pursuit,
        EntityId $owner,
        EntityId $actor,
        string $heldOn,
        ?string $dueOn,
        #[\SensitiveParameter] string $privateNote,
        #[\SensitiveParameter] string $message,
        string $at,
    ): self {
        self::date($heldOn);
        if ($dueOn !== null) {
            self::date($dueOn);
            if ($dueOn < $heldOn) {
                throw new ValidationException([
                    [
                        "field" => "due_on",
                        "message" => "Follow-up must be on or after the check-in.",
                    ],
                ]);
            }
        }
        $privateNote = self::message($privateNote, true);
        $message = self::message($message);
        $request = new self([
            "purpose" => "advising.check_in",
            "id" => $id->value,
            "student_id" => $student->value,
            "pursuit_id" => $pursuit->value,
            "owner_user_id" => $owner->value,
            "held_on" => $heldOn,
            "due_on" => $dueOn,
            "private_note" => $privateNote,
            "status" => "open",
            "version" => 0,
            "next_step" => $message,
            "response" => null,
            "resolution" => null,
            "created_at" => $at,
            "responded_at" => null,
            "resolved_at" => null,
            "receipts" => [],
        ]);
        $request->receipt("record", $actor, $request->intent("record", 0, $message));
        return $request;
    }

    public function intent(
        string $action,
        int $version,
        #[\SensitiveParameter] string $message,
    ): string {
        return hash(
            "sha256",
            json_encode(
                [
                    $this->data["id"],
                    $this->data["student_id"],
                    $this->data["pursuit_id"],
                    $this->data["owner_user_id"],
                    $this->data["held_on"],
                    $this->data["due_on"],
                    $this->data["private_note"],
                    $action,
                    $version,
                    self::message($message),
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    public function retry(string $action, EntityId $actor, string $intent): ?array
    {
        $receipt = $this->data["receipts"][$action] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt["actor_id"] !== $actor->value || !hash_equals($receipt["intent"], $intent)) {
            throw new ConcurrentModificationException(
                "This check-in action was already accepted with different details.",
            );
        }
        return $receipt["result"];
    }

    public function change(
        string $action,
        EntityId $actor,
        int $version,
        #[\SensitiveParameter] string $message,
        string $at,
    ): array {
        $intent = $this->intent($action, $version, $message);
        if (($result = $this->retry($action, $actor, $intent)) !== null) {
            return $result;
        }
        if (
            $version !== $this->data["version"] ||
            !in_array($this->data["status"], ["open", "responded"], true) ||
            !in_array($action, ["respond", "close", "cancel"], true) ||
            ($action === "respond" &&
                ($this->data["status"] !== "open" ||
                    $actor->value !== $this->data["owner_user_id"])) ||
            $at < $this->data["created_at"] ||
            ($this->data["responded_at"] !== null && $at < $this->data["responded_at"])
        ) {
            throw new ConcurrentModificationException(
                "This check-in changed. Refresh check-ins and review its current status.",
            );
        }
        $this->data["version"]++;
        if ($action === "respond") {
            $this->data["status"] = "responded";
            $this->data["response"] = self::message($message);
            $this->data["responded_at"] = $at;
        } else {
            $this->data["status"] = $action === "close" ? "closed" : "cancelled";
            $this->data["resolution"] = self::message($message);
            $this->data["resolved_at"] = $at;
        }
        return $this->receipt($action, $actor, $intent);
    }

    private function receipt(string $action, EntityId $actor, string $intent): array
    {
        $result = [
            "id" => $this->data["id"],
            "status" => $this->data["status"],
            "version" => $this->data["version"],
        ];
        $this->data["receipts"][$action] = [
            "actor_id" => $actor->value,
            "intent" => $intent,
            "result" => $result,
        ];
        return $result;
    }

    public function facts(): array
    {
        return $this->data;
    }

    public function view(bool $ownerView, bool $ownerChanged = false): array
    {
        return array_intersect_key(
            $this->data,
            array_flip([
                "id",
                "held_on",
                "due_on",
                "status",
                "version",
                "next_step",
                "response",
                "resolution",
                "created_at",
                "responded_at",
                "resolved_at",
            ]),
        ) +
        ($ownerView
        ? []
        : [
                    "private_note" => $this->data["private_note"],
                    "owner_changed" => $ownerChanged,
                ]);
    }

    public static function restore(#[\SensitiveParameter] array $data): self
    {
        try {
            $fields = [
                "purpose",
                "id",
                "student_id",
                "pursuit_id",
                "owner_user_id",
                "held_on",
                "due_on",
                "private_note",
                "status",
                "version",
                "next_step",
                "response",
                "resolution",
                "created_at",
                "responded_at",
                "resolved_at",
                "receipts",
            ];
            if (
                array_diff($fields, array_keys($data)) !== [] ||
                count($data) !== 17 ||
                ($data["purpose"] ?? null) !== "advising.check_in" ||
                !is_array($data["receipts"]) ||
                count($data["receipts"]) > 3 ||
                !isset($data["receipts"]["record"]) ||
                !in_array($data["status"], ["open", "responded", "closed", "cancelled"], true)
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["created_at", "responded_at", "resolved_at"] as $key) {
                if ($data[$key] === null && $key !== "created_at") {
                    continue;
                }
                $date = is_string($data[$key])
                ? \DateTimeImmutable::createFromFormat(
                    "!Y-m-d\TH:i:s\Z",
                    $data[$key],
                    new \DateTimeZone("UTC"),
                )
                : false;
                if ($date === false || $date->format("Y-m-d\TH:i:s\Z") !== $data[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            $request = self::create(
                new EntityId($data["id"]),
                new EntityId($data["student_id"]),
                new EntityId($data["pursuit_id"]),
                new EntityId($data["owner_user_id"]),
                new EntityId($data["receipts"]["record"]["actor_id"]),
                $data["held_on"],
                $data["due_on"],
                $data["private_note"],
                $data["next_step"],
                $data["created_at"],
            );
            if ($data["response"] !== null) {
                $actor = new EntityId($data["receipts"]["respond"]["actor_id"]);
                if ($actor->value !== $data["owner_user_id"]) {
                    throw new CorruptHistoryException();
                }
                $request->change("respond", $actor, 0, $data["response"], $data["responded_at"]);
            }
            if (in_array($data["status"], ["closed", "cancelled"], true)) {
                $action = $data["status"] === "closed" ? "close" : "cancel";
                $request->change(
                    $action,
                    new EntityId($data["receipts"][$action]["actor_id"]),
                    $request->facts()["version"],
                    $data["resolution"],
                    $data["resolved_at"],
                );
            }
            if ($request->facts() !== $data) {
                throw new CorruptHistoryException();
            }
            return $request;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    private static function date(string $date): void
    {
        if (
            !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $date, $parts) ||
            !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw new ValidationException([
                ["field" => "date", "message" => "Enter a valid calendar date."],
            ]);
        }
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ["advising_check_in" => "[private working request]"];
    }
}
