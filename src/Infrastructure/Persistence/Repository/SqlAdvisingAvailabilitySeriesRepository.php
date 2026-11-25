<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Advising\AdvisingAvailabilitySeries;
use Domain\Advising\Repository\AdvisingAvailabilitySeriesRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class SqlAdvisingAvailabilitySeriesRepository implements AdvisingAvailabilitySeriesRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    private function columns(AdvisingAvailabilitySeries $item): array
    {
        return array_intersect_key($item->facts(), array_flip(['id', 'host_person_id', 'host_user_id',
            'status', 'version']));
    }

    private function open(#[\SensitiveParameter] array $row): AdvisingAvailabilitySeries
    {
        try {
            if (!is_string($row['sealed_data']) || strlen($row['sealed_data']) > 32768) {
                throw new CorruptHistoryException();
            }
            $item = AdvisingAvailabilitySeries::restore(json_decode($this->cipher->open(
                $row['sealed_data']
            ), true, 12, JSON_THROW_ON_ERROR));
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

    public function find(EntityId $id): ?AdvisingAvailabilitySeries
    {
        $row = $this->sql->rows('sis_advising_availability_series', ['id' => $id->value], 1)[0] ?? null;
        return $row === null ? null : $this->open($row);
    }

    public function page(EntityId $host, ?EntityId $account, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $parameters = [$host->value];
        $where = $q('host_person_id') . ' = ?';
        if ($account !== null) {
            $where .= ' AND ' . $q('host_user_id') . ' = ?';
            $parameters[] = $account->value;
        }
        if ($after !== null) {
            $where .= ' AND ' . $q('id') . ' < ?';
            $parameters[] = $after->value;
        }
        return array_map($this->open(...), $this->sql->fetch('SELECT * FROM ' . $q('sis_advising_availability_series')
            . ' WHERE ' . $where . ' ORDER BY ' . $q('id') . ' DESC', $parameters, 11));
    }

    public function save(AdvisingAvailabilitySeries $item, ?int $version): void
    {
        $row = $this->columns($item) + ['sealed_data' => $this->cipher->seal(json_encode(
            $item->facts(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ))];
        if (strlen($row['sealed_data']) > 32768) {
            throw new \LengthException(trans('Use a shorter recurring availability definition.'));
        }
        if ($version === null) {
            try {
                $this->sql->insert('sis_advising_availability_series', $row);
            } catch (PDOException $error) {
                if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                    throw new ConcurrentModificationException(trans(
                        'This recurring offer reference was already used.'
                    ));
                }
                throw $error;
            }
        } elseif (
            $this->sql->update(
                'sis_advising_availability_series',
                $row,
                ['id' => $row['id'], 'version' => $version]
            ) !== 1
        ) {
            throw new ConcurrentModificationException(trans('Recurring availability changed. Review it again.'));
        }
    }
}
