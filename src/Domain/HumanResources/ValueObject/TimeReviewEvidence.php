<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\HumanResources\TimeEntry;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Frozen working-entry observation for one approval; names, wages and profile values are excluded. */
final readonly class TimeReviewEvidence implements \JsonSerializable
{
    public array $summary;
    public function __construct(#[\SensitiveParameter] public array $data)
    {
        try {
            self::keys($data, ["employee_id", "target", "policy", "sources", "scope"]);
            new EntityId($data["employee_id"]);
            self::keys($data["policy"], ["publication_id", "version", "rules"]);
            if (
                !in_array($data["scope"], ["affected_weeks", "selected_entry", "rejection"], true)
            ) {
                throw new CorruptHistoryException();
            }
            $policy = $data["policy"];
            if (
                !is_int($policy["version"]) ||
                $policy["version"] < 0 ||
                ($policy["publication_id"] === null) !== ($policy["version"] === 0)
            ) {
                throw new CorruptHistoryException();
            }
            if ($policy["publication_id"] !== null) {
                new EntityId($policy["publication_id"]);
            }
            $rules = new TimeApprovalRules($policy["rules"]);
            $target = self::entry($data["target"]);
            if (
                $data["target"]["status"] === "rejected" ||
                !array_is_list($data["sources"]) ||
                count($data["sources"]) < 1 ||
                count($data["sources"]) > 200
            ) {
                throw new CorruptHistoryException();
            }
            $seen = [];
            $sources = [];
            $found = false;
            foreach ($data["sources"] as $row) {
                $entry = self::entry($row);
                if (isset($seen[$row["id"]])) {
                    throw new CorruptHistoryException();
                }
                $seen[$row["id"]] = true;
                $sources[] = [$row, $entry];
                if ($row["id"] === $data["target"]["id"]) {
                    if ($row !== $data["target"]) {
                        throw new CorruptHistoryException();
                    }
                    $found = true;
                }
            }
            if (!$found || strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 55000) {
                throw new CorruptHistoryException();
            }
            $zone = new DateTimeZone($rules->data["timezone"]);
            $first = $target->startsAt->setTimezone($zone)->setTime(0, 0);
            $last = $target->endsAt->modify("-1 microsecond")->setTimezone($zone)->setTime(0, 0);
            $days = [];
            $weeks = [];
            $flags = [];
            for ($day = $first; $day <= $last; $day = $day->modify("+1 day")) {
                $seconds = self::total($sources, $day, $day->modify("+1 day"));
                $days[] = ["date" => $day->format("Y-m-d"), "seconds" => $seconds];
                if (
                    $rules->data["daily_minutes"] !== null &&
                    $seconds > $rules->data["daily_minutes"] * 60
                ) {
                    $flags["daily_limit"] = true;
                }
                $week = new TimeReviewWeek($day->format("Y-m-d"), $zone);
                $key = $week->startsAt->format("Y-m-d");
                if (!isset($weeks[$key])) {
                    $total = self::total($sources, $week->startsAt, $week->endsAt);
                    $weeks[$key] = ["week" => $key, "seconds" => $total];
                    if (
                        $rules->data["weekly_minutes"] !== null &&
                        $total > $rules->data["weekly_minutes"] * 60
                    ) {
                        $flags["weekly_limit"] = true;
                    }
                }
            }
            $overlaps = 0;
            foreach ($sources as [$row, $entry]) {
                if (
                    $row["id"] !== $data["target"]["id"] &&
                    $row["status"] !== "rejected" &&
                    $entry->startsAt < $target->endsAt &&
                    $entry->endsAt > $target->startsAt
                ) {
                    $overlaps++;
                }
            }
            if ($rules->data["overlaps"] && $overlaps > 0) {
                $flags["overlap"] = true;
            }
            $this->summary = [
                "scope" => $data["scope"],
                "policy" => $policy,
                "flags" => array_keys($flags),
                "days" => $days,
                "weeks" => array_values($weeks),
                "overlap_count" => $overlaps,
                "source_count" => count($sources),
                "requires_exception" =>
                    $rules->data["mode"] === "exception_required" && $flags !== [],
                "revision" => TimeApprovalRules::hash($data),
            ];
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public static function facts(TimeEntry $entry): array
    {
        $utc = new DateTimeZone("UTC");
        return [
            "id" => $entry->id->value,
            "starts_at" => $entry->work->startsAt->setTimezone($utc)->format(DATE_ATOM),
            "ends_at" => $entry->work->endsAt->setTimezone($utc)->format(DATE_ATOM),
            "status" => $entry->status,
            "version" => $entry->version,
        ];
    }
    private static function entry(#[\SensitiveParameter] array $row): TimeSlot
    {
        self::keys($row, ["id", "starts_at", "ends_at", "status", "version"]);
        new EntityId($row["id"]);
        if (
            !is_int($row["version"]) ||
            $row["version"] < 0 ||
            !in_array($row["status"], ["pending", "approved", "rejected"], true)
        ) {
            throw new CorruptHistoryException();
        }
        foreach (["starts_at", "ends_at"] as $key) {
            if (
                !is_string($row[$key]) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $row[$key]) ||
                new DateTimeImmutable($row[$key])->format(DATE_ATOM) !== $row[$key]
            ) {
                throw new CorruptHistoryException();
            }
        }
        $slot = new TimeSlot(
            new DateTimeImmutable($row["starts_at"]),
            new DateTimeImmutable($row["ends_at"]),
        );
        if ($slot->endsAt->getTimestamp() - $slot->startsAt->getTimestamp() > 86400) {
            throw new CorruptHistoryException();
        }
        return $slot;
    }
    private static function total(
        #[\SensitiveParameter] array $sources,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int {
        $seconds = 0;
        foreach ($sources as [$row, $entry]) {
            if ($row["status"] !== "rejected") {
                $seconds += max(
                    0,
                    min($entry->endsAt->getTimestamp(), $to->getTimestamp()) -
                        max($entry->startsAt->getTimestamp(), $from->getTimestamp()),
                );
            }
        }
        return $seconds;
    }
    public static function keys(array $data, array $keys): void
    {
        if (count($data) !== count($keys) || array_diff(array_keys($data), $keys) !== []) {
            throw new CorruptHistoryException();
        }
    }
    public function jsonSerialize(): array
    {
        return ["evidence" => "[restricted time-review evidence]"];
    }
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
