<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Domain\Gradebook\GradingScale;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\Gradebook\ValueObject\GradingScaleId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\DatabaseUnitOfWork;
use Infrastructure\Persistence\Repository\SqlGradingScaleRepository;
use Infrastructure\Persistence\SqlRecords;
use Infrastructure\SystemClock;
use LogicException;
use Qubus\Expressive\Database;

/** Offline, paged reconstruction from real recorded scale decisions. Never creates events. */
final readonly class GradeDefinitionProjectionUpgrade
{
    public function __construct(private Database $db, private ?ProtectedEventCodec $codec)
    {
    }
    public function run(): void
    {
        $sql = new SqlRecords($this->db);
        $q = $sql->quote(...);
        $after = "";
        $exists = $sql->rows("sis_grading_scales", [], 1) !== [];
        if (!$exists && $sql->rows("sis_history_streams", ["stream_type" => "grading_scale"], 1) === []) {
            return;
        }
        if ($this->codec === null) {
            throw new LogicException(
                "Configure the history codec to verify recorded grade policies before projection conversion.",
            );
        }
        $uow = new DatabaseUnitOfWork($this->db);
        $history = new SqlHistoricalEventStore(
            $sql,
            $uow,
            new SystemClock(),
            $this->codec,
            new HistoricalSchemaRegistry(),
        );
        $repo = new SqlGradingScaleRepository($sql);
        do {
            $rows = $sql->fetch(
                "SELECT * FROM " .
                    $q("sis_grading_scales") .
                    " WHERE " .
                    $q("id") .
                    " > ? ORDER BY " .
                    $q("id"),
                [$after],
                20,
            );
            foreach ($rows as $row) {
                $uow->run(function () use ($row, $history, $repo): void {
                    $id = new EntityId($row["id"]);
                    $program = $row["scope_key"] === "global" ? null : new EntityId($row["scope_key"]);
                    if (
                        $id->value !==
                            ($program === null ? GradingScaleService::GLOBAL_ID : $program->value) ||
                        $row["program_id"] !== $program?->value
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $stream = new StreamReference(new GradingScaleId($id->value), "grading_scale");
                    $root = GradingScale::root($stream->aggregateId);
                    $cursor = null;
                    do {
                        $page = $history->page($stream, $cursor, 20);
                        foreach ($page->events as $e) {
                            $root->acceptHistorical($e->event);
                            $p = $e->event->payload();
                            $repo->project($program, new EntityId($p["version_id"]), $p["definition"]);
                        }
                        $cursor = $page->nextCursor;
                    } while ($cursor !== null);
                    if (
                        $root->versionId() !== $row["version_id"] ||
                        $root->playhead() !== (int) $row["position"]
                    ) {
                        throw new CorruptHistoryException();
                    }
                    if (array_key_exists("definition_json", $row)) {
                        $legacy =
                            $row["definition_json"] === null
                                ? null
                                : json_decode($row["definition_json"], true, 8, JSON_THROW_ON_ERROR);
                        if (!self::same($legacy, $root->definition())) {
                            throw new CorruptHistoryException();
                        }
                    }
                });
            }
            if ($rows !== []) {
                $after = $rows[count($rows) - 1]["id"];
            }
        } while (count($rows) === 20);
        // A recorded stream without its metadata projection cannot be silently accepted as a new policy.
        $orphans = $sql->fetch(
            "SELECT s." .
                $q("aggregate_id") .
                " FROM " .
                $q("sis_history_streams") .
                " s LEFT JOIN " .
                $q("sis_grading_scales") .
                " g ON g." .
                $q("id") .
                " = s." .
                $q("aggregate_id") .
                " WHERE s." .
                $q("stream_type") .
                " = ? AND g." .
                $q("id") .
                " IS NULL",
            ["grading_scale"],
            1,
        );
        if ($orphans !== []) {
            throw new CorruptHistoryException();
        }
    }
    private static function same(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !self::same($v, $b[$k])) {
                return false;
            }
        }
        return true;
    }
}
