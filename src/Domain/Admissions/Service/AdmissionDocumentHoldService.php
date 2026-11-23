<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionDocumentHoldRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Audit\Repository\ActorDirectory;
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

/** Operational document preservation. A person hold covers admissions files across all their applications. */
final readonly class AdmissionDocumentHoldService
{
    public function __construct(
        private AdmissionDocumentHoldRepository $holds,
        private AdmissionsRepository $admissions,
        private AccessRepository $permissions,
        private MessageCipher $cipher,
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
            $actor->value !== $this->access->authenticatedUserId()->value
            || $this->access->requirePermission('sis:admissions:documents:retain')->value !== $actor->value
            || ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    /** Internal preservation check; callers authorize access and lock the canonical person before writes/deletion. */
    public function blocks(EntityId $person, EntityId $application): bool
    {
        $state = $this->state($person, $application);
        return $state['person']['active'] || $state['case']['active'];
    }

    /** @return array<string, mixed> */
    public function listing(EntityId $application, ?EntityId $before = null): array
    {
        $actor = $this->authorize();
        $person = $this->admissions->application($application)->personId;
        $state = $this->state($person, $application);
        $rows = $this->holds->history($person, $application, $before);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $history = [];
        foreach ($rows as $row) {
            $history[] = $this->describe($row, $person, $application);
        }
        $ids = [];
        foreach ([...$history, ...array_values($state)] as $item) {
            foreach (['placed_by', 'released_by'] as $field) {
                if (($item[$field] ?? null) !== null) {
                    $ids[$item[$field]] = new EntityId($item[$field]);
                }
            }
        }
        $names = [];
        foreach (array_chunk(array_values($ids), 20) as $chunk) {
            $names += $this->actors->names($chunk);
        }
        $label = static fn(array $item): array => $item + [
            'placed_by_name' => $names[$item['placed_by'] ?? ''] ?? null,
            'released_by_name' => $names[$item['released_by'] ?? ''] ?? null,
        ];
        $history = array_map($label, $history);
        $display = array_map($label, $state);
        $this->authorize($actor);
        return ['revision' => $this->revision($state), 'scopes' => $display, 'history' => $history,
            'next_cursor' => $more ? $rows[array_key_last($rows)]['id'] : null];
    }

    /** @return array<string, mixed> */
    public function review(
        EntityId $application,
        string $scope,
        string $action,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        $person = $this->admissions->application($application)->personId;
        return $this->transactions->run(function () use (
            $application,
            $person,
            $scope,
            $action,
            $revision,
            $reason,
            $actor,
        ): array {
            $this->lock($person, $application, $actor);
            $state = $this->state($person, $application);
            $this->assess($state, $scope, $action, $revision);
            $operation = EntityId::generate();
            return ['operation_id' => $operation->value, 'scope' => $scope, 'action' => $action,
                'revision' => $revision, 'reason' => $reason,
                'review_token' => $this->proofs->issue(
                    'admission-document-hold',
                    $action,
                    $application,
                    $actor,
                    $operation,
                    $this->intent($scope, $action, $revision, $reason),
                )];
        });
    }

    /** @return array{id:string,active:bool} */
    public function confirm(
        EntityId $application,
        EntityId $operation,
        string $scope,
        string $action,
        string $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        $person = $this->admissions->application($application)->personId;
        return $this->transactions->run(function () use (
            $application,
            $operation,
            $person,
            $scope,
            $action,
            $revision,
            $reason,
            $token,
            $actor,
        ): array {
            $this->lock($person, $application, $actor);
            $intent = $this->intent($scope, $action, $revision, $reason);
            $receipt = $this->holds->receipt($operation);
            $this->proofs->validate(
                $token,
                'admission-document-hold',
                $action,
                $application,
                $actor,
                $operation,
                $intent,
                accepted: $receipt !== null,
            );
            if ($receipt !== null) {
                $meta = $this->metadata($receipt, $person, $application);
                $saved = $meta[$action === 'place' ? 'placement' : 'release'] ?? null;
                if (
                    !is_array($saved) || $saved['actor'] !== $actor->value
                    || $saved['application'] !== $application->value || !hash_equals($saved['intent'], $intent)
                ) {
                    throw new AccessDeniedException();
                }
                $this->authorize($actor);
                return ['id' => $receipt['id'], 'active' => $action === 'place'];
            }
            $state = $this->state($person, $application);
            $this->assess($state, $scope, $action, $revision);
            $at = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            $decision = ['actor' => $actor->value, 'application' => $application->value,
                'intent' => $intent, 'reason' => $reason];
            if ($action === 'place') {
                $row = ['id' => $operation->value, 'person_id' => $person->value,
                    'application_id' => $scope === 'case' ? $application->value : null,
                    'scope_sequence' => $state[$scope]['sequence'] + 1, 'version' => 1,
                    'active' => 1, 'created_at' => $at, 'released_at' => null, 'release_operation_id' => null];
                $meta = ['placement' => $decision, 'release' => null];
                $row['protected_metadata'] = $this->protect($row, $meta);
                $this->holds->add($row);
            } else {
                $row = $this->holds->latest($person, $scope === 'case' ? $application : null);
                $meta = $this->metadata($row, $person, $application);
                $meta['release'] = $decision;
                $row = array_replace($row, ['active' => 0, 'version' => 2,
                    'released_at' => $at, 'release_operation_id' => $operation->value]);
                $row['protected_metadata'] = $this->protect($row, $meta);
                $this->holds->release(new EntityId($row['id']), [
                    'active' => 0, 'version' => 2, 'released_at' => $at,
                    'release_operation_id' => $operation->value, 'protected_metadata' => $row['protected_metadata'],
                ]);
            }
            $expected = $this->revision($this->state($person, $application));
            $this->audit->record(
                $actor,
                'admissions.document_hold_' . ($action === 'place' ? 'placed' : 'released'),
                new EntityId($row['id'])
            );
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                'admission-document-hold',
                $action,
                $application,
                $actor,
                $operation,
                $intent,
            );
            if (
                $this->holds->receipt($operation) != $row
                || !hash_equals($expected, $this->revision($this->state($person, $application)))
                || $this->admissions->application($application)->personId->value !== $person->value
            ) {
                throw new ConcurrentModificationException('The document hold changed during confirmation.');
            }
            return ['id' => $row['id'], 'active' => $action === 'place'];
        });
    }

    private function lock(EntityId $person, EntityId $application, EntityId $actor): void
    {
        $this->permissions->lock();
        $this->admissions->lockPerson($person);
        $this->authorize($actor);
        if ($this->admissions->application($application)->personId->value !== $person->value) {
            throw new ConcurrentModificationException('Reload the current application before changing holds.');
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

    private function intent(
        string $scope,
        string $action,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): string {
        if (
            !in_array($scope, ['case', 'person'], true) || !in_array($action, ['place', 'release'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1
        ) {
            throw new ValidationException([['field' => 'hold', 'message' => 'Review a valid document hold action.']]);
        }
        return hash('sha256', json_encode([$scope, $action, $revision, $reason], JSON_THROW_ON_ERROR));
    }

    private function assess(#[\SensitiveParameter] array $state, string $scope, string $action, string $revision): void
    {
        $this->intent($scope, $action, $revision, 'validate');
        if (
            !hash_equals($this->revision($state), $revision)
            || $state[$scope]['active'] !== ($action === 'release')
            || ($action === 'place' && $state[$scope]['sequence'] >= 2147483647)
        ) {
            throw new ConcurrentModificationException('The document holds changed. Refresh and review again.');
        }
    }

    private function revision(#[\SensitiveParameter] array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function state(EntityId $person, EntityId $application): array
    {
        $result = [];
        foreach (['person' => null, 'case' => $application] as $scope => $parent) {
            $row = $this->holds->latest($person, $parent);
            $result[$scope] = $row === null ? ['id' => null, 'active' => false, 'sequence' => 0]
            : $this->describe($row, $person, $application);
        }
        return $result;
    }

    private function describe(array $row, EntityId $person, EntityId $application): array
    {
        $meta = $this->metadata($row, $person, $application);
        return ['id' => $row['id'], 'scope' => $row['application_id'] === null ? 'person' : 'case',
            'active' => (int) $row['active'] === 1, 'sequence' => (int) $row['scope_sequence'],
            'created_at' => $row['created_at'], 'released_at' => $row['released_at'],
            'placed_by' => $meta['placement']['actor'], 'reason' => $meta['placement']['reason'],
            'released_by' => $meta['release']['actor'] ?? null, 'release_reason' => $meta['release']['reason'] ?? null];
    }

    private function routing(array $row): array
    {
        return ['id' => $row['id'], 'person_id' => $row['person_id'], 'application_id' => $row['application_id'],
            'active' => (int) $row['active'], 'version' => (int) $row['version'],
            'scope_sequence' => (int) $row['scope_sequence'], 'created_at' => $row['created_at'],
            'released_at' => $row['released_at'], 'release_operation_id' => $row['release_operation_id']];
    }

    private function protect(array $row, #[\SensitiveParameter] array $meta): string
    {
        $meta['routing'] = $this->routing($row);
        return $this->cipher->seal(json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function metadata(array $row, EntityId $person, EntityId $application): array
    {
        try {
            if (
                $row['person_id'] !== $person->value
                || ($row['application_id'] !== null && $row['application_id'] !== $application->value)
                || strlen($row['protected_metadata']) > 65536
            ) {
                throw new DomainException();
            }
            $meta = json_decode($this->cipher->open($row['protected_metadata']), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($meta) || ($meta['routing'] ?? null) !== $this->routing($row)) {
                throw new DomainException();
            }
            if (
                !in_array((int) $row['active'], [0, 1], true)
                || (int) $row['version'] !== ((int) $row['active'] === 1 ? 1 : 2)
                || (int) $row['scope_sequence'] < 1 || (int) $row['scope_sequence'] > 2147483647
                || ((int) $row['active'] === 1) !== ($row['released_at'] === null)
                || ((int) $row['active'] === 1) !== ($row['release_operation_id'] === null)
            ) {
                throw new DomainException();
            }
            foreach (['placement', 'release'] as $key) {
                $decision = $meta[$key] ?? null;
                if ($key === 'release' && (int) $row['active'] === 1 && $decision === null) {
                    continue;
                }
                if (
                    !is_array($decision) || !is_string($decision['actor'] ?? null)
                    || !is_string($decision['application'] ?? null) || !is_string($decision['intent'] ?? null)
                    || !is_string($decision['reason'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $decision['intent']) !== 1
                    || $this->reason($decision['reason']) !== $decision['reason']
                ) {
                    throw new DomainException();
                }
                new EntityId($decision['actor']);
                new EntityId($decision['application']);
            }
            return $meta;
        } catch (Throwable) {
            throw new DomainException(
                'Protected document holds need institutional review. No disposal was authorized.',
            );
        }
    }
}
