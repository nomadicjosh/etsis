<?php

declare(strict_types=1);

namespace Domain\Graduation\Service;

use Domain\Graduation\GraduationCase;
use Domain\Graduation\Repository\GraduationRepository;
use Domain\Graduation\ValueObject\GraduationId;
use Domain\Graduation\ValueObject\GraduationSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;

/** Internal source verification. Callers authorize and hold the student lock for decisions. */
final readonly class GraduationReader
{
    public function __construct(
        private GraduationRepository $records,
        private HistoricalEventStore $history,
        private StudentRecordRepository $students,
        private \Domain\Admissions\Repository\AdmissionsRepository $admissions,
    ) {
    }

    public function load(
        EntityId $student,
        EntityId $pursuit,
        HistoryReadBudget $budget,
    ): GraduationCase {
        if (
            $this->students->assignment($pursuit)->studentId->value !==
            $student->value
        ) {
            throw new CorruptHistoryException();
        }
        $root = GraduationCase::root(new GraduationId($pursuit->value));
        $cursor = null;
        $through = null;
        do {
            $page = $budget->page(
                $this->history,
                self::stream($pursuit),
                $cursor,
            );
            $through ??= $page->throughVersion;
            if ($through !== $page->throughVersion) {
                throw new CorruptHistoryException();
            }
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $row = $this->records->find($pursuit);
        $state = $root->state();
        if (
            $root->playhead() > 0 &&
            $state["person_id"] !==
                $this->admissions->student($student)->personId->value
        ) {
            throw new CorruptHistoryException();
        }

        if (
            $root->playhead() !== $through ||
            ($row === null) !== ($through === 0) ||
            ($row !== null &&
                ($row["student_id"] !== $student->value ||
                    $state["student_id"] !== $student->value ||
                    (int) $row["version"] !== $through ||
                    $row["source_hash"] !== GraduationSchema::hash($state) ||
                    (int) $row["eligible"] !==
                        (int) ($state["eligibility"]["eligible"] ?? false)))
        ) {
            throw new CorruptHistoryException();
        }
        return $root;
    }

    /** Locate streams from actual pursuits, so deleting a support row cannot conceal an award. */
    public function student(EntityId $student, HistoryReadBudget $budget): array
    {
        $after = null;
        $result = [];
        do {
            $rows = $this->students->assignments($student, $after, 21);
            foreach (array_slice($rows, 0, 20) as $row) {
                if (count($result) >= 100) {
                    throw new HistoryReadLimitException(
                        "Review this graduation history through the institutional bulk process.",
                    );
                }
                $id = new EntityId($row["id"]);
                $result[$id->value] = $this->load(
                    $student,
                    $id,
                    $budget,
                )->state();
                $after = $id;
            }
        } while (count($rows) > 20);
        return $result;
    }

    public function credentials(
        EntityId $student,
        HistoryReadBudget $budget,
    ): array {
        $items = [];
        foreach ($this->student($student, $budget) as $pursuit => $state) {
            if ($state["credential"] !== null) {
                $items[] =
                ["pursuit_id" => $pursuit, "version" => $state["version"]] +
                $state["credential"];
            }
        }
        return $items;
    }

    public static function transferRequired(
        array $states,
        string $target,
        array $scope,
        string $credit,
    ): bool {
        foreach ($states as $pursuit => $state) {
            if (
                $pursuit !== $target &&
                $state["ever_awarded"] &&
                ($scope["owner_assignment_id"] === $pursuit ||
                    in_array($credit, $state["awarded_work"], true))
            ) {
                return true;
            }
        }
        return false;
    }

    public static function stream(EntityId $pursuit): StreamReference
    {
        return new StreamReference(
            new GraduationId($pursuit->value),
            "graduation",
        );
    }
}
