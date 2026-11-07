<?php

declare(strict_types=1);

use Application\Http\Controller\SisRegistrationController;
use Application\Http\Controller\SisGradebookController;
use Application\Http\Controller\SisBookingController;
use Application\Http\Controller\SisTimesheetController;
use Application\Http\Controller\SisTransferController;
use Application\Http\Controller\SisLedgerController;
use Application\Http\Controller\SisReportController;
use Application\Http\Controller\SisImportController;
use Application\Http\Controller\SisCampaignController;
use Application\Http\Controller\SisPortalController;
use Application\Http\Controller\SisAuditController;
use Qubus\Routing\Interfaces\Mappable;
use Qubus\Routing\Psr7Router;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
    $router->map(['POST'], '/sis/courses/', [SisRegistrationController::class, 'course'])
        ->name('sis.registration.course');
    $router->map(['POST'], '/sis/sections/', [SisRegistrationController::class, 'section'])
        ->name('sis.registration.section');
    $router->map(['POST'], '/sis/terms/{id}/registration-window/', [SisRegistrationController::class, 'window'])
        ->name('sis.registration.window');
    $router->map(['POST'], '/sis/courses/{id}/prerequisites/', [SisRegistrationController::class, 'prerequisite'])
        ->name('sis.registration.prerequisite');
    $router->map(['POST'], '/sis/restrictions/', [SisRegistrationController::class, 'restriction'])
        ->name('sis.registration.restriction');
    $router->map(['POST'], '/sis/registrations/', [SisRegistrationController::class, 'register'])
        ->name('sis.registration.register');
    $router->map(['POST'], '/sis/registrations/drop/', [SisRegistrationController::class, 'drop'])
        ->name('sis.registration.drop');
    $router->map(['POST'], '/sis/grade-definitions/', [SisGradebookController::class, 'defineGrade'])
        ->name('sis.gradebook.definegrade');
    $router->map(['POST'], '/sis/sections/{id}/assignments/', [SisGradebookController::class, 'assignment'])
        ->name('sis.gradebook.assignment');
    $router->map(['POST'], '/sis/assignments/{id}/scores/', [SisGradebookController::class, 'score'])
        ->name('sis.gradebook.score');
    $router->map(['POST'], '/sis/sections/{id}/final-grades/', [SisGradebookController::class, 'finalGrade'])
        ->name('sis.gradebook.finalgrade');
    $router->map(['POST'], '/sis/sections/{id}/attendance/', [SisGradebookController::class, 'attendance'])
        ->name('sis.gradebook.attendance');
    $router->map(['GET'], '/sis/sections/{id}/students/{student}/scores/', [SisGradebookController::class, 'scores'])
        ->name('sis.gradebook.scores');
    $router->map(['GET'], '/sis/assignments/{id}/', [SisGradebookController::class, 'showAssignment'])
        ->name('sis.assignment.show');
    $router->map(['POST'], '/sis/assignments/{id}/edit/', [SisGradebookController::class, 'editAssignment'])
        ->name('sis.assignment.edit');
    $router->map(
        ['POST'], '/sis/sections/{id}/students/{student}/score-page/', [SisGradebookController::class, 'scorePage']
    )
        ->name('sis.gradebook.score-page');
    $router->map(
        ['POST'], '/sis/sections/{id}/students/{student}/attendance/',
        [SisGradebookController::class, 'attendanceHistory']
    )
        ->name('sis.gradebook.attendance-history');
    $router->map(['POST'], '/sis/rooms/', [SisBookingController::class, 'room'])
        ->name('sis.booking.room');
    $router->map(['POST'], '/sis/bookings/', [SisBookingController::class, 'request'])
        ->name('sis.booking.request');
    $router->map(['POST'], '/sis/bookings/{id}/approve/', [SisBookingController::class, 'approve'])
        ->name('sis.booking.approve');
    $router->map(['POST'], '/sis/bookings/{id}/cancel/', [SisBookingController::class, 'cancel'])
        ->name('sis.booking.cancel');
    $router->map(['GET'], '/sis/bookings/{id}/', [SisBookingController::class, 'show'])
        ->name('sis.booking.show');
    $router->map(['POST'], '/sis/employees/', [SisTimesheetController::class, 'employee'])
        ->name('sis.timesheet.employee');
    $router->map(['POST'], '/sis/timesheets/', [SisTimesheetController::class, 'submit'])
        ->name('sis.timesheet.submit');
    $router->map(['POST'], '/sis/timesheets/{id}/decision/', [SisTimesheetController::class, 'decide'])
        ->name('sis.timesheet.decide');
    $router->map(['GET'], '/sis/timesheets/{id}/', [SisTimesheetController::class, 'show'])
        ->name('sis.timesheet.show');
    $router->map(['POST'], '/sis/external-institutions/', [SisTransferController::class, 'institution'])
        ->name('sis.transfer.institution');
    $router->map(['POST'], '/sis/external-courses/', [SisTransferController::class, 'course'])
        ->name('sis.transfer.course');
    $router->map(['POST'], '/sis/transfer-equivalencies/', [SisTransferController::class, 'equate'])
        ->name('sis.transfer.equate');
    $router->map(['POST'], '/sis/transfer-awards/', [SisTransferController::class, 'award'])
        ->name('sis.transfer.award');
    $router->map(['GET'], '/sis/students/{id}/transcript/', [SisTransferController::class, 'transcript'])
        ->name('sis.transfer.transcript');
    $router->map(['POST'], '/sis/ledger-accounts/', [SisLedgerController::class, 'account'])
        ->name('sis.ledger.account');
    $router->map(['POST'], '/sis/journals/', [SisLedgerController::class, 'post'])
        ->name('sis.ledger.post');
    $router->map(['POST'], '/sis/journals/{id}/reverse/', [SisLedgerController::class, 'reverse'])
        ->name('sis.ledger.reverse');
    $router->map(['GET'], '/sis/journals/{id}/', [SisLedgerController::class, 'show'])
        ->name('sis.ledger.show');
    $router->map(
        ['POST'],
        '/sis/accounts/{id}/entries/{operation}/reverse/',
        [SisLedgerController::class, 'reverseEntry']
    )
        ->name('sis.ledger.reverseentry');
    $router->map(['POST'], '/sis/reports/', [SisReportController::class, 'create'])
        ->name('sis.report.create');
    $router->map(['POST'], '/sis/reports/{id}/shares/', [SisReportController::class, 'share'])
        ->name('sis.report.share');
    $router->map(['GET'], '/sis/reports/{id}/run/', [SisReportController::class, 'run'])
        ->name('sis.report.run');
    $router->map(['POST'], '/sis/imports/courses/{source}/', [SisImportController::class, 'courses'])
        ->name('sis.import.courses');
    $router->map(['POST'], '/sis/campaigns/', [SisCampaignController::class, 'create'])
        ->name('sis.campaign.create');
    $router->map(['POST'], '/sis/campaigns/{id}/queue/', [SisCampaignController::class, 'queue'])
        ->name('sis.campaign.queue');
    $router->map(['POST'], '/sis/campaigns/{id}/pause/', [SisCampaignController::class, 'pause'])
        ->name('sis.campaign.pause');
    $router->map(['POST'], '/sis/deliveries/{id}/dispatch/', [SisCampaignController::class, 'dispatch'])
        ->name('sis.campaign.dispatch');
    $router->map(['POST'], '/sis/portal/posts/', [SisPortalController::class, 'create'])
        ->name('sis.portal.create');
    $router->map(['POST'], '/sis/portal/posts/{id}/publication/', [SisPortalController::class, 'publish'])
        ->name('sis.portal.publish');
    $router->map(['GET'], '/sis/portal/content/{code}/', [SisPortalController::class, 'show'])
        ->name('sis.portal.show');
    $router->map(['GET'], '/sis/audit/subjects/{id}/', [SisAuditController::class, 'subject'])
        ->name('sis.audit.subject');
    $router->map(['POST'], '/sis/restrictions/{id}/end/', [SisRegistrationController::class, 'endRestriction'])
        ->name('sis.registration.end_restriction');
    $router->map(['POST'], '/sis/billing-rules/', [SisLedgerController::class, 'defineBillingRule'])
        ->name('sis.billing.define');
    $router->map(['POST'], '/sis/billing-rules/{id}/retire/', [SisLedgerController::class, 'retireBillingRule'])
        ->name('sis.billing.retire');
    $router->map(['POST'], '/sis/billing-batches/', [SisLedgerController::class, 'generateBills'])
        ->name('sis.billing.generate');
    $router->map(['GET'], '/sis/billing-rules/{id}/', [SisLedgerController::class, 'billingRule'])
        ->name('sis.billing.show');
    $router->map(['GET'], '/sis/employees/{id}/', [SisTimesheetController::class, 'employeeProfile'])
        ->name('sis.timesheet.employee_profile');
};
