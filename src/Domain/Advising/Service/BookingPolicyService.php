<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Advising\BookingPolicyRevision;
use Domain\Advising\BookingPolicySnapshot;
use Domain\Advising\Repository\BookingPolicyRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

final readonly class BookingPolicyService
{
    public const string PERMISSION = 'sis:advising:policy';
    public const string SCOPE = '00000000000000000000000000';

    public function __construct(
        private BookingPolicyRepository $records,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs
    ) {
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission(self::PERMISSION);
        if (
            ($expected !== null && $expected->value !== $actor->value)
            || $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    /** Internal operational read: the appointment caller must first authorize its owner/staff/advisor scope. */
    public function snapshot(): BookingPolicySnapshot
    {
        return $this->records->current()?->snapshot() ?? BookingPolicySnapshot::defaults();
    }

    public function assertCurrent(BookingPolicySnapshot $snapshot): void
    {
        if ($this->snapshot()->facts() !== $snapshot->facts()) {
            throw new ConcurrentModificationException(trans(
                'Appointment booking policy changed. Refresh the current rules.'
            ));
        }
    }

    public function view(): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($actor): array {
            $current = $this->snapshot();
            $this->audit->record($actor, 'advising.booking_policy_read', new EntityId(self::SCOPE));
            $this->assertCurrent($current);
            $this->authorize($actor);
            return ['current' => $current->facts(), 'defaults' => BookingPolicySnapshot::DEFAULTS];
        });
    }

    public function review(array $rules, int $version, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($rules, $version, $reason, $actor): array {
            $current = $this->snapshot();
            if ($current->revision !== $version) {
                throw new ConcurrentModificationException(trans('Reload booking policy before reviewing changes.'));
            }
            $id = EntityId::generate();
            $next = BookingPolicyRevision::create($id, $version, $rules, $actor, $reason, $this->now());
            $this->audit->record($actor, 'advising.booking_policy_reviewed', new EntityId(self::SCOPE));
            $this->assertCurrent($current);
            $this->authorize($actor);
            return ['id' => $id->value, 'version' => $version, 'before' => $current->facts(),
                'rules' => $next->snapshot()->rules, 'reason' => $next->facts()['reason'],
                'review_token' => $this->proofs->issue(
                    'advising.booking_policy',
                    'save',
                    new EntityId(self::SCOPE),
                    $actor,
                    $id,
                    $next->facts()['receipt']['intent']
                )];
        });
    }

    public function save(
        EntityId $id,
        array $rules,
        int $version,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($id, $rules, $version, $reason, $token, $actor): array {
            $this->roles->lock();
            $this->authorize($actor);
            $old = $this->records->find($id);
            $intent = BookingPolicyRevision::intent($id, $version, $rules, $reason);
            $this->proofs->validate(
                $token,
                'advising.booking_policy',
                'save',
                new EntityId(self::SCOPE),
                $actor,
                $id,
                $intent,
                $old !== null
            );
            if ($old !== null) {
                $result = $old->retry($actor, $intent);
                $next = $old;
            } else {
                $current = $this->snapshot();
                if ($current->revision !== $version) {
                    throw new ConcurrentModificationException(trans(
                        'Booking policy changed after review. Review the current rules.'
                    ));
                }
                $next = BookingPolicyRevision::create($id, $version, $rules, $actor, $reason, $this->now());
                $this->records->save($next);
                $this->audit->record($actor, 'advising.booking_policy_saved', $id);
                $this->assertCurrent($next->snapshot());
                $result = $next->retry($actor, $intent);
            }
            if ($this->records->find($id)?->facts() !== $next->facts()) {
                throw new ConcurrentModificationException(trans(
                    'Booking policy changed before completion. Refresh it.'
                ));
            }
            $this->authorize($actor);
            $this->proofs->validate(
                $token,
                'advising.booking_policy',
                'save',
                new EntityId(self::SCOPE),
                $actor,
                $id,
                $intent,
                $old !== null
            );
            return $result;
        });
    }

    public function history(?int $before = null): array
    {
        $actor = $this->authorize();
        if ($before !== null && ($before < 1 || $before > 1000001)) {
            throw new ValidationException([['field' => 'before',
                'message' => trans('Use a valid booking-policy history page.')]]);
        }
        return $this->transactions->run(function () use ($before, $actor): array {
            $rows = $this->records->page($before);
            $items = array_slice($rows, 0, 10);
            $this->audit->record($actor, 'advising.booking_policy_history_read', new EntityId(self::SCOPE));
            foreach ($items as $item) {
                if ($this->records->find(new EntityId($item->facts()['id']))?->facts() !== $item->facts()) {
                    throw new ConcurrentModificationException(trans('Booking-policy history changed. Refresh it.'));
                }
            }
            $this->authorize($actor);
            return ['items' => array_map(static fn(BookingPolicyRevision $r): array => $r->view(), $items),
                'next_before' => count($rows) > 10 ? end($items)->facts()['revision'] : null];
        });
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
