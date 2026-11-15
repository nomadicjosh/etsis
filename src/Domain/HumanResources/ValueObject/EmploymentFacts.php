<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use Domain\HumanResources\Employee;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

final class EmploymentFacts
{
    /** @return array{id:string,person_id:string,job_title:string,active:bool} */
    public static function of(Employee $employee): array
    {
        return ['id' => $employee->id->value, 'person_id' => $employee->personId->value,
            'job_title' => $employee->jobTitle, 'active' => $employee->active];
    }
    /** @param array<string,mixed> $facts */
    public static function restore(#[\SensitiveParameter] array $facts): Employee
    {
        try {
            if (
                count($facts) !== 4 || array_diff(array_keys($facts), ['id', 'person_id', 'job_title', 'active']) !== []
                || !is_string($facts['id']) || !is_string($facts['person_id']) || !is_string($facts['job_title'])
                || !is_bool($facts['active'])
            ) {
                throw new CorruptHistoryException();
            }
            return new Employee(
                new EntityId($facts['id']),
                new EntityId($facts['person_id']),
                $facts['job_title'],
                $facts['active']
            );
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
    /** @param array<string,mixed> $payload */
    public static function validate(#[\SensitiveParameter] array $payload): void
    {
        if (
            count($payload) !== 5 || array_diff(
                array_keys($payload),
                ['operation_id', 'previous_operation_id', 'provenance', 'facts', 'intent']
            ) !== []
            || !is_string($payload['operation_id']) || ($payload['previous_operation_id'] !== null
                && !is_string($payload['previous_operation_id']))
            || !in_array($payload['provenance'], ['created', 'observed', 'changed'], true)
            || !is_array($payload['facts']) || !is_string($payload['intent'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['intent']) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        new EntityId($payload['operation_id']);
        if ($payload['previous_operation_id'] !== null) {
            new EntityId($payload['previous_operation_id']);
        }
        self::restore($payload['facts']);
    }
}
