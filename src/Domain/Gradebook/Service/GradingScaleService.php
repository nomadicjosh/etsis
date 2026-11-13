<?php

declare(strict_types=1);

namespace Domain\Gradebook\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\Event\GradingScaleWasChanged;
use Domain\Gradebook\GradingScale;
use Domain\Gradebook\Repository\GradingScaleRepository;
use Domain\Gradebook\ValueObject\GradingScaleDefinition;
use Domain\Gradebook\ValueObject\GradingScaleSnapshot;
use Domain\Gradebook\ValueObject\GradingScaleId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class GradingScaleService
{
    public const string GLOBAL_ID = "01K00000000000000000000000";
    public function __construct(
        private GradingScaleRepository $repository,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }
    /** Internal policy resolution. The calling workflow must authorize its read first.
     * @return array<string,mixed>
     */
    public function resolve(?EntityId $program = null, ?HistoryReadBudget $budget = null): array
    {
        if ($program !== null) {
            $this->repository->requireProgram($program);
            $state = $this->state($program, $budget);
            if ($state->definition() !== null) {
                return new GradingScaleSnapshot([
                    "source" => "program",
                    "program_id" => $program->value,
                    "version_id" => $state->versionId(),
                    "maximum_milli" => new GradingScaleDefinition($state->definition())->maximum(),
                    "is_default" => false,
                    "definition" => $state->definition(),
                ])->data;
            }
        }
        $global = $this->state(null, $budget);
        $d = new GradingScaleDefinition($global->definition() ?? ["grades" => []]);
        return new GradingScaleSnapshot([
            "source" => $d->definition["grades"] === [] ? "default" : "global",
            "program_id" => null,
            "version_id" => $global->versionId(),
            "maximum_milli" => $d->maximum(),
            "is_default" => $d->definition["grades"] === [],
            "definition" => $d->definition,
        ])->data;
    }
    /** Internal immutable read; the academic workflow must authorize student access first.
     * @return array<string,mixed>
     */
    public function publication(?EntityId $program, EntityId $version): array
    {
        $stream = $this->stream($program);
        $root = GradingScale::root($stream->aggregateId);
        $cursor = null;
        $found = null;
        do {
            $page = $this->history->page($stream, $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $p = $stored->event->payload();
                if ($p["version_id"] === $version->value) {
                    $found = $p["definition"];
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($found === null) {
            throw new \Domain\Shared\Exception\RecordNotFoundException("Published grade scale not found.");
        }
        $rows = $this->repository->grades($version);
        $scaleId = $program === null ? self::GLOBAL_ID : $program->value;
        if (count($rows) !== count($found["grades"])) {
            throw new CorruptHistoryException();
        }
        foreach ($rows as $i => $r) {
            $facts = array_intersect_key(
                $r,
                array_flip([
                    "code",
                    "min_score_milli",
                    "max_score_milli",
                    "points_milli",
                    "passes",
                    "counts_gpa",
                ]),
            );
            if ($r["scale_id"] !== $scaleId || !$this->same($facts, $found["grades"][$i])) {
                throw new CorruptHistoryException();
            }
        }
        return [
            "definition" => $found,
            "grades" => $rows,
            "program_id" => $program?->value,
            "version_id" => $version->value,
        ];
    }
    /** @return array<string,mixed> */
    public function view(?EntityId $program = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $current = $this->resolve($program);
        return ["scale" => $current, "global_scale" => $this->resolve(), "program_id" => $program?->value];
    }
    /** @return array<string,mixed> */
    public function review(
        ?EntityId $program,
        ?GradingScaleDefinition $definition,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            !mb_check_encoding($reason, "UTF-8") ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" => "Enter a reason for this grading policy change, up to 1,000 characters.",
                ],
            ]);
        }
        if (
            ($definition !== null && $definition->definition["grades"] === []) ||
            ($program === null && $definition === null)
        ) {
            throw new ValidationException([
                [
                    "field" => "definition",
                    "message" =>
                        $program === null
                            ? "Add at least one grade to the global scale."
                            : "Add at least one grade to the program scale, or choose the global scale.",
                ],
            ]);
        }
        return $this->transactions->run(function () use ($program, $definition, $reason, $actor): array {
            $this->repository->lockScope($program);
            $state = $this->state($program);
            $op = EntityId::generate();
            $data = [
                "kind" => "grading-scale-review.v1",
                "program_id" => $program?->value,
                "actor_id" => $actor->value,
                "operation_id" => $op->value,
                "expected_version" => $state->playhead(),
                "previous_version_id" => $state->versionId(),
                "definition" => $definition?->definition,
                "reason" => $reason,
                "effective_at" => $this->clock->now()->format(DATE_ATOM),
            ];
            return $data + ["review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR))];
        });
    }
    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    public function commit(?EntityId $program, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 65536) {
                throw new InvalidArgumentException();
            }
            $data = json_decode($this->cipher->open($token), true, 12, JSON_THROW_ON_ERROR);
            if (
                !is_array($data) ||
                ($data["kind"] ?? null) !== "grading-scale-review.v1" ||
                ($data["program_id"] ?? null) !== $program?->value
            ) {
                throw new InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new InvalidArgumentException("Review this grading scale again.");
        }
        if ($data["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($program, $data, $actor): array {
            $this->repository->lockScope($program);
            $this->state($program);
            $op = new EntityId($data["operation_id"]);
            $event = GradingScaleWasChanged::occur(
                new GradingScaleId($program === null ? self::GLOBAL_ID : $program->value),
                [
                    "version_id" => $op->value,
                    "previous_version_id" => $data["previous_version_id"],
                    "definition" => $data["definition"],
                ],
                ["__aggregate_type" => "grading_scale"],
            )->withPlayhead($data["expected_version"] + 1);
            $receipt = $this->history->append(
                $this->stream($program),
                $op,
                $data["expected_version"],
                new EventContext(
                    $actor,
                    new DateTimeImmutable($data["effective_at"]),
                    $op,
                    reason: $data["reason"],
                ),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                $this->repository->save($program, $op, $receipt["last_version"], $data["definition"]);
                $this->audit->record(
                    $actor,
                    "grading_scale.changed",
                    $program ?? new EntityId(self::GLOBAL_ID),
                );
            }
            return $receipt;
        });
    }
    private function stream(?EntityId $program): StreamReference
    {
        return new StreamReference(
            new GradingScaleId($program === null ? self::GLOBAL_ID : $program->value),
            "grading_scale",
        );
    }
    private function state(?EntityId $program, ?HistoryReadBudget $budget = null): GradingScale
    {
        $state = GradingScale::root(
            new GradingScaleId($program === null ? self::GLOBAL_ID : $program->value),
        );
        $cursor = null;
        do {
            $page = $budget === null
            ? $this->history->page($this->stream($program), $cursor, 20)
            : $budget->page($this->history, $this->stream($program), $cursor);
            foreach ($page->events as $e) {
                $state->acceptHistorical($e->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $r = $this->repository->current($program);
        if (
            ($r === null && $state->playhead() !== 0) ||
            ($r !== null &&
                ($r["position"] !== $state->playhead() ||
                    $r["version_id"] !== $state->versionId() ||
                    !$this->same($r["definition"], $state->definition())))
        ) {
            throw new CorruptHistoryException();
        }
        return $state;
    }
    private function same(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
            return false;
        }
        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b) || !$this->same($v, $b[$k])) {
                return false;
            }
        }
        return true;
    }
}
