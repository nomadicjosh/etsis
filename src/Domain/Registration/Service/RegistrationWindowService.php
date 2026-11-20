<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Event\RegistrationWindowWasPublished;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\RegistrationWindowHistory;
use Domain\Registration\Repository\RegistrationWindowRepository;
use Domain\Registration\ValueObject\EnrollmentFacts;
use Domain\Registration\ValueObject\RegistrationWindowDefinition;
use Domain\Registration\ValueObject\RegistrationWindowId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;

final readonly class RegistrationWindowService
{
    public function __construct(
        private RegistrationWindowRepository $records,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private MessageCipher $cipher,
    ) {
    }
    /** Internal command read: the caller owns student/section locks and authorization; this locks the term. */
    public function checked(
        EntityId $term,
        RegistrationWindow $read,
    ): RegistrationWindow {
        $this->records->lock($term);
        $state = $this->state($term);
        $current =
        $state["window"] ??
        throw new RecordNotFoundException(
            'Configure this term\'s registration window.',
        );
        if ($current->values() !== $read->values()) {
            throw new ConcurrentModificationException(
                "The registration window changed. Review registration again.",
            );
        }
        return $current;
    }
    public function show(EntityId $term): array
    {
        $actor = $this->authorize();
        $state = $this->state($term);
        $result = [
            "term" => ["id" => $term->value, "code" => $state["term"]["code"]],
            "definition" =>
                $state["head"]?->event->payload()["definition"] ??
                ($state["window"] === null
                    ? null
                    : $this->definition($term, $state["window"])->data),
            "version" => $state["head"]?->event->playhead() ?? 0,
            "published" => $state["head"] !== null,
        ];
        $this->authorize($actor);
        return $result;
    }
    public function review(
        EntityId $term,
        RegistrationWindow $window,
        int $version,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        if (
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            !mb_check_encoding($reason, "UTF-8") ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" => "Enter a reason, up to 1,000 characters.",
                ],
            ]);
        }
        return $this->transactions->run(function () use (
            $term,
            $window,
            $version,
            $reason,
            $actor,
        ): array {
            $this->records->lock($term);
            $this->authorize($actor);
            $state = $this->state($term);
            if (
                $version < 0 ||
                $version >= 1000 ||
                $version !== ($state["head"]?->event->playhead() ?? 0)
            ) {
                throw new ConcurrentModificationException(
                    "Reload this registration policy before reviewing.",
                );
            }
            $definition = $this->definition($term, $window);
            $data = [
                "kind" => "registration-window",
                "term_id" => $term->value,
                "actor_id" => $actor->value,
                "operation_id" => EntityId::generate()->value,
                "expected_version" => $version,
                "previous" => $state["row"],
                "definition" => $definition->data,
                "reason" => trim($reason),
                "issued_at" => $this->clock->now()->getTimestamp(),
            ];
            $this->authorize($actor);
            return [
                "definition" => $definition->data,
                "version" => $version + 1,
                "review_token" => $this->cipher->seal(
                    json_encode($data, JSON_THROW_ON_ERROR),
                ),
            ];
        });
    }
    public function publish(
        EntityId $term,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize();
        try {
            if (strlen($token) > 32768) {
                throw new \InvalidArgumentException();
            }
            $data = json_decode(
                $this->cipher->open($token),
                true,
                12,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($data) ||
                ($data["kind"] ?? null) !== "registration-window" ||
                ($data["term_id"] ?? null) !== $term->value
            ) {
                throw new \InvalidArgumentException();
            }
            $definition = new RegistrationWindowDefinition($data["definition"]);
            $operation = new EntityId($data["operation_id"]);
        } catch (\Throwable) {
            throw new ValidationException([
                [
                    "field" => "review_token",
                    "message" => "Review this registration policy again.",
                ],
            ]);
        }
        if ($data["actor_id"] !== $actor->value) {
            throw new AccessDeniedException(
                "This review belongs to another staff member.",
            );
        }
        return $this->transactions->run(function () use (
            $term,
            $data,
            $definition,
            $operation,
            $actor,
        ): array {
            $this->records->lock($term);
            $this->authorize($actor);
            $state = $this->state($term);
            $root = RegistrationWindowHistory::root(
                new RegistrationWindowId($term->value),
            );
            $cursor = null;
            $budget = new HistoryReadBudget(50, 1000);
            do {
                $page = $budget->page(
                    $this->history,
                    self::stream($term),
                    $cursor,
                );
                foreach ($page->events as $stored) {
                    $root->acceptHistorical($stored->event);
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            $hash = hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
            if (($old = $root->operation($operation->value)) !== null) {
                if (!hash_equals($old["review_hash"], $hash)) {
                    throw new ConcurrentModificationException(
                        "This operation belongs to another review.",
                    );
                }
                return array_intersect_key(
                    $old,
                    array_flip(["publication_id", "version"]),
                );
            }
            $this->fresh($term, $data, $definition, $actor);
            if ($root->playhead() !== $data["expected_version"]) {
                throw new ConcurrentModificationException(
                    "The registration policy changed. Review again.",
                );
            }
            $observed =
                $root->playhead() === 0 && $state["window"] !== null
                    ? $this->definition($term, $state["window"])->data
                    : null;
            $event = RegistrationWindowWasPublished::occur(
                new RegistrationWindowId($term->value),
                [
                    "publication_id" => $operation->value,
                    "previous_publication_id" =>
                        $root->current()["publication_id"] ?? null,
                    "definition" => $definition->data,
                    "observed_previous" => $observed,
                    "review_hash" => $hash,
                ],
                ["__aggregate_type" => "term_registration_window"],
            )->withPlayhead($root->playhead() + 1);
            $this->history->append(
                self::stream($term),
                $operation,
                $root->playhead(),
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $operation,
                    reason: $data["reason"],
                ),
                [$event],
            );
            $reference = [
                "term_id" => $term->value,
                "publication_id" => $operation->value,
                "version" => $event->playhead(),
                "definition_hash" => $definition->fingerprint(),
            ];
            $this->records->publish($term, $definition->data, $reference);
            $this->audit->record(
                $actor,
                "registration.window_published",
                $term,
            );
            $this->authorize($actor);
            $after = $this->state($term);
            $this->age($data);
            if (
                $after["window"]->reference != $reference ||
                $this->definition($term, $definition->window())->data !==
                    $definition->data
            ) {
                throw new ConcurrentModificationException(
                    "The reviewed policy changed before completion.",
                );
            }
            return [
                "publication_id" => $operation->value,
                "version" => $event->playhead(),
            ];
        });
    }
    public function page(EntityId $term, ?string $cursor = null): array
    {
        $actor = $this->authorize();
        $this->records->term($term);
        $page = $this->history->page(self::stream($term), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            $items[] = [
                "publication_id" => $p["publication_id"],
                "version" => $stored->event->playhead(),
                "definition" => $p["definition"],
                "observed_previous" => $p["observed_previous"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        $this->authorize($actor);
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }
    private function fresh(
        EntityId $term,
        array $data,
        RegistrationWindowDefinition $definition,
        EntityId $actor,
    ): void {
        $this->authorize($actor);
        $this->age($data);
        $state = $this->state($term);
        if (
            $state["row"] !== $data["previous"] ||
            ($state["head"]?->event->playhead() ?? 0) !==
                $data["expected_version"] ||
            $this->definition($term, $definition->window())->data !==
                $definition->data
        ) {
            throw new ConcurrentModificationException(
                "The term or registration policy changed. Review again.",
            );
        }
    }
    private function age(array $data): void
    {
        $age = $this->clock->now()->getTimestamp() - $data["issued_at"];
        if ($age < 0 || $age >= 1200) {
            throw new ConcurrentModificationException(
                "This review expired. Review the current policy again.",
            );
        }
    }
    private function definition(
        EntityId $term,
        RegistrationWindow $window,
    ): RegistrationWindowDefinition {
        return new RegistrationWindowDefinition([
            "term_id" => $term->value,
            "term_code" => $this->records->term($term)["code"],
            "opens_at" => EnrollmentFacts::utc($window->dates->start),
            "closes_at" => EnrollmentFacts::utc($window->dates->end),
            "course_limit" => $window->courseLimit,
            "timezone" => $this->clock->now()->getTimezone()->getName(),
        ]);
    }
    private function state(EntityId $term): array
    {
        $calendar = $this->records->term($term);
        $row = $this->records->current($term);
        $head = $this->history->latest(self::stream($term));
        $window = null;
        if ($row !== null) {
            $dates = [];
            foreach (["opens_at", "closes_at"] as $key) {
                $date = DateTimeImmutable::createFromFormat(
                    "!Y-m-d H:i:s",
                    $row[$key],
                    new DateTimeZone("UTC"),
                );
                if (
                    $date === false ||
                    $date->format("Y-m-d H:i:s") !== $row[$key]
                ) {
                    throw new CorruptHistoryException();
                }
                $dates[] = $date;
            }
            $limit = filter_var($row["course_limit"], FILTER_VALIDATE_INT);
            if ($limit === false || $limit < 1 || $limit > 1000) {
                throw new CorruptHistoryException();
            }
            $window = new RegistrationWindow(new DateRange(...$dates), $limit);
        }
        if ($head !== null) {
            if (
                !($head->event instanceof RegistrationWindowWasPublished) ||
                $row === null
            ) {
                throw new CorruptHistoryException();
            }
            $p = $head->event->payload();
            $definition = new RegistrationWindowDefinition($p["definition"]);
            $reference = [
                "term_id" => $term->value,
                "publication_id" => $p["publication_id"],
                "version" => $head->event->playhead(),
                "definition_hash" => $definition->fingerprint(),
            ];
            if (
                $row["publication_id"] !== $reference["publication_id"] ||
                (int) $row["policy_version"] !== $reference["version"] ||
                $row["definition_hash"] !== $reference["definition_hash"] ||
                $window->values() !== $definition->window()->values()
            ) {
                throw new CorruptHistoryException();
            }
            $window = $definition->window($reference);
        } elseif (
            ($row["publication_id"] ?? null) !== null ||
            ($row["policy_version"] ?? null) !== null ||
            ($row["definition_hash"] ?? null) !== null
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "term" => $calendar,
            "row" => $row,
            "head" => $head,
            "window" => $window,
        ];
    }
    public static function stream(EntityId $term): StreamReference
    {
        return new StreamReference(
            new RegistrationWindowId($term->value),
            "term_registration_window",
        );
    }
    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException("The catalog account changed.");
        }
        return $actor;
    }
}
