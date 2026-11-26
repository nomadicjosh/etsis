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
    public function show(EntityId $term, ?EntityId $section = null): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $state = $this->current($term, $section);
        $own = $state['event']['definition'] ?? null;
        $inherit = $section !== null && ($own === null || $own['uses_term_policy']);
        $effective = $inherit ? $this->current($term) : $state;
        $row = $effective['row'];
        $published = $effective['event']['definition'] ?? null;
        $calendar = $this->policies->calendar($term, $section);
        $windows = $row['windows'] ?? [['through' => null, 'grade_code' => 'W']];
        $result = [
            'term' => $calendar,
            'version' => $state['row']['version'] ?? -1,
            'windows' => $windows,
            'default_policy' => $row === null,
            'published' => $own,
            'calendar_changed' => !$inherit && $own !== null && !$this->same(
                array_diff_key($own, ['windows' => true]),
                $this->calendar($term, $section, $section === null ? null : $own['grace_ends_on'], false),
            ),
            'grading' => array_map(fn(array $w): ?array => $this->policies->grade($w['grade_code']), $windows),
            'can_manage_credits' => $this->canManageCredits($actor),
        ];
        if ($section !== null) {
            $result += ['section' => ['id' => $section->value, 'code' => $calendar['section_code']],
                'uses_term_policy' => $inherit,
                'grace_ends_on' => $published['grace_ends_on'] ?? $calendar['add_drop_ends_on']];
        }
        $this->authorize($actor);
        return $result;
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $term,
        int $version,
        DropWindows $windows,
        #[\SensitiveParameter] string $reason,
        ?EntityId $section = null,
        ?string $grace = null,
        bool $inherit = false,
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
            $section,
            $grace,
            $inherit,
        ): array {
            $this->policies->lockTerm($term, $section);
            $state = $this->current($term, $section);
            if (
                $version < -1 ||
                $version >= 2000000000 ||
                ($state["row"]["version"] ?? -1) !== $version
            ) {
                throw new ConcurrentModificationException(
                    "Reload the drop policy before reviewing.",
                );
            }
            $inputs = $this->inputs($term, $windows->windows, $section, $grace, $inherit);
            $creditSource = $this->creditSource($term, $section, $state);
            $this->authorize($actor, $this->financial(
                $inputs['definition']['windows'],
                [...($state['row']['windows'] ?? []), ...$creditSource['windows']]
            ));
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
                'credit_source' => $creditSource,
                "inputs" => $inputs,
                "reason" => trim($reason),
                "reviewed_at" => $this->clock->now()->format(DATE_ATOM),
            ] + ($section === null ? [] : ['section_id' => $section->value]);
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
        ?EntityId $section = null,
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
                ($data["term_id"] ?? null) !== $term->value ||
                ($data['section_id'] ?? null) !== $section?->value
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
            $section,
        ): array {
            $this->policies->lockTerm($term, $section);
            $current = $this->current($term, $section);
            $root = $this->replay($term, $section);
            if (
                $root->playhead() !== $current["head"] ||
                $root->current() !== $current["event"]
            ) {
                throw new CorruptHistoryException();
            }
            $financial = $this->financial(
                $data['inputs']['definition']['windows'],
                [...($data['previous_policy']['windows'] ?? []), ...($data['credit_source']['windows'] ?? [])]
            );
            $this->authorize($actor, $financial);
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
                (isset($data['credit_source']) &&
                    $this->creditSource($term, $section, $current) !== $data['credit_source']) ||
                $this->inputs(
                    $term,
                    $data["inputs"]["definition"]["windows"],
                    $section,
                    $section === null ? null : $data['inputs']['definition']['grace_ends_on'],
                    $data['inputs']['definition']['uses_term_policy'] ?? false,
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
            $eventClass = $section === null ? DropPolicyWasPublished::class :
                \Domain\Registration\Event\SectionDropPolicyWasPublished::class;
            $event = $eventClass::occur(
                new DropPolicyPublicationId(($section ?? $term)->value, $section !== null),
                [
                    "publication_id" => $op->value,
                    "previous_publication_id" =>
                        $data["previous_publication_id"],
                    "version" => $data["version"] + 1,
                    "definition" => $data["inputs"]["definition"],
                    "observed_previous_policy" => $legacy,
                    "review_hash" => $hash,
                ],
                ["__aggregate_type" => $section === null ? "term_drop_policy" : "section_drop_policy"],
            )->withPlayhead($root->playhead() + 1);
            $root->acceptHistorical($event);
            $this->authorize($actor, $financial);
            $this->history->append(
                $this->stream($term, $section),
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
                $section,
            );
            $this->audit->record(
                $actor,
                "registration.drop_policy_published",
                $section ?? $term,
            );
            $this->authorize($actor, $financial);
            $after = $this->current($term, $section);
            $completedAge = $this->clock->now()->getTimestamp() -
                new DateTimeImmutable($data['reviewed_at'])->getTimestamp();
            if (
                !$this->same($after['event'], $root->current()) ||
                ($section !== null && ($data['credit_source']['scope'] ?? null) === 'term' &&
                    $this->creditSource($term, null, $this->current($term)) !== $data['credit_source']) ||
                $this->inputs(
                    $term,
                    $definition['windows'],
                    $section,
                    $section === null ? null : $definition['grace_ends_on'],
                    $definition['uses_term_policy'] ?? false
                ) !== $data['inputs'] ||
                $completedAge < 0 || $completedAge > 1200
            ) {
                throw new ConcurrentModificationException(
                    'The reviewed policy changed before completion. Review again.',
                );
            }
            return [
                "publication_id" => $op->value,
                "version" => $data["version"] + 1,
            ];
        });
    }

    /** Authorized summaries only; protected reasons/actors and raw envelopes are never returned.
     * @return array<string,mixed> */
    public function history(EntityId $term, ?string $cursor = null, ?EntityId $section = null): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->policies->calendar($term, $section);
        $page = $this->history->page($this->stream($term, $section), $cursor, 20);
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
        if ($section !== null) {
            $this->policies->calendar($term, $section);
        }
        $sectionState = $section === null ? null : $this->current($term, $section);
        $override = $sectionState !== null && $sectionState['event'] !== null &&
        !$sectionState['event']['definition']['uses_term_policy'];
        $state = $override ? $sectionState : $this->current($term);
        $policy = $state["row"];
        $published = $state["event"];
        if (
            $published !== null && ($published['definition']['term_id'] !== $term->value ||
            $published["definition"]["timezone"] !== $this->clock->now()->getTimezone()->getName())
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
                    ] + ($override ? ['section_id' => $section->value] : []),
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
        $credit = new DropWindows($policy['windows'])->creditOn($effective);
        if (($credit ?? 0) > 0 && $published === null) {
            throw new ValidationException([['field' => 'policy', 'message' =>
                \Codefy\Framework\Helpers\trans(
                    'Publish and approve the withdrawal credit schedule before using it.',
                )]]);
        }
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
        ] + ($credit === null ? [] : ['credit_basis_points' => $credit]);
    }

    /** @return array{row:?array,event:?array,head:int} */
    private function current(EntityId $term, ?EntityId $section = null): array
    {
        $row = $this->policies->find($term, $section);
        $stored = $this->history->latest($this->stream($term, $section));
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
            $eventClass = $section === null ? DropPolicyWasPublished::class :
            \Domain\Registration\Event\SectionDropPolicyWasPublished::class;
            if (!($stored->event instanceof $eventClass)) {
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

    /** @return array<string,mixed> */
    private function calendar(
        EntityId $term,
        ?EntityId $section = null,
        ?string $grace = null,
        bool $inherit = false
    ): array {
        $calendar = $this->policies->calendar($term, $section);
        return [
            'term_id' => $term->value, 'term_code' => $calendar['code'],
            'starts_on' => $calendar['starts_on'], 'ends_on' => $calendar['ends_on'],
            'grace_ends_on' => $grace ?? $calendar['add_drop_ends_on'],
            'timezone' => $this->clock->now()->getTimezone()->getName(),
        ] + ($section === null ? [] : ['section_id' => $section->value,
            'section_code' => $calendar['section_code'], 'uses_term_policy' => $inherit]);
    }
    /** @param list<array{through:?string,grade_code:string}> $windows
     * @return array<string,mixed> */
    private function inputs(
        EntityId $term,
        array $windows,
        ?EntityId $section = null,
        ?string $grace = null,
        bool $inherit = false
    ): array {
        if ($section === null && ($grace !== null || $inherit)) {
            throw new \InvalidArgumentException('Section overrides require a section.');
        }
        $inherited = null;
        if ($inherit) {
            $state = $this->current($term);
            $calendar = $state['event']['definition'] ?? $this->calendar($term);
            $windows = $state['row']['windows'] ?? [['through' => null, 'grade_code' => 'W']];
            $calendar = array_replace(
                $this->calendar($term, $section, $calendar['grace_ends_on'], true),
                array_intersect_key($calendar, array_flip(DropPolicyDefinition::FIELDS))
            );
            $inherited = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        } else {
            if ($section !== null && $grace === null) {
                throw new ValidationException([['field' => 'grace_ends_on',
                    'message' => 'Enter the section drop/add deadline.']]);
            }
            $calendar = $this->calendar($term, $section, $grace);
        }
        try {
            $definition = new DropPolicyDefinition($calendar + ['windows' => new DropWindows($windows)->windows]);
        } catch (\InvalidArgumentException $error) {
            throw new ValidationException([['field' => 'policy', 'message' => $error->getMessage()]]);
        }
        $grades = [];
        foreach ($definition->data['windows'] as $window) {
            $grade = $this->policies->grade($window['grade_code']);
            if (!$inherit && $grade === null) {
                throw new ValidationException([['field' => 'windows',
                    'message' => 'Choose existing catalog grade codes.']]);
            }
            $grades[] = $grade;
        }
        return ['definition' => $definition->data, 'grading' => $grades] +
        ($inherit ? ['inherited_revision' => $inherited] : []);
    }

    private function replay(EntityId $term, ?EntityId $section = null): DropPolicyPublication
    {
        $root = DropPolicyPublication::root(
            new DropPolicyPublicationId(($section ?? $term)->value, $section !== null),
        );
        $cursor = null;
        $pages = 0;
        do {
            if (++$pages > 50) {
                throw new CorruptHistoryException();
            }
            $page = $this->history->page($this->stream($term, $section), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $root;
    }

    private function stream(EntityId $term, ?EntityId $section = null): StreamReference
    {
        return new StreamReference(
            new DropPolicyPublicationId(($section ?? $term)->value, $section !== null),
            $section === null ? "term_drop_policy" : "section_drop_policy",
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

    private function creditSource(EntityId $term, ?EntityId $section, array $state): array
    {
        $parent = $section !== null && ($state['event'] === null || $state['event']['definition']['uses_term_policy']);
        $source = $parent ? $this->current($term) : $state;
        return ['scope' => $parent || $section === null ? 'term' : 'section',
            'publication_id' => $source['event']['publication_id'] ?? null,
            'windows' => $source['row']['windows'] ?? []];
    }

    private function financial(array $proposed, array $previous): bool
    {
        return array_any(
            [...$proposed, ...$previous],
            static fn (array $row): bool => ($row['credit_basis_points'] ?? 0) > 0
        );
    }

    private function canManageCredits(EntityId $actor): bool
    {
        try {
            return $this->access->requirePermission('sis:finance:post')->value === $actor->value;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function authorize(EntityId $actor, bool $financial = false): void
    {
        if (
            $this->access->requirePermission("sis:catalog:manage")->value !==
            $actor->value || $this->access->authenticatedUserId()->value !== $actor->value ||
            ($financial && !$this->canManageCredits($actor))
        ) {
            throw new AccessDeniedException("Access denied.");
        }
    }
}
