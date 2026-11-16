<?php

declare(strict_types=1);

namespace Domain\Finance\Service;

use Codefy\Domain\EventSourcing\EventStream;
use DateTimeZone;
use Domain\Audit\Repository\ActorDirectory;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Event\AccountEntryWasCorrected;
use Domain\Finance\Event\AccountPaymentDetailsWereChanged;
use Domain\Finance\Event\AccountEntryWasPosted;
use Domain\Finance\Event\AccountEntryWasReversed;
use Domain\Finance\StudentAccount;
use Domain\Finance\ValueObject\AccountId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

/** Selected financial facts only. No raw envelopes, hashes, proofs or source-system payloads leave this query. */
final readonly class AccountEntryHistory
{
    private const int PAGE_SIZE = 20;
    private const int MAX_EVENTS = 2000;
    private const int MAX_SUMMARY_BYTES = 2097152;

    public function __construct(
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private ActorDirectory $actors,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function page(AccountId $id, EntityId $operation, int $after = 0, ?int $throughPosition = null): array
    {
        $actor = $this->authorize();
        if (
            $after < 0 ||
            $after >= self::MAX_EVENTS ||
            $after % self::PAGE_SIZE !== 0 ||
            ($after !== 0 && $throughPosition === null) ||
            ($throughPosition !== null && ($throughPosition < 1 || $throughPosition > self::MAX_EVENTS))
        ) {
            throw $this->invalidPage();
        }
        return $this->transactions->run(function () use ($id, $operation, $after, $throughPosition, $actor): array {
            $budget = new HistoryReadBudget();
            $cursor = null;
            $through = $throughPosition;
            $events = $summaries = [];
            $bytes = 0;
            do {
                $page = $budget->page($this->history, new StreamReference($id, "student_account"), $cursor);
                if ($page->throughVersion === 0) {
                    throw new RecordNotFoundException("Account not found.");
                }
                if ($page->throughVersion > self::MAX_EVENTS) {
                    throw new HistoryReadLimitException();
                }
                $through ??= $page->throughVersion;
                if ($through > $page->throughVersion) {
                    throw $this->invalidPage();
                }
                foreach ($page->events as $stored) {
                    if ($stored->event->playhead() > $through) {
                        continue;
                    }
                    $events[] = $stored->event;
                    $summary = $this->summary($stored);
                    if ($summary === null) {
                        continue;
                    }
                    // Retain only minimized context; discard legacy rows and other envelope metadata per page.
                    $bytes += strlen($summary["reason"] ?? "") + 512
                        + strlen($summary['payment_details']['reference'] ?? '')
                        + strlen($summary['previous_payment_details']['reference'] ?? '');
                    if ($bytes > self::MAX_SUMMARY_BYTES) {
                        throw new HistoryReadLimitException();
                    }
                    $summaries[] = $summary;
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            $root = StudentAccount::reconstituteFromEventStream(new EventStream($id, $events));
            if (!($root instanceof StudentAccount) || $root->playhead() !== $through) {
                throw new CorruptHistoryException();
            }
            $entry = $root->entry($operation) ?? throw new RecordNotFoundException("Account entry not found.");
            $family = $this->family($root, $operation);
            $related = array_values(
                array_filter($summaries, static fn(array $row): bool => isset($family[$row["operation_id"]])),
            );
            if ($related === [] || ($after !== 0 && $after >= count($related))) {
                throw $this->invalidPage();
            }
            $items = array_slice(array_reverse($related), $after, self::PAGE_SIZE);
            $this->authorize($actor);
            $ids = [];
            foreach ($items as $row) {
                if ($row["actor_id"] !== null) {
                    $ids[$row["actor_id"]] = new EntityId($row["actor_id"]);
                }
            }
            $names = $this->actors->names(array_values($ids));
            foreach ($items as &$row) {
                $row["recorded_by"] =
                    $row["actor_id"] === null ? "Not recorded" : $names[$row["actor_id"]] ?? "Unavailable account";
                unset($row["actor_id"]);
                $actual = $root->entry(new EntityId($row["operation_id"])) ?? throw new CorruptHistoryException();
                $row["type"] = $actual["type"];
                $row["currency"] = $actual["currency"];
                $row["amount_minor"] = $actual["minor"];
                if (isset($row["source_operation_id"])) {
                    $source =
                        $root->entry(new EntityId($row["source_operation_id"])) ??
                        throw new CorruptHistoryException();
                    $row["previous_minor"] = $source["minor"];
                }
            }
            unset($row);
            $this->audit->record($actor, "account.entry_history_read", new EntityId((string) $id));
            $this->authorize($actor);
            return [
                "operation_id" => $operation->value,
                "entry" => $entry,
                "through_position" => $through,
                "items" => $items,
                "next_after" => count($related) > $after + self::PAGE_SIZE ? $after + self::PAGE_SIZE : null,
                "earlier_details_unknown" =>
                    count(
                        array_filter(
                            $related,
                            static fn(array $r): bool => $r["actor_source"] !== "command" ||
                                $r["effective_at"] === null ||
                                $r["reason"] === null,
                        ),
                    ) > 0,
            ];
        });
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:finance:read");
        if (
            ($expected !== null && $actor->value !== $expected->value) ||
            $this->access->requirePermission("sis:finance:post")->value !== $actor->value
        ) {
            throw new AccessDeniedException("The financial history reviewer changed.");
        }
        return $actor;
    }

    /** @return array<string,mixed>|null */
    private function summary(#[\SensitiveParameter] StoredEvent $stored): ?array
    {
        $event = $stored->event;
        $action = match (true) {
            $event instanceof AccountEntryWasPosted => "posted",
            $event instanceof AccountEntryWasReversed => "reversed",
            $event instanceof AccountEntryWasCorrected => "corrected",
            $event instanceof AccountPaymentDetailsWereChanged => "receipt_corrected",
            default => null,
        };
        if ($action === null) {
            return null;
        }
        $payload = $event->payload();
        $recordedActor = $stored->context->actor;
        $actor = $recordedActor === null ? $payload["actor_id"] ?? null : $recordedActor->value;
        return [
            "sequence" => $event->playhead(),
            "operation_id" => $payload["entry_operation_id"] ?? $payload["operation_id"],
            "action" => $action,
            "actor_id" => $actor,
            "actor_source" =>
                $actor === null
                    ? "unknown"
                    : ($stored->context->origin === "command"
                        ? "command"
                        : "earlier_event"),
            "recorded_at" => $stored->recordedAt->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s.u\Z"),
            "effective_at" => $stored->context->effectiveAt
                ?->setTimezone(new DateTimeZone("UTC"))
                ->format("Y-m-d\TH:i:s.u\Z"),
            "reason" => $stored->context->reason,
        ] + ($action === "corrected" ? ["source_operation_id" => $payload["source_operation_id"]] : [])
        + ($action === 'receipt_corrected' ? ['previous_payment_details' => $payload['previous_details'],
                'payment_details' => $payload['details']]
                : (in_array($action, ['posted', 'corrected'], true)
                    ? ['payment_details' => $event->metadata()['payment_details'] ?? null] : []));
    }

    /** Original and replacement identities form one retained correction chain, including reversed entries.
     * @return array<string,true>
     */
    private function family(StudentAccount $root, EntityId $operation): array
    {
        $entries = [];
        foreach ($root->entries() as $entry) {
            $entries[$entry["operation_id"]] = $entry;
        }
        $family = [];
        $pending = [$operation->value];
        while ($pending !== []) {
            $id = array_pop($pending);
            if (isset($family[$id])) {
                continue;
            }
            $entry = $entries[$id] ?? throw new CorruptHistoryException();
            $family[$id] = true;
            foreach (["corrects_operation_id", "corrected_by_operation_id"] as $key) {
                if (isset($entry[$key])) {
                    $pending[] = $entry[$key];
                }
            }
        }
        return $family;
    }

    private function invalidPage(): ValidationException
    {
        return new ValidationException([["field" => "history", "message" => "Refresh this entry history."]]);
    }
}
