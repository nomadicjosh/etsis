<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Catalog\CoursePublication;
use Domain\Catalog\ProgramPublication;
use Domain\Catalog\ValueObject\ProgramPublicationId;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Catalog\ValueObject\CoursePublicationId;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\DegreeAudit\CourseGroupPublication;
use Domain\DegreeAudit\RequirementsPublication;
use Domain\DegreeAudit\ValueObject\CourseGroupPublicationId;
use Domain\DegreeAudit\ValueObject\RequirementsPublicationId;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;

/** Internal immutable reader. Call only after the workflow authorizes its academic or authoring scope. */
final readonly class PublishedRequirementsReader
{
    public function __construct(private HistoricalEventStore $history)
    {
    }
    /** @return array<string, mixed> */
    public function page(EntityId $template, ?string $cursor = null): array
    {
        $page = $this->history->page($this->degreeStream($template), $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $p = $stored->event->payload();
            $items[] = [
                "version_id" => $p["version_id"],
                "version" => $stored->event->playhead(),
                "schema_version" => $stored->event->metaParam("schema_version", 1),
                "program_version_id" => $stored->event->metaParam("curriculum_version_id"),
                "name" => $p["definition"]["name"],
                "academic_year_code" => $p["labels"]["academic_year_code"],
            ];
        }
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }
    /** @return array<string, mixed> */
    public function version(EntityId $template, EntityId $version, ?HistoryReadBudget $budget = null): array
    {
        return $this->readVersion($template, $version, $budget);
    }
    /** Only the retained policy/context envelope; no group/course resolution is needed for overall totals.
     * @return array<string,mixed>
     */
    public function calculationVersion(
        EntityId $template,
        EntityId $version,
        HistoryReadBudget $budget,
    ): array {
        return $this->readVersion($template, $version, $budget, false);
    }
    /** @return array<string,mixed> */
    private function readVersion(
        EntityId $template,
        EntityId $version,
        ?HistoryReadBudget $budget = null,
        bool $includeGroups = true,
    ): array {
        $stream = $this->degreeStream($template);
        $state = RequirementsPublication::root($stream->aggregateId);
        $found = $this->selected($stream, $version, $state, $budget);
        $p = $found["payload"];
        $program =
        $found["program_version_id"] === null
        ? null
        : $this->programDefinition(
            new EntityId($p["program_id"]),
            new EntityId($p["academic_year_id"]),
            new EntityId($found["program_version_id"]),
            $p["labels"],
            $budget,
        );
        $groups = [];
        if ($includeGroups && $found["schema_version"] >= 3) {
            $groups = $this->groups($p["definition"]["groups"], $budget);
        }
        return [
            "template_id" => $template->value,
            "version_id" => $version->value,
            "version" => $found["version"],
            "schema_version" => $found["schema_version"],
            "program_version_id" => $found["program_version_id"],
            "program_publication" => $program,
            "program_id" => $p["program_id"],
            "academic_year_id" => $p["academic_year_id"],
            "context" => $p["labels"],
            "definition" => $p["definition"],
            "grade_scale" => $p["grade_scale"] ?? null,
            "group_details" => $groups,
        ];
    }
    /** @param array<string,mixed> $labels
     * @return array<string, mixed>
     */
    public function programDefinition(
        EntityId $program,
        EntityId $year,
        EntityId $version,
        array $labels = [],
        ?HistoryReadBudget $budget = null,
    ): array {
        $stream = new StreamReference(new ProgramPublicationId($program->value), "program_publication");
        $found = $this->selected($stream, $version, ProgramPublication::root($stream->aggregateId), $budget);
        $payload = $found["payload"];
        if (
            $payload["academic_year_id"] !== $year->value ||
            ($labels !== [] &&
                (($labels["program_code"] ?? null) !== $payload["definition"]["code"] ||
                    ($labels["program_title"] ?? null) !== $payload["definition"]["title"]))
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "version_id" => $version->value,
            "catalog_revision" => $payload["catalog_revision"],
            "academic_year_code" => $payload["academic_year_code"],
            "definition" => ProgramDefinition::fromArray($payload["definition"])->describe(),
            'specializations' => $payload['specializations'],
        ];
    }

    /** Selected reusable publications with one shared replay budget and deduplicated course reads.
     * Calling authoring/academic workflows must authorize their own scope first.
     * @param array<mixed> $references Validated before reading any stream.
     * @return array<string,array<string,mixed>>
     */
    public function groupVersions(array $references, HistoryReadBudget $budget): array
    {
        if (count($references) > 25 || !array_is_list($references)) {
            throw new \InvalidArgumentException("Use at most 25 selected course-group publications.");
        }
        $seen = [];
        $selected = [];
        foreach ($references as $reference) {
            if (
                !is_array($reference) ||
                count($reference) !== 2 ||
                !is_string($reference["group_id"] ?? null) ||
                !is_string($reference["version_id"] ?? null) ||
                isset($seen[$reference["group_id"]])
            ) {
                throw new \InvalidArgumentException("Choose one publication per reusable group.");
            }
            new EntityId($reference["group_id"]);
            new EntityId($reference["version_id"]);
            $seen[$reference["group_id"]] = true;
            $selected[] = ["group_id" => $reference["group_id"], "version_id" => $reference["version_id"]];
        }
        return $this->groups($selected, $budget);
    }

    /** Resolve shared courses once per invocation, retaining only requested versions.
     * @param list<array{group_id:string, version_id:string}> $references
     * @return array<string, array<string,mixed>>
     */
    private function groups(array $references, ?HistoryReadBudget $budget = null): array
    {
        $groups = [];
        $requested = [];
        foreach ($references as $reference) {
            $id = new EntityId($reference["group_id"]);
            $version = new EntityId($reference["version_id"]);
            $stream = new StreamReference(
                new CourseGroupPublicationId($id->value),
                "course_group_publication",
            );
            $found = $this->selected(
                $stream,
                $version,
                CourseGroupPublication::root($stream->aggregateId),
                $budget,
            );
            $definition = $found["payload"]["definition"];
            $groups[$id->value] = [
                "definition" => $definition,
                "version" => $found["version"],
                "version_id" => $version->value,
                "course_details" => [],
            ];
            foreach ($definition["courses"] as $course) {
                foreach ([$course['version_id'], ...($course['accepted_version_ids'] ?? [])] as $accepted) {
                    $requested[$course['course_id']][$accepted] = true;
                }
            }
        }
        $courses = [];
        foreach ($requested as $courseId => $versions) {
            $id = new EntityId($courseId);
            $stream = new StreamReference(new CoursePublicationId($id->value), "course_publication");
            $selected = $this->selectedVersions(
                $stream,
                $versions,
                CoursePublication::root($stream->aggregateId),
                $budget,
            );
            foreach ($selected as $versionId => $item) {
                $published = PublishedCourseDefinition::fromPublication(
                    $id,
                    $item + ["definition" => $item["payload"]["definition"]],
                );
                $courses[$courseId][$versionId] = [
                    "code" => $published->definition->code,
                    "title" => $published->definition->text->transcriptTitle ?? $published->definition->title,
                    "credits_milli" => $published->definition->creditsMilli,
                    "version" => $item['version'],
                ];
            }
        }
        foreach ($groups as &$group) {
            foreach ($group["definition"]["courses"] as $reference) {
                $details = $courses[$reference['course_id']][$reference['version_id']];
                if (($reference['accepted_version_ids'] ?? []) !== []) {
                    $details['accepted_versions'] = array_map(
                        static fn(string $v): array => ['version_id' => $v] + $courses[$reference['course_id']][$v],
                        $reference['accepted_version_ids'],
                    );
                }
                $group['course_details'][$reference['course_id']] = $details;
            }
        }
        unset($group);
        return $groups;
    }
    /** @return array<string, mixed> */
    private function selected(
        StreamReference $stream,
        EntityId $version,
        RequirementsPublication|CourseGroupPublication|CoursePublication|ProgramPublication $state,
        ?HistoryReadBudget $budget = null,
    ): array {
        return $this->selectedVersions($stream, [$version->value => true], $state, $budget)[$version->value];
    }
    /** Verify a complete frozen stream, keeping only the explicitly requested versions.
     * @param array<string, true> $versions
     * @return array<string, array<string,mixed>>
     */
    private function selectedVersions(
        StreamReference $stream,
        array $versions,
        RequirementsPublication|CourseGroupPublication|CoursePublication|ProgramPublication $state,
        ?HistoryReadBudget $budget = null,
    ): array {
        $cursor = null;
        $found = [];
        do {
            $page =
            $budget === null
            ? $this->history->page($stream, $cursor, 10)
            : $budget->page($this->history, $stream, $cursor);
            foreach ($page->events as $stored) {
                $event = $stored->event;
                $state->acceptHistorical($event);
                $payload = $event->payload();
                $versionId = $payload["version_id"];
                if (isset($versions[$versionId])) {
                    if (isset($found[$versionId])) {
                        throw new CorruptHistoryException();
                    }
                    $found[$versionId] = [
                        "version_id" => $versionId,
                        "version" => $event->playhead(),
                        "schema_version" => $event->metaParam("schema_version", 1),
                        "program_version_id" => $event->metaParam("curriculum_version_id"),
                        "payload" => $payload,
                    ];
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if (count($found) !== count($versions)) {
            throw new RecordNotFoundException("Published requirements reference not found.");
        }
        return $found;
    }
    /** Internal course publications, after student/office authorization in the calling workflow.
     * @return array<string, mixed> */
    public function coursePage(EntityId $course, ?string $cursor = null): array
    {
        $stream = new StreamReference(new CoursePublicationId($course->value), "course_publication");
        $page = $this->history->page($stream, $cursor, 10);
        $items = [];
        foreach ($page->events as $stored) {
            $e = $stored->event;
            $p = $e->payload();
            $items[] = $this->courseItem($course, [
                "version_id" => $p["version_id"],
                "version" => $e->playhead(),
                "schema_version" => $e->metaParam("schema_version", 1),
                "payload" => $p,
            ]);
        }
        return ["items" => $items, "next_cursor" => $page->nextCursor];
    }
    /** @return array<string, mixed> */
    public function courseVersion(
        EntityId $course,
        EntityId $version,
        ?HistoryReadBudget $budget = null,
    ): array {
        return $this->courseVersions($course, [$version], $budget)[$version->value];
    }
    /** Selected immutable labels in one scan per course; no current catalog fallback.
     * @param list<EntityId> $versions
     * @return array<string,array<string,mixed>>
     */
    public function courseVersions(EntityId $course, array $versions, ?HistoryReadBudget $budget = null): array
    {
        if ($versions === [] || count($versions) > 500) {
            throw new \InvalidArgumentException('Choose bounded explicit course publications.');
        }
        $requested = [];
        foreach ($versions as $version) {
            if (isset($requested[$version->value])) {
                throw new \InvalidArgumentException('Choose distinct course publications.');
            }
            $requested[$version->value] = true;
        }
        $s = new StreamReference(new CoursePublicationId($course->value), 'course_publication');
        $found = $this->selectedVersions($s, $requested, CoursePublication::root($s->aggregateId), $budget);
        $rows = [];
        foreach ($found as $id => $item) {
            $rows[$id] = $this->courseItem($course, $item);
        }
        return $rows;
    }
    /** @param array<string,mixed> $item
     * @return array<string,mixed> */
    private function courseItem(EntityId $course, array $item): array
    {
        $d = PublishedCourseDefinition::fromPublication(
            $course,
            $item + ["definition" => $item["payload"]["definition"]],
        );
        return [
            "course_id" => $course->value,
            "version_id" => $item["version_id"],
            "version" => $item["version"],
            "code" => $d->definition->code,
            "title" => $d->definition->text->transcriptTitle ?? $d->definition->title,
            "credits_milli" => $d->definition->creditsMilli,
        ];
    }
    private function degreeStream(EntityId $id): StreamReference
    {
        return new StreamReference(new RequirementsPublicationId($id->value), "degree_audit_requirements");
    }
}
