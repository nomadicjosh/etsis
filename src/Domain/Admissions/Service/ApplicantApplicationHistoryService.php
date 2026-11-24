<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeZone;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\MatriculationId;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;

/** Owner-only curated admissions facts. Private office evidence and addressed letters are excluded. */
final readonly class ApplicantApplicationHistoryService
{
    public function __construct(
        private AdmissionsRepository $admissions,
        private PersonAccountRepository $people,
        private StudentRecordRepository $records,
        private AdmissionDecisionLedger $ledger,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function page(EntityId $id, ?string $cursor = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $route = $this->admissions->application($id);
        $this->owner($route, $actor);
        return $this->transactions->run(function () use ($route, $actor, $cursor): array {
            $this->admissions->lockPerson($route->personId);
            $this->admissions->lockApplication($route->id);
            $a = $this->admissions->application($route->id);
            $this->owner($a, $actor);
            if ($a->personId->value !== $route->personId->value) {
                throw new ConcurrentModificationException('The application changed. Reload its history.');
            }
            $heads = $this->heads($a);
            $current = $this->current($a);
            $timeline = $this->ledger->timeline($a, $cursor);
            $items = [];
            foreach ($timeline['items'] as $item) {
                // Letters have their own addressed-owner checks and response workflow. Never transfer them here.
                if (str_starts_with($item['kind'], 'offer_')) {
                    continue;
                }
                $view = ['kind' => $item['kind'], 'status' => $item['status'],
                    'effective_at' => $item['effective_at'], 'recorded_at' => $item['recorded_at']];
                if ($item['source'] !== null) {
                    $view['source'] = $this->source($item['source']);
                }
                if ($item['program'] !== null) {
                    $view['program'] = ['before' => $this->program($item['program']['before']),
                        'after' => $this->program($item['program']['after'])];
                }
                if ($item['entry'] !== null) {
                    $view['entry'] = ['before' => $this->term($item['entry']['before']),
                        'after' => $this->term($item['entry']['after'])];
                }
                $items[] = $view;
            }
            $submission = $timeline['submission'];
            $result = ['current' => $current,
                'original' => $submission === null ? null : $this->source($submission['source'])
                    + ['effective_at' => $submission['effective_at'], 'recorded_at' => $submission['recorded_at']],
                'earlier_history_unknown' => $timeline['earlier_history_unknown'],
                'items' => $items, 'next_cursor' => $timeline['next_cursor']];
            $this->audit->record($actor, 'admissions.owner_history_read', $a->id);
            $after = $this->admissions->application($a->id);
            $this->owner($after, $actor);
            if ($after != $a || $this->current($after) !== $current || $this->heads($after) !== $heads) {
                throw new ConcurrentModificationException('The application changed. Reload its history.');
            }
            return $result + ['checked_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM)];
        });
    }

    private function owner(AdmissionApplication $a, EntityId $actor): void
    {
        $person = $this->people->findByUser($actor);
        if (
            $this->access->authenticatedUserId()->value !== $actor->value
            || $person?->ownerUserId?->value !== $actor->value || $person->id->value !== $a->personId->value
        ) {
            throw new AccessDeniedException('This account does not own the application.');
        }
    }

    private function current(AdmissionApplication $a): array
    {
        return ['status' => $a->status->value, 'program' => $this->program($this->admissions->program($a->programId)),
            'entry_term' => ['code' => $this->records->term($a->entryTermId)['code']]];
    }

    /** Verify the head again after the read/audit, without another full replay. */
    private function heads(AdmissionApplication $a): array
    {
        $decision = new StreamReference(new AdmissionDecisionId($a->id->value), 'admission_decision');
        $matriculation = new StreamReference(new MatriculationId($a->id->value), 'matriculation');
        return [$this->history->latest($decision)?->hash, $this->history->latest($matriculation)?->hash];
    }

    private function source(array $source): array
    {
        return ['program' => ['code' => $source['program_code'], 'title' => $source['program_title']],
            'entry_term' => ['code' => $source['entry_term_code']]];
    }

    private function program(array $program): array
    {
        return ['code' => $program['code'], 'title' => $program['title']];
    }

    private function term(array $term): array
    {
        return ['code' => $term['code'], 'name' => $term['name'], 'starts_on' => $term['starts_on'],
            'ends_on' => $term['ends_on'], 'academic_year' => $term['year_code']];
    }
}
