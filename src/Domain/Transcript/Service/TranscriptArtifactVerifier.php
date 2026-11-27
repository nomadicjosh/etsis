<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptArtifactRepository;
use LengthException;
use RuntimeException;
use Throwable;

/** Selected retained artifact integrity; deliberately independent of today's academic sources and mail. */
final readonly class TranscriptArtifactVerifier
{
    public function __construct(private TranscriptArtifactRepository $records, private TranscriptArtifactReader $reader)
    {
    }

    /** Fixed operator metadata only. A successful result is not independent proof of issuance/archive completeness.
     * @return array<string,mixed> */
    public function verify(EntityId $document): array
    {
        $result = ['status' => 'unverified', 'scope' => 'transcript_artifact',
            'document_reference' => $document->value, 'preparation_checked' => false,
            'preparation_release_recorded' => false, 'delivery_present' => null,
            'delivery_checked' => false, 'delivery_release_recorded' => false,
            'issues' => [], 'records_changed' => false];
        $captured = [];
        $request = null;
        $started = hrtime(true);
        $stage = 'unavailable';
        try {
            if ($this->records->inTransaction()) {
                throw new RuntimeException();
            }
            $stage = 'document';
            $row = $this->records->document($document);
            $captured['document'] = $this->fingerprint($row);
            if ($row === null || $row['id'] !== $document->value || (int) $row['prepared_version'] < 1) {
                throw new RuntimeException();
            }
            $request = new EntityId($row['request_id']);
            $preparedVersion = (int) $row['prepared_version'];
            $payload = $this->reader->prepared($row);
            $this->reader->pdf($row, $payload);
            $result['preparation_checked'] = true;
            $result['preparation_release_recorded'] = $payload['release_review'] !== null;
            unset($row, $payload);
            $this->budget($started);
            $stage = 'request';
            $row = $this->records->request($request);
            $captured['request'] = $this->fingerprint($row);
            if ($row === null || $row['id'] !== $request->value || (int) $row['version'] < $preparedVersion) {
                throw new RuntimeException();
            }
            unset($row);
            $stage = 'delivery';
            $row = $this->records->delivery($document);
            $captured['delivery'] = $this->fingerprint($row);
            $result['delivery_present'] = $row !== null;
            if ($row !== null) {
                $payload = $this->reader->delivery($row);
                if (
                    $row['document_id'] !== $document->value ||
                    ($payload['request']['request_id'] ?? null) !== $request->value ||
                    ($payload['result']['request_id'] ?? null) !== $request->value ||
                    ($payload['result']['status'] ?? null) !== 'fulfilled' ||
                    ($payload['request']['delivered_at'] ?? null) !== ($payload['result']['delivered_at'] ?? null)
                ) {
                    throw new RuntimeException();
                }
                $result['delivery_checked'] = true;
                $result['delivery_release_recorded'] = $payload['release_review'] !== null;
                unset($payload);
            }
            unset($row);
            $this->budget($started);
            $result['status'] = 'verified';
        } catch (LengthException) {
            $result['status'] = 'limited';
        } catch (Throwable) {
            $result['issues'] = [$stage];
        }
        // A legitimate concurrent preparation/delivery must not be diagnosed as damaged restored data.
        try {
            foreach ($captured as $part => $fingerprint) {
                $this->budget($started);
                $current = match ($part) {
                    'document' => $this->records->document($document),
                    'request' => $this->records->request($request),
                    'delivery' => $this->records->delivery($document),
                };
                if ($fingerprint !== $this->fingerprint($current)) {
                    $result['status'] = 'changed';
                    $result['issues'] = [];
                    break;
                }
                unset($current);
            }
        } catch (LengthException) {
            $result['status'] = 'limited';
            $result['issues'] = [];
        } catch (Throwable) {
            $result['status'] = 'unverified';
            $result['issues'] = ['unavailable'];
        }
        if ($result['status'] !== 'verified') {
            $result['preparation_checked'] = $result['delivery_checked'] = false;
            $result['preparation_release_recorded'] = $result['delivery_release_recorded'] = false;
        }
        return $result;
    }

    private function budget(int $started): void
    {
        if (hrtime(true) - $started >= 5_000_000_000) {
            throw new LengthException();
        }
    }

    private function fingerprint(#[\SensitiveParameter] ?array $row): ?string
    {
        if ($row === null) {
            return null;
        }
        $hash = hash_init('sha256');
        foreach ($row as $key => $value) {
            hash_update($hash, $key . ':' . ($value === null ? 'NULL' : strlen((string) $value)) . ':');
            hash_update($hash, (string) $value);
        }
        return hash_final($hash);
    }
}
