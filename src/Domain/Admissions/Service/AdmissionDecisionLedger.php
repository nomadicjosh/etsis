<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\AdmissionDecision;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Event\AdmissionApplicationWasObserved;
use Domain\Admissions\Event\AdmissionWasDecided;
use Domain\Admissions\Matriculation;
use Domain\Admissions\ValueObject\AdmissionDecisionId;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Admissions\ValueObject\MatriculationId;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal ledger. Entry points authorize, hold the application lock and own the transaction. */
final readonly class AdmissionDecisionLedger
{
    public function __construct(
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }
    public function verify(AdmissionApplication $a): int
    {
        return $this->load($a)["root"]->playhead();
    }
    /** @return array<string,mixed>|null */
    public function receipt(AdmissionApplication $a, EntityId $operation, EntityId $actor): ?array
    {
        $r = $this->load($a);
        if ($r["root"]->source() === null || $r["root"]->decision() !== $operation->value) {
            return null;
        }
        if ($r["actor"] !== $actor->value) {
            throw new AccessDeniedException("This admissions decision belongs to another actor.");
        }
        return $this->result($r["root"], $r["at"] ?? throw new CorruptHistoryException());
    }
    /** Called after projection CAS succeeds in the same transaction.
     * @param array<string,mixed> $source
     * @return array<string,mixed> */
    public function record(
        AdmissionApplication $before,
        AdmissionApplication $after,
        array $source,
        EntityId $operation,
        EntityId $actor,
        #[\SensitiveParameter] string $reason,
    ): array {
        if ($this->access->authenticatedUserId()->value !== $actor->value) {
            throw new AccessDeniedException("The admissions actor changed.");
        }
        // Verify against the accepted/rejected projection only after composing the real decision below.
        $root = AdmissionDecision::root(new AdmissionDecisionId($before->id->value));
        if ($this->history->page($this->stream($before->id), null, 1)->events !== []) {
            throw new CorruptHistoryException();
        }
        $observation = PublishedGradeId::forVersion($operation, "admissions.observation");
        $observed = AdmissionApplicationWasObserved::occur(
            new AdmissionDecisionId($before->id->value),
            ["decision_id" => $observation->value, "application" => AdmissionDecisionSchema::facts($before)],
            ["__aggregate_type" => "admission_decision"],
        )->withPlayhead(1);
        $root->acceptHistorical($observed);
        $event = AdmissionWasDecided::occur(
            new AdmissionDecisionId($before->id->value),
            [
                "decision_id" => $operation->value,
                "previous_decision_id" => $observation->value,
                "status" => $after->status->value,
                "application_version" => $after->version,
                "source" => $source,
            ],
            ["__aggregate_type" => "admission_decision"],
        )->withPlayhead(2);
        $root->acceptHistorical($event);
        $at = $this->clock->now();
        $this->history->append(
            $this->stream($before->id),
            $observation,
            0,
            new EventContext(
                $actor,
                $at,
                $operation,
                reason: "Submitted application observed before this decision; earlier history is unknown.",
            ),
            [$observed],
        );
        $this->history->append(
            $this->stream($before->id),
            $operation,
            1,
            new EventContext($actor, $at, $operation, $observation, $reason),
            [$event],
        );
        $this->verify($after);
        return $this->result($root, $at->format(DATE_ATOM));
    }
    /** @return array<string,mixed> */
    public function timeline(AdmissionApplication $a): array
    {
        $r = $this->load($a);
        $source = $r["root"]->source();
        if ($source === null) {
            return [
                "history_recorded" => false,
                "earlier_history_unknown" => true,
                "current_status" => $a->status->value,
            ];
        }
        return [
            "history_recorded" => true,
            "earlier_history_unknown" => true,
            "current_status" => $a->status->value,
            "decision" => $this->result($r["root"], $r["at"] ?? throw new CorruptHistoryException()),
            "source" => AdmissionDecisionSchema::displaySource($source),
            "recorded_at" => $r["recorded_at"],
        ];
    }
    /** @return array{root:AdmissionDecision,actor:?string,at:?string,recorded_at:?string} */
    private function load(AdmissionApplication $a): array
    {
        $root = AdmissionDecision::root(new AdmissionDecisionId($a->id->value));
        $cursor = null;
        $actor = null;
        $at = null;
        $recordedAt = null;
        do {
            $page = $this->history->page($this->stream($a->id), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if ($stored->event instanceof AdmissionWasDecided) {
                    $actor = $stored->context->actor?->value;
                    $at = $stored->context->effectiveAt?->format(DATE_ATOM);
                    $recordedAt = $stored->recordedAt->format(DATE_ATOM);
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $facts = $root->application();
        if ($facts !== null) {
            if ($root->source() === null || $actor === null || $at === null) {
                throw new CorruptHistoryException();
            }
            $expected = AdmissionDecisionSchema::facts($a);
            if ($a->status === ApplicationStatus::Matriculated) {
                if ($facts["status"] !== "accepted" || $a->version !== $facts["version"] + 1) {
                    throw new CorruptHistoryException();
                }
                $this->verifyMatriculation($a, $facts["version"]);
                $expected["status"] = "accepted";
                $expected["version"] = $facts["version"];
            }
            foreach ($expected as $key => $value) {
                if ($facts[$key] !== $value) {
                    throw new CorruptHistoryException();
                }
            }
        }
        return ["root" => $root, "actor" => $actor, "at" => $at, "recorded_at" => $recordedAt];
    }
    private function verifyMatriculation(AdmissionApplication $a, int $version): void
    {
        $root = Matriculation::root(new MatriculationId($a->id->value));
        $page = $this->history->page(
            new StreamReference(new MatriculationId($a->id->value), "matriculation"),
            null,
            2,
        );
        if ($page->nextCursor !== null) {
            throw new CorruptHistoryException();
        }
        foreach ($page->events as $stored) {
            $root->acceptHistorical($stored->event);
        }
        $f = $root->facts();
        if (
            $f === null ||
            $f["accepted_version"] !== $version ||
            $f["person_id"] !== $a->personId->value ||
            $f["program_id"] !== $a->programId->value ||
            $f["entry_term_id"] !== $a->entryTermId->value
        ) {
            throw new CorruptHistoryException();
        }
    }
    /** @return array<string,mixed> */
    private function result(AdmissionDecision $root, string $at): array
    {
        $a = $root->application() ?? throw new CorruptHistoryException();
        return [
            "application_id" => $a["id"],
            "decision_id" => $root->decision(),
            "status" => $a["status"],
            "version" => $a["version"],
            "effective_at" => $at,
        ];
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(new AdmissionDecisionId($id->value), "admission_decision");
    }
}
