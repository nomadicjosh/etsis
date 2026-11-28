<?php

declare(strict_types=1);

namespace Domain\Person;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Person\Event\PersonPhotoWasRecorded;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

final class PhotoHistory extends EventSourcedAggregate
{
    public const array FIELDS = [
        "person_id",
        "owner_user_id",
        "identity_version",
        "photo_id",
        "institutional",
        "source",
        "image_hash",
        "mode",
        "operation_id",
        "import_ref",
        "intent",
    ];

    public static function validate(array $p): void
    {
        try {
            if (
                count($p) !== count(self::FIELDS) ||
                array_diff(array_keys($p), self::FIELDS) !== []
            ) {
                throw new \InvalidArgumentException();
            }
            $fields = ["person_id", "owner_user_id", "operation_id"];
            foreach ($fields as $field) {
                new EntityId($p[$field]);
            }
            if (
                !is_int($p["identity_version"]) ||
                $p["identity_version"] < 0 ||
                !is_bool($p["institutional"]) ||
                !in_array($p["mode"], ["owner", "staff"], true) ||
                !in_array(
                    $p["source"],
                    ["upload", "camera", "gravatar", "import", "removed"],
                    true,
                ) ||
                ($p["photo_id"] === null) !== ($p["image_hash"] === null) ||
                ($p["source"] === "removed") !== ($p["photo_id"] === null) ||
                ($p["institutional"] &&
                    ($p["mode"] !== "staff" || $p["photo_id"] === null)) ||
                ($p["source"] === "import" && $p["mode"] !== "staff") ||
                ($p["source"] === "gravatar" && $p["mode"] !== "owner") ||
                preg_match('/^[a-f0-9]{64}$/D', $p["intent"]) !== 1
            ) {
                throw new \InvalidArgumentException();
            }
            if ($p["photo_id"] !== null) {
                new EntityId($p["photo_id"]);
                if (preg_match('/^[a-f0-9]{64}$/D', $p["image_hash"]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            }
            if (($p["source"] === "import") !== ($p["import_ref"] !== null)) {
                throw new \InvalidArgumentException();
            }
            if ($p["import_ref"] !== null) {
                $ref = $p["import_ref"];
                if (
                    !is_array($ref) ||
                    count($ref) !== 3 ||
                    !is_int($ref["ordinal"] ?? null) ||
                    $ref["ordinal"] < 0 ||
                    $ref["ordinal"] >= 200 ||
                    preg_match('/^[a-f0-9]{64}$/D', $ref["digest"] ?? "") !== 1
                ) {
                    throw new \InvalidArgumentException();
                }
                new EntityId($ref["archive_id"]);
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public static function intent(
        array $facts,
        string $reason,
        int $position,
    ): string {
        unset($facts["intent"]);
        return self::hash([$facts, $reason, $position]);
    }

    public static function hash(array $values): string
    {
        $order = static function (array $data) use (&$order): array {
            if (!array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as &$value) {
                if (is_array($value)) {
                    $value = $order($value);
                }
            }
            return $data;
        };
        return hash(
            "sha256",
            json_encode($order($values), JSON_THROW_ON_ERROR),
        );
    }

    protected function applyPersonPhotoWasRecorded(
        PersonPhotoWasRecorded $event,
    ): void {
        self::validate($event->payload());
    }
}
