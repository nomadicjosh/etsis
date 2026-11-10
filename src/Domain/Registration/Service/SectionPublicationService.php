<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\Service\CoursePublicationService;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Event\SectionDefinitionWasPublished;
use Domain\Registration\Repository\SectionCourseBindingRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Registration\SectionPublication;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Registration\ValueObject\SectionPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use DomainException;
use InvalidArgumentException;
use Throwable;

final readonly class SectionPublicationService
{
    public function __construct(
        private CatalogRevisionRepository $catalog,
        private CatalogWorkflowRepository $relationships,
        private SectionCourseBindingRepository $bindings,
        private CoursePublicationService $courses,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private SectionVersionRepository $versions,
    ) {
    }

    /** @return array<string, mixed> */
    public function setup(EntityId $section): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use (
            $section,
            $actor,
        ): array {
            [$row, $term] = $this->lock($section);
            $this->authorize($actor);
            [$root] = $this->load($section);
            return [
                "section" => $row,
                "term" => $term,
                "course_definition" => $this->verifiedCourse($section),
                "latest" => $root->latest(),
                "faculty_label" => $this->facultyLabel($row),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function review(
        EntityId $section,
        string $startsOn,
        string $endsOn,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $this->validateInput($startsOn, $endsOn, $reason, $actor, $section);
        return $this->transactions->run(function () use (
            $section,
            $startsOn,
            $endsOn,
            $reason,
            $actor,
        ): array {
            [$row, $term] = $this->lock($section);
            $this->authorize($actor);
            $course =
                $this->verifiedCourse($section) ??
                throw new DomainException(
                    "Retain a course definition for this section before publishing its details.",
                );
            [$root] = $this->load($section);
            $definition = $this->definition(
                $row,
                $term,
                $course,
                $startsOn,
                $endsOn,
            );
            $latest = $root->latest();
            if ($latest !== null) {
                $this->sameOwnership($latest["definition"], $definition);
            }
            // Validate the protected reason before returning a review; the effective time is set on confirmation.
            new EventContext(
                $actor,
                $this->clock->now(),
                $section,
                reason: $reason,
            );
            $review = [
                "kind" => "section-publication-review.v1",
                "section_id" => $section->value,
                "actor_id" => $actor->value,
                "operation_id" => EntityId::generate()->value,
                "expected_version" => $root->playhead(),
                "previous_version_id" => $latest["version_id"] ?? null,
                "definition" => $definition,
                "source_hash" => $this->fingerprint([
                    $row,
                    $term,
                    $course,
                    $this->faculty($row),
                ]),
                "reason" => $reason,
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
            ];
            return $review + [
                "review_token" => $this->cipher->seal(
                    json_encode($review, JSON_THROW_ON_ERROR),
                ),
                "faculty_label" => $this->facultyLabel($row),
            ];
        });
    }

    /** @return array{operation_id: string, first_version: int, last_version: int, event_ids: list<string>} */
    public function publish(
        EntityId $section,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 16384) {
                throw new InvalidArgumentException();
            }
            $r = json_decode(
                $this->cipher->open($token),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($r) ||
                ($r["kind"] ?? null) !== "section-publication-review.v1" ||
                ($r["section_id"] ?? null) !== $section->value ||
                !is_string($r["actor_id"] ?? null) ||
                !is_string($r["operation_id"] ?? null) ||
                !is_int($r["expected_version"] ?? null) ||
                $r["expected_version"] < 0 ||
                !is_int($r["expires_at"] ?? null) ||
                !is_string($r["source_hash"] ?? null) ||
                !is_string($r["reason"] ?? null) ||
                !is_array($r["definition"] ?? null) ||
                !array_key_exists("previous_version_id", $r)
            ) {
                throw new InvalidArgumentException();
            }
            new SectionDefinitionV1($r["definition"]);
            $operation = new EntityId($r["operation_id"]);
        } catch (Throwable) {
            throw new InvalidArgumentException(
                "Review this section again before publishing.",
            );
        }
        if ($r["actor_id"] !== $actor->value) {
            throw new AccessDeniedException(
                "This section review belongs to another staff member.",
            );
        }
        return $this->transactions->run(function () use (
            $section,
            $actor,
            $operation,
            $r,
        ): array {
            [$row, $term] = $this->lock($section);
            $this->authorize($actor);
            [$root, $accepted] = $this->load($section, $operation);
            $payload = [
                "version_id" => $operation->value,
                "previous_version_id" => $r["previous_version_id"],
                "definition" => $r["definition"],
            ];
            if ($accepted !== null) {
                if (
                    $this->fingerprint($accepted->event->payload()) !==
                        $this->fingerprint($payload) ||
                    $accepted->context->actor?->value !== $actor->value ||
                    $accepted->context->reason !== $r["reason"] ||
                    $accepted->event->playhead() !== $r["expected_version"] + 1
                ) {
                    throw new ConcurrentModificationException(
                        "This publication operation was already used.",
                    );
                }
                return [
                    "operation_id" => $operation->value,
                    "first_version" => $accepted->event->playhead(),
                    "last_version" => $accepted->event->playhead(),
                    "event_ids" => [(string) $accepted->event->eventId()],
                ];
            }
            $course = $this->verifiedCourse($section);
            if (
                $r["expires_at"] <= $this->clock->now()->getTimestamp() ||
                $root->playhead() !== $r["expected_version"] ||
                ($root->latest()["version_id"] ?? null) !==
                    $r["previous_version_id"] ||
                $course === null ||
                !hash_equals(
                    $r["source_hash"],
                    $this->fingerprint([
                        $row,
                        $term,
                        $course,
                        $this->faculty($row),
                    ]),
                ) ||
                $this->definition(
                    $row,
                    $term,
                    $course,
                    $r["definition"]["instruction_starts_on"],
                    $r["definition"]["instruction_ends_on"],
                ) !== $r["definition"]
            ) {
                throw new ConcurrentModificationException(
                    "The section or review changed. Review its details again.",
                );
            }
            if ($root->latest() !== null) {
                $this->sameOwnership(
                    $root->latest()["definition"],
                    $r["definition"],
                );
            }
            $event = SectionDefinitionWasPublished::occur(
                $this->stream($section)->aggregateId,
                $payload,
                ["__aggregate_type" => "section_publication"],
            )->withPlayhead($root->playhead() + 1);
            $receipt = $this->history->append(
                $this->stream($section),
                $operation,
                $root->playhead(),
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $operation,
                    reason: $r["reason"],
                ),
                [$event],
            );
            $this->versions->retain(new PublishedSectionDefinition(
                $section,
                $operation,
                $receipt["last_version"],
                new SectionDefinitionV1($r["definition"]),
            ));
            $this->audit->record(
                $actor,
                "section.definition_published",
                $section,
            );
            return $receipt;
        });
    }

    /** Internal preparation for an explicit version-selection command, never an automatic "latest" choice.
     * Existing publications are verified from actual events before their reference cache is populated.
     */
    public function cacheVersion(EntityId $section, EntityId $publication): PublishedSectionDefinition
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($section, $publication, $actor) {
            $this->lock($section);
            $this->authorize($actor);
            [, $accepted] = $this->load($section, $publication);
            if ($accepted === null) {
                throw new \Domain\Shared\Exception\RecordNotFoundException("Published section definition not found.");
            }
            $definition = new PublishedSectionDefinition(
                $section,
                $publication,
                $accepted->event->playhead(),
                new SectionDefinitionV1($accepted->event->payload()["definition"]),
            );
            $this->versions->retain($definition);
            return $definition;
        });
    }

    /** Curated publication pages omit protected reasons, actors and raw envelopes.
     * @return array<string, mixed>
     */
    public function versions(EntityId $section, ?string $cursor = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $this->catalog->get("sections", $section);
        $page = $this->history->page($this->stream($section), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            if (!($stored->event instanceof SectionDefinitionWasPublished)) {
                throw new CorruptHistoryException();
            }
            $items[] = [
                "version" => $stored->event->playhead(),
                "version_id" => $stored->event->payload()["version_id"],
                "definition" => $stored->event->payload()["definition"],
                "effective_at" => $stored->context->describe()["effective_at"],
                "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
            ];
        }
        return [
            "items" => $items,
            "next_cursor" => $page->nextCursor,
            "through_version" => $page->throughVersion,
        ];
    }

    /** @return array{SectionPublication, ?StoredEvent} */
    private function load(EntityId $section, ?EntityId $operation = null): array
    {
        $root = SectionPublication::root($this->stream($section)->aggregateId);
        $accepted = null;
        $cursor = null;
        do {
            $page = $this->history->page($this->stream($section), $cursor, 20);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                if (
                    $operation !== null &&
                    $stored->event->payload()["version_id"] ===
                        $operation->value
                ) {
                    if (
                        $accepted !== null ||
                        $stored->context->correlation?->value !==
                            $operation->value
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $accepted = $stored;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return [$root, $accepted];
    }

    /** @return array{array<string, scalar|null>, array<string, scalar|null>} */
    private function lock(EntityId $section): array
    {
        $this->catalog->lock("sections", $section);
        $row = $this->catalog->get("sections", $section);
        $this->catalog->lock(
            "courses",
            new EntityId((string) $row["course_id"]),
        );
        $this->catalog->lock("terms", new EntityId((string) $row["term_id"]));
        return [
            $row,
            $this->catalog->get(
                "terms",
                new EntityId((string) $row["term_id"]),
            ),
        ];
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

    private function validateInput(
        string $start,
        string $end,
        #[\SensitiveParameter] string $reason,
        EntityId $actor,
        EntityId $section,
    ): void {
        $dates = ['instruction_starts_on' => $start, 'instruction_ends_on' => $end];
        foreach ($dates as $field => $value) {
            try {
                SectionDefinitionV1::assertDate($value);
            } catch (InvalidArgumentException) {
                throw new ValidationException([['field' => $field, 'message' => 'Choose a valid calendar date.']]);
            }
        }
        if ($end < $start) {
            throw new ValidationException([['field' => 'instruction_ends_on',
                'message' => 'Instruction must end on or after its start date.']]);
        }
        try {
            if (mb_strlen($reason) > 1000) {
                throw new InvalidArgumentException();
            }
            new EventContext($actor, $this->clock->now(), $section, reason: $reason);
        } catch (InvalidArgumentException) {
            throw new ValidationException([['field' => 'reason',
                'message' => 'Enter a reason of up to 1000 characters, without unsupported control characters.']]);
        }
    }

    /** @param array<string, scalar|null> $row */
    private function facultyLabel(array $row): string
    {
        return $this->faculty($row)["name"] ?? "Not assigned";
    }

    /** @param array<string, scalar|null> $row
     * @return array{id: string, name: string, person_id: string}|null
     */
    private function faculty(array $row): ?array
    {
        return $row["faculty_user_id"] === null
        ? null
        : $this->relationships->faculty(
            new EntityId((string) $row["faculty_user_id"]),
        );
    }

    /** @return array<string, mixed>|null */
    private function verifiedCourse(EntityId $section): ?array
    {
        $bound = $this->bindings->bound($section);
        $page = $this->history->page(
            new StreamReference(
                new \Domain\Registration\ValueObject\SectionCourseBindingId(
                    $section->value,
                ),
                "section_course_binding",
            ),
            null,
            2,
        );
        if ($bound === null) {
            if ($page->throughVersion !== 0 || $page->events !== []) {
                throw new CorruptHistoryException();
            }
            return null;
        }
        if (
            $page->throughVersion !== 1 ||
            count($page->events) !== 1 ||
            $page->nextCursor !== null ||
            !(
                $page->events[0]->event instanceof
                \Domain\Registration\Event\SectionCourseVersionWasBound
            ) ||
            $page->events[0]->event->payload() !== [
                "course_id" => $bound->courseId->value,
                "course_version_id" => $bound->versionId->value,
            ]
        ) {
            throw new CorruptHistoryException();
        }
        $published = $this->courses
            ->publishedDefinition($bound->courseId, $bound->versionId)
            ->describe();
        if ($published !== $bound->describe()) {
            throw new CorruptHistoryException();
        }
        return $published;
    }

    /** @param array<string, scalar|null> $row
     * @param array<string, scalar|null> $term
     * @param array<string, mixed> $course
     * @return array<string, mixed>
     */
    private function definition(
        array $row,
        array $term,
        array $course,
        string $start,
        string $end,
    ): array {
        return new SectionDefinitionV1([
            "course_id" => $row["course_id"],
            "course_version_id" => $course["version_id"],
            "term_id" => $term["id"],
            "term_code" => $term["code"],
            "term_starts_on" => $term["starts_on"],
            "term_ends_on" => $term["ends_on"],
            "code" => $row["code"],
            "capacity" => (int) $row["capacity"],
            "faculty_user_id" => $row["faculty_user_id"],
            "faculty_person_id" => $this->faculty($row)["person_id"] ?? null,
            "delivery_mode" => $row["delivery_mode"],
            "fee_minor" => (int) $row["fee_minor"],
            "currency" => $row["currency"],
            "instruction_starts_on" => $start,
            "instruction_ends_on" => $end,
        ])->data;
    }

    /** @param array<string, mixed> $earlier
     * @param array<string, mixed> $next
     */
    private function sameOwnership(array $earlier, array $next): void
    {
        foreach (["course_id", "course_version_id", "term_id"] as $field) {
            if ($earlier[$field] !== $next[$field]) {
                throw new CorruptHistoryException();
            }
        }
    }

    /** Normalize portable scalar representations before hashing reviewed source context.
     * @param array<mixed> $values
     */
    private function fingerprint(array $values): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value === null ? null : (string) $value;
            }
            ksort($value);
            return array_map($normalize, $value);
        };
        return hash(
            "sha256",
            json_encode($normalize($values), JSON_THROW_ON_ERROR),
        );
    }

    private function stream(EntityId $section): StreamReference
    {
        return new StreamReference(
            new SectionPublicationId($section->value),
            "section_publication",
        );
    }
}
