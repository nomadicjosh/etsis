<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\AcademicCalendar\Repository\AcademicCalendarRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Event\ProgramDefinitionWasPublished;
use Domain\Catalog\ProgramPublication;
use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Catalog\Repository\ProgramDefinitionRepository;
use Domain\Catalog\ValueObject\ProgramClassifications;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Catalog\ValueObject\ProgramPublicationId;
use Domain\Catalog\ValueObject\ProgramPublicationSchema;
use Domain\Communications\Service\MessageCipher;
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
use InvalidArgumentException;
use Throwable;

final readonly class ProgramPublicationService
{
    public function __construct(
        private CatalogRevisionRepository $catalog,
        private ProgramDefinitionRepository $details,
        private ClassificationRepository $classifications,
        private CatalogYearRepository $cohorts,
        private AcademicCalendarRepository $calendar,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private SpecializationPolicyService $specializations,
    ) {
    }

    /** Indexed draft read, without replay or write locks.
     * @return array<string, mixed>
     */
    public function draft(EntityId $program): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $definition = $this->definition($program)->describe();
        return ["id" => $program->value, "definition" => $definition, "revision" => $this->hash($definition)];
    }

    /** @return array<string, mixed> */
    public function save(
        EntityId $program,
        string $code,
        string $title,
        ?string $description,
        ?EntityId $department,
        ?EntityId $academicLevel,
        ?EntityId $award,
        string $revision,
    ): array {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $choices = ["department" => $department, "academic_level" => $academicLevel, "award" => $award];
        return $this->transactions->run(function () use (
            $program,
            $code,
            $title,
            $description,
            $choices,
            $revision,
            $actor,
        ): array {
            $this->catalog->lock("programs", $program);
            $this->lockChoices($program, array_values(array_filter($choices)));
            $this->authorize($actor);
            $old = $this->definition($program)->describe();
            if (!hash_equals($this->hash($old), $revision)) {
                throw new ConcurrentModificationException("Reload the program details before saving.");
            }
            $values = [];
            foreach ($choices as $kind => $id) {
                $row = $id === null ? null : $this->classifications->get($id);
                if (
                    $row !== null &&
                    ($row["kind"] !== $kind ||
                        (!$row["active"] && ($old["classifications"][$kind]["id"] ?? null) !== $id->value))
                ) {
                    throw new ValidationException([
                        [
                            "field" => $kind . "_id",
                            "message" => "Choose an active definition from the matching category.",
                        ],
                    ]);
                }
                $values[$kind] =
                    $row === null ? null : array_intersect_key($row, array_flip(["id", "code", "title"]));
            }
            $definition = new ProgramDefinition(
                strtoupper(trim($code)),
                trim($title),
                $description,
                ProgramClassifications::fromArray($values),
            );
            $this->catalog->save("programs", $program, [
                "code" => $definition->code,
                "title" => $definition->title,
            ]);
            $this->details->saveDraft($program, $definition->description, $choices);
            $this->audit->record($actor, "program.details_saved", $program);
            return $this->draft($program);
        });
    }

    /** @return array<string, mixed> */
    public function review(EntityId $program, EntityId $year, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException("Use a publication reason up to 1000 characters.");
        }
        return $this->transactions->run(function () use ($program, $year, $reason, $actor): array {
            $this->specializations->lockInstitution();
            $this->catalog->lock("programs", $program);
            $this->details->lockYear($year);
            $this->lockChoices($program);
            $this->authorize($actor);
            $definition = $this->definition($program)->describe();
            $context = $this->year($year);
            [$root, , $previous] = $this->load($program, $year);
            $this->assertCurrent($program, $year, $previous);
            $operation = EntityId::generate();
            new EventContext($actor, $this->clock->now(), $operation, reason: $reason);
            $this->authorize($actor);
            $review = [
                "kind" => "program-publication-review",
                "program_id" => $program->value,
                "operation_id" => $operation->value,
                "actor_id" => $actor->value,
                "expected_version" => $root->playhead(),
                "previous_version_id" => $root->versionId(),
                "academic_year_id" => $year->value,
                "academic_year_code" => $context["code"],
                "catalog_revision" => $this->cohorts->nextRevision($program, $year),
                "previous_catalog_id" => $this->details->current($program, $year)["id"] ?? null,
                "definition" => $definition,
                'specializations' => $this->specializations->snapshot($program)->data,
                "reason" => $reason,
                "expires_at" => $this->clock->now()->getTimestamp() + 1200,
            ];
            ProgramPublicationSchema::validate($this->payload($review));
            return $review + [
                "review_token" => $this->cipher->seal(json_encode($review, JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /** @return array{operation_id:string, first_version:int, last_version:int, event_ids:list<string>} */
    public function publish(EntityId $program, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        try {
            if (strlen($token) > 131072) {
                throw new InvalidArgumentException();
            }
            $review = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($review) ||
                ($review["kind"] ?? null) !== "program-publication-review" ||
                ($review["program_id"] ?? null) !== $program->value ||
                !is_string($review["actor_id"] ?? null) ||
                !is_string($review["operation_id"] ?? null) ||
                !is_int($review["expected_version"] ?? null) ||
                $review["expected_version"] < 0 ||
                !is_int($review["expires_at"] ?? null) ||
                !is_string($review["reason"] ?? null) ||
                array_key_exists("effective_at", $review)
            ) {
                throw new InvalidArgumentException();
            }
            $operation = new EntityId($review["operation_id"]);
            $payload = $this->payload($review);
            ProgramPublicationSchema::validate($payload);
            $year = new EntityId($payload["academic_year_id"]);
        } catch (Throwable) {
            throw new InvalidArgumentException("Review these program details again before publishing.");
        }
        if ($review["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This program review belongs to another staff member.");
        }
        return $this->transactions->run(function () use (
            $program,
            $year,
            $operation,
            $payload,
            $review,
            $actor,
        ): array {
            $this->specializations->lockInstitution();
            $this->catalog->lock("programs", $program);
            $this->details->lockYear($year);
            $this->authorize($actor);
            [$root, $accepted, $previous] = $this->load($program, $year, $operation);
            if ($accepted !== null) {
                if (
                    $accepted->context->actor?->value !== $actor->value ||
                    $accepted->context->reason !== $review["reason"] ||
                    $accepted->event->playhead() !== $review["expected_version"] + 1 ||
                    $this->hash($accepted->event->payload()) !== $this->hash($payload)
                ) {
                    throw new ConcurrentModificationException("This publication operation was already used.");
                }
                $this->authorize($actor);
                return [
                    "operation_id" => $operation->value,
                    "first_version" => $accepted->event->playhead(),
                    "last_version" => $accepted->event->playhead(),
                    "event_ids" => [(string) $accepted->event->eventId()],
                ];
            }
            $this->lockChoices($program);
            $this->assertCurrent($program, $year, $previous);
            if (
                $review["expires_at"] <= $this->clock->now()->getTimestamp() ||
                $root->playhead() !== $review["expected_version"] ||
                $root->versionId() !== $payload["previous_version_id"] ||
                $this->definition($program)->describe() !== $payload["definition"] ||
                $this->hash($this->specializations->snapshot($program)->data) !==
                    $this->hash($payload['specializations']) ||
                $this->year($year)["code"] !== $payload["academic_year_code"] ||
                $this->cohorts->nextRevision($program, $year) !== $payload["catalog_revision"] ||
                ($this->details->current($program, $year)["id"] ?? null) !== $payload["previous_catalog_id"]
            ) {
                throw new ConcurrentModificationException(
                    "The program, academic year or publication choices changed. Review them again.",
                );
            }
            $this->authorize($actor);
            $event = ProgramDefinitionWasPublished::occur($this->stream($program)->aggregateId, $payload, [
                "__aggregate_type" => "program_publication",
                "schema_version" => 1,
            ])->withPlayhead($root->playhead() + 1);
            $receipt = $this->history->append(
                $this->stream($program),
                $operation,
                $root->playhead(),
                new EventContext($actor, $this->clock->now(), $operation, reason: $review["reason"]),
                [$event],
            );
            $definition = ProgramDefinition::fromArray($payload["definition"]);
            $this->cohorts->addRelease($operation, [
                "program_id" => $program->value,
                "year_id" => $year->value,
                "revision" => $payload["catalog_revision"],
                "published" => 0,
                "is_current" => 0,
                "program_code" => $definition->code,
                "program_title" => $definition->title,
            ]);
            $this->details->retain(
                $program,
                $operation,
                $payload["academic_year_code"],
                $receipt["last_version"],
                $definition,
                new \Domain\Catalog\ValueObject\ProgramSpecializationSnapshot($payload['specializations']),
            );
            $this->cohorts->publishRelease($operation, $program);
            $this->audit->record($actor, "program.definition_published", $program);
            return $receipt;
        });
    }

    /** Explicit, curated publication history; ordinary program lists remain indexed reads.
     * @return array<string, mixed>
     */
    public function versions(EntityId $program, ?string $cursor = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        $this->catalog->get("programs", $program);
        $page = $this->history->page($this->stream($program), $cursor, 20);
        $items = [];
        foreach ($page->events as $stored) {
            if (!($stored->event instanceof ProgramDefinitionWasPublished)) {
                throw new CorruptHistoryException();
            }
            $payload = $stored->event->payload();
            unset($payload["previous_version_id"], $payload["previous_catalog_id"]);
            $items[] = $payload + [
                "version" => $stored->event->playhead(),
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

    /** @return array{ProgramPublication, ?StoredEvent, ?StoredEvent} */
    private function load(EntityId $program, EntityId $year, ?EntityId $operation = null): array
    {
        $root = ProgramPublication::root($this->stream($program)->aggregateId);
        $accepted = $latestYear = null;
        $cursor = null;
        do {
            $page = $this->history->page($this->stream($program), $cursor, 50);
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $payload = $stored->event->payload();
                if ($payload["academic_year_id"] === $year->value) {
                    if (
                        $latestYear !== null &&
                        ($payload["previous_catalog_id"] !== $latestYear->event->payload()["version_id"] ||
                            $payload["catalog_revision"] <= $latestYear->event->payload()["catalog_revision"])
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $latestYear = $stored;
                }
                if ($operation !== null && $payload["version_id"] === $operation->value) {
                    if ($accepted !== null || $stored->context->correlation?->value !== $operation->value) {
                        throw new CorruptHistoryException();
                    }
                    $accepted = $stored;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return [$root, $accepted, $latestYear];
    }

    private function assertCurrent(EntityId $program, EntityId $year, ?StoredEvent $latest): void
    {
        if ($latest === null) {
            return;
        }
        $payload = $latest->event->payload();
        $retained = $this->details->retained(new EntityId($payload["version_id"]));
        $expected = [
            "program_id" => $program->value,
            "academic_year_id" => $year->value,
            "academic_year_code" => $payload["academic_year_code"],
            "catalog_revision" => $payload["catalog_revision"],
            "stream_version" => $latest->event->playhead(),
            "definition" => $payload["definition"],
            'specializations' => $payload['specializations'],
        ];
        if (
            $retained === null ||
            $this->hash($retained) !== $this->hash($expected) ||
            ($this->details->current($program, $year)["id"] ?? null) !== $payload["version_id"]
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** @param list<EntityId> $additional */
    private function lockChoices(EntityId $program, array $additional = []): void
    {
        $draft = $this->details->draft($program);
        $ids = array_map(static fn(EntityId $id): string => $id->value, $additional);
        foreach (ClassificationKind::programKinds() as $kind) {
            if ($draft[$kind->value . "_id"] !== null) {
                $ids[] = $draft[$kind->value . "_id"];
            }
        }
        $ids = array_unique($ids);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $this->classifications->lock(new EntityId($id));
        }
    }

    private function definition(EntityId $program): ProgramDefinition
    {
        $core = $this->catalog->get("programs", $program);
        $draft = $this->details->draft($program);
        $choices = [];
        foreach (ClassificationKind::programKinds() as $kind) {
            $id = $draft[$kind->value . "_id"];
            $row = $id === null ? null : $this->classifications->get(new EntityId($id));
            if ($row !== null && $row["kind"] !== $kind->value) {
                throw new CorruptHistoryException();
            }
            $choices[$kind->value] =
            $row === null ? null : array_intersect_key($row, array_flip(["id", "code", "title"]));
        }
        return ProgramDefinition::fromArray([
            "code" => $core["code"],
            "title" => $core["title"],
            "description" => $draft["description"],
            "classifications" => $choices,
        ]);
    }

    /** @return array{id:string, code:string} */
    private function year(EntityId $year): array
    {
        $row = $this->calendar->year($year);
        $this->cohorts->year($year);
        return ["id" => $year->value, "code" => $row["code"]];
    }

    /** @param array<string, mixed> $review
     * @return array<string, mixed>
     */
    private function payload(array $review): array
    {
        $fields = [
            "operation_id",
            "previous_version_id",
            "academic_year_id",
            "academic_year_code",
            "catalog_revision",
            "previous_catalog_id",
            "definition",
            'specializations',
        ];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $review)) {
                throw new InvalidArgumentException("Provide a complete reviewed program publication.");
            }
        }
        return [
            "version_id" => $review["operation_id"],
            "previous_version_id" => $review["previous_version_id"],
            "academic_year_id" => $review["academic_year_id"],
            "academic_year_code" => $review["academic_year_code"],
            "catalog_revision" => $review["catalog_revision"],
            "previous_catalog_id" => $review["previous_catalog_id"],
            "definition" => $review["definition"],
            'specializations' => $review['specializations'],
        ];
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission("sis:catalog:manage")->value !== $actor->value) {
            throw new AccessDeniedException("Access denied.");
        }
    }

    private function stream(EntityId $program): StreamReference
    {
        return new StreamReference(new ProgramPublicationId($program->value), "program_publication");
    }

    /** @param array<mixed> $value */
    private function hash(array $value): string
    {
        $normalize = function (mixed $v) use (&$normalize): mixed {
            if (!is_array($v)) {
                return $v;
            }
            ksort($v);
            return array_map($normalize, $v);
        };
        return hash("sha256", json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }
}
