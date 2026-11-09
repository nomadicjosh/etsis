<?php

declare(strict_types=1);

namespace Domain\Registration;

use Codefy\Domain\Aggregate\EventSourcedAggregate;
use Domain\Registration\Event\SectionCourseVersionWasBound;
use Domain\Shared\EventSourcing\CorruptHistoryException;

/** A section is linked once. Reassignment requires a future explicit amendment workflow. */
final class SectionCourseBinding extends EventSourcedAggregate
{
    private ?string $courseVersionId = null;

    public function whenSectionCourseVersionWasBound(SectionCourseVersionWasBound $event): void
    {
        if ($this->courseVersionId !== null) {
            throw new CorruptHistoryException();
        }
        $this->courseVersionId = $event->payload()["course_version_id"];
    }

    public function courseVersionId(): ?string
    {
        return $this->courseVersionId;
    }
}
