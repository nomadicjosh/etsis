<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Repository\BillingBatchRepository;
use Domain\Finance\ValueObject\TermFeeQuote;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

/** Review existing flat fees, then confirm exactly that bounded, actor-owned selection. */
final readonly class BillingBatchService
{
    public const int MAX_STUDENTS = 25;
    private const int MAX_CONTEXT_BYTES = 262144;
    private const string PURPOSE = 'finance.term-billing';
    private const array PERMISSIONS = ['sis:finance:post', 'sis:finance:read', 'sis:student:read'];

    public function __construct(
        private TermFeeBilling $billing,
        private BillingBatchRepository $batches,
        private AdmissionsRepository $admissions,
        private RegistrationRepository $registration,
        private CatalogRevisionRepository $catalog,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private MessageCipher $cipher,
        private FinancialCommandPolicy $commands,
    ) {
    }

    /** @param list<EntityId> $students
     * @return array<string,mixed> */
    public function review(EntityId $term, array $students, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->authorize();
        $reason = $this->commands->reason($reason);
        $students = $this->selection($students);
        $people = $this->people($students);
        return $this->transactions->run(function () use ($term, $students, $people, $reason, $actor): array {
            $this->lock($students, $people);
            [$context] = $this->context($term, $students);
            $this->actor($actor);
            $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $proof = ['purpose' => self::PURPOSE, 'operation_id' => EntityId::generate()->value,
                'actor_id' => $actor->value, 'reason' => $reason, 'context' => $context,
                'issued_at' => $now->format('Y-m-d\TH:i:s.uP'),
                'expires_at' => $now->modify('+20 minutes')->format('Y-m-d\TH:i:s.uP')];
            return $context + ['review_token' => $this->cipher->seal($this->encode($proof)),
                'expires_at' => $proof['expires_at']];
        });
    }

    /** @return array<string,mixed> */
    public function confirm(#[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        $proof = $this->proof($token, $actor);
        $operation = new EntityId($proof['operation_id']);
        $hash = hash('sha256', $token);
        if (($receipt = $this->batches->receipt($operation)) !== null) {
            return $this->replay($receipt, $actor, $hash);
        }
        $this->fresh($proof);
        $term = new EntityId($proof['context']['term']['id']);
        $students = $this->selection(array_values(array_map(
            static fn (array $row): EntityId => new EntityId($row['student_id']),
            $proof['context']['students']
        )));
        $people = $this->people($students);
        return $this->transactions->run(function () use (
            $proof,
            $operation,
            $term,
            $students,
            $people,
            $actor,
            $hash,
        ): array {
            // No consistent read precedes these ordered locks: a waiting original retry sees the committed receipt.
            $this->lock($students, $people);
            if (($receipt = $this->batches->receipt($operation)) !== null) {
                return $this->replay($receipt, $actor, $hash);
            }
            $this->fresh($proof);
            [$context, $quotes] = $this->context($term, $students);
            $this->actor($actor);
            $reviewedHash = hash('sha256', $this->encode($proof['context']));
            if (!hash_equals($reviewedHash, hash('sha256', $this->encode($context)))) {
                throw new ConcurrentModificationException(
                    'The fees, program selections or accounts changed. Review the billing batch again.'
                );
            }
            if ($context['blocked'] > 0) {
                throw new ValidationException([['field' => 'students',
                    'message' => 'Resolve every billing issue shown in the review before confirming.']]);
            }
            $items = [];
            foreach ($quotes as $quote) {
                $this->actor($actor);
                $items[] = $this->billing->apply($quote, $this->commands->context(
                    $actor,
                    $operation,
                    $proof['reason'],
                    $term
                ));
            }
            $completed = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
            $result = ['batch_id' => $operation->value, 'term_id' => $term->value,
                'completed_at' => $completed, 'posted' => array_sum(array_column($items, 'posted')),
                'accounts_created' => count(array_filter($items, static fn (array $r): bool => $r['created'])),
                'students' => $items, 'totals' => $context['totals']];
            $this->batches->save($operation, $actor, $term, $hash, $result, $completed);
            $this->audit->record($actor, 'billing.batch_completed', $operation);
            $this->actor($actor);
            $this->fresh($proof);
            return $result;
        });
    }

    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission('sis:finance:post');
        $this->actor($actor);
        return $actor;
    }

    private function actor(EntityId $actor): void
    {
        foreach (self::PERMISSIONS as $permission) {
            $this->commands->assertActor($actor, $permission);
        }
    }

    /** @param list<EntityId> $students
     * @return list<EntityId> */
    private function selection(array $students): array
    {
        if (count($students) < 1 || count($students) > self::MAX_STUDENTS) {
            throw new ValidationException([[
                'field' => 'students', 'message' => 'Select between one and 25 students.',
            ]]);
        }
        $unique = [];
        foreach ($students as $id) {
            if (isset($unique[$id->value])) {
                throw new ValidationException([['field' => 'students', 'message' => 'Select each student only once.']]);
            }
            $unique[$id->value] = $id;
        }
        ksort($unique, SORT_STRING);
        return array_values($unique);
    }

    /** @param list<EntityId> $students
     * @return list<EntityId> */
    private function people(array $students): array
    {
        $people = [];
        foreach ($students as $id) {
            $person = $this->admissions->student($id)->personId;
            $people[$person->value] = $person;
        }
        ksort($people, SORT_STRING);
        return array_values($people);
    }

    /** @param list<EntityId> $students
     * @param list<EntityId> $people */
    private function lock(array $students, array $people): void
    {
        foreach ($students as $id) {
            $this->registration->lockStudent($id);
        }
        foreach ($people as $id) {
            $this->registration->lockPerson($id);
        }
    }

    /** @param list<EntityId> $students
     * @return array{array<string,mixed>,list<TermFeeQuote>} */
    private function context(EntityId $term, array $students): array
    {
        $budget = new HistoryReadBudget();
        $termRow = $this->catalog->get('terms', $term);
        $programs = [];
        foreach ($students as $id) {
            $program = $this->admissions->student($id)->programId;
            $programs[$program->value] = $program;
        }
        $rules = $this->billing->rules($term, $budget, array_values($programs));
        $programLabels = [];
        foreach ($programs as $program) {
            $reference = $this->catalog->get('programs', $program);
            $programLabels[$program->value] = $reference['code'];
        }
        $definitions = $rows = $quotes = $totals = [];
        $blocked = $new = $lineCount = 0;
        foreach ($students as $id) {
            $quote = $this->billing->quote($id, $term, $rules, $budget);
            $facts = $quote->facts();
            $lineCount += count($facts['items']);
            if ($lineCount > 2000) {
                throw new ValidationException([['field' => 'students',
                    'message' => 'This review has too many fee lines. Select a smaller student group.']]);
            }
            $person = $this->admissions->person($this->admissions->student($id)->personId);
            $rows[] = $facts + ['name' => $person->givenName . ' ' . $person->familyName,
                'institutional_id' => $person->institutionalId->value,
                'program_label' => $programLabels[$facts['program_id']]];
            $quotes[] = $quote;
            $blocked += (int) ($facts['problems'] !== []);
            $new += $facts['new_charges'];
            if ($facts['problems'] === [] && $facts['new_charges'] > 0) {
                $currency = $facts['currency'];
                $previous = $totals[$currency] ?? 0;
                if ($facts['charge_minor'] > PHP_INT_MAX - $previous) {
                    throw new ValidationException([[
                        'field' => 'students', 'message' => 'This total exceeds the calculation limit.',
                    ]]);
                }
                $totals[$currency] = $previous + $facts['charge_minor'];
            }
            foreach ($quote->rules as $rule) {
                $definitions[(string) $rule->aggregateId()] = ['id' => (string) $rule->aggregateId(),
                    'version' => $rule->playhead(), 'name' => $rule->name(), 'kind' => $rule->kind(),
                    'minor' => $rule->amount()->minor, 'currency' => $rule->amount()->currency,
                    'program_id' => $rule->programId()?->value];
            }
        }
        ksort($definitions, SORT_STRING);
        ksort($totals, SORT_STRING);
        $totals = array_map(
            static fn (string $currency, int $minor): array => ['currency' => $currency, 'minor' => $minor],
            array_keys($totals),
            array_values($totals)
        );
        return [['term' => ['id' => $term->value, 'code' => $termRow['code'],
            'starts_on' => $termRow['starts_on'], 'ends_on' => $termRow['ends_on']],
            'rules' => array_values($definitions), 'students' => $rows, 'blocked' => $blocked,
            'new_charges' => $new, 'totals' => $totals], $quotes];
    }

    /** @param array<string,mixed> $data */
    private function encode(#[\SensitiveParameter] array $data): string
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_CONTEXT_BYTES) {
            throw new ValidationException([[
                'field' => 'students', 'message' => 'This review is too large. Select a smaller student group.',
            ]]);
        }
        return $encoded;
    }

    /** @return array<string,mixed> */
    private function proof(#[\SensitiveParameter] string $token, EntityId $actor): array
    {
        try {
            if ($token === '' || strlen($token) > 600000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 32, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || ($p['purpose'] ?? '') !== self::PURPOSE || !is_string($p['actor_id'] ?? null)
                || !is_string($p['operation_id'] ?? null) || !is_string($p['reason'] ?? null)
                || !is_string($p['issued_at'] ?? null) || !is_string($p['expires_at'] ?? null)
                || !is_array($p['context']['students'] ?? null) || count($p['context']['students']) < 1
                || count($p['context']['students']) > self::MAX_STUDENTS
                || !array_is_list($p['context']['students'])
                || !is_string($p['context']['term']['id'] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            new EntityId($p['operation_id']);
            new EntityId($p['context']['term']['id']);
            foreach ($p['context']['students'] as $row) {
                if (!is_array($row) || !is_string($row['student_id'] ?? null)) {
                    throw new \InvalidArgumentException();
                }
                new EntityId($row['student_id']);
            }
            $this->commands->reason($p['reason']);
        } catch (Throwable) {
            throw new ValidationException([[
                'field' => 'review_token', 'message' => 'Review this billing batch again.',
            ]]);
        }
        if ($p['actor_id'] !== $actor->value) {
            throw new AccessDeniedException('This billing review belongs to another operator.');
        }
        return $p;
    }

    /** @param array<string,mixed> $proof */
    private function fresh(#[\SensitiveParameter] array $proof): void
    {
        try {
            $issued = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $proof['issued_at']);
            $expires = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $proof['expires_at']);
            $now = $this->clock->now();
            if (
                $issued === false || $expires === false || $issued > $now || $expires <= $now
                || $expires->getTimestamp() - $issued->getTimestamp() !== 1200
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new ConcurrentModificationException('This billing review expired. Review the current batch again.');
        }
    }

    /** @param array{actor_id:string,request_hash:string,result:array<string,mixed>} $receipt
     * @return array<string,mixed> */
    private function replay(array $receipt, EntityId $actor, string $hash): array
    {
        $this->actor($actor);
        if ($receipt['actor_id'] !== $actor->value || !hash_equals($receipt['request_hash'], $hash)) {
            throw new AccessDeniedException('This billing receipt belongs to a different reviewed instruction.');
        }
        return $receipt['result'];
    }
}
