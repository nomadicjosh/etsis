<?php

declare(strict_types=1);

namespace Application\Service;

use Application\View\AcademicTimetableCalendar;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Service\AcademicTimetableService;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

/** One current owner-authorized snapshot; no stored document, subscription credential or academic decision. */
final readonly class AcademicTimetableExport
{
    public function __construct(
        private AcademicTimetableService $timetable,
        private AcademicTimetableCalendar $calendar,
        private StudentAccess $access,
        private AuditTrail $audit,
        private UnitOfWork $transactions,
    ) {
    }

    public function prepare(EntityId $term, ?string $date = null, string $view = 'week'): string
    {
        $owner = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($owner, $term, $date, $view): string {
            $snapshot = $this->timetable->calendar($term, $date, $view);
            $body = $this->calendar->render($owner, $snapshot);
            $this->audit->record($owner, 'timetable.calendar_prepared', $term);
            if ($owner->value !== $this->access->authenticatedUserId()->value) {
                throw new AccessDeniedException();
            }
            if ($snapshot !== $this->timetable->calendar($term, $snapshot['from'], $view)) {
                throw new ConcurrentModificationException(trans_html('Your published timetable changed. Refresh it.'));
            }
            return $body;
        });
    }
}
