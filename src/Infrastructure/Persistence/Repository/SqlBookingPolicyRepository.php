<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Advising\BookingPolicyRevision;
use Domain\Advising\Repository\BookingPolicyRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class SqlBookingPolicyRepository implements BookingPolicyRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    private function open(#[\SensitiveParameter] array $row): BookingPolicyRevision
    {
        try {
            if (!is_string($row['sealed_data']) || strlen($row['sealed_data']) > 16384) {
                throw new CorruptHistoryException();
            }
            $item = BookingPolicyRevision::restore(json_decode(
                $this->cipher->open($row['sealed_data']),
                true,
                10,
                JSON_THROW_ON_ERROR
            ));
            if (
                $row['id'] !== $item->facts()['id']
                || (string) $row['revision'] !== (string) $item->facts()['revision']
            ) {
                throw new CorruptHistoryException();
            }
            return $item;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function current(): ?BookingPolicyRevision
    {
        $q = $this->sql->quote(...);
        $row = $this->sql->fetch('SELECT * FROM ' . $q('sis_advising_booking_policies') . ' ORDER BY '
            . $q('revision') . ' DESC', [], 1)[0] ?? null;
        return $row === null ? null : $this->open($row);
    }

    public function find(EntityId $id): ?BookingPolicyRevision
    {
        $row = $this->sql->rows('sis_advising_booking_policies', ['id' => $id->value], 1)[0] ?? null;
        return $row === null ? null : $this->open($row);
    }

    public function page(?int $before = null): array
    {
        $q = $this->sql->quote(...);
        return array_map($this->open(...), $this->sql->fetch(
            'SELECT * FROM ' . $q('sis_advising_booking_policies')
            . ($before === null ? '' : ' WHERE ' . $q('revision') . ' < ?') . ' ORDER BY ' . $q('revision') . ' DESC',
            $before === null ? [] : [$before],
            11
        ));
    }

    public function save(BookingPolicyRevision $revision): void
    {
        $data = $revision->facts();
        $sealed = $this->cipher->seal(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        if (strlen($sealed) > 16384) {
            throw new \LengthException(trans('Use a shorter booking-policy reason.'));
        }
        try {
            $this->sql->insert('sis_advising_booking_policies', ['id' => $data['id'],
                'revision' => $data['revision'], 'sealed_data' => $sealed]);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ConcurrentModificationException(trans(
                    'Booking policy changed. Refresh and review the current rules.'
                ));
            }
            throw $error;
        }
    }
}
