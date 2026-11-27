<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\Repository\AcademicDateReconciliation;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;

/** Pure replay into isolated memory; deliberate promotion only changes this disposable read model. */
final readonly class SqlAcademicDateReconciliation implements AcademicDateReconciliation
{
    public function __construct(
        private SqlRecords $sql,
        private HistoricalEventStore $history,
        private AcademicCreditRepository $credits,
        private SqlAcademicDateProjection $projection,
        private UnitOfWork $transactions,
    ) {
    }

    public function run(?EntityId $credit, ?EntityId $after, bool $apply, int $eventLimit = 500): array
    {
        if (($credit !== null && $after !== null) || $eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException();
        }
        $next = null;
        if ($credit === null) {
            $q = $this->sql->quote(...);
            $heads = $this->sql->fetch('SELECT ' . $q('aggregate_id') . ' FROM ' . $q('sis_history_streams') .
                ' WHERE ' . $q('stream_type') . ' = ?' .
                ($after === null ? '' : ' AND ' . $q('aggregate_id') . ' > ?') .
                ' ORDER BY ' . $q('aggregate_id'), $after === null ? ['academic_credit'] :
                    ['academic_credit', $after->value], 2);
            if ($heads === []) {
                return ['status' => 'empty', 'credit_reference' => null, 'next_after' => null];
            }
            $credit = new EntityId($heads[0]['aggregate_id']);
            $next = count($heads) > 1 ? $credit->value : null;
        }
        return $this->transactions->run(function () use ($credit, $apply, $eventLimit, $next): array {
            $stream = new StreamReference(new AcademicCreditId($credit->value), 'academic_credit');
            $budget = new HistoryReadBudget(
                maximumEvents: $eventLimit,
                maximumBytes: 4_194_304,
                maximumMilliseconds: 5000
            );
            $head = $this->history->latest($stream);
            if ($head === null) {
                return ['status' => 'not_tracked', 'credit_reference' => $credit->value, 'next_after' => $next];
            }
            $previous = $this->projection->current($credit);
            $root = AcademicCredit::root($stream->aggregateId);
            $cursor = null;
            do {
                $page = $budget->page($this->history, $stream, $cursor);
                foreach ($page->events as $stored) {
                    $root->acceptHistorical($stored->event);
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            if ($root->playhead() !== $head->event->playhead()) {
                throw new ConcurrentModificationException('The academic date source changed.');
            }
            $this->verifyCurrentCredit($root);
            $plain = SqlAcademicDateProjection::fromRoot($root, $head->hash);
            $matches = false;
            if ($previous !== null) {
                try {
                    $matches = ProtectedEventCodec::canonical($this->projection->verified($previous)) ===
                        ProtectedEventCodec::canonical($plain);
                } catch (CorruptHistoryException) {
                    // A deliberate apply may repair only this projection after complete verified replay.
                }
            }
            if ($apply && !$matches) {
                // Serialize with journal writers without modifying the protected head or anchor.
                $streamRow = $this->sql->rows('sis_history_streams', [
                    'stream_type' => 'academic_credit', 'aggregate_id' => $credit->value,
                ], 1)[0] ?? throw new CorruptHistoryException();
                $locked = $this->sql->current('sis_history_streams', $streamRow['id']);
                if (
                    $locked === null || (int) $locked['version'] !== $root->playhead() ||
                    $locked['head_hash'] !== $head->hash || $locked['stream_type'] !== 'academic_credit' ||
                    $locked['aggregate_id'] !== $credit->value
                ) {
                    throw new ConcurrentModificationException('The academic date source changed.');
                }
                $this->verifyCurrentCredit($root);
                $this->projection->replace($plain, $previous);
            } elseif ($this->projection->current($credit) !== $previous) {
                throw new ConcurrentModificationException('The academic date index changed.');
            }
            $end = $this->history->latest($stream);
            $budget->checkTime();
            $this->verifyCurrentCredit($root);
            if ($end === null || $end->hash !== $head->hash || $end->event->playhead() !== $root->playhead()) {
                throw new ConcurrentModificationException('The academic date source changed.');
            }
            if (
                $apply && ProtectedEventCodec::canonical($this->projection->verified(
                    $this->projection->current($credit),
                ))
                !== ProtectedEventCodec::canonical($plain)
            ) {
                throw new CorruptHistoryException();
            }
            $budget->checkTime();
            return ['status' => $matches ? 'ready' : ($apply ? 'applied' : 'needs_reconciliation'),
                'credit_reference' => $credit->value, 'position' => $root->playhead(), 'next_after' => $next];
        });
    }

    private function verifyCurrentCredit(#[\SensitiveParameter] AcademicCredit $root): void
    {
        $facts = $root->credit() ?? throw new CorruptHistoryException();
        $id = new EntityId($facts['id']);
        $mapping = $this->credits->mapping($id);
        if (
            ProtectedEventCodec::canonical($mapping ?? []) !== ProtectedEventCodec::canonical([
            'id' => $id->value, 'enrollment_id' => $facts['source_id'],
            'student_id' => $facts['student_id'], 'section_id' => $root->section()])
        ) {
            throw new CorruptHistoryException();
        }
        try {
            $projected = $this->credits->projection($id);
        } catch (InvalidArgumentException) {
            throw new CorruptHistoryException();
        }
        if (
            $facts['status'] === 'D' ? $projected !== null :
            ProtectedEventCodec::canonical($projected ?? []) !== ProtectedEventCodec::canonical($facts)
        ) {
            throw new CorruptHistoryException();
        }
    }
}
