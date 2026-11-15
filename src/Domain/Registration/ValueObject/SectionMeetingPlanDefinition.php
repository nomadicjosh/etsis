<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** A self-contained academic publication; private booking purposes and people are deliberately omitted. */
final readonly class SectionMeetingPlanDefinition
{
    /** @var array<string, mixed> */
    public array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        self::keys($data, ['section_id', 'section_code', 'term_code', 'timezone', 'locations', 'meetings']);
        new EntityId($data['section_id']);
        self::label($data['section_code']);
        self::label($data['term_code']);
        if (!is_string($data['timezone']) || !in_array($data['timezone'], DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Use a named institutional timezone for meeting plans.');
        }
        if (
            !is_array($data['locations']) || !array_is_list($data['locations']) || count($data['locations']) > 100
            || !is_array($data['meetings']) || !array_is_list($data['meetings']) || count($data['meetings']) > 500
        ) {
            throw new InvalidArgumentException('A meeting plan supports up to 500 meetings and 100 rooms.');
        }
        $locations = [];
        foreach ($data['locations'] as $location) {
            if (!is_array($location)) {
                throw new InvalidArgumentException('Invalid meeting location.');
            }
            self::keys($location, ['room_id', 'code', 'name', 'building_id', 'building_code', 'building_name']);
            new EntityId($location['room_id']);
            self::label($location['code']);
            self::label($location['name']);
            if (isset($locations[$location['room_id']])) {
                throw new InvalidArgumentException('Duplicate meeting location.');
            }
            if ($location['building_id'] !== null) {
                new EntityId($location['building_id']);
                self::label($location['building_code']);
                self::label($location['building_name']);
            } elseif ($location['building_code'] !== null || $location['building_name'] !== null) {
                throw new InvalidArgumentException('Invalid building reference.');
            }
            $locations[$location['room_id']] = $location;
        }
        $ids = $used = [];
        foreach ($data['meetings'] as $meeting) {
            if (!is_array($meeting)) {
                throw new InvalidArgumentException('Invalid section meeting.');
            }
            self::keys($meeting, ['booking_id', 'occurrence_id', 'offering_publication_id',
                'starts_at', 'ends_at', 'room_id']);
            foreach (['booking_id', 'occurrence_id', 'offering_publication_id'] as $field) {
                new EntityId($meeting[$field]);
            }
            if (isset($ids[$meeting['occurrence_id']])) {
                throw new InvalidArgumentException('Invalid or duplicate meeting occurrence.');
            }
            $ids[$meeting['occurrence_id']] = true;
            foreach (['starts_at', 'ends_at'] as $field) {
                $value = $meeting[$field];
                $date = is_string($value) ? DateTimeImmutable::createFromFormat(
                    '!Y-m-d\TH:i:s\Z',
                    $value,
                    new DateTimeZone('UTC')
                ) : false;
                if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
                    throw new InvalidArgumentException('Meetings require unambiguous UTC instants.');
                }
            }
            if ($meeting['ends_at'] <= $meeting['starts_at']) {
                throw new InvalidArgumentException('A meeting must end after it starts.');
            }
            if ($meeting['room_id'] !== null) {
                new EntityId($meeting['room_id']);
                if (!isset($locations[$meeting['room_id']])) {
                    throw new InvalidArgumentException('A meeting location is missing.');
                }
                $used[$meeting['room_id']] = true;
            }
        }
        if (count($used) !== count($locations)) {
            throw new InvalidArgumentException('Only meeting locations belong in a published plan.');
        }
        ksort($locations);
        usort($data['meetings'], static fn(array $a, array $b): int =>
            [$a['starts_at'], $a['occurrence_id']] <=> [$b['starts_at'], $b['occurrence_id']]);
        $data['locations'] = array_values($locations);
        // Bound plaintext before encryption and before returning an encrypted review to the browser.
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 240000) {
            throw new InvalidArgumentException('This meeting plan exceeds the publication size limit.');
        }
        $this->data = self::canonical($data);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::canonical(...), $value);
    }

    /** @param array<mixed> $data
     * @param list<string> $keys
     */
    private static function keys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('Unexpected meeting plan fields.');
        }
    }

    private static function label(mixed $value): void
    {
        if (
            !is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === ''
            || mb_strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            throw new InvalidArgumentException('Invalid meeting plan label.');
        }
    }
}
