<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use Domain\Catalog\Course;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Catalog\ValueObject\CourseDefinition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;

/** Internal confirmation-local reads. Never persist or reuse between transactions or browser reviews. */
final class RegistrationCourseRead
{
    /** @var array<string,CourseDefinition> */
    private array $publications = [];
    /** @var array<string,string> */
    private array $selections = [];

    /** Compare each current course projection, even when its immutable publication was already verified. */
    public function verify(Course $course, PublishedCourseReader $reader, HistoryReadBudget $budget): void
    {
        if ($course->publicationVersionId === null) {
            return;
        }
        $key = $course->id->value . ':' . $course->publicationVersionId->value;
        if (!isset($this->publications[$key])) {
            if (count($this->publications) >= 100) {
                throw new HistoryReadLimitException('This registration exceeds its course verification limit.');
            }
            $this->publications[$key] = $reader->version(
                $course->id,
                $course->publicationVersionId,
                $budget
            )->definition;
        }
        $definition = $this->publications[$key];
        if (
            $definition->code !== $course->code ||
            $definition->title !== $course->title ||
            $definition->creditsMilli !== $course->creditsMilli ||
            $definition->creditPolicy?->describe() !== $course->creditPolicy?->describe() ||
            $definition->text->transcriptTitle !== $course->publishedTranscriptTitle ||
            $definition->prerequisites !== $course->publishedPrerequisites ||
            $definition->prerequisiteGroups?->creditOrigin !== $course->prerequisiteGroups?->creditOrigin ||
            $definition->prerequisiteGroups?->rule?->definition !== $course->prerequisiteGroups?->rule?->definition ||
            $definition->prerequisiteGroups?->minimumRequirements !==
                $course->prerequisiteGroups?->minimumRequirements ||
            $definition->prerequisiteGroups?->groups !== $course->prerequisiteGroups?->groups ||
            $definition->prerequisiteGroups?->minimums !== $course->prerequisiteGroups?->minimums ||
            $definition->prerequisiteGroups?->corequisites !== $course->prerequisiteGroups?->corequisites ||
            $definition->prerequisiteGroups?->minimumGradePointsMilli !==
                $course->prerequisiteGroups?->minimumGradePointsMilli
        ) {
            throw new CorruptHistoryException();
        }
    }

    /** Capture/compare one section's assessed course policy, including earlier unbound draft rules.
     * @param list<string> $required */
    public function selection(EntityId $section, Course $course, array $required): void
    {
        sort($required, SORT_STRING);
        $fingerprint = hash('sha256', json_encode([
            $course->id->value, $course->code, $course->title, $course->creditsMilli, $course->active,
            $course->publishedTranscriptTitle, $course->publicationVersionId?->value, $required,
            $course->prerequisiteGroups?->groups, $course->prerequisiteGroups?->minimums,
            $course->prerequisiteGroups?->minimumRequirements, $course->prerequisiteGroups?->creditOrigin,
            $course->prerequisiteGroups?->rule?->definition,
            $course->prerequisiteGroups?->minimumGradePointsMilli, $course->prerequisiteGroups?->corequisites,
            $course->creditPolicy?->describe(), $course->registrationCreditsMilli(),
        ], JSON_THROW_ON_ERROR));
        $old = $this->selections[$section->value] ?? null;
        if ($old !== null && !hash_equals($old, $fingerprint)) {
            throw new ConcurrentModificationException(
                'The assessed course definition changed. Review registration again.'
            );
        }
        if ($old === null && count($this->selections) >= 100) {
            throw new HistoryReadLimitException('This registration exceeds its course selection limit.');
        }
        $this->selections[$section->value] = $fingerprint;
    }
}
