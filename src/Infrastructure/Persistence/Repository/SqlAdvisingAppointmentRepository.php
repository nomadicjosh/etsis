<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Advising\AdvisingAppointment;
use Domain\Advising\AppointmentTime;
use Domain\Advising\Repository\AdvisingAppointmentRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class SqlAdvisingAppointmentRepository implements AdvisingAppointmentRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    private function open(#[\SensitiveParameter] array $row): AdvisingAppointment
    {
        try {
            if (!is_string($row['sealed_data']) || strlen($row['sealed_data']) > 65536) {
                throw new CorruptHistoryException();
            }
            $item = AdvisingAppointment::restore(json_decode(
                $this->cipher->open($row['sealed_data']),
                true,
                16,
                JSON_THROW_ON_ERROR
            ));
            foreach ($this->columns($item) as $key => $value) {
                if ((string) $row[$key] !== (string) $value) {
                    throw new CorruptHistoryException();
                }
            }
            return $item;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    private function columns(AdvisingAppointment $item): array
    {
        $data = $item->facts();
        return array_intersect_key($data, array_flip([
            'id', 'student_id', 'pursuit_id', 'owner_user_id', 'host_person_id', 'status', 'version',
        ])) + ['starts_at' => $data['time']['starts_at'], 'ends_at' => $data['time']['ends_at']];
    }

    public function find(EntityId $student, EntityId $pursuit, EntityId $id): ?AdvisingAppointment
    {
        $row = $this->sql->rows('sis_advising_appointments', [
            'id' => $id->value, 'student_id' => $student->value, 'pursuit_id' => $pursuit->value,
        ], 1)[0] ?? null;
        return $row === null ? null : $this->open($row);
    }

    public function page(
        EntityId $student,
        EntityId $pursuit,
        ?EntityId $owner,
        bool $finished,
        ?EntityId $after,
    ): array {
        $q = $this->sql->quote(...);
        $where = $q('student_id') . ' = ? AND ' . $q('pursuit_id') . ' = ? AND '
        . $q('status') . ($finished ? ' <> ?' : ' = ?');
        $parameters = [$student->value, $pursuit->value, 'scheduled'];
        if ($owner !== null) {
            $where .= ' AND ' . $q('owner_user_id') . ' = ?';
            $parameters[] = $owner->value;
        }
        if ($after !== null) {
            $where .= ' AND ' . $q('id') . ' < ?';
            $parameters[] = $after->value;
        }
        return array_map($this->open(...), $this->sql->fetch('SELECT * FROM ' . $q('sis_advising_appointments')
            . ' WHERE ' . $where . ' ORDER BY ' . $q('id') . ' DESC', $parameters, 11));
    }

    public function activeCount(EntityId $student, EntityId $pursuit): int
    {
        return count($this->sql->rows('sis_advising_appointments', [
            'student_id' => $student->value, 'pursuit_id' => $pursuit->value, 'status' => 'scheduled',
        ], 20));
    }

    public function conflict(EntityId $student, EntityId $host, EntityId $except, AppointmentTime $time): bool
    {
        $q = $this->sql->quote(...);
        $studentPerson = $this->sql->one('sis_students', ['id' => $student->value])['person_id'];
        $studentIds = array_column($this->sql->rows('sis_students', ['person_id' => $host->value], 2), 'id');
        foreach (
            ['student_id' => [$student->value, ...$studentIds],
            'host_person_id' => array_unique([$host->value, $studentPerson])] as $column => $ids
        ) {
            $rows = $this->sql->fetch(
                'SELECT * FROM ' . $q('sis_advising_appointments')
                . ' WHERE ' . $q($column) . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
                . ' AND ' . $q('status') . ' = ? AND ' . $q('id') . ' <> ?'
                . ' AND ' . $q('starts_at') . ' < ? AND ' . $q('ends_at') . ' > ?',
                [...$ids, 'scheduled', $except->value, $time->endsAt, $time->startsAt],
                1
            );
            if ($rows !== []) {
                $this->open($rows[0]);
                return true;
            }
        }
        return false;
    }

    public function save(AdvisingAppointment $item, ?int $expectedVersion): void
    {
        $row = $this->columns($item) + ['sealed_data' => $this->cipher->seal(json_encode(
            $item->facts(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ))];
        if (strlen($row['sealed_data']) > 65536) {
            throw new ValidationException([['field' => 'message',
                'message' => trans('Use shorter appointment details.')]]);
        }
        if ($expectedVersion === null) {
            try {
                $this->sql->insert('sis_advising_appointments', $row);
            } catch (PDOException $error) {
                if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    throw new ConcurrentModificationException(trans('This appointment reference was already used.'));
                }
                throw $error;
            }
        } elseif (
            $this->sql->update('sis_advising_appointments', $row, [
            'id' => $row['id'], 'student_id' => $row['student_id'], 'pursuit_id' => $row['pursuit_id'],
            'version' => $expectedVersion,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException(trans('The appointment changed. Refresh appointments.'));
        }
    }
}
