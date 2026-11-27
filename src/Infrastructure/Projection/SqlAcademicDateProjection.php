<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Event\AcademicCompletionWasRecorded;
use Domain\AcademicRecord\Event\InstitutionalAcademicPeriodWasRecorded;
use Domain\AcademicRecord\Event\InstitutionalCreditWasDropped;
use Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalProjection;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

use function Codefy\Framework\Helpers\trans;

/** Current dates only; the protected journal remains the sole historical authority. */
final readonly class SqlAcademicDateProjection implements HistoricalProjection
{
    public const array FIELDS = ['id', 'student_id', 'enrollment_id', 'academic_on', 'completed_on',
        'removed', 'position', 'head_hash'];

    public function __construct(private SqlRecords $sql, private ProtectedEventCodec $codec)
    {
    }

    public function current(EntityId $id): ?array
    {
        return $this->sql->rows('sis_academic_date_index', ['id' => $id->value], 1)[0] ?? null;
    }

    public function project(
        StreamReference $stream,
        int $previousVersion,
        #[\SensitiveParameter] array $events,
        string $previousHash,
        string $headHash,
    ): void {
        if ($stream->type !== 'academic_credit') {
            return;
        }
        $id = new EntityId((string) $stream->aggregateId);
        $before = $this->current($id);
        if ($previousVersion === 0) {
            if ($before !== null || !(($events[0] ?? null) instanceof InstitutionalCreditWasEstablished)) {
                throw new CorruptHistoryException();
            }
            $credit = $events[0]->payload()['credit'];
            if ($credit['id'] !== $id->value || $credit['credit_type'] !== 'institutional') {
                throw new CorruptHistoryException();
            }
            $row = ['id' => $id->value, 'student_id' => $credit['student_id'],
                'enrollment_id' => $credit['source_id'], 'academic_on' => null, 'completed_on' => null,
                'removed' => (int) ($credit['status'] === 'D'), 'position' => 0, 'head_hash' => $previousHash];
        } else {
            if ($before === null) {
                throw new ValidationException([['field' => 'record', 'message' => trans(
                    'Academic dates need operator reconciliation before this record can change.',
                )]]);
            }
            $row = $this->verified($before);
            if ($row['position'] !== $previousVersion || !hash_equals($previousHash, $row['head_hash'])) {
                throw new CorruptHistoryException();
            }
        }
        foreach ($events as $event) {
            if ($event->playhead() !== $row['position'] + 1) {
                throw new CorruptHistoryException();
            }
            $payload = $event->payload();
            if ($event instanceof AcademicCompletionWasRecorded) {
                if ($payload['previous_completed_on'] !== $row['completed_on']) {
                    throw new CorruptHistoryException();
                }
                $row['completed_on'] = $payload['completed_on'];
            } elseif ($event instanceof InstitutionalAcademicPeriodWasRecorded) {
                if ($payload['previous_academic_on'] !== $row['academic_on']) {
                    throw new CorruptHistoryException();
                }
                $row['academic_on'] = $payload['academic_on'];
            } elseif ($event instanceof InstitutionalCreditWasDropped) {
                $row['removed'] = (int) $payload['outcome']['delete_records'];
            }
            $row['position'] = $event->playhead();
        }
        $row['head_hash'] = $headHash;
        $this->replace($row, $before);
    }

    /** Verify the retained checkpoint before advancing it; ordinary reports use indexed coverage checks. */
    public function verified(#[\SensitiveParameter] array $stored): array
    {
        try {
            $plain = $this->normalize(array_intersect_key($stored, array_flip(self::FIELDS)));
            $sealed = $this->codec->open('readmodel.academic_dates', $stored['sealed_state']);
            if (ProtectedEventCodec::canonical($plain) !== ProtectedEventCodec::canonical($sealed)) {
                throw new CorruptHistoryException();
            }
            return $plain;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public static function fromRoot(#[\SensitiveParameter] AcademicCredit $root, string $hash): array
    {
        $credit = $root->credit();
        return ['id' => $credit['id'], 'student_id' => $credit['student_id'],
            'enrollment_id' => $credit['source_id'], 'academic_on' => $root->academicOn(),
            'completed_on' => $root->completedOn(), 'removed' => (int) ($credit['status'] === 'D'),
            'position' => $root->playhead(), 'head_hash' => $hash];
    }

    /** Deliberate replacement follows complete replay and head/current-row checks in the reconciler. */
    public function replace(#[\SensitiveParameter] array $plain, #[\SensitiveParameter] ?array $previous): void
    {
        $plain = $this->normalize($plain);
        $id = new EntityId($plain['id']);
        if ($this->current($id) !== $previous) {
            throw new ConcurrentModificationException('The academic date index changed.');
        }
        $row = $plain + ['sealed_state' => $this->codec->seal('readmodel.academic_dates', $plain)];
        if ($previous === null) {
            $this->sql->insert('sis_academic_date_index', $row);
        } else {
            unset($row['id']);
            if (
                $this->sql->update('sis_academic_date_index', $row, ['id' => $id->value,
                'position' => $previous['position'], 'head_hash' => $previous['head_hash']]) !== 1
            ) {
                throw new ConcurrentModificationException('The academic date index changed.');
            }
        }
    }

    private function normalize(#[\SensitiveParameter] array $plain): array
    {
        if (count($plain) !== count(self::FIELDS) || array_diff(array_keys($plain), self::FIELDS) !== []) {
            throw new CorruptHistoryException();
        }
        foreach (['id', 'student_id', 'enrollment_id'] as $key) {
            new EntityId($plain[$key]);
        }
        foreach (['position', 'removed'] as $key) {
            if (
                !is_int($plain[$key]) && (!is_string($plain[$key]) ||
                preg_match('/^(0|[1-9][0-9]*)$/D', $plain[$key]) !== 1)
            ) {
                throw new CorruptHistoryException();
            }
            $plain[$key] = (int) $plain[$key];
        }
        if (
            $plain['position'] < 1 || $plain['position'] > 2000000000 ||
            !in_array($plain['removed'], [0, 1], true) ||
            !is_string($plain['head_hash']) || preg_match('/^[a-f0-9]{64}$/D', $plain['head_hash']) !== 1
        ) {
            throw new CorruptHistoryException();
        }
        foreach (['academic_on', 'completed_on'] as $key) {
            if ($plain[$key] !== null) {
                \Domain\Registration\ValueObject\SectionDefinitionV1::assertDate($plain[$key]);
            }
        }
        if (
            $plain['academic_on'] !== null && $plain['completed_on'] !== null &&
            $plain['academic_on'] > $plain['completed_on']
        ) {
            throw new CorruptHistoryException();
        }
        return $plain;
    }
}
