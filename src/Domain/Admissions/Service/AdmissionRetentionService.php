<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionDocumentRepository;
use Domain\Admissions\Repository\AdmissionRetentionRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\ValueObject\AdmissionRetentionDefinition;
use Domain\Audit\Repository\ActorDirectory;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use Throwable;

/** Audited operational policies and retained file classifications; no academic events or automated disposal. */
final readonly class AdmissionRetentionService
{
    public function __construct(
        private AdmissionRetentionRepository $repository,
        private AdmissionDocumentRepository $documents,
        private AdmissionsRepository $admissions,
        private AdmissionDocumentContent $content,
        private AdmissionDocumentPolicy $deployment,
        private MessageCipher $cipher,
        private AccessRepository $permissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private ActorDirectory $actors,
    ) {
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:admissions:manage');
        if (
            $this->access->authenticatedUserId()->value !== $actor->value
            || $this->access->requirePermission('sis:admissions:documents:retain')->value !== $actor->value
            || ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function at(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function configuration(): array
    {
        $actor = $this->authorize();
        $data = $this->current();
        $data['new_ids'] = [];
        for ($i = count($data['definition']['categories']); $i < AdmissionRetentionDefinition::LIMIT; $i++) {
            $data['new_ids'][] = EntityId::generate()->value;
        }
        $data['deployment_days'] = $this->deployment->retentionDays;
        $data['uploads_enabled'] = $this->deployment->acceptsUploads();
        $this->authorize($actor);
        return $data;
    }

    private function current(): array
    {
        $row = $this->repository->current();
        if ($row === null) {
            return ['id' => null, 'revision' => 0, 'definition' => new AdmissionRetentionDefinition()->data(),
                'created_at' => null];
        }
        $meta = $this->policyMetadata($row);
        return ['id' => $row['id'], 'revision' => (int) $row['revision'],
            'definition' => $meta['definition'], 'created_at' => $row['created_at']];
    }

    public function reviewPolicy(
        AdmissionRetentionDefinition $definition,
        int $revision,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use ($definition, $revision, $reason, $actor): array {
            $this->permissions->lock();
            $this->authorize($actor);
            $this->policySource($definition, $revision);
            $operation = EntityId::generate();
            $this->authorize($actor);
            return ['operation_id' => $operation->value, 'revision' => $revision,
                'definition' => $definition->data(), 'reason' => $reason,
                'review_token' => $this->proofs->issue(
                    'admission-retention-policy',
                    'publish',
                    $operation,
                    $actor,
                    $operation,
                    $this->policyIntent($definition, $revision, $reason)
                )];
        });
    }

    public function publishPolicy(
        EntityId $operation,
        AdmissionRetentionDefinition $definition,
        int $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use (
            $operation,
            $definition,
            $revision,
            $reason,
            $token,
            $actor
        ): array {
            $this->permissions->lock();
            $this->authorize($actor);
            $intent = $this->policyIntent($definition, $revision, $reason);
            $receipt = $this->repository->policy($operation);
            $this->proofs->validate(
                $token,
                'admission-retention-policy',
                'publish',
                $operation,
                $actor,
                $operation,
                $intent,
                accepted: $receipt !== null
            );
            if ($receipt !== null) {
                $meta = $this->policyMetadata($receipt);
                $this->receipt($meta, $actor, $intent);
                return ['id' => $operation->value, 'revision' => (int) $receipt['revision']];
            }
            $this->policySource($definition, $revision);
            $row = ['id' => $operation->value, 'revision' => $revision + 1, 'created_at' => $this->at()];
            $row['protected_metadata'] = $this->seal($row, ['actor' => $actor->value, 'intent' => $intent,
                'reason' => $reason, 'definition' => $definition->data()]);
            $this->repository->addPolicy($row);
            $this->audit->record($actor, 'admissions.document_retention_policy_published', $operation);
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                'admission-retention-policy',
                'publish',
                $operation,
                $actor,
                $operation,
                $intent
            );
            if ($this->repository->current() != $row) {
                throw new ConcurrentModificationException('The retention policy changed during confirmation.');
            }
            return ['id' => $operation->value, 'revision' => $revision + 1];
        });
    }

    private function policySource(AdmissionRetentionDefinition $definition, int $revision): void
    {
        $current = $this->current();
        if ($revision < 0 || $revision >= 2000000000 || $current['revision'] !== $revision) {
            throw new ConcurrentModificationException('Reload and review the current retention policy.');
        }
        $definition->retains(AdmissionRetentionDefinition::fromArray($current['definition']));
    }

    private function policyIntent(
        AdmissionRetentionDefinition $definition,
        int $revision,
        #[\SensitiveParameter] string $reason,
    ): string {
        return $this->hash([$definition->data(), $revision, $reason]);
    }

    public function policies(?int $before = null): array
    {
        $actor = $this->authorize();
        if ($before !== null && $before < 1) {
            throw new ValidationException([['field' => 'before', 'message' => 'Choose a valid policy page.']]);
        }
        $rows = $this->repository->policies($before);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $items = [];
        foreach ($rows as $row) {
            $meta = $this->policyMetadata($row);
            $items[] = ['id' => $row['id'], 'revision' => (int) $row['revision'], 'recorded_at' => $row['created_at'],
                'actor' => $meta['actor'], 'reason' => $meta['reason'], 'definition' => $meta['definition']];
        }
        $this->names($items);
        $this->authorize($actor);
        return ['items' => $items, 'next_cursor' => $more ? (int) $rows[array_key_last($rows)]['revision'] : null];
    }

    /** Internal upload policy read. The caller authorizes ownership and holds the shared permission lock. */
    public function uploadBasis(): array
    {
        return $this->basis($this->current());
    }

    private function basis(array $policy, ?string $category = null): array
    {
        $definition = AdmissionRetentionDefinition::fromArray($policy['definition']);
        $category ??= $definition->defaultId;
        $choice = $category === null ? null : $definition->category($category);
        return \Domain\Admissions\ValueObject\DocumentRetentionBasis::fromArray([
            'source' => $choice === null ? 'deployment' : 'category', 'policy_id' => $policy['id'],
            'policy_revision' => $policy['revision'], 'days' => $choice['days'] ?? $this->deployment->retentionDays,
            'category' => $choice === null ? null : array_intersect_key($choice, array_flip(['id', 'code', 'title'])),
        ])->data();
    }

    /** Internal atomic upload capture. Retries are handled by the upload's original fingerprint. */
    public function recordUpload(array $document, EntityId $actor, array $basis): void
    {
        $row = $this->classificationRow(new EntityId($document['id']), $document);
        $row['protected_metadata'] = $this->seal($row, ['source' => 'upload', 'actor' => $actor->value,
            'intent' => $this->hash(['upload', $document['id']]), 'reason' => null, 'before' => null,
            'after' => $basis, 'expires_before' => null, 'expires_after' => $document['expires_at']]);
        $this->repository->addClassification($row);
    }

    public function reviewClassification(
        EntityId $application,
        EntityId $document,
        int $version,
        EntityId $category,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use (
            $application,
            $document,
            $version,
            $category,
            $reason,
            $actor
        ): array {
            $row = $this->lockDocument($application, $document, $actor);
            $context = $this->classificationContext($row, $version, $category);
            $operation = EntityId::generate();
            $revision = $this->hash($context);
            $intent = $this->classificationIntent($application, $document, $version, $category, $reason, $revision);
            $this->authorize($actor);
            return ['application_id' => $application->value, 'document_id' => $document->value,
                'operation_id' => $operation->value, 'version' => $version, 'category_id' => $category->value,
                'reason' => $reason, 'revision' => $revision, 'before' => $context['before'],
                'after' => $context['after'], 'expires_before' => $row['expires_at'],
                'expires_after' => $context['expires_after'], 'review_token' => $this->proofs->issue(
                    'admission-document-category',
                    'classify',
                    $document,
                    $actor,
                    $operation,
                    $intent
                )];
        });
    }

    public function classify(
        EntityId $application,
        EntityId $document,
        EntityId $operation,
        int $version,
        EntityId $category,
        #[\SensitiveParameter] string $reason,
        string $revision,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use (
            $application,
            $document,
            $operation,
            $version,
            $category,
            $reason,
            $revision,
            $token,
            $actor
        ): array {
            $row = $this->lockDocument($application, $document, $actor);
            $intent = $this->classificationIntent($application, $document, $version, $category, $reason, $revision);
            $receipt = $this->repository->classification($operation);
            $this->proofs->validate(
                $token,
                'admission-document-category',
                'classify',
                $document,
                $actor,
                $operation,
                $intent,
                accepted: $receipt !== null
            );
            if ($receipt !== null) {
                $meta = $this->classificationMetadata($receipt, $row);
                $this->receipt($meta, $actor, $intent);
                return ['id' => $document->value, 'version' => (int) $receipt['document_version']];
            }
            $context = $this->classificationContext($row, $version, $category);
            if (!hash_equals($this->hash($context), $revision)) {
                throw new ConcurrentModificationException('The file or retention policy changed. Review again.');
            }
            $meta = $this->content->metadata($row);
            // Preserve the actual current cutoff on older files that used one shared expiry field.
            $meta['applicant_expires_at'] ??= $row['expires_at'];
            $meta['retention_basis'] = $context['after'];
            $changes = $this->content->changes($row, ['expires_at' => $context['expires_after']], $meta);
            $this->documents->change($document, $version, $changes);
            $decision = $this->classificationRow($operation, array_replace($row, ['version' => $version + 1]));
            $decision['protected_metadata'] = $this->seal($decision, ['source' => 'staff', 'actor' => $actor->value,
                'intent' => $intent, 'reason' => $reason, 'before' => $context['before'], 'after' => $context['after'],
                'expires_before' => $row['expires_at'], 'expires_after' => $context['expires_after']]);
            $this->repository->addClassification($decision);
            $this->audit->record($actor, 'admissions.document_category_assigned', $document);
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                'admission-document-category',
                'classify',
                $document,
                $actor,
                $operation,
                $intent
            );
            if (
                $this->documents->find($document) != array_replace($row, $changes, ['version' => $version + 1])
                || $this->repository->classification($operation) != $decision
                || $this->current()['id'] !== $context['after']['policy_id']
                || $this->admissions->application($application)->personId->value !== $row['person_id']
            ) {
                throw new ConcurrentModificationException('The retention basis changed during confirmation.');
            }
            return ['id' => $document->value, 'version' => $version + 1];
        });
    }

    private function lockDocument(EntityId $application, EntityId $document, EntityId $actor): array
    {
        $this->permissions->lock();
        $person = $this->admissions->application($application)->personId;
        $this->admissions->lockPerson($person);
        $this->documents->lock($document);
        $this->authorize($actor);
        $row = $this->documents->find($document);
        if (
            $row === null || $row['application_id'] !== $application->value || $row['person_id'] !== $person->value
            || $this->admissions->application($application)->personId->value !== $person->value
        ) {
            throw new AccessDeniedException();
        }
        $this->content->metadata($row);
        return $row;
    }

    private function classificationContext(array $row, int $version, EntityId $category): array
    {
        if ((int) $row['version'] !== $version || in_array($row['status'], ['purging', 'purged'], true)) {
            throw new ConcurrentModificationException('Reload a retained document before classifying it.');
        }
        $basis = $this->basis($this->current(), $category->value);
        $expires = new DateTimeImmutable($row['created_at'])->modify('+' . $basis['days'] . ' days')
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
        return ['source' => hash('sha256', $row['protected_metadata']),
            'before' => $this->content->metadata($row)['retention_basis'] ?? null,
            'after' => $basis, 'expires_after' => max($expires, $row['expires_at'])];
    }

    private function classificationIntent(
        EntityId $application,
        EntityId $document,
        int $version,
        EntityId $category,
        #[\SensitiveParameter] string $reason,
        string $revision,
    ): string {
        return $this->hash([$application->value, $document->value, $version, $category->value, $reason, $revision]);
    }

    private function classificationRow(EntityId $operation, array $document): array
    {
        return ['id' => $operation->value, 'document_id' => $document['id'],
            'application_id' => $document['application_id'], 'person_id' => $document['person_id'],
            'document_version' => (int) $document['version'], 'recorded_at' => $this->at()];
    }

    public function history(EntityId $application, EntityId $document, ?EntityId $before = null): array
    {
        $actor = $this->authorize();
        $row = $this->documents->find($document);
        if (
            $row === null || $row['application_id'] !== $application->value
            || $row['person_id'] !== $this->admissions->application($application)->personId->value
        ) {
            throw new AccessDeniedException();
        }
        $this->content->metadata($row);
        $rows = $this->repository->classifications($document, $before);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $items = [];
        foreach ($rows as $decision) {
            $meta = $this->classificationMetadata($decision, $row);
            $items[] = array_intersect_key($meta, array_flip([
                'source', 'actor', 'reason', 'before', 'after', 'expires_before', 'expires_after',
            ])) + ['id' => $decision['id'], 'recorded_at' => $decision['recorded_at']];
        }
        $this->names($items);
        $this->authorize($actor);
        if ($this->admissions->application($application)->personId->value !== $row['person_id']) {
            throw new ConcurrentModificationException('The document parent changed. Reload the application.');
        }
        return ['items' => $items, 'next_cursor' => $more ? $rows[array_key_last($rows)]['id'] : null];
    }

    private function policyMetadata(array $row): array
    {
        $meta = $this->open($row);
        AdmissionRetentionDefinition::fromArray($meta['definition']);
        if ((int) $row['revision'] < 1 || (int) $row['revision'] > 2000000000) {
            throw new DomainException('Protected retention configuration needs institutional review.');
        }
        return $meta;
    }

    private function classificationMetadata(array $row, array $document): array
    {
        if (
            $row['document_id'] !== $document['id'] || $row['application_id'] !== $document['application_id']
            || $row['person_id'] !== $document['person_id']
        ) {
            throw new AccessDeniedException();
        }
        $meta = $this->open($row);
        if (
            !in_array($meta['source'] ?? null, ['upload', 'staff'], true)
            || !is_array($meta['after'] ?? null) || !array_key_exists('before', $meta)
            || !is_string($meta['intent'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $meta['intent']) !== 1
        ) {
            throw new DomainException('Protected classification history needs institutional review.');
        }
        \Domain\Admissions\ValueObject\DocumentRetentionBasis::fromArray($meta['after']);
        if ($meta['before'] !== null) {
            \Domain\Admissions\ValueObject\DocumentRetentionBasis::fromArray($meta['before']);
        }
        if ($meta['source'] === 'staff') {
            $this->reason($meta['reason']);
        }
        return $meta;
    }

    private function seal(array $row, #[\SensitiveParameter] array $meta): string
    {
        $meta['routing'] = $row;
        return $this->cipher->seal(json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function open(array $row): array
    {
        try {
            if (strlen($row['protected_metadata']) > 262144) {
                throw new DomainException();
            }
            $meta = json_decode($this->cipher->open($row['protected_metadata']), true, 16, JSON_THROW_ON_ERROR);
            unset($row['protected_metadata']);
            foreach (['revision', 'document_version'] as $field) {
                if (isset($row[$field])) {
                    $row[$field] = (int) $row[$field];
                }
            }
            if (!is_array($meta) || ($meta['routing'] ?? null) !== $row || !is_string($meta['actor'] ?? null)) {
                throw new DomainException();
            }
            new EntityId($meta['actor']);
            return $meta;
        } catch (Throwable) {
            throw new DomainException('Protected document retention needs institutional review.');
        }
    }

    private function receipt(array $meta, EntityId $actor, string $intent): void
    {
        if ($meta['actor'] !== $actor->value || !hash_equals($meta['intent'], $intent)) {
            throw new AccessDeniedException();
        }
        $this->authorize($actor);
    }

    private function names(array &$items): void
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[$item['actor']] = new EntityId($item['actor']);
        }
        $names = $this->actors->names(array_values($ids));
        foreach ($items as &$item) {
            $item['actor_name'] = $names[$item['actor']] ?? null;
        }
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        if (
            trim($reason) === '' || mb_strlen($reason) > 1000 || !mb_check_encoding($reason, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $reason)
        ) {
            throw new ValidationException([
                ['field' => 'reason', 'message' => 'Enter a reason up to 1,000 characters.'],
            ]);
        }
        return trim($reason);
    }

    private function hash(#[\SensitiveParameter] array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
