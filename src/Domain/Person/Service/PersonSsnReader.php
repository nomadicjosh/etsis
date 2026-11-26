<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Person\PersonSsnHistory;
use Domain\Person\Repository\PersonSsnRepository;
use Domain\Person\ValueObject\PersonSsnId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;

/** Internal authenticated reader shared by authorized commands and trusted diagnostics. No writes or disclosure. */
final readonly class PersonSsnReader
{
    public const int MAX_EVENTS = 500;

    public function __construct(
        private PersonSsnRepository $records,
        private HistoricalEventStore $history,
        private MessageCipher $cipher,
    ) {
    }

    public function read(EntityId $id, HistoryReadBudget $budget): PersonSsnHistory
    {
        $root = $this->replay($id, $budget);
        $this->verifyProjection($id, $root, $this->records->find($id));
        return $root;
    }

    public function replay(EntityId $id, HistoryReadBudget $budget): PersonSsnHistory
    {
        $stream = new StreamReference(new PersonSsnId($id->value), 'person_ssn');
        $root = PersonSsnHistory::root($stream->aggregateId);
        $cursor = null;
        do {
            $page = $budget->page($this->history, $stream, $cursor);
            if ($page->throughVersion > self::MAX_EVENTS) {
                throw new HistoryReadLimitException();
            }
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $page->throughVersion) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    public function verifyProjection(EntityId $id, PersonSsnHistory $root, #[\SensitiveParameter] ?array $row): void
    {
        if ($root->playhead() === 0) {
            if ($row !== null) {
                throw new CorruptHistoryException();
            }
            return;
        }
        try {
            if (
                $row === null || (string) $row['version'] !== (string) $root->playhead()
                || !is_string($row['sealed_data']) || strlen($row['sealed_data']) > 4096
                || json_decode($this->cipher->open($row['sealed_data']), true, 8, JSON_THROW_ON_ERROR)
                    !== self::projection($id, $root->playhead(), $root->lastFour())
            ) {
                throw new CorruptHistoryException();
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public static function projection(EntityId $id, int $version, ?string $lastFour): array
    {
        return ['purpose' => 'person.ssn.mask', 'person_id' => $id->value, 'version' => $version,
            'last_four' => $lastFour];
    }
}
