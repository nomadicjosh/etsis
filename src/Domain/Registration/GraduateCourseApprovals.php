<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Registration\Event\GraduateCourseApprovalWasRecorded;
use Domain\Registration\ValueObject\GraduateCourseScope;
use Domain\Registration\ValueObject\GraduateRegistrationRules;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\ValueObject\EntityId;

/** One pursuit stream, independent advisor/registrar decisions and preserved original operation receipts. */
final class GraduateCourseApprovals extends EventSourcedAggregate
{
    public const array FIELDS = [
        "operation_id",
        "action",
        "scope",
        "actor_id",
        "effective_at",
        "advisor_operation_id",
        "before_hash",
        "intent",
    ];
    public const array ACTIONS = [
        "advisor_approve",
        "advisor_revoke",
        "registrar_override",
        "registrar_revoke",
    ];
    private array $sections = [];
    private array $records = [];
    private ?string $student = null;

    public static function validate(#[\SensitiveParameter] array $p): void
    {
        try {
            if (
                count($p) !== count(self::FIELDS) ||
                array_diff(array_keys($p), self::FIELDS) !== [] ||
                !in_array($p["action"], self::ACTIONS, true) ||
                !is_array($p["scope"])
            ) {
                throw new \InvalidArgumentException();
            }
            new EntityId($p["operation_id"]);
            new EntityId($p["actor_id"]);
            new GraduateCourseScope($p["scope"]);
            if ($p["advisor_operation_id"] !== null) {
                new EntityId($p["advisor_operation_id"]);
            }
            foreach (["before_hash", "intent"] as $key) {
                if (!is_string($p[$key]) || preg_match('/^[a-f0-9]{64}$/D', $p[$key]) !== 1) {
                    throw new \InvalidArgumentException();
                }
            }
            $date = is_string($p["effective_at"])
            ? \DateTimeImmutable::createFromFormat("!Y-m-d\TH:i:s.u\Z", $p["effective_at"])
            : false;
            if (!$date || $date->format("Y-m-d\TH:i:s.u\Z") !== $p["effective_at"]) {
                throw new \InvalidArgumentException();
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function accept(StoredEvent $stored): void
    {
        $e = $stored->event;
        $p = $e->payload();
        self::validate($p);
        if (
            !($e instanceof GraduateCourseApprovalWasRecorded) ||
            !$e->aggregateId()->equals($this->aggregateId()) ||
            $e->playhead() !== $this->playhead() + 1 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor?->value !== $p["actor_id"] ||
            $stored->context->correlation?->value !== $p["operation_id"] ||
            $stored->context->reason === null ||
            $stored->context->effectiveAt
                ?->setTimezone(new \DateTimeZone("UTC"))
                ->format("Y-m-d\TH:i:s.u\Z") !== $p["effective_at"] ||
            $p["intent"] !== self::intent($p, $stored->context->reason)
        ) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($e);
        $this->playhead = $e->playhead();
    }

    public static function intent(array $p, string $reason): string
    {
        unset($p["intent"]);
        return GraduateRegistrationRules::hash([$p, $reason]);
    }

    public function whenGraduateCourseApprovalWasRecorded(GraduateCourseApprovalWasRecorded $event): void
    {
        $p = $event->payload();
        self::validate($p);
        $scope = new GraduateCourseScope($p["scope"]);
        $p["scope"] = $scope->data;
        $section = $scope->data["section_id"];
        $before = $this->section(new EntityId($section));
        if (
            (string) $this->aggregateId() !== $scope->data["pursuit_id"] ||
            isset($this->records[$p["operation_id"]]) ||
            ($this->student !== null && $this->student !== $scope->data["student_id"]) ||
            $p["before_hash"] !== GraduateRegistrationRules::hash($before)
        ) {
            throw new CorruptHistoryException();
        }
        $entry = array_intersect_key($p, array_flip(["operation_id", "scope", "actor_id", "effective_at"]));
        $next = $before;
        if ($p["action"] === "advisor_approve") {
            if ($p["advisor_operation_id"] !== null) {
                throw new CorruptHistoryException();
            }
            $next["advisor"] = $entry;
            $next["registrar"] = null;
        } elseif ($p["action"] === "advisor_revoke") {
            if (
                $before["advisor"] === null ||
                $p["advisor_operation_id"] !== null ||
                $before["advisor"]["scope"] !== $p["scope"]
            ) {
                throw new CorruptHistoryException();
            }
            $next["advisor"] = $next["registrar"] = null;
        } elseif ($p["action"] === "registrar_override") {
            if (
                $before["advisor"] === null ||
                $p["advisor_operation_id"] !== $before["advisor"]["operation_id"] ||
                new GraduateCourseScope($before["advisor"]["scope"])->binding() !== $scope->binding()
            ) {
                throw new CorruptHistoryException();
            }
            $next["registrar"] = $entry + ["advisor_operation_id" => $p["advisor_operation_id"]];
        } else {
            if (
                $before["registrar"] === null ||
                $before["registrar"]["scope"] !== $p["scope"] ||
                $p["advisor_operation_id"] !== $before["registrar"]["advisor_operation_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $next["registrar"] = null;
        }
        $next["operation_id"] = $p["operation_id"];
        $this->student = $scope->data["student_id"];
        $this->sections[$section] = $next;
        $this->records[$p["operation_id"]] = $p + ["position" => $event->playhead()];
        if (count($this->sections) > 200) {
            throw new CorruptHistoryException();
        }
    }

    public function section(EntityId $section): array
    {
        return $this->sections[$section->value] ?? [
            "advisor" => null,
            "registrar" => null,
            "operation_id" => null,
        ];
    }

    public function record(EntityId $operation): ?array
    {
        return $this->records[$operation->value] ?? null;
    }

    public function __debugInfo(): array
    {
        return ["approvals" => "[protected graduate-course decisions]"];
    }
}
