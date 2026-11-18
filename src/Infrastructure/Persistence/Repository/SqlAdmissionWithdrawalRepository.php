<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionWithdrawalRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdmissionWithdrawalRepository implements AdmissionWithdrawalRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function revision(EntityId $application): int
    {
        $row = $this->sql->one('sis_applications', ['id' => $application->value]);
        return $this->integer($row['withdrawal_revision']);
    }
    private function integer(mixed $v): int
    {
        $i = filter_var($v, FILTER_VALIDATE_INT);
        if ($i === false || $i < 0 || $i >= 2000000000) {
            throw new CorruptHistoryException();
        }
        return $i;
    }
    public function find(EntityId $id): ?array
    {
        $r = $this->sql->rows('sis_admission_withdrawal_requests', ['id' => $id->value], 1)[0] ?? null;
        return $r === null ? null : $this->open($r);
    }
    public function pending(EntityId $application): ?array
    {
        $rows = $this->sql->rows(
            'sis_admission_withdrawal_requests',
            ['application_id' => $application->value, 'status' => 'pending'],
            2
        );
        if (count($rows) > 1) {
            throw new CorruptHistoryException();
        }
        return isset($rows[0]) ? $this->open($rows[0]) : null;
    }
    public function page(EntityId $application, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $args = [$application->value];
        $where = $q('application_id') . ' = ?';
        if ($after !== null) {
            $where .= ' AND ' . $q('id') . ' < ?';
            $args[] = $after->value;
        }
        return array_map($this->open(...), $this->sql->fetch(
            'SELECT * FROM '
            . $q('sis_admission_withdrawal_requests') . ' WHERE ' . $where . ' ORDER BY ' . $q('id') . ' DESC',
            $args,
            11
        ));
    }
    public function queue(?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = 'r.' . $q('status') . ' = ?';
        $args = ['pending'];
        if ($after !== null) {
            $where .= ' AND r.' . $q('id') . ' > ?';
            $args[] = $after->value;
        }
        // No private request wording or journal replay in the paged worklist.
        return $this->sql->fetch('SELECT r.' . $q('id') . ', r.' . $q('application_id')
            . ', p.' . $q('given_name') . ', p.' . $q('family_name')
            . ', a.' . $q('status') . ' AS ' . $q('application_status')
            . ' FROM ' . $q('sis_admission_withdrawal_requests') . ' r JOIN ' . $q('sis_applications') . ' a ON a.'
            . $q('id') . ' = r.' . $q('application_id') . ' JOIN ' . $q('sis_persons') . ' p ON p.' . $q('id')
            . ' = a.' . $q('person_id') . ' WHERE ' . $where . ' ORDER BY r.' . $q('id'), $args, 21);
    }
    private function open(#[\SensitiveParameter] array $row): array
    {
        try {
            if (!is_string($row['sealed_data'] ?? null) || strlen($row['sealed_data']) > 200000) {
                throw new CorruptHistoryException();
            }
            $p = json_decode($this->cipher->open($row['sealed_data']), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($p)) {
                throw new CorruptHistoryException();
            }
            \Domain\Admissions\ValueObject\AdmissionWithdrawalRequestSchema::validate($p);
            foreach (['id', 'application_id', 'owner_user_id', 'status', 'version'] as $key) {
                if ((string) $row[$key] !== (string) $p[$key]) {
                    throw new CorruptHistoryException();
                }
            }
            $this->integer($p['version']);
            return $p;
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function save(#[\SensitiveParameter] array $request, int $expectedRevision, bool $new): void
    {
        $id = new EntityId($request['application_id']);
        if ($this->revision($id) !== $expectedRevision) {
            throw new ConcurrentModificationException('This application request changed. Review it again.');
        }
        $row = array_intersect_key(
            $request,
            array_flip(['id', 'application_id', 'owner_user_id', 'status', 'version'])
        );
        $row['sealed_data'] = $this->cipher->seal(json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->open($row);
        if ($new) {
            $this->sql->insert('sis_admission_withdrawal_requests', $row);
        } else {
            $this->sql->update(
                'sis_admission_withdrawal_requests',
                $row,
                ['id' => $request['id'], 'version' => $request['version'] - 1]
            );
        }
        $this->sql->update(
            'sis_applications',
            ['withdrawal_revision' => $expectedRevision + 1],
            ['id' => $id->value, 'withdrawal_revision' => $expectedRevision]
        );
        if (
            $this->revision($id) !== $expectedRevision + 1
            || $this->find(new EntityId($request['id'])) !== $request
        ) {
            throw new ConcurrentModificationException('The request write could not be verified.');
        }
    }
}
