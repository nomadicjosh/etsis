<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\TermReportingDefaultRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTermReportingDefaultRepository implements TermReportingDefaultRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function current(EntityId $term): array
    {
        $row = $this->sql->current('sis_term_reporting_defaults', $term->value);
        return ['reporting_group_id' => $row['reporting_group_id'] ?? null, 'version' => (int) ($row['version'] ?? 0)];
    }

    public function save(EntityId $term, ?EntityId $group, int $version): void
    {
        $values = ['reporting_group_id' => $group?->value, 'version' => $version];
        if ($this->sql->current('sis_term_reporting_defaults', $term->value) === null) {
            $this->sql->insert('sis_term_reporting_defaults', ['id' => $term->value] + $values);
        } else {
            $this->sql->update('sis_term_reporting_defaults', $values, ['id' => $term->value]);
        }
    }
}
