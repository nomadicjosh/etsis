<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionDocumentRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Throwable;

/** Working attachments only. A clean scan neither verifies a credential nor makes an admission decision. */
final readonly class AdmissionDocumentService
{
    public function __construct(
        private AdmissionDocumentRepository $documents,
        private AdmissionsRepository $admissions,
        private AdmissionDocumentStorage $storage,
        private AdmissionDocumentPolicy $policy,
        private MessageCipher $cipher,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private AccessRepository $permissions,
        private AdmissionDecisionLedger $decisions,
        private AdmissionDocumentHoldService $holds,
        private AdmissionRetentionService $retention,
    ) {
    }
    private function at(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
    private function authorize(EntityId $application, bool $staff, ?EntityId $expected = null): EntityId
    {
        $actor = $staff ? $this->access->requirePermission('sis:admissions:manage')
        : $this->access->authenticatedUserId();
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException('The document account changed.');
        }
        $app = $this->admissions->application($application);
        if (!$staff && $this->admissions->person($app->personId)->ownerUserId?->value !== $actor->value) {
            throw new AccessDeniedException('This account does not own the application.');
        }
        return $actor;
    }
    private function retainAuthority(EntityId $application, ?EntityId $expected = null): EntityId
    {
        $actor = $this->authorize($application, true, $expected);
        if ($this->access->requirePermission('sis:admissions:documents:retain')->value !== $actor->value) {
            throw new AccessDeniedException('Document retention authority is required.');
        }
        return $actor;
    }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function metadata(array $row): array
    {
        return new AdmissionDocumentContent($this->cipher, $this->storage)->metadata($row);
    }
    /** @return array<string,mixed> */
    public function listing(EntityId $application, bool $staff): array
    {
        $actor = $this->authorize($application, $staff);
        $rows = $this->documents->forApplication($application);
        if (count($rows) > 20) {
            throw new DomainException('This document inventory needs institutional review.');
        }
        $items = [];
        $canRetain = false;
        if ($staff) {
            try {
                $this->retainAuthority($application, $actor);
                $canRetain = true;
            } catch (AccessDeniedException) {
            }
        }
        $person = $this->admissions->application($application)->personId;
        $scopeHeld = $staff && $this->holds->blocks($person, $application);
        foreach ($rows as $row) {
            if ($row['person_id'] !== $person->value) {
                throw new AccessDeniedException('The document parent does not match this application.');
            }
            $meta = $this->metadata($row);
            if (!$staff && ($meta['applicant_visible'] ?? false) !== true) {
                continue;
            }
            $items[] = ['id' => $row['id'], 'name' => $meta['name'] ?? 'Document removal pending',
                'bytes' => $meta['bytes'] ?? null, 'applicant_visible' => $meta['applicant_visible'] ?? false,
                'status' => $row['status'], 'version' => (int) $row['version'],
                'created_at' => $row['created_at'],
                'expires_at' => $staff ? $row['expires_at'] : ($meta['applicant_expires_at'] ?? $row['expires_at']),
                'held' => $staff && (int) $row['held'] === 1,
                'scope_held' => $staff && $scopeHeld,
                'retained_evidence' => $staff && ($meta['evidence_decisions'] ?? []) !== [],
                'can_select_evidence' => $staff && $row['status'] === 'ready' && $row['expires_at'] > $this->at(),
                'can_purge' => $canRetain && !$scopeHeld && (int) $row['held'] === 0
                    && $row['expires_at'] <= $this->at()
                    && ($meta['evidence_decisions'] ?? []) === []
                    && !in_array($row['status'], ['purging', 'purged'], true),
                'can_download' => $row['status'] === 'ready'
                    && ($staff || ($meta['applicant_expires_at'] ?? $row['expires_at']) > $this->at())];
            if ($staff) {
                $index = array_key_last($items);
                $items[$index]['retention_basis'] = $meta['retention_basis'] ?? null;
                $items[$index]['applicant_expires_at'] = $meta['applicant_expires_at'] ?? $row['expires_at'];
            }
        }
        $this->authorize($application, $staff, $actor);
        $result = ['enabled' => $this->policy->acceptsUploads(), 'max_bytes' => AdmissionDocumentPolicy::MAX_BYTES,
            'upload_id' => EntityId::generate()->value, 'can_retain' => $canRetain, 'items' => $items];
        if ($canRetain) {
            $result['retention_holds'] = $this->holds->listing($application);
        }
        $this->authorize($application, $staff, $actor);
        return $result;
    }
    /** @return array{id:string,status:string} */
    public function upload(
        EntityId $application,
        EntityId $id,
        #[\SensitiveParameter] string $name,
        #[\SensitiveParameter] string $bytes,
        bool $staff,
        bool $visible = false
    ): array {
        $actor = $this->authorize($application, $staff);
        if ($staff && $visible) {
            $this->retainAuthority($application, $actor);
        }
        if (!$this->policy->acceptsUploads()) {
            throw new DomainException('Uploads are disabled. Follow the admissions document-delivery instructions.');
        }
        if ($bytes === '' || strlen($bytes) > AdmissionDocumentPolicy::MAX_BYTES) {
            throw new ValidationException([['field' => 'document', 'message' => 'Choose a document of up to 5 MiB.']]);
        }
        $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        $type = class_exists(\finfo::class) ? new \finfo(FILEINFO_MIME_TYPE)->buffer($bytes) : false;
        if (
            $bytes === '' || strlen($bytes) > AdmissionDocumentPolicy::MAX_BYTES || !is_string($type)
            || !isset($types[$type]) || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 120
            || trim($name) === '' || preg_match('~[\x00-\x1f\x7f/\\\\]~', $name)
        ) {
            throw new ValidationException([['field' => 'document',
                'message' => 'Choose a PDF, JPEG or PNG up to 5 MiB; use a simple filename up to 120 characters.']]);
        }
        $meta = ['document_id' => $id->value, 'application_id' => $application->value,
            'name' => trim($name), 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'extension' => $types[$type], 'applicant_visible' => !$staff || $visible];
        $meta['upload_fingerprint'] = hash('sha256', json_encode([$meta, $actor->value, $staff], JSON_THROW_ON_ERROR));
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use (
            $route,
            $application,
            $id,
            $actor,
            $meta,
            $bytes,
            $staff,
            $visible
        ): array {
            $this->permissions->lock();
            $this->admissions->lockPerson($route->personId);
            $this->admissions->lockApplication($application);
            if ($this->admissions->application($application)->personId->value !== $route->personId->value) {
                throw new ConcurrentModificationException('Reload the application before uploading.');
            }
            $this->authorize($application, $staff, $actor);
            if ($staff && $visible) {
                $this->retainAuthority($application, $actor);
            }
            $previous = $this->documents->find($id);
            if ($previous !== null) {
                if (
                    $previous['application_id'] !== $application->value || $previous['uploaded_by'] !== $actor->value
                    || !hash_equals($this->metadata($previous)['upload_fingerprint'], $meta['upload_fingerprint'])
                ) {
                    throw new ConcurrentModificationException('Use a new document reference for another upload.');
                }
                $this->authorize($application, $staff, $actor);
                return ['id' => $id->value, 'status' => $previous['status']];
            }
            if (
                count($this->documents->forApplication($application)) >= 20
                || $this->documents->retainedCount($route->personId) >= 50
            ) {
                throw new ValidationException([['field' => 'document',
                    'message' => 'Retained document limit reached. Contact admissions before uploading more.']]);
            }
            $written = false;
            try {
                $basis = $this->retention->uploadBasis();
                $meta['retention_basis'] = $basis;
                $created = $this->at();
                $this->storage->put($application, $id, $bytes);
                $written = true;
                $row = ['id' => $id->value, 'application_id' => $application->value,
                    'person_id' => $route->personId->value, 'uploaded_by' => $actor->value, 'version' => 1,
                    'held' => 0, 'status' => 'quarantined', 'created_at' => $created, 'scan_after' => $created,
                    'scan_token' => null, 'expires_at' => new DateTimeImmutable($created)
                        ->modify('+' . $basis['days'] . ' days')->format('Y-m-d\TH:i:s.u\Z'),
                    'protected_metadata' => ''];
                $content = new AdmissionDocumentContent($this->cipher, $this->storage);
                $meta['applicant_expires_at'] = $row['expires_at'];
                $row['protected_metadata'] = $content->protect($row, $meta);
                $this->documents->add($row);
                $this->retention->recordUpload($row, $actor, $basis);
                $this->audit->record($actor, 'admissions.document_uploaded', $id);
                $this->authorize($application, $staff, $actor);
                if ($staff && $visible) {
                    $this->retainAuthority($application, $actor);
                }
                if (
                    $this->retention->uploadBasis() !== $basis
                    || $this->admissions->application($application)->personId->value !== $route->personId->value
                ) {
                    throw new ConcurrentModificationException('The upload retention policy changed. Retry the upload.');
                }
            } catch (Throwable $error) {
                try {
                    if ($written) {
                        $this->storage->remove($application, $id);
                    }
                } catch (Throwable) {
                    // Only encrypted artifacts can remain after a failed cleanup; they have no read endpoint.
                }
                throw $error;
            }
            return ['id' => $id->value, 'status' => 'quarantined'];
        });
    }
    /** @return array<string,mixed> */
    private function ownedRow(EntityId $application, EntityId $id): array
    {
        $row = $this->documents->find($id) ?? throw new RecordNotFoundException('Document not found.');
        if (
            $row['application_id'] !== $application->value
            || $row['person_id'] !== $this->admissions->application($application)->personId->value
        ) {
            throw new AccessDeniedException('This document belongs to another application.');
        }
        return $row;
    }
    /** @return array{bytes:string,filename:string} */
    public function download(EntityId $application, EntityId $id, bool $staff): array
    {
        $actor = $this->authorize($application, $staff);
        $route = $this->admissions->application($application);
        return $this->transactions->run(function () use ($route, $application, $id, $staff, $actor): array {
            $this->admissions->lockPerson($route->personId);
            $this->documents->lock($id);
            $row = $this->ownedRow($application, $id);
            $this->authorize($application, $staff, $actor);
            $meta = $this->metadata($row);
            if (
                $row['status'] !== 'ready'
                || (!$staff && ($meta['applicant_expires_at'] ?? $row['expires_at']) <= $this->at())
            ) {
                throw new DomainException('This document is not available for download.');
            }
            if (!$staff && $meta['applicant_visible'] !== true) {
                throw new AccessDeniedException('This file is restricted to admissions staff.');
            }
            $bytes = new AdmissionDocumentContent($this->cipher, $this->storage)->read($row);
            $this->audit->record($actor, 'admissions.document_downloaded', $id);
            $this->authorize($application, $staff, $actor);
            return ['bytes' => $bytes, 'filename' => 'admissions-document.' . $meta['extension']];
        });
    }
    public function rescan(EntityId $application, EntityId $id, int $version): void
    {
        $actor = $this->authorize($application, true);
        $route = $this->admissions->application($application);
        $this->transactions->run(function () use ($route, $application, $id, $version, $actor): void {
            $this->permissions->lock();
            $this->admissions->lockPerson($route->personId);
            $this->documents->lock($id);
            $row = $this->ownedRow($application, $id);
            $this->authorize($application, true, $actor);
            $content = new AdmissionDocumentContent($this->cipher, $this->storage);
            $meta = $content->metadata($row);
            if ($row['status'] === 'quarantined' && (int) $row['version'] === $version + 1) {
                $this->authorize($application, true, $actor);
                return;
            }
            if (
                (int) $row['version'] !== $version
                || !in_array($row['status'], ['ready', 'rejected', 'quarantined'], true)
            ) {
                throw new ConcurrentModificationException('Reload this document before requesting another scan.');
            }
            unset($meta['scan_verdict'], $meta['scanned_at']);
            $this->documents->change($id, $version, $content->changes(
                $row,
                ['status' => 'quarantined', 'scan_after' => $this->at(), 'scan_token' => null],
                $meta
            ));
            $this->audit->record($actor, 'admissions.document_rescan_requested', $id);
            $this->authorize($application, true, $actor);
        });
    }
    public function retain(
        EntityId $application,
        EntityId $id,
        int $version,
        bool $hold,
        ?string $until = null,
        ?bool $visible = null
    ): void {
        $actor = $this->retainAuthority($application);
        $route = $this->admissions->application($application);
        $this->transactions->run(function () use (
            $route,
            $application,
            $id,
            $version,
            $hold,
            $until,
            $actor,
            $visible
        ): void {
            $this->permissions->lock();
            $this->admissions->lockPerson($route->personId);
            $this->documents->lock($id);
            $row = $this->ownedRow($application, $id);
            $this->retainAuthority($application, $actor);
            $meta = $this->metadata($row);
            $expires = $row['expires_at'];
            if ($until !== null) {
                $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $until) === 1
                    ? DateTimeImmutable::createFromFormat('!Y-m-d', $until, new DateTimeZone('UTC')) : false;
                if ($date === false || $date->format('Y-m-d') !== $until) {
                    throw new DomainException('Choose a valid retention end date.');
                }
                $expires = $until . 'T23:59:59.999999Z';
                if ($expires < $row['expires_at']) {
                    throw new DomainException('This screen can extend retention, not shorten it.');
                }
            }
            if (in_array($row['status'], ['purging', 'purged'], true) || (int) $row['version'] !== $version) {
                throw new ConcurrentModificationException('Reload the current document before changing retention.');
            }
            $shareChanged = $visible !== null && $meta['applicant_visible'] !== $visible;
            $availableBefore = $meta['applicant_expires_at'] ?? $row['expires_at'];
            if ($until !== null || ($shareChanged && $visible === true)) {
                $meta['applicant_expires_at'] = $expires;
            }
            $accessChanged = ($meta['applicant_expires_at'] ?? $row['expires_at']) !== $availableBefore;
            if ($visible !== null) {
                $meta['applicant_visible'] = $visible;
            }
            if (
                (int) $row['held'] !== (int) $hold || $expires !== $row['expires_at'] || $shareChanged || $accessChanged
            ) {
                $changes = new AdmissionDocumentContent($this->cipher, $this->storage)->changes(
                    $row,
                    ['held' => (int) $hold, 'expires_at' => $expires],
                    $meta
                );
                $this->documents->change($id, $version, $changes);
                $action = $hold ? 'admissions.document_retained_hold' : 'admissions.document_retention_updated';
                $this->audit->record($actor, $action, $id);
                if ($accessChanged) {
                    $this->audit->record($actor, 'admissions.document_applicant_access_extended', $id);
                }
                if ($shareChanged) {
                    $this->audit->record(
                        $actor,
                        $visible ? 'admissions.document_shared' : 'admissions.document_restricted',
                        $id
                    );
                }
            }
            $this->retainAuthority($application, $actor);
        });
    }
    /** @return array{review_token:string} */
    public function reviewPurge(
        EntityId $application,
        EntityId $id,
        int $version,
        #[\SensitiveParameter] string $reason
    ): array {
        $actor = $this->retainAuthority($application);
        if (
            trim($reason) === '' || mb_strlen($reason) > 1000 || !mb_check_encoding($reason, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $reason)
        ) {
            throw new DomainException('Enter a short disposal reason of up to 1,000 characters.');
        }
        $row = $this->ownedRow($application, $id);
        $this->metadata($row);
        $this->purgeEligible($row, $version);
        $token = $this->cipher->seal(json_encode([
            'kind' => 'admission-document-purge', 'application' => $application->value,
            'id' => $id->value, 'actor' => $actor->value, 'version' => $version, 'reason' => trim($reason),
            'expires' => $this->clock->now()->getTimestamp() + 1200], JSON_THROW_ON_ERROR));
        $this->retainAuthority($application, $actor);
        return ['review_token' => $token];
    }
    /** @param array<string,mixed> $row */
    private function purgeEligible(array $row, int $version): void
    {
        if ($this->holds->blocks(new EntityId($row['person_id']), new EntityId($row['application_id']))) {
            throw new ConcurrentModificationException('A case or person hold protects this document from removal.');
        }
        if (
            ($this->metadata($row)['evidence_decisions'] ?? []) !== []
            || $this->decisions->retainsDocument(
                $this->admissions->application(new EntityId($row['application_id'])),
                new EntityId($row['id'])
            )
        ) {
            throw new ConcurrentModificationException('This file is retained admissions-decision evidence.');
        }
        if (
            (int) $row['version'] !== $version || (int) $row['held'] !== 0 || $row['expires_at'] > $this->at()
            || in_array($row['status'], ['purging', 'purged'], true)
        ) {
            throw new ConcurrentModificationException('Review an expired document without a hold before removal.');
        }
    }
    public function purge(EntityId $application, EntityId $id, #[\SensitiveParameter] string $token): void
    {
        $actor = $this->retainAuthority($application);
        try {
            if (strlen($token) > 20000) {
                throw new DomainException();
            }
            $p = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || ($p['kind'] ?? null) !== 'admission-document-purge'
                || ($p['application'] ?? null) !== $application->value || ($p['id'] ?? null) !== $id->value
                || ($p['actor'] ?? null) !== $actor->value || !is_int($p['version'] ?? null)
                || !is_int($p['expires'] ?? null) || !is_string($p['reason'] ?? null)
            ) {
                throw new DomainException();
            }
        } catch (Throwable) {
            throw new DomainException('Review this document disposal again.');
        }
        $route = $this->admissions->application($application);
        $this->transactions->run(function () use ($route, $application, $id, $p, $token, $actor): void {
            $this->permissions->lock();
            $this->admissions->lockPerson($route->personId);
            $this->documents->lock($id);
            $row = $this->ownedRow($application, $id);
            $this->retainAuthority($application, $actor);
            if (in_array($row['status'], ['purging', 'purged'], true)) {
                if (!hash_equals($this->metadata($row)['receipt'] ?? '', hash('sha256', $token))) {
                    throw new ConcurrentModificationException('This document already has another disposition.');
                }
                $this->retainAuthority($application, $actor);
                return;
            }
            $source = $this->metadata($row);
            $this->purgeEligible($row, $p['version']);
            if ($p['expires'] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException('Review this document disposal again.');
            }
            $meta = ['document_id' => $id->value, 'application_id' => $application->value,
                'receipt' => hash('sha256', $token), 'reason' => $p['reason'],
                'upload_fingerprint' => $source['upload_fingerprint'],
                'actor' => $actor->value, 'at' => $this->at()];
            $changes = new AdmissionDocumentContent($this->cipher, $this->storage)->changes(
                $row,
                ['status' => 'purging', 'scan_token' => null],
                $meta
            );
            $this->documents->change($id, (int) $row['version'], $changes);
            $this->audit->record($actor, 'admissions.document_disposal_approved', $id);
            $this->retainAuthority($application, $actor);
        });
    }
}
