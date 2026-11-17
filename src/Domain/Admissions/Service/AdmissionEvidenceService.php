<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeZone;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Repository\AdmissionDocumentRepository;
use Domain\Admissions\ValueObject\AdmissionEvidence;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal collaborator: caller authorizes and holds the person/application locks and transaction. */
final readonly class AdmissionEvidenceService
{
    public function __construct(
        private AdmissionDocumentRepository $documents,
        private AdmissionDocumentContent $content,
        private Clock $clock,
    ) {
    }

    /** @param array<mixed> $selection @return list<array<string,mixed>> */
    public function capture(AdmissionApplication $application, #[\SensitiveParameter] array $selection): array
    {
        if (!array_is_list($selection) || count($selection) > AdmissionEvidence::MAXIMUM) {
            throw new ValidationException([['field' => 'documents', 'message' => 'Select up to ten evidence files.']]);
        }
        $ids = [];
        foreach ($selection as $item) {
            if (
                !is_array($item) || count($item) !== 2 || !is_string($item['id'] ?? null)
                || !is_int($item['version'] ?? null) || $item['version'] < 1 || isset($ids[$item['id']])
            ) {
                throw new ValidationException([['field' => 'documents', 'message' => 'Reload the document choices.']]);
            }
            $ids[new EntityId($item['id'])->value] = $item['version'];
        }
        ksort($ids, SORT_STRING);
        $items = [];
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        foreach ($ids as $id => $version) {
            $reference = new EntityId($id);
            $this->documents->lock($reference);
            $row = $this->documents->find($reference);
            if (
                $row === null || $row['application_id'] !== $application->id->value
                || $row['person_id'] !== $application->personId->value || (int) $row['version'] !== $version
                || $row['status'] !== 'ready' || $row['expires_at'] <= $now
            ) {
                throw new ConcurrentModificationException('Select current, available, unexpired application files.');
            }
            $meta = $this->content->metadata($row);
            // Check the actual encrypted artifact, not only its clean-scan projection. IO is local and bounded.
            $this->content->read($row);
            $items[] = ['id' => $id, 'version' => $version, 'name' => $meta['name'], 'bytes' => $meta['bytes'],
                'sha256' => $meta['sha256'], 'scanned_at' => $meta['scanned_at'] ?? null];
        }
        AdmissionEvidence::validate($items);
        return $items;
    }

    /** @param list<array<string,mixed>> $items */
    public function retain(#[\SensitiveParameter] array $items, EntityId $decision): void
    {
        AdmissionEvidence::validate($items);
        foreach ($items as $item) {
            $id = new EntityId($item['id']);
            $row = $this->documents->find($id);
            if ($row === null || (int) $row['version'] !== $item['version']) {
                throw new ConcurrentModificationException('The reviewed document changed. Review the decision again.');
            }
            $meta = $this->content->metadata($row);
            $retained = $meta['evidence_decisions'] ?? [];
            if (count($retained) >= 500) {
                throw new ConcurrentModificationException('This file requires an institutional history review.');
            }
            $retained[] = $decision->value;
            $meta['evidence_decisions'] = $retained;
            $this->documents->change($id, $item['version'], $this->content->changes($row, [], $meta));
        }
    }
}
