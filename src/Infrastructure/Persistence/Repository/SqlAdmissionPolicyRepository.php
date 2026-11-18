<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionPolicyRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdmissionPolicyRepository implements AdmissionPolicyRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function lock(): void
    {
        $this->sql->lock('sis_admission_policy', self::ID, 'id');
    }
    public function offers(): array
    {
        $r = $this->sql->one('sis_admission_policy', ['id' => self::ID]);
        if (!in_array((string) $r['require_acceptance'], ['0', '1'], true)) {
            throw new CorruptHistoryException();
        }
        return ['require_acceptance' => (int) $r['require_acceptance'] === 1,
            'revision' => $this->revision($r['offer_revision'])];
    }
    public function withdrawals(): array
    {
        $r = $this->sql->one('sis_admission_policy', ['id' => self::ID]);
        if (!in_array($r['withdrawal_mode'], ['request', 'direct', 'staff_only'], true)) {
            throw new CorruptHistoryException();
        }
        return ['mode' => $r['withdrawal_mode'], 'revision' => $this->revision($r['withdrawal_revision'])];
    }
    public function answers(): array
    {
        $r = $this->sql->one('sis_admission_policy', ['id' => self::ID]);
        if (!in_array($r['answer_mode'], ['request', 'before_decision'], true)) {
            throw new CorruptHistoryException();
        }
        return ['mode' => $r['answer_mode'], 'revision' => $this->revision($r['answer_revision'])];
    }
    public function saveAnswers(string $mode, int $expectedRevision): void
    {
        if (!in_array($mode, ['request', 'before_decision'], true)) {
            throw new \InvalidArgumentException('Choose a valid application answer revision policy.');
        }
        $this->save(['answer_mode' => $mode], 'answer_revision', $expectedRevision);
    }
    private function revision(mixed $value): int
    {
        $v = filter_var($value, FILTER_VALIDATE_INT);
        if ($v === false || $v < 0 || $v > 2000000000) {
            throw new CorruptHistoryException();
        }
        return $v;
    }
    public function saveOffers(bool $required, int $expectedRevision): void
    {
        $this->save(['require_acceptance' => (int) $required], 'offer_revision', $expectedRevision);
    }
    public function saveWithdrawals(string $mode, int $expectedRevision): void
    {
        if (!in_array($mode, ['request', 'direct', 'staff_only'], true)) {
            throw new \InvalidArgumentException('Choose a valid application withdrawal policy.');
        }
        $this->save(['withdrawal_mode' => $mode], 'withdrawal_revision', $expectedRevision);
    }
    private function save(array $values, string $field, int $expected): void
    {
        $before = $this->sql->one('sis_admission_policy', ['id' => self::ID]);
        if ($expected < 0 || $expected >= 2000000000 || $this->revision($before[$field]) !== $expected) {
            throw new ConcurrentModificationException('This admissions policy changed. Reload it.');
        }
        $this->sql->update(
            'sis_admission_policy',
            $values + [$field => $expected + 1],
            ['id' => self::ID, $field => $expected]
        );
    }
}
