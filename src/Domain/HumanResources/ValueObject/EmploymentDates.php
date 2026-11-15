<?php

declare(strict_types=1);

namespace Domain\HumanResources\ValueObject;

use Domain\HumanResources\EmployeePosition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Recorded employment periods; unknown earlier service is never inferred. */
final readonly class EmploymentDates
{
    /** @var list<array{id:string,from:string,through:?string,voided:bool}> */
    public array $periods;

    /** @param array<array-key,array<string,mixed>> $periods */
    public function __construct(public EntityId $employee, array $periods, public int $version)
    {
        if (!array_is_list($periods) || count($periods) > 100 || $version < 0 || $version > 2000) {
            throw new CorruptHistoryException();
        }
        $normalized = [];
        foreach ($periods as $p) {
            if (
                count($p) !== 4 || !is_string($p['id']) || !is_string($p['from'])
                || (!is_string($p['through']) && $p['through'] !== null) || !is_bool($p['voided'])
                || isset($normalized[$p['id']])
            ) {
                throw new CorruptHistoryException();
            }
            new EntityId($p['id']);
            EmployeePosition::date($p['from'], 'from');
            if ($p['through'] !== null) {
                EmployeePosition::date($p['through'], 'through');
                if ($p['through'] < $p['from']) {
                    DefinitionRules::invalid('through', 'The last employment day must follow or equal the hire date.');
                }
            }
            $normalized[$p['id']] = ['id' => $p['id'], 'from' => $p['from'], 'through' => $p['through'],
                'voided' => $p['voided']];
        }
        ksort($normalized);
        $this->periods = array_values($normalized);
        $active = array_values(array_filter($this->periods, static fn(array $p): bool => !$p['voided']));
        usort($active, static fn(array $a, array $b): int => $a['from'] <=> $b['from']);
        for ($i = 1; $i < count($active); $i++) {
            if ($active[$i - 1]['through'] === null || $active[$i - 1]['through'] >= $active[$i]['from']) {
                DefinitionRules::invalid(
                    'from',
                    'Employment periods cannot overlap. Close or correct the earlier period first.'
                );
            }
        }
    }

    public function change(EntityId $id, string $from, ?string $through, bool $voided): self
    {
        $periods = array_column($this->periods, null, 'id');
        if (!isset($periods[$id->value]) && count($periods) >= 100) {
            throw new \Domain\Shared\EventSourcing\HistoryReadLimitException();
        }
        if (!isset($periods[$id->value]) && $voided) {
            DefinitionRules::invalid('voided', 'Record an employment period before voiding it.');
        }
        $periods[$id->value] = ['id' => $id->value, 'from' => $from, 'through' => $through, 'voided' => $voided];
        return new self($this->employee, array_values($periods), $this->version + 1);
    }

    /** @return ?array{id:string,from:string,through:?string,voided:bool} */
    public function at(string $date): ?array
    {
        foreach ($this->periods as $period) {
            if (
                !$period['voided'] && $date >= $period['from']
                && ($period['through'] === null || $date <= $period['through'])
            ) {
                return $period;
            }
        }
        return null;
    }

    public function covers(string $from, ?string $through): bool
    {
        $period = $this->at($from);
        return $period !== null && ($period['through'] === null
            || ($through !== null && $through <= $period['through']));
    }

    /** @return array{employee_id:string,periods:list<array{id:string,from:string,through:?string,voided:bool}>,version:int} */
    public function facts(): array
    {
        return ['employee_id' => $this->employee->value, 'periods' => $this->periods, 'version' => $this->version];
    }

    /** @param array<string,mixed> $facts */
    public static function restore(#[\SensitiveParameter] array $facts): self
    {
        try {
            if (count($facts) !== 3) {
                throw new CorruptHistoryException();
            }
            return new self(new EntityId($facts['employee_id']), $facts['periods'], $facts['version']);
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
}
