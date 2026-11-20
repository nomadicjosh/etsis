<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\CoRequisiteRepository;
use Domain\Registration\Repository\RegistrationScheduleRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCoRequisiteRepository implements CoRequisiteRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function policy(): array
    {
        $r = $this->sql->one('sis_registration_policy', ['id' => RegistrationScheduleRepository::POLICY_ID]);
        $v = filter_var($r['coreq_revision'], FILTER_VALIDATE_INT);
        if (
            !in_array((string) $r['coreq_prior'], ['0', '1'], true) ||
            !in_array($r['coreq_drop'], ['block', 'warn'], true) || $v === false || $v < 0 || $v > 2000000000
        ) {
            throw new CorruptHistoryException();
        }
        return ['prior_completion' => (bool) $r['coreq_prior'], 'drop_mode' => $r['coreq_drop'], 'revision' => $v];
    }
    public function savePolicy(bool $prior, string $mode, int $revision): void
    {
        if (!in_array($mode, ['block', 'warn'], true) || $revision < 0 || $revision >= 2000000000) {
            throw new \InvalidArgumentException('Choose a valid co-requisite policy.');
        }
        $this->sql->lock('sis_registration_policy', RegistrationScheduleRepository::POLICY_ID, 'id');
        if (
            $this->sql->update(
                'sis_registration_policy',
                ['coreq_prior' => (int) $prior,
                'coreq_drop' => $mode, 'coreq_revision' => $revision + 1],
                ['id' => RegistrationScheduleRepository::POLICY_ID, 'coreq_revision' => $revision]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The co-requisite policy changed. Reload it.');
        }
    }
    public function activeBook(EntityId $student): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT e.' . $q('id') . ' FROM ' . $q('sis_enrollments') .
            ' e WHERE e.' . $q('student_id') . ' = ? AND e.' . $q('status') . " IN ('A','N') UNION SELECT m." .
            $q('id') . ' FROM ' . $q('sis_registration_streams') . ' m WHERE m.' . $q('student_id') .
            ' = ? AND NOT EXISTS (SELECT d.' . $q('id') . ' FROM ' . $q('sis_registration_decisions') .
            ' d WHERE d.' . $q('enrollment_id') . ' = m.' . $q('id') . ' AND d.' . $q('stream_version') .
            ' > 1) ORDER BY ' . $q('id'), [$student->value, $student->value], 101);
    }
}
