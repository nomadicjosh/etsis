<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\AcademicRecord\Repository\AcademicProjectionVerifier;
use Domain\AcademicRecord\ValueObject\AcademicProjectionCheck;
use Domain\AcademicRecord\ValueObject\AcademicRecordProjectionCheck;
use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\Service\AcademicInputVerifier;
use Domain\DegreeAudit\ValueObject\AcademicInputStatus;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** Observational maintenance check. No locks, transaction-wide snapshot, writes or repair calls. */
final readonly class SqlAcademicProjectionVerifier implements AcademicProjectionVerifier
{
    public function __construct(
        private SqlRecords $sql,
        private AcademicInputRepository $inputs,
        private AcademicInputVerifier $verifier,
    ) {
    }

    public function verify(EntityId $student, int $eventLimit = 500): AcademicProjectionCheck
    {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException('Use an academic verification allowance of 1–2000 events.');
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
        $before = null;
        $records = null;
        $status = 'unverified';
        $issues = [];
        try {
            if ($this->sql->tableExists('event_store') || $this->sql->inTransaction()) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($student);
            if ($before['student'] === []) {
                $issues = ['student_identity'];
            } else {
                $result = $this->verifier->verify($student, $budget);
                $budget->checkTime();
                $records = count($result->items);
                // Earlier uncertainty includes restricted removed records; do not certify their earlier history.
                $gaps = $result->status === AcademicInputStatus::HistoryGaps ||
                array_any($result->references, static fn(array $r): bool => $r['earlier_history_unknown']);
                $status = $gaps ? 'history_gaps' : ($result->references === [] ? 'observed_empty' : 'verified');
            }
        } catch (HistoryReadLimitException) {
            $status = 'limited';
        } catch (CorruptHistoryException | RecordNotFoundException) {
            $issues = ['academic_sources'];
        } catch (Throwable) {
            $issues = ['unavailable'];
        }
        if ($before !== null) {
            try {
                if ($before !== $this->boundary($student)) {
                    $status = 'changed';
                    $issues = [];
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                $status = 'limited';
                $issues = [];
            } catch (Throwable) {
                $status = 'unverified';
                $issues = ['unavailable'];
            }
        }
        if (!in_array($status, ['verified', 'history_gaps', 'observed_empty'], true)) {
            $records = null;
        }
        return new AcademicProjectionCheck($status, $student, $budget->eventsRead(), $records, $issues);
    }

    public function credit(
        EntityId $credit,
        int $eventLimit = 500,
    ): AcademicRecordProjectionCheck {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException(
                "Use an academic verification allowance of 1–2000 events.",
            );
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000,
        );
        $before = null;
        $status = "unverified";
        $issues = [];
        $current = $unknown = null;
        try {
            if (
                $this->sql->tableExists("event_store") ||
                $this->sql->inTransaction()
            ) {
                throw new CorruptHistoryException();
            }
            $before = $this->creditBoundary($credit);
            if ($before["selected"] === null) {
                $issues = ["academic_sources"];
            } elseif ($before["student"] === []) {
                $issues = ["student_identity"];
            } else {
                $set = $this->verifier->selected($credit, $budget);
                if (
                    $set === null ||
                    count($set->references) !== 1 ||
                    $set->references[0]["credit_id"] !== $credit->value
                ) {
                    throw new CorruptHistoryException();
                }
                $unknown = $set->references[0]["earlier_history_unknown"];
                $current = count($set->items);
                $status = $unknown ? "history_gaps" : "verified";
            }
            $budget->checkTime();
        } catch (HistoryReadLimitException) {
            $status = "limited";
        } catch (CorruptHistoryException | RecordNotFoundException) {
            $issues = ["academic_sources"];
        } catch (Throwable) {
            $issues = ["unavailable"];
        }
        if ($before !== null) {
            try {
                if ($before !== $this->creditBoundary($credit)) {
                    $status = "changed";
                    $issues = [];
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                $status = "limited";
                $issues = [];
            } catch (Throwable) {
                $status = "unverified";
                $issues = ["unavailable"];
            }
        }
        if (!in_array($status, ["verified", "history_gaps"], true)) {
            $current = $unknown = null;
        }
        return new AcademicRecordProjectionCheck(
            $status,
            $credit,
            $budget->eventsRead(),
            $current,
            $unknown,
            $issues,
        );
    }

    /** The selected source is resolved by indexed identities, independently of career inventory size. */
    private function creditBoundary(EntityId $credit): array
    {
        $selected = $this->inputs->selected($credit);
        if ($selected === null) {
            return ["selected" => null, "student" => []];
        }
        $q = $this->sql->quote(...);
        $student = $this->sql->fetch(
            "SELECT " .
                $q("id") .
                ", " .
                $q("person_id") .
                " FROM " .
                $q("sis_students") .
                " WHERE " .
                $q("id") .
                " = ?",
            [$selected["student_id"]],
            1,
        );
        $source = $selected["source"];
        $projections = [];
        $referenceTables = ["sis_credit_course_versions", "sis_credit_posting_terms"];
        foreach ($referenceTables as $table) {
            $projections[$table] = $this->sql->rows(
                $table,
                ["id" => $credit->value],
                2,
            );
        }
        if ($source["origin"] === "institutional") {
            $projections["offering"] = $this->sql->rows(
                "sis_enrollment_section_versions",
                ["id" => $source["id"]],
                2,
            );
            $projections["pending"] = $this->sql->rows(
                "sis_grade_change_requests",
                ["credit_id" => $credit->value, "status" => "pending"],
                2,
            );
        }
        $streams = ["transfer_credit" => $source["id"]];
        if ($source["origin"] === "institutional") {
            $streams = ["academic_credit" => $credit->value, "enrollment" => $source["id"]];
        }
        $heads = [];
        foreach ($streams as $type => $id) {
            $heads[$type] = $this->sql->fetch(
                "SELECT " .
                    implode(
                        ", ",
                        array_map($q, [
                            "id",
                            "stream_type",
                            "aggregate_id",
                            "version",
                            "head_hash",
                        ]),
                    ) .
                    " FROM " .
                    $q("sis_history_streams") .
                    " WHERE " .
                    $q("stream_type") .
                    " = ? AND " .
                    $q("aggregate_id") .
                    " = ?",
                [$type, $id],
                2,
            );
        }
        return [
            "selected" => $selected,
            "student" => $student,
            "projections" => $projections,
            "heads" => $heads,
        ];
    }

    /** Scalar routing/head evidence, including removed sources; never returned to operators.
     * @return array<string,mixed>
     */
    private function boundary(EntityId $student): array
    {
        $q = $this->sql->quote(...);
        $identity = $this->sql->fetch(
            'SELECT ' . $q('id') . ' FROM ' . $q('sis_students') . ' WHERE ' . $q('id') . ' = ?',
            [$student->value],
            1,
        );
        if ($identity === []) {
            return ['student' => []];
        }
        $sources = $this->inputs->inventory($student)->sources;
        $ids = ['academic_credit' => [], 'enrollment' => [], 'transfer_credit' => []];
        foreach ($sources as $source) {
            if ($source['origin'] === 'institutional') {
                if ($source['credit_id'] !== null) {
                    $ids['academic_credit'][] = $source['credit_id'];
                }
                $ids['enrollment'][] = $source['id'];
            } else {
                $ids['transfer_credit'][] = $source['id'];
            }
        }
        $heads = [];
        foreach ($ids as $type => $references) {
            if ($references === []) {
                continue;
            }
            $heads[$type] = $this->sql->fetch(
                'SELECT ' . implode(', ', array_map($q, ['id', 'stream_type', 'aggregate_id', 'version', 'head_hash']))
                . ' FROM ' . $q('sis_history_streams') . ' WHERE ' . $q('stream_type') . ' = ? AND '
                . $q('aggregate_id') . ' IN (' . implode(', ', array_fill(0, count($references), '?')) . ')'
                . ' ORDER BY ' . $q('aggregate_id'),
                [$type, ...$references],
                201,
            );
        }
        return ['student' => $identity, 'sources' => $sources, 'heads' => $heads];
    }
}
