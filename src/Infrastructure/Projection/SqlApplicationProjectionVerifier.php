<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\ApplicationProjectionVerifier;
use Domain\Admissions\Service\AdmissionDecisionLedger;
use Domain\Admissions\ValueObject\ApplicationProjectionCheck;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlSubmissionProjection;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** One selected application; shared domain validation, no locks, repairs or decision writes. */
final readonly class SqlApplicationProjectionVerifier implements ApplicationProjectionVerifier
{
    public function __construct(
        private SqlRecords $sql,
        private AdmissionsRepository $applications,
        private AdmissionDecisionLedger $ledger,
    ) {
    }

    public function verify(EntityId $application, int $eventLimit = 500): ApplicationProjectionCheck
    {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException('Use an application verification allowance of 1–2000 events.');
        }
        if ($this->sql->inTransaction()) {
            return new ApplicationProjectionCheck('unverified', $application, 0, issues: ['unavailable']);
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
        $before = null;
        $status = 'unverified';
        $issues = [];
        $decision = $matriculation = $earlier = null;
        try {
            if ($this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($application);
            if (count($before['application']) !== 1 || count($before['person']) !== 1) {
                $issues[] = 'application';
            } else {
                $decision = $this->position($before['decision']);
                $matriculation = $this->position($before['matriculation']);
                if ($decision + $matriculation > $eventLimit) {
                    throw new HistoryReadLimitException();
                }
                $current = $this->applications->application($application);
                $inspection = $this->ledger->inspect($current, $budget);
                if (
                    $inspection['decision_position'] !== $decision
                    || $inspection['matriculation_position'] !== $matriculation
                    || count($before['submission']) > 1
                ) {
                    throw new CorruptHistoryException();
                }
                $earlier = $inspection['earlier_history_unknown'];
                $fact = $inspection['submission_fact'];
                try {
                    if ($fact !== null) {
                        new SqlSubmissionProjection($this->sql)->verify($fact);
                    } elseif ($before['intake'] !== []) {
                        // An observed application cannot establish an original submission timestamp.
                        throw new CorruptHistoryException();
                    }
                } catch (CorruptHistoryException) {
                    $issues[] = 'intake';
                }
                $budget->checkTime();
                $status = $issues === [] ? ($earlier ? 'history_gaps' : 'verified') : 'unverified';
            }
        } catch (HistoryReadLimitException) {
            $status = 'limited';
            $issues = [];
        } catch (CorruptHistoryException) {
            $status = 'unverified';
            $issues = ['application'];
        } catch (Throwable) {
            $status = 'unverified';
            $issues = ['unavailable'];
        }
        if ($before !== null) {
            try {
                // Concurrent real commits must not be described as corruption or a completed observation.
                if ($before !== $this->boundary($application)) {
                    $status = 'changed';
                    $issues = [];
                }
            } catch (Throwable) {
                $status = 'unverified';
                $issues = ['unavailable'];
            }
        }
        return new ApplicationProjectionCheck(
            $status,
            $application,
            $budget->eventsRead(),
            $decision,
            $matriculation,
            $issues,
            in_array($status, ['verified', 'history_gaps'], true) ? $earlier : null,
        );
    }

    private function position(array $heads): int
    {
        if ($heads === []) {
            return 0;
        }
        $value = $heads[0]['version'];
        if (count($heads) !== 1 || !is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            throw new CorruptHistoryException();
        }
        $position = (int) $value;
        if ((string) $position !== (string) $value) {
            throw new CorruptHistoryException();
        }
        return $position;
    }

    /** Capture small current rows and immutable-artifact references, never credentials or answer bodies. */
    private function boundary(EntityId $application): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->rows('sis_applications', ['id' => $application->value], 2);
        $head = fn (string $type): array => $this->sql->fetch('SELECT '
            . implode(', ', array_map($q, ['id', 'stream_type', 'aggregate_id', 'version', 'head_hash']))
            . ' FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type') . ' = ? AND '
            . $q('aggregate_id') . ' = ?', [$type, $application->value], 2);
        return ['application' => $rows,
            'person' => $rows === [] ? [] : $this->sql->fetch('SELECT '
                . implode(', ', array_map($q, ['id', 'owner_user_id'])) . ' FROM ' . $q('sis_persons')
                . ' WHERE ' . $q('id') . ' = ?', [$rows[0]['person_id']], 2),
            'decision' => $head('admission_decision'), 'matriculation' => $head('matriculation'),
            'intake' => $this->sql->rows('sis_application_intake', ['id' => $application->value], 2),
            'submission' => $this->sql->fetch(
                'SELECT '
                . implode(', ', array_map($q, ['id', 'form_id', 'application_id', 'owner_user_id', 'fingerprint']))
                . ' FROM ' . $q('sis_form_submissions') . ' WHERE ' . $q('application_id') . ' = ?',
                [$application->value],
                2
            ),
        ];
    }
}
