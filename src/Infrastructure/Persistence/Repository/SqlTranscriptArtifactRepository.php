<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptArtifactRepository;
use Domain\Transcript\Service\TranscriptArtifactReader;
use Infrastructure\Persistence\SqlRecords;

/** Independent operator port: ordinary SELECTs, bounded ciphertext, no recipient/credential reads. */
final readonly class SqlTranscriptArtifactRepository implements TranscriptArtifactRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function inTransaction(): bool
    {
        return $this->sql->inTransaction();
    }

    public function document(EntityId $id): ?array
    {
        return $this->artifact('sis_transcript_documents', 'id', $id, [
            'id', 'request_id', 'actor_id', 'prepared_version', 'created_at', 'pdf_hash',
        ]);
    }

    public function delivery(EntityId $document): ?array
    {
        return $this->artifact('sis_transcript_deliveries', 'document_id', $document, [
            'id', 'document_id', 'actor_id', 'created_at',
        ]);
    }

    public function request(EntityId $id): ?array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch('SELECT ' . implode(', ', array_map($q, ['id', 'version', 'status'])) .
            ' FROM ' . $q('sis_transcript_requests') . ' WHERE ' . $q('id') . ' = ?', [$id->value], 1)[0] ?? null;
    }

    /** @param list<string> $fields
     * @return array<string,mixed>|null */
    private function artifact(string $table, string $key, EntityId $id, array $fields): ?array
    {
        $q = $this->sql->quote(...);
        $columns = array_map($q, $fields);
        $substring = in_array($this->sql->driver(), ['sqlite', 'oci'], true) ? 'SUBSTR' : 'SUBSTRING';
        $columns[] = $substring . '(' . $q('encrypted_content') . ', 1, ' .
        (TranscriptArtifactReader::MAX_CIPHERTEXT + 1) . ') AS ' . $q('encrypted_content');
        return $this->sql->fetch('SELECT ' . implode(', ', $columns) . ' FROM ' . $q($table) .
            ' WHERE ' . $q($key) . ' = ?', [$id->value], 1)[0] ?? null;
    }
}
