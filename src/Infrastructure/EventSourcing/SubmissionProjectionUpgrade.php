<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Domain\Admissions\Event\AdmissionApplicationWasSubmitted;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\SubmissionFact;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\Repository\SqlSubmissionProjection;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\SystemClock;
use LogicException;
use Qubus\Expressive\Database;

/** Alpha migration of a derived index only. No events, receipts or existing academic rows are changed. */
final readonly class SubmissionProjectionUpgrade
{
    public function __construct(private Database $db, private ?ProtectedEventCodec $codec)
    {
    }

    public function run(): void
    {
        $sql = new SqlRecords($this->db);
        // Explicit offline bound: stop rather than silently leaving an incomplete index on a large installation.
        $q = $sql->quote(...);
        $streams = $sql->fetch(
            'SELECT * FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type') . ' = ? ORDER BY '
                . $q('id'),
            ['admission_decision'],
            1000,
        );
        if (
            count($streams) === 1000 && $sql->fetch(
                'SELECT ' . $q('id') . ' FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type')
                . ' = ? AND ' . $q('id') . ' > ?',
                ['admission_decision', $streams[999]['id']],
                1,
            ) !== []
        ) {
            throw new LogicException('Submission index conversion exceeds the alpha migration limit of 1000 streams.');
        }
        if ($streams !== [] && $this->codec === null) {
            throw new LogicException('Configure the history codec before rebuilding recorded submission dates.');
        }
        $transactions = new DatabaseUnitOfWork($this->db);
        $history = $this->codec === null ? null : new SqlHistoricalEventStore(
            $sql,
            $transactions,
            new SystemClock(),
            $this->codec,
            new HistoricalSchemaRegistry(),
        );
        $projection = new SqlSubmissionProjection($sql);
        $transactions->run(function () use ($sql, $streams, $history, $projection): void {
            $deadline = microtime(true) + 30;
            foreach ($streams as $stream) {
                if (microtime(true) > $deadline) {
                    throw new LogicException('Submission index conversion exceeded its 30-second work budget.');
                }
                $id = new AdmissionDecisionId($stream['aggregate_id']);
                $first = $history?->page(new StreamReference($id, 'admission_decision'), null, 1)->events[0]
                    ?? throw new CorruptHistoryException();
                $root = \Domain\Admissions\AdmissionDecision::root($id);
                $root->acceptHistorical($first->event);
                if ($first->event instanceof AdmissionApplicationWasSubmitted) {
                    $projection->project(SubmissionFact::fromEvent($first));
                } elseif ($sql->rows('sis_application_intake', ['id' => (string) $id], 1) !== []) {
                    // An observation does not prove when the application was submitted.
                    throw new CorruptHistoryException();
                }
            }
            $q = $sql->quote(...);
            $orphans = $sql->fetch(
                'SELECT i.' . $q('id') . ' FROM ' . $q('sis_application_intake') . ' i LEFT JOIN '
                    . $q('sis_history_streams') . ' s ON s.' . $q('aggregate_id') . ' = i.' . $q('id')
                    . ' AND s.' . $q('stream_type') . ' = ? WHERE s.' . $q('id') . ' IS NULL',
                ['admission_decision'],
                1,
            );
            if ($orphans !== []) {
                throw new CorruptHistoryException();
            }
        });
    }
}
