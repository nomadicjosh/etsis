<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\SectionMeetingPlanRepository;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Scheduling\Repository\BookingSectionRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSectionMeetingPlanRepository implements SectionMeetingPlanRepository
{
    public function __construct(private SqlRecords $sql, private BookingSectionRepository $sections)
    {
    }

    public function capture(EntityId $section, string $timezone): array
    {
        $selected = $this->sections->selected($section, false);
        $q = $this->sql->quote(...);
        $columns = [
            'b.id AS booking_id', 'b.version AS booking_version', 'b.status', 'b.room_id',
            'b.section_publication_id AS offering_publication_id', 's.id AS occurrence_id',
            's.starts_at', 's.ends_at', 'r.code AS room_code', 'r.name AS room_name',
            'r.building_id', 'g.code AS building_code', 'g.name AS building_name',
        ];
        $columns = array_map(static function (string $column) use ($q): string {
            $parts = explode(' AS ', $column);
            [$alias, $field] = explode('.', $parts[0]);
            return $alias . '.' . $q($field) . (isset($parts[1]) ? ' AS ' . $q($parts[1]) : '');
        }, $columns);
        $rows = $this->sql->fetch('SELECT ' . implode(', ', $columns) . ' FROM ' . $q('sis_bookings')
            . ' b JOIN ' . $q('sis_section_versions') . ' v ON v.' . $q('id') . ' = b.'
            . $q('section_publication_id') . ' JOIN ' . $q('sis_booking_slots') . ' s ON s.'
            . $q('booking_id') . ' = b.' . $q('id') . ' LEFT JOIN ' . $q('sis_rooms') . ' r ON r.'
            . $q('id') . ' = b.' . $q('room_id') . ' LEFT JOIN ' . $q('sis_buildings') . ' g ON g.'
            . $q('id') . ' = r.' . $q('building_id') . ' WHERE v.' . $q('section_id') . ' = ? AND b.'
            . $q('status') . ' IN (?, ?) AND s.' . $q('cancelled_at') . ' IS NULL ORDER BY s.'
            . $q('starts_at') . ', s.' . $q('id'), [$section->value, 'approved', 'requested'], 501);
        if (count($rows) > 500) {
            throw new ValidationException([['field' => 'meetings',
                'message' => 'Review a section with 500 or fewer active meeting occurrences. No partial plan was used.',
            ]]);
        }
        $locations = $meetings = $pending = $rooms = $bookings = $buildings = [];
        foreach ($rows as $row) {
            $bookings[$row['booking_id']] = true;
            if ($row['room_id'] !== null) {
                $rooms[$row['room_id']] = true;
            }
            if ($row['building_id'] !== null) {
                $buildings[$row['building_id']] = true;
            }
            if ($row['status'] === 'requested') {
                $pending[$row['booking_id']] = true;
                continue;
            }
            $meetings[] = ['booking_id' => $row['booking_id'],
                'occurrence_id' => $row['occurrence_id'], 'offering_publication_id' => $row['offering_publication_id'],
                'starts_at' => str_replace(' ', 'T', $row['starts_at']) . 'Z',
                'ends_at' => str_replace(' ', 'T', $row['ends_at']) . 'Z', 'room_id' => $row['room_id']];
            if ($row['room_id'] !== null) {
                $locations[$row['room_id']] = ['room_id' => $row['room_id'], 'code' => $row['room_code'],
                    'name' => $row['room_name'], 'building_id' => $row['building_id'],
                    'building_code' => $row['building_code'], 'building_name' => $row['building_name']];
            }
        }
        $definition = new SectionMeetingPlanDefinition(['section_id' => $section->value,
            'section_code' => $selected['code'], 'term_code' => $selected['term_code'], 'timezone' => $timezone,
            'locations' => array_values($locations), 'meetings' => $meetings]);
        return ['definition' => $definition->data, 'pending_booking_ids' => array_keys($pending),
            'revision' => hash('sha256', json_encode([$selected, $rows, $timezone], JSON_THROW_ON_ERROR)),
            'locks' => ['rooms' => array_keys($rooms), 'bookings' => array_keys($bookings),
                'buildings' => array_keys($buildings)]];
    }

    public function lock(EntityId $section, array $candidate): void
    {
        // Match booking writers: never acquire a physical room while holding the section identity lock.
        foreach (['rooms' => 'capacity', 'bookings' => 'version', 'buildings' => 'code'] as $kind => $column) {
            $ids = $candidate['locks'][$kind];
            sort($ids, SORT_STRING);
            foreach ($ids as $id) {
                $this->sql->lock('sis_' . $kind, new EntityId($id)->value, $column);
            }
        }
        $this->sections->lock($section);
        // The service's first plain reads now see decisions committed before these locks, including on MySQL.
    }

    public function current(EntityId $section): ?array
    {
        return $this->sql->rows('sis_section_meeting_plans', ['id' => $section->value], 1)[0] ?? null;
    }

    public function retain(EntityId $section, EntityId $publication, int $version, string $fingerprint): void
    {
        $data = ['publication_id' => $publication->value, 'version' => $version, 'definition_hash' => $fingerprint];
        if ($version === 1) {
            $this->sql->insert('sis_section_meeting_plans', ['id' => $section->value] + $data);
        } elseif (
            $this->sql->update(
                'sis_section_meeting_plans',
                $data,
                ['id' => $section->value, 'version' => $version - 1]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The published meeting-plan reference changed.');
        }
    }
}
