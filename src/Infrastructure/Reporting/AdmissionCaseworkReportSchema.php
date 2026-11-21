<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

/** Current operational routing only. Encrypted casework and application answers stay outside reports. */
final class AdmissionCaseworkReportSchema
{
    /** @param array<string,array<string,mixed>> $datasets
     * @return array<string,array<string,mixed>>
     */
    public static function extend(array $datasets): array
    {
        $dataset = &$datasets['applications'];
        $dataset['relationships']['casework'] = [
            'table' => 'sis_admission_cases', 'parent' => 'application', 'left' => 'id', 'right' => 'application_id',
            'label' => 'Current admissions casework', 'permissions' => [],
        ];
        $dataset['relationships']['case_assignee'] = [
            'table' => 'users', 'parent' => 'casework', 'left' => 'assigned_to', 'right' => 'user_id',
            'label' => 'Assigned colleague account', 'permissions' => ['sis:person:manage'],
        ];
        $dataset['relationships']['case_assignee_person'] = [
            'table' => 'sis_persons', 'parent' => 'case_assignee', 'left' => 'user_id', 'right' => 'owner_user_id',
            'label' => 'Assigned colleague', 'permissions' => ['sis:person:manage'],
        ];
        $field = static fn(string $label, string $type, string $column): array => [
            'label' => $label, 'type' => $type, 'relation' => 'casework', 'column' => $column, 'permissions' => [],
        ];
        $dataset['fields'] += [
            'case_reference' => $field('Casework reference', 'string', 'id'),
            'case_office' => $field('Casework office', 'string', 'office'),
            'case_work_status' => $field('Casework status', 'string', 'work_status') + [
                'choices' => ['open' => 'Open', 'waiting' => 'Waiting', 'done' => 'Done'],
            ],
            'case_due_on' => $field('Next casework due date', 'date', 'next_due_on'),
            'case_updated_at' => $field('Casework updated UTC', 'datetime', 'updated_at'),
            'case_assigned_to' => array_replace(
                $field('Assigned account reference', 'string', 'assigned_to'),
                ['permissions' => ['sis:person:manage']],
            ),
            'case_assignee_name' => [
                'label' => 'Assigned colleague', 'type' => 'string', 'relation' => 'case_assignee_person',
                'column' => 'given_name', 'expression' => 'full_name', 'permissions' => ['sis:person:manage'],
            ],
        ];
        $dataset['description'] .= ' Current casework routing and deadlines are operational facts, '
        . 'separate from admissions decisions. Missing casework stays blank; private content is excluded.';
        return $datasets;
    }
}
