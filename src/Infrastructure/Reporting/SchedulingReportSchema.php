<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** Approved scheduling read models; private requester identities and meeting links stay outside reporting. */
final class SchedulingReportSchema
{
    /** @return array<string,array<string,mixed>> */
    public static function datasets(): array
    {
        $join = static fn(
            string $table,
            string $parent,
            string $left,
            string $right,
            string $label,
            bool $many = false,
            array $permissions = [],
        ): array => [
            'table' => $table, 'parent' => $parent, 'left' => $left, 'right' => $right,
            'label' => $label, 'many' => $many, 'permissions' => $permissions,
        ];
        $field = static fn(string $label, string $type, string $relation, string $column): array => [
            'label' => $label, 'type' => $type, 'relation' => $relation, 'column' => $column, 'permissions' => [],
        ];
        $roomFields = [
            'room_id' => $field('Room reference', 'string', 'room', 'id'),
            'room_code' => $field('Room code', 'string', 'room', 'code'),
            'room_name' => $field('Room name', 'string', 'room', 'name'),
            'room_capacity' => $field('Room capacity', 'integer', 'room', 'capacity'),
            'building_code' => $field('Building code', 'string', 'building', 'code'),
            'building_name' => $field('Building name', 'string', 'building', 'name'),
        ];
        $meetingFields = [
            'meeting_id' => $field('Meeting reference', 'string', 'meeting', 'id'),
            'starts_at' => $field('Meeting starts UTC', 'datetime', 'meeting', 'starts_at'),
            'ends_at' => $field('Meeting ends UTC', 'datetime', 'meeting', 'ends_at'),
            'cancelled_at' => $field('Meeting cancelled UTC', 'datetime', 'meeting', 'cancelled_at'),
            'booking_id' => $field('Booking reference', 'string', 'booking', 'id'),
            'booking_title' => $field('Booking title', 'string', 'booking', 'title'),
            'booking_status' => $field('Booking status', 'string', 'booking', 'status') + ['choices' => [
                'requested' => 'Requested', 'approved' => 'Approved', 'cancelled' => 'Cancelled',
            ]],
            'category_code' => $field('Category code', 'string', 'category', 'code'),
            'category_name' => $field('Category name', 'string', 'category', 'name'),
            'section_publication_id' => $field('Linked offering reference', 'string', 'offering', 'id'),
            'section_code' => $field('Linked offering section code', 'string', 'offering', 'code'),
            'term_code' => $field('Linked offering term', 'string', 'offering', 'term_code'),
            'delivery_mode' => $field('Linked offering delivery', 'string', 'offering', 'delivery_mode') + [
                'choices' => ['unspecified' => 'Unspecified', 'in_person' => 'In person',
                    'online' => 'Online', 'hybrid' => 'Hybrid'],
            ],
            'course_code' => $field('Linked offering course code', 'string', 'course_version', 'code'),
            'course_title' => $field('Linked offering course title', 'string', 'course_version', 'title'),
        ];
        $building = $join('sis_buildings', 'room', 'building_id', 'id', 'Buildings');
        $related = [
            'category' => $join('sis_booking_categories', 'booking', 'category_id', 'id', 'Booking categories'),
            'offering' => $join(
                'sis_section_versions',
                'booking',
                'section_publication_id',
                'id',
                'Linked published offerings',
                permissions: ['sis:catalog:manage'],
            ),
            'course_version' => $join(
                'sis_course_versions',
                'offering',
                'course_version_id',
                'id',
                'Retained course definitions',
            ),
        ];
        return [
            'meetings' => [
                'id' => 'meetings', 'table' => 'sis_booking_slots', 'root' => 'meeting',
                'label' => 'Scheduled meetings',
                'description' => 'One row per booking occurrence, including online meetings. Times are UTC. '
                    . 'Booking approval and occurrence cancellation are separate filters; these are booking '
                    . 'records, not proof that a class timetable has been published.',
                'permissions' => ['sis:scheduling:manage'],
                'relationships' => [
                    'booking' => $join('sis_bookings', 'meeting', 'booking_id', 'id', 'Bookings'),
                    'room' => $join('sis_rooms', 'meeting', 'room_id', 'id', 'Rooms'),
                    'building' => $building,
                ] + $related,
                'fields' => $meetingFields + $roomFields,
            ],
            'rooms' => [
                'id' => 'rooms', 'table' => 'sis_rooms', 'root' => 'room',
                'label' => 'Rooms and bookings',
                'description' => 'Start with every physical room, including rooms without bookings or a building. '
                    . 'Connecting meetings adds one row per occurrence; count distinct meetings or rooms '
                    . 'when summarizing. No booking in a chosen window does not certify availability.',
                'permissions' => ['sis:scheduling:manage'],
                'relationships' => [
                    'building' => $building,
                    'meeting' => $join('sis_booking_slots', 'room', 'id', 'room_id', 'Room meetings', true),
                    'booking' => $join('sis_bookings', 'meeting', 'booking_id', 'id', 'Bookings'),
                ] + $related,
                'fields' => $roomFields + $meetingFields,
            ],
        ];
    }

    /** Attach bookings for the section's currently selected offering without reinterpreting older bookings.
     * @param array<string,mixed> $sections
     * @return array<string,mixed>
     */
    public static function withSectionBookings(array $sections): array
    {
        $base = self::datasets()['meetings'];
        $mapping = ['meeting' => 'scheduled_meeting', 'booking' => 'scheduled_booking',
            'room' => 'scheduled_room', 'building' => 'scheduled_building', 'category' => 'scheduled_category'];
        $sections['relationships']['scheduled_offering'] = [
            'table' => 'sis_section_offering_versions', 'parent' => 'section', 'left' => 'id', 'right' => 'id',
            'label' => 'Selected offering for bookings', 'permissions' => ['sis:scheduling:manage'],
        ];
        $sections['relationships']['scheduled_booking'] = [
            'table' => 'sis_bookings', 'parent' => 'scheduled_offering',
            'left' => 'publication_id', 'right' => 'section_publication_id',
            'label' => 'Selected offering bookings', 'many' => true, 'permissions' => [],
        ];
        $sections['relationships']['scheduled_meeting'] = [
            'table' => 'sis_booking_slots', 'parent' => 'scheduled_booking', 'left' => 'id', 'right' => 'booking_id',
            'label' => 'Booked class meetings', 'many' => true, 'permissions' => [],
        ];
        foreach (['room', 'building', 'category'] as $id) {
            $relation = $base['relationships'][$id];
            $sections['relationships'][$mapping[$id]] = array_replace($relation, [
                'parent' => $mapping[$relation['parent']],
            ]);
        }
        foreach ($base['fields'] as $id => $field) {
            if (isset($mapping[$field['relation']])) {
                $sections['fields']['scheduled_' . $id] = array_replace($field, [
                    'relation' => $mapping[$field['relation']],
                ]);
            }
        }
        return $sections;
    }
}
