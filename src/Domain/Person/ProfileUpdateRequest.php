<?php

declare(strict_types=1);

namespace Domain\Person;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Person\Service\ProfileLedger;
use Throwable;

/** Audited working request, never the authority for actual profile values or approvals. */
final class ProfileUpdateRequest
{
    public const array AREAS = [
        "contact" => "Contact information",
        "addresses" => "Dated addresses",
        "emergency_contacts" => "Emergency contacts",
        "education" => "Educational background",
        "identity" => "Recorded identity",
    ];

    private function __construct(#[\SensitiveParameter] private array $data)
    {
    }

    public static function message(#[\SensitiveParameter] string $message): string
    {
        $message = trim($message);
        if (
            !mb_check_encoding($message, "UTF-8") ||
            mb_strlen($message) < 1 ||
            mb_strlen($message) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message)
        ) {
            throw new ValidationException([
                ["field" => "message", "message" => "Enter a message of 1–1,000 characters."],
            ]);
        }
        return $message;
    }

    public static function create(
        EntityId $id,
        EntityId $person,
        EntityId $owner,
        EntityId $actor,
        string $area,
        #[\SensitiveParameter] string $message,
        string $at,
    ): self {
        if (!isset(self::AREAS[$area])) {
            throw new ValidationException([
                [
                    "field" => "area",
                    "message" => "Choose recorded identity, contact, addresses, emergency contacts "
                        . "or educational background.",
                ],
            ]);
        }
        $message = self::message($message);
        $request = new self([
            "purpose" => "person.profile_update",
            "id" => $id->value,
            "person_id" => $person->value,
            "owner_user_id" => $owner->value,
            "area" => $area,
            "status" => "requested",
            "version" => 0,
            "instructions" => $message,
            "response" => null,
            "resolution" => null,
            "created_at" => $at,
            "responded_at" => null,
            "resolved_at" => null,
            "receipts" => [],
        ]);
        $request->receipt("request", $actor, $request->intent("request", 0, $message));
        return $request;
    }

    public function intent(
        string $action,
        int $version,
        #[\SensitiveParameter] string $message,
    ): string {
        return ProfileLedger::intent([
            $this->data["id"],
            $this->data["person_id"],
            $this->data["area"],
            $action,
            $version,
            self::message($message),
        ]);
    }

    public function retry(string $action, EntityId $actor, string $intent): ?array
    {
        $receipt = $this->data["receipts"][$action] ?? null;
        if ($receipt === null) {
            return null;
        }
        if ($receipt["actor_id"] !== $actor->value || !hash_equals($receipt["intent"], $intent)) {
            throw new ConcurrentModificationException(
                "This request action was already accepted with different details.",
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
            !in_array($this->data["status"], ["requested", "responded"], true) ||
            !in_array($action, ["respond", "close", "cancel"], true) ||
            ($action === "respond" && $this->data["status"] !== "requested")
        ) {
            throw new ConcurrentModificationException(
                "This request changed. Refresh requests and review its current status.",
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

    public function view(bool $ownerChanged = false): array
    {
        return array_intersect_key(
            $this->data,
            array_flip([
                "id",
                "area",
                "status",
                "version",
                "instructions",
                "response",
                "resolution",
                "created_at",
                "responded_at",
                "resolved_at",
            ]),
        ) + ["area_label" => self::AREAS[$this->data["area"]], "owner_changed" => $ownerChanged];
    }

    public static function restore(#[\SensitiveParameter] array $data): self
    {
        try {
            $fields = ['purpose', 'id', 'person_id', 'owner_user_id', 'area', 'status', 'version',
                'instructions', 'response', 'resolution', 'created_at', 'responded_at', 'resolved_at', 'receipts'];
            if (
                array_diff($fields, array_keys($data)) !== [] || count($data) !== 14 ||
                ($data["purpose"] ?? null) !== "person.profile_update" ||
                !is_array($data["receipts"]) ||
                count($data["receipts"]) > 3 ||
                !isset($data["receipts"]["request"]) ||
                !in_array($data["status"], ["requested", "responded", "closed", "cancelled"], true)
            ) {
                throw new CorruptHistoryException();
            }
            foreach (["created_at", "responded_at", "resolved_at"] as $key) {
                if ($data[$key] === null && $key !== "created_at") {
                    continue;
                }
                $date = is_string($data[$key])
                ? \DateTimeImmutable::createFromFormat("!Y-m-d\TH:i:s\Z", $data[$key], new \DateTimeZone("UTC"))
                : false;
                if ($date === false || $date->format("Y-m-d\TH:i:s\Z") !== $data[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            $request = self::create(
                new EntityId($data["id"]),
                new EntityId($data["person_id"]),
                new EntityId($data["owner_user_id"]),
                new EntityId($data["receipts"]["request"]["actor_id"]),
                $data["area"],
                $data["instructions"],
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

    public function __debugInfo(): array
    {
        return ["profile_request" => "[private working request]"];
    }
}
