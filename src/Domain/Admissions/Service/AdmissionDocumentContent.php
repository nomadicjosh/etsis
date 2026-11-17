<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Throwable;

/** Protected artifact decoding is shared by download and background scanning. Callers authorize access. */
final readonly class AdmissionDocumentContent
{
    public function __construct(private MessageCipher $cipher, private AdmissionDocumentStorage $storage)
    {
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function metadata(array $row): array
    {
        try {
            $meta = json_decode($this->cipher->open($row['protected_metadata']), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($meta) || ($meta['document_id'] ?? null) !== $row['id']
                || ($meta['application_id'] ?? null) !== $row['application_id']
                || ($meta['_routing'] ?? null) !== $this->routing($row)
            ) {
                throw new DomainException();
            }
            if (
                !is_string($meta['upload_fingerprint'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $meta['upload_fingerprint'])
            ) {
                throw new DomainException();
            }
            if (!in_array($row['status'], ['purging', 'purged'], true)) {
                if (
                    !is_string($meta['name'] ?? null) || !is_int($meta['bytes'] ?? null)
                    || $meta['bytes'] < 1 || $meta['bytes'] > AdmissionDocumentPolicy::MAX_BYTES
                    || !is_string($meta['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $meta['sha256'])
                    || !in_array($meta['extension'] ?? null, ['pdf', 'png', 'jpg'], true)
                    || !is_bool($meta['applicant_visible'] ?? null)
                ) {
                    throw new DomainException();
                }
            }
            if ($row['status'] === 'ready' && ($meta['scan_verdict'] ?? null) !== 'clean') {
                throw new DomainException();
            }
            return $meta;
        } catch (Throwable) {
            throw new DomainException('This protected document needs institutional review.');
        }
    }
    /** @param array<string,mixed> $row @return array<string,int|string|null> */
    private function routing(array $row): array
    {
        $routing = [];
        $fields = ['person_id', 'uploaded_by', 'status', 'created_at', 'expires_at', 'scan_after', 'scan_token'];
        foreach ($fields as $key) {
            $routing[$key] = $row[$key];
        }
        $routing['held'] = (int) $row['held'];
        $routing['version'] = (int) $row['version'];
        return $routing;
    }
    /** @param array<string,mixed> $row @param array<string,mixed> $meta */
    public function protect(array $row, #[\SensitiveParameter] array $meta): string
    {
        $meta['_routing'] = $this->routing($row);
        return $this->cipher->seal(json_encode($meta, JSON_THROW_ON_ERROR));
    }
    /** Protect updated routing before the repository's expected-version write.
     * @param array<string,mixed> $row @param array<string,mixed> $values
     * @param array<string,mixed>|null $meta @return array<string,mixed>
     */
    public function changes(array $row, array $values, #[\SensitiveParameter] ?array $meta = null): array
    {
        $meta ??= $this->metadata($row);
        $next = array_replace($row, $values, ['version' => (int) $row['version'] + 1]);
        $values['protected_metadata'] = $this->protect($next, $meta);
        return $values;
    }
    /** @param array<string,mixed> $row */
    public function read(array $row): string
    {
        $meta = $this->metadata($row);
        $bytes = $this->storage->read(new EntityId($row['application_id']), new EntityId($row['id']));
        if (
            strlen($bytes) !== ($meta['bytes'] ?? null)
            || !hash_equals($meta['sha256'] ?? '', hash('sha256', $bytes))
        ) {
            throw new DomainException('This protected document needs institutional review.');
        }
        return $bytes;
    }
}
