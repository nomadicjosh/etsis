<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\AdmissionCasework;
use Domain\Admissions\AdmissionChecklistPreset;
use Domain\Admissions\Repository\AdmissionCaseworkRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;
use UnexpectedValueException;

final readonly class SqlAdmissionCaseworkRepository implements AdmissionCaseworkRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    public function find(EntityId $application): ?array
    {
        $row = $this->sql->rows('sis_admission_cases', ['application_id' => $application->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            $snapshot = $this->open($row['encrypted_payload']);
            if (!is_array($snapshot['facts'] ?? null) || !$this->validHash($snapshot['request_hash'] ?? null)) {
                throw new UnexpectedValueException();
            }
            $facts = $snapshot['facts'];
            if (
                !array_key_exists('assigned_to', $facts) || !is_int($facts['version'] ?? null)
                || $facts['version'] < 1 || (!is_string($facts['assigned_to']) && $facts['assigned_to'] !== null)
            ) {
                throw new UnexpectedValueException();
            }
            $case = AdmissionCasework::fromInput(
                $application,
                array_diff_key($facts, array_flip(['application_id', 'assigned_to', 'version'])),
                $facts['assigned_to'] === null ? null : new EntityId($facts['assigned_to']),
                $facts['version']
            );
            if (
                $case->facts() !== $facts || $case->version !== (int) $row['version']
                || $case->assignedTo?->value !== $row['assigned_to'] || $case->values['office'] !== $row['office']
                || $case->values['work_status'] !== $row['work_status'] || $case->nextDueOn() !== $row['next_due_on']
            ) {
                throw new UnexpectedValueException();
            }
            return ['case' => $case, 'request_hash' => $snapshot['request_hash']];
        } catch (Throwable) {
            throw new UnexpectedValueException('Application casework is unavailable.');
        }
    }

    public function save(AdmissionCasework $case, int $expected, EntityId $actor, string $at, string $requestHash): void
    {
        $values = ['assigned_to' => $case->assignedTo?->value, 'office' => $case->values['office'],
            'work_status' => $case->values['work_status'], 'next_due_on' => $case->nextDueOn(),
            'encrypted_payload' => $this->cipher->seal(json_encode(['facts' => $case->facts(),
                'request_hash' => $requestHash], JSON_THROW_ON_ERROR)),
            'version' => $case->version, 'updated_by' => $actor->value, 'updated_at' => $at];
        if ($expected === 0) {
            $this->sql->insert('sis_admission_cases', ['id' => EntityId::generate()->value,
                'application_id' => $case->application->value] + $values);
        } elseif (
            $this->sql->update(
                'sis_admission_cases',
                $values,
                ['application_id' => $case->application->value, 'version' => $expected]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('Reload the application casework.');
        }
    }

    public function preset(EntityId $id): ?array
    {
        $row = $this->sql->rows('sis_admission_checklists', ['id' => $id->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            $facts = $this->open($row['encrypted_definition']);
            if (
                !is_array($facts['values'] ?? null) || !is_int($facts['version'] ?? null)
                || !$this->validHash($facts['request_hash'] ?? null)
            ) {
                throw new UnexpectedValueException();
            }
            $preset = AdmissionChecklistPreset::fromInput($id, $facts['values'], $facts['version']);
            if (
                $facts !== ['id' => $id->value, 'version' => $preset->version, 'values' => $preset->values(),
                'request_hash' => $facts['request_hash']]
                || $preset->version !== (int) $row['version'] || $preset->name !== $row['name']
                || $preset->program?->value !== $row['program_id'] || $preset->enabled !== (bool) $row['enabled']
            ) {
                throw new UnexpectedValueException();
            }
            return ['preset' => $preset, 'request_hash' => $facts['request_hash']];
        } catch (Throwable) {
            throw new UnexpectedValueException('Checklist preset is unavailable.');
        }
    }

    public function savePreset(
        AdmissionChecklistPreset $preset,
        int $expected,
        EntityId $actor,
        string $at,
        string $hash
    ): void {
        $values = ['name' => $preset->name, 'program_id' => $preset->program?->value,
            'enabled' => (int) $preset->enabled,
            'version' => $preset->version, 'updated_by' => $actor->value, 'updated_at' => $at,
            'encrypted_definition' => $this->cipher->seal(json_encode(['id' => $preset->id->value,
                'version' => $preset->version, 'values' => $preset->values(),
                'request_hash' => $hash], JSON_THROW_ON_ERROR))];
        if ($expected === 0) {
            $this->sql->insert('sis_admission_checklists', ['id' => $preset->id->value] + $values);
        } elseif (
            $this->sql->update(
                'sis_admission_checklists',
                $values,
                ['id' => $preset->id->value, 'version' => $expected]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('Reload the checklist preset.');
        }
    }

    public function lockPreset(EntityId $id): void
    {
        $this->sql->lock('sis_admission_checklists', $id->value, 'version');
    }
    public function lockActor(EntityId $actor): void
    {
        $this->sql->lock('users', $actor->value, 'user_id', 'user_id');
    }
    public function requireProgram(EntityId $program): void
    {
        $this->sql->one('sis_programs', ['id' => $program->value]);
    }

    public function presets(EntityId $program, string $search, ?EntityId $after, bool $includeDisabled): array
    {
        $q = $this->sql->quote(...);
        $where = ['(' . $q('program_id') . ' IS NULL OR ' . $q('program_id') . ' = ?)'];
        $args = [$program->value];
        if (!$includeDisabled) {
            $where[] = $q('enabled') . ' = ?';
            $args[] = 1;
        }
        if ($search !== '') {
            $where[] = 'LOWER(' . $q('name') . ") LIKE ? ESCAPE '!'";
            $args[] = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        }
        if ($after !== null) {
            $where[] = $q('id') . ' < ?';
            $args[] = $after->value;
        }
        $columns = array_map($q, ['id', 'name', 'program_id', 'enabled', 'version']);
        return $this->sql->fetch('SELECT ' . implode(', ', $columns)
            . ' FROM ' . $q('sis_admission_checklists') . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY ' . $q('id') . ' DESC', $args, 21);
    }

    public function queue(
        ?EntityId $assignedTo,
        bool $unassigned,
        bool $dueOnly,
        string $today,
        ?EntityId $after
    ): array {
        $q = $this->sql->quote(...);
        $where = ['c.' . $q('work_status') . ' IN (?, ?)', 'a.' . $q('status') . ' <> ?'];
        $args = ['open', 'waiting', 'withdrawn'];
        if ($assignedTo !== null) {
            $where[] = 'c.' . $q('assigned_to') . ' = ?';
            $args[] = $assignedTo->value;
        } elseif ($unassigned) {
            $where[] = 'c.' . $q('assigned_to') . ' IS NULL';
        }
        if ($dueOnly) {
            $where[] = 'c.' . $q('next_due_on') . ' <= ?';
            $args[] = $today;
        }
        if ($after !== null) {
            $where[] = 'c.' . $q('id') . ' < ?';
            $args[] = $after->value;
        }
        $columns = array_map(
            static fn (string $field): string => 'c.' . $q($field),
            ['id', 'application_id', 'assigned_to', 'office', 'work_status', 'next_due_on']
        );
        return $this->sql->fetch('SELECT ' . implode(', ', $columns)
            . ', p.' . $q('given_name') . ', p.' . $q('family_name') . ', i.' . $q('institutional_id')
            . ' FROM ' . $q('sis_admission_cases') . ' c JOIN ' . $q('sis_applications') . ' a ON a.' . $q('id')
            . ' = c.' . $q('application_id') . ' JOIN ' . $q('sis_persons')
            . ' p ON p.' . $q('id') . ' = a.' . $q('person_id')
            . ' JOIN ' . $q('sis_person_identifiers') . ' i ON i.' . $q('person_id') . ' = p.' . $q('id')
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY c.' . $q('id') . ' DESC', $args, 21);
    }

    private function validHash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    /** @return array<string,mixed> */
    private function open(#[\SensitiveParameter] mixed $ciphertext): array
    {
        if (!is_string($ciphertext) || strlen($ciphertext) > 1048576) {
            throw new UnexpectedValueException();
        }
        $value = json_decode($this->cipher->open($ciphertext), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new UnexpectedValueException();
        }
        return $value;
    }
}
