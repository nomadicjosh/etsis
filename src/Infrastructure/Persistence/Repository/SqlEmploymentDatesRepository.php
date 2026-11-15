<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\HumanResources\Repository\EmploymentDatesRepository;
use Domain\HumanResources\ValueObject\EmploymentDates;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlEmploymentDatesRepository implements EmploymentDatesRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function find(EntityId $employee): ?EmploymentDates
    {
        $row = $this->sql->rows('sis_employment_dates', ['employee_id' => $employee->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            if (!is_string($row['encrypted_dates']) || strlen($row['encrypted_dates']) > 65536) {
                throw new CorruptHistoryException();
            }
            $data = json_decode($this->cipher->open($row['encrypted_dates']), true, 12, JSON_THROW_ON_ERROR);
            if (!is_array($data) || count($data) !== 2 || ($data['purpose'] ?? null) !== 'hr.employment-dates') {
                throw new CorruptHistoryException();
            }
            $dates = EmploymentDates::restore($data['facts']);
            if ($dates->employee->value !== $employee->value || (string) $dates->version !== (string) $row['version']) {
                throw new CorruptHistoryException();
            }
            return $dates;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function save(EmploymentDates $dates, int $expectedVersion): void
    {
        if ($dates->version !== $expectedVersion + 1) {
            throw new ConcurrentModificationException();
        }
        $data = ['encrypted_dates' => $this->cipher->seal(json_encode(
            ['purpose' => 'hr.employment-dates', 'facts' => $dates->facts()],
            JSON_THROW_ON_ERROR
        )), 'version' => $dates->version];
        if ($expectedVersion === 0) {
            $this->sql->insert('sis_employment_dates', ['employee_id' => $dates->employee->value] + $data);
        } elseif (
            $this->sql->update(
                'sis_employment_dates',
                $data,
                ['employee_id' => $dates->employee->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('Review employment dates again.');
        }
    }
}
