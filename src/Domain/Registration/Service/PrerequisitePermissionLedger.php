<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Registration\PrerequisitePermission;
use Domain\Registration\Repository\PrerequisitePermissionRepository;
use Domain\Registration\ValueObject\PrerequisitePermissionId;
use Domain\Registration\ValueObject\PrerequisitePermissionReference;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal protected approval read. Callers authorize their student and own command locking. */
final readonly class PrerequisitePermissionLedger
{
    public function __construct(
        private PrerequisitePermissionRepository $records,
        private HistoricalEventStore $history,
        private Clock $clock,
    ) {
    }

    public static function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new PrerequisitePermissionId($id->value), "prerequisite_permission");
    }

    public function current(EntityId $student, EntityId $pursuit, EntityId $section): ?array
    {
        $row = $this->records->current($student, $pursuit, $section);
        if ($row === null) {
            return null;
        }
        $stored =
        $this->history->latest(self::stream(new EntityId($row["id"]))) ??
        throw new CorruptHistoryException();
        $state = PrerequisitePermission::recordedState($stored);
        if ($state !== array_diff_key($row, ["id" => true])) {
            throw new CorruptHistoryException();
        }
        return $row;
    }

    public function effective(
        EntityId $student,
        EntityId $pursuit,
        EntityId $section,
        EntityId $term,
        ?EntityId $courseVersion,
    ): ?PrerequisitePermissionReference {
        $row = $this->current($student, $pursuit, $section);
        $approval = $row["approval"] ?? null;
        if (
            $approval === null ||
            $approval["term_id"] !== $term->value ||
            $approval["course_version_id"] !== $courseVersion?->value ||
            new \DateTimeImmutable($approval["expires_at"]) <= $this->clock->now()
        ) {
            return null;
        }
        return new PrerequisitePermissionReference([
            "stream_id" => $row["id"],
            "operation_id" => $row["operation_id"],
            "position" => $row["position"],
            ...$row["scope"],
            ...$approval,
        ]);
    }

    public function load(EntityId $id, array $scope): PrerequisitePermission
    {
        $root = PrerequisitePermission::root(self::stream($id)->aggregateId);
        $budget = new HistoryReadBudget();
        $cursor = null;
        do {
            $page = $budget->page($this->history, self::stream($id), $cursor);
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $row = $this->records->current(
            new EntityId($scope["student_id"]),
            new EntityId($scope["pursuit_id"]),
            new EntityId($scope["section_id"]),
        );
        if (
            $root->playhead() === 0
                ? $row !== null
                : $row === null ||
                    $row["id"] !== $id->value ||
                    $root->state() !== array_diff_key($row, ["id" => true]) ||
                    $root->state()["scope"] !== $scope
        ) {
            throw new CorruptHistoryException();
        }
        return $root;
    }
}
