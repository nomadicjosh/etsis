<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use Domain\Advising\AdvisingCheckIn;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Throwable;

/** Authenticated original storage and its minimized operational date projection. */
final class AdvisingCheckInStorage
{
    public static function open(
        #[\SensitiveParameter] array $row,
        MessageCipher $cipher,
    ): AdvisingCheckIn {
        try {
            if (!is_string($row["sealed_data"]) || strlen($row["sealed_data"]) > 65536) {
                throw new CorruptHistoryException();
            }
            $data = json_decode($cipher->open($row["sealed_data"]), true, 12, JSON_THROW_ON_ERROR);
            $request = AdvisingCheckIn::restore($data);
            foreach (
                ["id", "student_id", "pursuit_id", "owner_user_id", "status", "version"] as $key
            ) {
                if ((string) $row[$key] !== (string) $data[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            return $request;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public static function dates(AdvisingCheckIn $checkIn): array
    {
        $data = $checkIn->facts();
        return [
            "held_on" => $data["held_on"],
            "due_on" => $data["due_on"],
            "recorded_on" => substr($data["created_at"], 0, 10),
            "responded_on" =>
                $data["responded_at"] === null ? null : substr($data["responded_at"], 0, 10),
            "resolved_on" =>
                $data["resolved_at"] === null ? null : substr($data["resolved_at"], 0, 10),
        ];
    }
}
