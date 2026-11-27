<?php

declare(strict_types=1);

namespace Application\Service;

use Application\View\AdvisingAppointmentCalendar;
use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;

use function Codefy\Framework\Helpers\trans;

/** Current owner-only snapshot, deliberately downloaded rather than published as a subscription. */
final readonly class AdvisingAppointmentExport
{
    public function __construct(
        private AdvisingAppointmentService $appointments,
        private AdvisingAppointmentCalendar $calendar,
        private StudentAccess $access,
        private AuditTrail $audit,
        private UnitOfWork $transactions,
    ) {
    }

    public function prepare(?string $date = null, string $view = 'week', bool $includeCancelled = false): string
    {
        $owner = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($owner, $date, $view, $includeCancelled): string {
            $snapshot = $this->appointments->calendarExport($date, $view, $includeCancelled);
            $body = $this->calendar->render($owner, $snapshot);
            $this->audit->record($owner, 'advising.appointment_calendar_prepared', $owner);
            if ($owner->value !== $this->access->authenticatedUserId()->value) {
                throw new AccessDeniedException();
            }
            if ($snapshot !== $this->appointments->calendarExport($snapshot['from'], $view, $includeCancelled)) {
                throw new ConcurrentModificationException(trans('Appointments changed. Refresh the calendar.'));
            }
            return $body;
        });
    }
}
