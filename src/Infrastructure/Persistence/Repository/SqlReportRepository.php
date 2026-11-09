<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Reporting\Repository\ReportRepository;
use Domain\Reporting\StudentReport;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlReportRepository implements ReportRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function add(StudentReport $report): void
    {
        $this->sql->insert('sis_reports', ['id' => $report->id->value, 'owner_user_id' => $report->ownerUserId->value,
            'name' => $report->name, 'program_id' => $report->programId?->value, 'term_id' => $report->termId?->value,
            'active' => $report->active === null ? null : (int) $report->active]);
    }

    public function isRelational(EntityId $id): bool
    {
        return $this->sql->rows('sis_report_queries', ['id' => $id->value], 1) !== [];
    }

    public function get(EntityId $id): StudentReport
    {
        $r = $this->sql->one('sis_reports', ['id' => $id->value]);

        return new StudentReport(
            $id,
            new EntityId($r['owner_user_id']),
            $r['name'],
            $r['program_id'] === null ? null : new EntityId($r['program_id']),
            $r['term_id'] === null ? null : new EntityId($r['term_id']),
            $r['active'] === null ? null : (bool) $r['active']
        );
    }

    public function share(EntityId $report, EntityId $user): void
    {
        $criteria = ['report_id' => $report->value, 'user_id' => $user->value];
        if ($this->sql->rows('sis_report_shares', $criteria) === []) {
            $this->sql->insert('sis_report_shares', ['id' => EntityId::generate()->value] + $criteria);
        }
    }

    public function lock(EntityId $report): void
    {
        $this->sql->lock('sis_reports', $report->value, 'id');
    }

    public function unshare(EntityId $report, EntityId $user): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute('DELETE FROM ' . $q('sis_report_shares') . ' WHERE ' . $q('report_id')
            . ' = ? AND ' . $q('user_id') . ' = ?', [$report->value, $user->value]);
    }

    public function shares(EntityId $report, ?EntityId $after, int $limit): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT r.' . $q('id') . ', r.' . $q('user_id') . ', p.'
            . $q('given_name') . ', p.' . $q('family_name') . ' FROM ' . $q('sis_report_shares') . ' r LEFT JOIN '
            . $q('sis_persons') . ' p ON p.' . $q('owner_user_id') . ' = r.' . $q('user_id')
            . ' WHERE r.' . $q('report_id') . ' = ?'
            . ($after === null ? '' : ' AND r.' . $q('id') . ' > ?') . ' ORDER BY r.' . $q('id'),
            $after === null ? [$report->value] : [$report->value, $after->value],
            $limit
        );
    }

    public function isSharedWith(EntityId $report, EntityId $user): bool
    {
        return $this->sql->rows('sis_report_shares', ['report_id' => $report->value, 'user_id' => $user->value]) !== [];
    }

    public function run(StudentReport $report, ?EntityId $after, int $limit): array
    {
        $criteria = [];
        if ($report->programId !== null) {
            $criteria['primary_program_id'] = $report->programId->value;
        }
        if ($report->termId !== null) {
            $criteria['entry_term_id'] = $report->termId->value;
        }
        if ($report->active !== null) {
            $criteria['active'] = (int) $report->active;
        }
        $q = $this->sql->quote(...);
        $where = [];
        foreach (array_keys($criteria) as $column) {
            $where[] = 's.' . $q($column) . ' = ?';
        }
        $parameters = array_values($criteria);
        if ($after !== null) {
            $where[] = 's.' . $q('id') . ' > ?';
            $parameters[] = $after->value;
        }
        $rows = $this->sql->fetch(
            'SELECT s.' . $q('id') . ' AS ' . $q('student_id') . ', i.'
            . $q('institutional_id') . ', s.' . $q('primary_program_id') . ' AS ' . $q('program_id')
            . ', s.' . $q('entry_term_id') . ', s.' . $q('active')
            . ', p.' . $q('code') . ' AS ' . $q('program_code')
            . ', t.' . $q('code') . ' AS ' . $q('entry_term_code') . ' FROM ' . $q('sis_students')
            . ' s JOIN ' . $q('sis_programs') . ' p ON p.' . $q('id') . ' = s.' . $q('primary_program_id')
            . ' JOIN ' . $q('sis_terms') . ' t ON t.' . $q('id') . ' = s.' . $q('entry_term_id')
            . ' JOIN ' . $q('sis_person_identifiers') . ' i ON i.' . $q('person_id') . ' = s.' . $q('person_id')
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY s.' . $q('id'),
            $parameters,
            $limit
        );
        foreach ($rows as &$row) {
            $row['active'] = (bool) $row['active'];
        }
        unset($row);

        return $rows;
    }

    public function describe(EntityId $report): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            'SELECT r.' . $q('id') . ', r.' . $q('name') . ', r.' . $q('program_id')
            . ', r.' . $q('term_id') . ', r.' . $q('active') . ', p.' . $q('code') . ' AS ' . $q('program_code')
            . ', p.' . $q('title') . ' AS ' . $q('program_title') . ', t.' . $q('code') . ' AS ' . $q('entry_term_code')
            . ' FROM ' . $q('sis_reports') . ' r LEFT JOIN ' . $q('sis_programs') . ' p ON p.' . $q('id')
            . ' = r.' . $q('program_id') . ' LEFT JOIN ' . $q('sis_terms') . ' t ON t.' . $q('id')
            . ' = r.' . $q('term_id') . ' WHERE r.' . $q('id') . ' = ?',
            [$report->value],
            1
        );
        $record = $rows[0] ?? throw new \Domain\Shared\Exception\RecordNotFoundException('Report not found.');
        $record['active'] = $record['active'] === null ? null : (bool) $record['active'];

        return $record + ['relational' => $this->isRelational($report)];
    }
}
