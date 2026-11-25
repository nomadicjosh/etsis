<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Advising\AdvisingAvailability;
use Domain\Advising\AppointmentTime;
use Domain\Advising\Repository\AdvisingAvailabilityRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class SqlAdvisingAvailabilityRepository implements AdvisingAvailabilityRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    private function columns(AdvisingAvailability $item): array
    {
        $d = $item->facts();
        return array_intersect_key($d, array_flip(['id', 'host_person_id', 'host_user_id', 'status', 'version']))
        + ['starts_at' => $d['time']['starts_at'], 'ends_at' => $d['time']['ends_at']];
    }

    private function open(#[\SensitiveParameter] array $row): AdvisingAvailability
    {
        try {
            if (!is_string($row['sealed_data']) || strlen($row['sealed_data']) > 32768) {
                throw new CorruptHistoryException();
            }
            $item = AdvisingAvailability::restore(json_decode(
                $this->cipher->open($row['sealed_data']),
                true,
                12,
                JSON_THROW_ON_ERROR
            ));
            foreach ($this->columns($item) as $field => $value) {
                if ((string) $row[$field] !== (string) $value) {
                    throw new CorruptHistoryException();
                }
            }
            return $item;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function find(EntityId $id): ?AdvisingAvailability
    {
        $row = $this->sql->rows('sis_advising_availability', ['id' => $id->value], 1)[0] ?? null;
        return $row === null ? null : $this->open($row);
    }

    public function page(EntityId $host, ?EntityId $account, string $now, bool $finished, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = $q('host_person_id') . ' = ? AND (' . $q('status')
        . ($finished ? ' <> ? OR ' : ' = ? AND ') . $q('ends_at') . ($finished ? ' <= ?)' : ' > ?)');
        $parameters = [$host->value, 'open', $now];
        if ($account !== null) {
            $where .= ' AND ' . $q('host_user_id') . ' = ?';
            $parameters[] = $account->value;
        }
        if ($after !== null) {
            $where .= ' AND ' . $q('id') . ' < ?';
            $parameters[] = $after->value;
        }
        return array_map($this->open(...), $this->sql->fetch('SELECT * FROM ' . $q('sis_advising_availability')
            . ' WHERE ' . $where . ' ORDER BY ' . $q('id') . ' DESC', $parameters, 11));
    }

    public function on(EntityId $host, string $fromUtc, string $throughUtc): array
    {
        $q = $this->sql->quote(...);
        return array_map($this->open(...), $this->sql->fetch(
            'SELECT * FROM ' . $q('sis_advising_availability')
            . ' WHERE ' . $q('host_person_id') . ' = ? AND ' . $q('status') . ' = ? AND '
            . $q('starts_at') . ' >= ? AND ' . $q('starts_at') . ' < ? ORDER BY ' . $q('starts_at') . ', ' . $q('id'),
            [$host->value, 'open', $fromUtc, $throughUtc],
            21
        ));
    }

    public function activeCount(EntityId $host, string $now): int
    {
        $q = $this->sql->quote(...);
        return count($this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_advising_availability')
            . ' WHERE ' . $q('host_person_id') . ' = ? AND ' . $q('status') . ' = ? AND ' . $q('ends_at') . ' > ?',
            [$host->value, 'open', $now],
            101
        ));
    }

    public function conflict(EntityId $host, AppointmentTime $time, ?EntityId $except = null): bool
    {
        $q = $this->sql->quote(...);
        $sql = 'SELECT ' . $q('id') . ' FROM ' . $q('sis_advising_availability')
        . ' WHERE ' . $q('host_person_id') . ' = ? AND ' . $q('status') . ' = ? AND '
        . $q('starts_at') . ' < ? AND ' . $q('ends_at') . ' > ?';
        $parameters = [$host->value, 'open', $time->endsAt, $time->startsAt];
        if ($except !== null) {
            $sql .= ' AND ' . $q('id') . ' <> ?';
            $parameters[] = $except->value;
        }
        return $this->sql->fetch($sql, $parameters, 1) !== [];
    }

    public function save(AdvisingAvailability $item, ?int $version): void
    {
        $row = $this->columns($item) + ['sealed_data' => $this->cipher->seal(json_encode(
            $item->facts(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ))];
        if (strlen($row['sealed_data']) > 32768) {
            throw new \LengthException(trans('Use shorter availability instructions.'));
        }
        if ($version === null) {
            try {
                $this->sql->insert('sis_advising_availability', $row);
            } catch (PDOException $error) {
                if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    throw new ConcurrentModificationException(trans('This availability reference was already used.'));
                }
                throw $error;
            }
        } elseif (
            $this->sql->update('sis_advising_availability', $row, ['id' => $row['id'],
            'version' => $version]) !== 1
        ) {
            throw new ConcurrentModificationException(trans('Availability changed. Refresh its current state.'));
        }
    }
}
