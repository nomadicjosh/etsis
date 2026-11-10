<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Catalog\CoursePublication;
use Domain\Catalog\ValueObject\CoursePublicationId;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\DegreeAudit\CourseGroupPublication;
use Domain\DegreeAudit\RequirementsPublication;
use Domain\DegreeAudit\ValueObject\CourseGroupPublicationId;
use Domain\DegreeAudit\ValueObject\RequirementsPublicationId;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;

/** Internal immutable reader. Call only after the workflow authorizes the student and scope. */
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
    public function version(EntityId $template, EntityId $version): array
    {
        $stream = $this->degreeStream($template);
        $state = RequirementsPublication::root($stream->aggregateId);
        $found = $this->selected($stream, $version, $state);
        $p = $found["payload"];
        $groups = [];
        if ($found["schema_version"] >= 3) {
            foreach ($p["definition"]["groups"] as $g) {
                $groups[$g["group_id"]] = $this->group(
                    new EntityId($g["group_id"]),
                    new EntityId($g["version_id"]),
                );
            }
        }
        return [
            "template_id" => $template->value,
            "version_id" => $version->value,
            "version" => $found["version"],
            "schema_version" => $found["schema_version"],
            "program_version_id" => $found["program_version_id"],
            "program_id" => $p["program_id"],
            "academic_year_id" => $p["academic_year_id"],
            "context" => $p["labels"],
            "definition" => $p["definition"],
            "grade_scale" => $p["grade_scale"] ?? null,
            "group_details" => $groups,
        ];
    }
    /** @return array<string, mixed> */
    private function group(EntityId $id, EntityId $version): array
    {
        $stream = new StreamReference(new CourseGroupPublicationId($id->value), "course_group_publication");
        $found = $this->selected($stream, $version, CourseGroupPublication::root($stream->aggregateId));
        $d = $found["payload"]["definition"];
        $courses = [];
        foreach ($d["courses"] as $c) {
            $course = new EntityId($c["course_id"]);
            $s = new StreamReference(new CoursePublicationId($course->value), "course_publication");
            $item = $this->selected(
                $s,
                new EntityId($c["version_id"]),
                CoursePublication::root($s->aggregateId),
            );
            $published = PublishedCourseDefinition::fromPublication(
                $course,
                $item + ["definition" => $item["payload"]["definition"]],
            );
            $courses[$course->value] = [
                "code" => $published->definition->code,
                "title" => $published->definition->text->transcriptTitle ?? $published->definition->title,
                "credits_milli" => $published->definition->creditsMilli,
            ];
        }
        return [
            "definition" => $d,
            "version" => $found["version"],
            "version_id" => $version->value,
            "course_details" => $courses,
        ];
    }
    /** @return array<string, mixed> */
    private function selected(
        StreamReference $stream,
        EntityId $version,
        RequirementsPublication|CourseGroupPublication|CoursePublication $state,
    ): array {
        $cursor = null;
        $found = null;
        do {
            $page = $this->history->page($stream, $cursor, 10);
            foreach ($page->events as $stored) {
                $e = $stored->event;
                $state->acceptHistorical($e);
                $p = $e->payload();
                if ($p["version_id"] === $version->value) {
                    $found = [
                        "version_id" => $p["version_id"],
                        "version" => $e->playhead(),
                        "schema_version" => $e->metaParam("schema_version", 1),
                        "program_version_id" => $e->metaParam("curriculum_version_id"),
                        "payload" => $p,
                    ];
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return $found ?? throw new RecordNotFoundException("Published requirements reference not found.");
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
    public function courseVersion(EntityId $course, EntityId $version): array
    {
        $s = new StreamReference(new CoursePublicationId($course->value), "course_publication");
        return $this->courseItem(
            $course,
            $this->selected($s, $version, CoursePublication::root($s->aggregateId)),
        );
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
