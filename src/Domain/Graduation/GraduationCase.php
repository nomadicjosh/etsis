<?php

declare(strict_types=1);

namespace Domain\Graduation;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Graduation\Event\GraduationDecisionWasRecorded;
use Domain\Graduation\ValueObject\GraduationSchema;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;

/** Eligibility is evidence; only a separate conferral creates a credential. */
final class GraduationCase extends EventSourcedAggregate
{
    public const array FIELDS = GraduationSchema::FIELDS;
    private ?string $student = null;
    private ?string $person = null;
    private ?string $decision = null;
    private ?array $eligibility = null;
    private ?array $credential = null;
    private array $awardedWork = [];
    private bool $everAwarded = false;
    private array $receipts = [];

    public static function validate(array $p): void
    {
        GraduationSchema::validate($p);
    }

    public function accept(StoredEvent $stored): void
    {
        $event = $stored->event;
        $p = $event->payload();
        self::validate($p);
        if (
            !($event instanceof GraduationDecisionWasRecorded) ||
            !$event->aggregateId()->equals($this->aggregateId()) ||
            $event->playhead() !== $this->playhead() + 1 ||
            $stored->context->origin !== "command" ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            trim($stored->context->reason) === "" ||
            $stored->context->effectiveAt === null ||
            $stored->context->correlation?->value !== $p["decision_id"] ||
            $stored->context->causation?->value !== $this->decision ||
            $p["previous_decision_id"] !== $this->decision ||
            ($this->student !== null && $p["student_id"] !== $this->student) ||
            ($this->person !== null &&
                $p["identity"]["person_id"] !== $this->person) ||
            isset($this->receipts[$p["decision_id"]])
        ) {
            throw new CorruptHistoryException();
        }
        $proposal =
        $p["details"]["proposal"] ??
        ($p["details"]["credential"]["proposal"] ?? null);
        if (
            $proposal !== null &&
            $proposal["awarded_on"] >
                $stored->context->effectiveAt->format("Y-m-d")
        ) {
            throw new CorruptHistoryException();
        }
        $details = $p['details'];
        $request = match ($p['action']) {
            'eligibility' => ['proposal' => $details['proposal'], 'attested' => $details['attested'],
                'history_acknowledged' => $details['history_acknowledged']],
            'correct' => ['title' => $details['credential']['proposal']['title'],
                'honors' => $details['credential']['proposal']['honors']],
            default => [],
        };
        $intent = GraduationSchema::hash([$p['student_id'], (string) $this->aggregateId(),
            $p['action'], $request, $p['revision'], trim($stored->context->reason)]);
        if (!hash_equals($p['intent'], $intent)) {
            throw new CorruptHistoryException();
        }
        $this->applyThat($event);
        $this->playhead = $event->playhead();
        $this->receipts[$p["decision_id"]] = [
            "intent" => $p["intent"],
            "actor" => $stored->context->actor->value,
            "action" => $p["action"],
            "operation_id" => $p["decision_id"],
        ];
    }

    public function whenGraduationDecisionWasRecorded(
        GraduationDecisionWasRecorded $event,
    ): void {
        $p = $event->payload();
        $d = $p["details"];
        if ($p["action"] === "eligibility") {
            if (
                $this->credential !== null ||
                $d["snapshot"]["pursuit_id"] !== (string) $this->aggregateId()
            ) {
                throw new CorruptHistoryException();
            }
            $this->eligibility = $d + ["decision_id" => $p["decision_id"]];
        } elseif ($p["action"] === "confer") {
            if (
                $this->credential !== null ||
                !($this->eligibility["eligible"] ?? false) ||
                $d["eligibility_id"] !== $this->eligibility["decision_id"] ||
                $d["credential"]["id"] !== $p["decision_id"] ||
                $d["credential"]["proposal"] !== $this->eligibility["proposal"]
            ) {
                throw new CorruptHistoryException();
            }
            $academic = GraduationSchema::curriculum(
                new \Domain\DegreeAudit\ValueObject\DegreeProgressSnapshot(
                    $this->eligibility["snapshot"],
                ),
            );
            if (
                GraduationSchema::hash([
                    $d["credential"]["program"],
                    $d["credential"]["components"],
                ]) !==
                GraduationSchema::hash([
                    $academic["program"],
                    $academic["components"],
                ])
            ) {
                throw new CorruptHistoryException();
            }
            $this->credential = $d["credential"];
            $this->everAwarded = true;
            foreach ($this->eligibility["snapshot"]["inputs"] as $work) {
                $this->awardedWork[$work["id"]] = true;
            }
            $this->eligibility = null;
        } elseif ($p["action"] === "correct") {
            $before = $this->credential;
            $after = $d["credential"];
            if ($before === null) {
                throw new CorruptHistoryException();
            }
            foreach (["title", "honors"] as $key) {
                $before["proposal"][$key] = $after["proposal"][$key];
            }
            if ($before !== $after || $this->credential === $after) {
                throw new CorruptHistoryException();
            }
            $this->credential = $after;
        } else {
            if (
                $this->credential === null ||
                $this->credential["id"] !== $d["credential_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $this->credential = null;
            $this->eligibility = null;
        }
        $this->student = $p["student_id"];
        $this->person = $p["identity"]["person_id"];
        $this->decision = $p["decision_id"];
    }

    public function state(): array
    {
        return [
            "student_id" => $this->student,
            "person_id" => $this->person,
            "decision_id" => $this->decision,
            "eligibility" => $this->eligibility,
            "credential" => $this->credential,
            "ever_awarded" => $this->everAwarded,
            "awarded_work" => array_keys($this->awardedWork),
            "version" => $this->playhead(),
        ];
    }

    public function receipt(string $id): ?array
    {
        return $this->receipts[$id] ?? null;
    }
}
