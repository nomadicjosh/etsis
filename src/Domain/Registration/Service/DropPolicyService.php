<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use Domain\AcademicRecord\Service\InstitutionalCreditLedger;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\DropPolicyPublication;
use Domain\Registration\Event\DropPolicyWasPublished;
use Domain\Registration\Repository\DropPolicyRepository;
use Domain\Registration\ValueObject\DropPolicyDefinition;
use Domain\Registration\ValueObject\DropPolicyPublicationId;
use Domain\Registration\ValueObject\DropWindows;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

final readonly class DropPolicyService
{
    public function __construct(
        private DropPolicyRepository $policies,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private InstitutionalCreditLedger $credits,
        private HistoricalEventStore $history,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function show(EntityId $term): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $state = $this->current($term);
        $row = $state["row"];
        $calendar = $this->policies->calendar($term);
        $windows = $row["windows"] ?? [
            ["through" => null, "grade_code" => "W"],
        ];
        $published = $state["event"]["definition"] ?? null;
        $result = [
            "term" => $calendar,
            "version" => $row["version"] ?? -1,
            "windows" => $windows,
            "default_policy" => $row === null,
            "published" => $published,
            "calendar_changed" =>
                $published !== null &&
                !$this->same(
                    array_diff_key($published, ["windows" => true]),
                    $this->calendar($term),
                ),
            "grading" => array_map(
                fn(array $w): ?array => $this->policies->grade(
                    $w["grade_code"],
                ),
                $windows,
            ),
        ];
        $this->authorize($actor);
        return $result;
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $term,
        int $version,
        DropWindows $windows,
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
                    "message" => "Enter a reason, up to 1,000 characters.",
                ],
            ]);
        }
        return $this->transactions->run(function () use (
            $term,
            $version,
            $windows,
            $reason,
            $actor,
        ): array {
            $this->policies->lockTerm($term);
            $state = $this->current($term);
            if (
                $version < -1 ||
                $version >= 2000000000 ||
                ($state["row"]["version"] ?? -1) !== $version
            ) {
                throw new ConcurrentModificationException(
                    "Reload the drop policy before reviewing.",
                );
            }
            $inputs = $this->inputs($term, $windows->windows);
            $this->authorize($actor);
            $data = [
                "kind" => "term-drop-policy-review",
                "term_id" => $term->value,
                "actor_id" => $actor->value,
                "operation_id" => EntityId::generate()->value,
                "head" => $state["head"],
                "version" => $version,
                "previous_publication_id" =>
                    $state["event"]["publication_id"] ?? null,
                "previous_policy" => $state["row"],
                "inputs" => $inputs,
                "reason" => trim($reason),
                "reviewed_at" => $this->clock->now()->format(DATE_ATOM),
            ];
            return [
                "definition" => $inputs["definition"],
                "grading" => $inputs["grading"],
                "version" => $version + 1,
                "review_token" => $this->cipher->seal(
                    json_encode($data, JSON_THROW_ON_ERROR),
                ),
            ];
        });
    }

    /** @return array{publication_id:string,version:int} */
    public function commit(
        EntityId $term,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 65536) {
                throw new \InvalidArgumentException();
            }
            $data = json_decode(
                $this->cipher->open($token),
                true,
                20,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($data) ||
                ($data["kind"] ?? null) !== "term-drop-policy-review" ||
                ($data["term_id"] ?? null) !== $term->value
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            throw new ValidationException([
                [
                    "field" => "review_token",
                    "message" => "Review this drop policy again.",
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
            $actor,
        ): array {
            $this->policies->lockTerm($term);
            $current = $this->current($term);
            $root = $this->replay($term);
            if (
                $root->playhead() !== $current["head"] ||
                $root->current() !== $current["event"]
            ) {
                throw new CorruptHistoryException();
            }
            $this->authorize($actor);
            $hash = hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
            $old = $root->operation($data["operation_id"]);
            if ($old !== null) {
                if (!hash_equals($old["review_hash"], $hash)) {
                    throw new ConcurrentModificationException(
                        "Review this policy again.",
                    );
                }
                return [
                    "publication_id" => $old["publication_id"],
                    "version" => $old["version"],
                ];
            }
            $now = $this->clock->now();
            $age =
                $now->getTimestamp() -
                new DateTimeImmutable($data["reviewed_at"])->getTimestamp();
            if (
                $age < 0 ||
                $age > 1200 ||
                $current["head"] !== $data["head"] ||
                $current["row"] !== $data["previous_policy"] ||
                $this->inputs(
                    $term,
                    $data["inputs"]["definition"]["windows"],
                ) !== $data["inputs"]
            ) {
                throw new ConcurrentModificationException(
                    "The review expired or the term, grades or policy changed. Review again.",
                );
            }
            if ($root->playhead() >= 1000) {
                throw new ValidationException([
                    [
                        "field" => "policy",
                        "message" =>
                            "This policy history needs an operator review before another publication.",
                    ],
                ]);
            }
            $op = new EntityId($data["operation_id"]);
            $legacy =
                $current["head"] === 0 && $current["row"] !== null
                    ? array_intersect_key(
                        $current["row"],
                        array_flip(["version", "windows"]),
                    )
                    : null;
            $event = DropPolicyWasPublished::occur(
                new DropPolicyPublicationId($term->value),
                [
                    "publication_id" => $op->value,
                    "previous_publication_id" =>
                        $data["previous_publication_id"],
                    "version" => $data["version"] + 1,
                    "definition" => $data["inputs"]["definition"],
                    "observed_previous_policy" => $legacy,
                    "review_hash" => $hash,
                ],
                ["__aggregate_type" => "term_drop_policy"],
            )->withPlayhead($root->playhead() + 1);
            $root->acceptHistorical($event);
            $this->authorize($actor);
            $this->history->append(
                $this->stream($term),
                $op,
                $current["head"],
                new EventContext($actor, $now, $op, reason: $data["reason"]),
                [$event],
            );
            $definition = $data["inputs"]["definition"];
            $this->policies->save(
                $term,
                $data["version"],
                $definition["windows"],
                $op,
                array_diff_key($definition, ["windows" => true]),
            );
            $this->audit->record(
                $actor,
                "registration.drop_policy_published",
                $term,
            );
            $this->authorize($actor);
            return [
                "publication_id" => $op->value,
                "version" => $data["version"] + 1,
            ];
        });
    }

    /** Authorized summaries only; protected reasons/actors and raw envelopes are never returned.
     * @return array<string,mixed> */
    public function history(EntityId $term, ?string $cursor = null): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->policies->calendar($term);
        $page = $this->history->page($this->stream($term), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            $items[] = [
                "publication_id" => $p["publication_id"],
                "version" => $p["version"],
                "definition" => $p["definition"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                "observed_previous_policy" => $p["observed_previous_policy"],
            ];
        }
        $this->authorize($actor);
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }

    /** Calling workflows separately authorize and lock the affected student/section/term.
     * @return array<string,mixed> */
    public function outcome(
        EntityId $term,
        string $effective,
        ?EntityId $student = null,
        ?EntityId $enrollment = null,
        ?EntityId $section = null,
        ?\Domain\Shared\EventSourcing\HistoryReadBudget $budget = null,
    ): array {
        $state = $this->current($term);
        $policy = $state["row"];
        $published = $state["event"];
        if (
            $published !== null &&
            $published["definition"]["timezone"] !==
                $this->clock->now()->getTimezone()->getName()
        ) {
            throw new ValidationException([
                [
                    "field" => "policy",
                    "message" =>
                        "Catalog staff must review this term’s drop policy after the institution timezone changed.",
                ],
            ]);
        }
        $calendar =
        $published["definition"] ?? $this->policies->calendar($term);
        $grace = $calendar["grace_ends_on"] ?? $calendar["add_drop_ends_on"];
        $base = [
            "term_code" => $calendar["term_code"] ?? $calendar["code"],
            "grace_ends_on" => $grace,
            "policy_reference" =>
                $published === null
                    ? null
                    : [
                        "term_id" => $term->value,
                        "publication_id" => $published["publication_id"],
                        "version" => $published["version"],
                        "definition_hash" => new DropPolicyDefinition(
                            $published["definition"],
                        )->hash(),
                    ],
        ];
        if ($effective <= $grace) {
            return $base + [
                "delete_records" => true,
                "grade_code" => null,
                "grade_id" => null,
                "points_milli" => 0,
                "counts_gpa" => false,
                "policy_version" => $published["version"] ?? -1,
            ];
        }
        if ($policy === null) {
            return $base + [
                "delete_records" => false,
                "grade_code" => "W",
                "grade_id" => null,
                "points_milli" => 0,
                "counts_gpa" => false,
                "policy_version" => -1,
            ];
        }
        $code = new DropWindows($policy["windows"])->gradeOn($effective);
        $grade =
        $student !== null && $enrollment !== null && $section !== null
        ? $this->credits->dropGrade(
            $enrollment,
            $student,
            $section,
            $code,
            $budget,
        )
        : $this->policies->grade($code, $student);
        if ($grade === null) {
            throw new ValidationException([
                [
                    "field" => "grade",
                    "message" =>
                        "Catalog staff must review this term’s missing drop grade.",
                ],
            ]);
        }
        return $base + [
            "delete_records" => false,
            "grade_code" => $code,
            "grade_id" => $grade["id"],
            "points_milli" => (int) $grade["points_milli"],
            "counts_gpa" => (bool) $grade["counts_gpa"],
            "policy_version" => $policy["version"],
        ];
    }

    /** @return array{row:?array,event:?array,head:int} */
    private function current(EntityId $term): array
    {
        $row = $this->policies->find($term);
        $stored = $this->history->latest($this->stream($term));
        $p = $stored?->event->payload();
        if ($p === null) {
            if (
                ($row["publication_id"] ?? null) !== null ||
                ($row["calendar"] ?? null) !== null
            ) {
                throw new CorruptHistoryException();
            }
            if ($row !== null) {
                new DropWindows($row["windows"]);
                if ($row["version"] < 0) {
                    throw new CorruptHistoryException();
                }
            }
        } else {
            if (!($stored->event instanceof DropPolicyWasPublished)) {
                throw new CorruptHistoryException();
            }
            $definition = $p["definition"];
            if (
                $row === null ||
                $row["publication_id"] !== $p["publication_id"] ||
                $row["version"] !== $p["version"] ||
                !$this->same($row["windows"], $definition["windows"]) ||
                !$this->same(
                    $row["calendar"],
                    array_diff_key($definition, ["windows" => true]),
                )
            ) {
                throw new CorruptHistoryException();
            }
        }
        return [
            "row" => $row,
            "event" => $p,
            "head" => $stored?->event->playhead() ?? 0,
        ];
    }

    /** @param list<array{through:?string,grade_code:string}> $windows */
    private function definition(
        EntityId $term,
        array $windows,
    ): DropPolicyDefinition {
        return new DropPolicyDefinition(
            $this->calendar($term) + ["windows" => $windows],
        );
    }

    /** @return array<string,string> */
    private function calendar(EntityId $term): array
    {
        $calendar = $this->policies->calendar($term);
        return [
            "term_id" => $term->value,
            "term_code" => $calendar["code"],
            "starts_on" => $calendar["starts_on"],
            "ends_on" => $calendar["ends_on"],
            "grace_ends_on" => $calendar["add_drop_ends_on"],
            "timezone" => $this->clock->now()->getTimezone()->getName(),
        ];
    }

    /** @param list<array{through:?string,grade_code:string}> $windows
     * @return array<string,mixed> */
    private function inputs(EntityId $term, array $windows): array
    {
        $windows = new DropWindows($windows)->windows;
        $calendar = $this->policies->calendar($term);
        $grades = [];
        foreach ($windows as $window) {
            $grade = $this->policies->grade($window["grade_code"]);
            if (
                ($window["through"] !== null &&
                    $window["through"] <= $calendar["add_drop_ends_on"]) ||
                $grade === null
            ) {
                throw new ValidationException([
                    [
                        "field" => "windows",
                        "message" =>
                            "Use cutoffs after the drop/add deadline and existing catalog grade codes.",
                    ],
                ]);
            }
            $grades[] = $grade;
        }
        return [
            "definition" => $this->definition($term, $windows)->data,
            "grading" => $grades,
        ];
    }

    private function replay(EntityId $term): DropPolicyPublication
    {
        $root = DropPolicyPublication::root(
            new DropPolicyPublicationId($term->value),
        );
        $cursor = null;
        $pages = 0;
        do {
            if (++$pages > 50) {
                throw new CorruptHistoryException();
            }
            $page = $this->history->page($this->stream($term), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $root;
    }

    private function stream(EntityId $term): StreamReference
    {
        return new StreamReference(
            new DropPolicyPublicationId($term->value),
            "term_drop_policy",
        );
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        if (
            count($a) !== count($b) ||
            array_is_list($a) !== array_is_list($b)
        ) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!array_key_exists($key, $b) || !$this->same($value, $b[$key])) {
                return false;
            }
        }
        return true;
    }

    private function authorize(EntityId $actor): void
    {
        if (
            $this->access->requirePermission("sis:catalog:manage")->value !==
            $actor->value
        ) {
            throw new AccessDeniedException("Access denied.");
        }
    }
}
