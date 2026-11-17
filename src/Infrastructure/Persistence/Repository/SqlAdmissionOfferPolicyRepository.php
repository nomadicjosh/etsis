<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Admissions\Repository\AdmissionOfferPolicyRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAdmissionOfferPolicyRepository implements AdmissionOfferPolicyRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function lock(): void
    {
        $this->sql->lock('sis_admission_offer_policy', self::ID, 'id');
    }
    public function get(): array
    {
        $r = $this->sql->one('sis_admission_offer_policy', ['id' => self::ID]);
        if (!in_array((string) $r['require_acceptance'], ['0', '1'], true) || (int) $r['revision'] < 0) {
            throw new CorruptHistoryException();
        }
        return ['require_acceptance' => (int) $r['require_acceptance'] === 1, 'revision' => (int) $r['revision']];
    }
    public function save(bool $required, int $expectedRevision): void
    {
        if ($this->get()['revision'] !== $expectedRevision) {
            throw new ConcurrentModificationException('This offer policy changed. Reload it.');
        }
        $this->sql->update(
            'sis_admission_offer_policy',
            ['require_acceptance' => (int) $required, 'revision' => $expectedRevision + 1],
            ['id' => self::ID, 'revision' => $expectedRevision]
        );
        if ($this->get()['revision'] !== $expectedRevision + 1) {
            throw new ConcurrentModificationException('This offer policy changed. Reload it.');
        }
    }
}
