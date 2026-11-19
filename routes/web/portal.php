<?php

declare(strict_types=1);

use Application\Http\Controller\PersonAddressController as Addresses;
use Application\Http\Controller\CampusPortalController as Portal;
use Application\Http\Controller\SisPortalEditorController as Editor;
use Application\Http\Controller\PortalPaymentController as Payments;
use Qubus\Routing\Interfaces\Mappable;
use Application\Http\Controller\PersonEmergencyContactsController as Emergency;
use Application\Http\Controller\PersonEducationController as Education;
use Application\Http\Controller\AdmissionCaseworkController as Casework;
use Application\Http\Controller\AdmissionUpdatesController as AdmissionUpdates;
use Application\Http\Controller\AdmissionDocumentController as Documents;
use Qubus\Routing\Psr7Router;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
    $revisions = \Application\Http\Controller\ApplicationRevisionController::class;
    $router->map(['GET'], '/portal/api/applications/{id}/answers/', [$revisions, 'mine']);
    $router->map(['POST'], '/portal/api/applications/{id}/answers/review/', [$revisions, 'reviewMine']);
    $router->map(['POST'], '/portal/api/applications/{id}/answers/commit/', [$revisions, 'commitMine']);
    $withdrawals = \Application\Http\Controller\AdmissionWithdrawalController::class;
    $router->map(['GET'], '/portal/api/applications/{id}/withdrawal/', [$withdrawals, 'mine']);
    $router->map(['POST'], '/portal/api/applications/{id}/withdrawal/review/', [$withdrawals, 'reviewMine']);
    $router->map(['POST'], '/portal/api/applications/{id}/withdrawal/commit/', [$withdrawals, 'commitMine']);
    $offers = \Application\Http\Controller\AdmissionOfferController::class;
    $router->map(['GET'], '/portal/api/applications/{id}/offer/', [$offers, 'mine']);
    $router->map(['POST'], '/portal/api/applications/{id}/offer/review/', [$offers, 'reviewMine']);
    $router->map(['POST'], '/portal/api/applications/{id}/offer/commit/', [$offers, 'commitMine']);
    $router->map(['GET'], '/portal/api/applications/{application}/documents/', [Documents::class, 'listingMine']);
    $router->map(['POST'], '/portal/api/applications/{application}/documents/', [Documents::class, 'uploadMine']);
    $router->map(
        ['GET'],
        '/portal/api/applications/{application}/documents/{id}/download/',
        [Documents::class, 'downloadMine']
    );

    $router->map(['GET'], '/portal/admissions-updates/', [Portal::class, 'admissionsUpdates'])
        ->name('portal.admissionsupdates');
    $router->map(['GET'], '/portal/api/admissions-updates/', [AdmissionUpdates::class, 'page'])
        ->name('portal.admissionsupdates.page');
    $router->map(['POST'], '/portal/api/admissions-updates/{id}/read/', [AdmissionUpdates::class, 'read'])
        ->name('portal.admissionsupdates.read');
    $router->map(['GET'], '/portal/api/applications/{id}/next-steps/', [Casework::class, 'applicant'])
        ->name('portal.application.nextsteps');
    $router->map(['GET'], '/portal/api/education/', [Education::class, 'mine'])->name('portal.education');
    $router->map(['POST'], '/portal/api/education/', [Education::class, 'saveMine'])->name('portal.education.save');
    $router->map(['GET'], '/portal/api/addresses/', [Addresses::class, 'mine'])
        ->name('portal.addresses');
    $router->map(['POST'], '/portal/api/addresses/', [Addresses::class, 'saveMine'])
        ->name('portal.addresses.save');
    $router->map(['GET'], '/portal/api/emergency-contacts/', [Emergency::class, 'mine'])
        ->name('portal.emergency');
    $router->map(['POST'], '/portal/api/emergency-contacts/', [Emergency::class, 'saveMine'])
        ->name('portal.emergency.save');

    $router->map(
        ['GET'],
        '/portal/password-setup/',
        [\Application\Http\Controller\AccountSetupController::class,
        'show']
    )->name('portal.account.setup');
    $router->map(
        ['POST'],
        '/portal/password-setup/',
        [\Application\Http\Controller\AccountSetupController::class,
        'complete']
    )->name('portal.account.setup.complete')->middleware(['rate.limiter']);

    /** @var Psr7Router&Mappable $router */

    $moodle = \Application\Http\Controller\PortalMoodleController::class;
    $router->map(["GET"], "/portal/moodle/", [$moodle, "page"])->name("portal.moodle");
    $router->map(["POST"], "/portal/moodle/launch/", [$moodle, "launch"])->name("portal.moodle.launch");
    $router
        ->map(["POST"], "/sis/moodle/session-channel/", [$moodle, "channel"])
        ->name("moodle.session.channel");
    $router->map(["GET"], "/portal/", [Portal::class, "home"])->name("portal.home");
    $events = \Application\Http\Controller\CampusEventController::class;
    $router->map(['GET'], '/portal/events/', [$events, 'page'])->name('portal.events');
    $router->map(['GET'], '/portal/api/events/', [$events, 'calendar'])->name('portal.events.calendar');
    $router->map(['GET'], '/portal/timetable/', [Portal::class, 'timetable'])->name('portal.timetable');
    $router->get('/portal/bookings/', [Portal::class, 'bookings'])->name('portal.bookings');
    $bookingUpdates = \Application\Http\Controller\PortalBookingUpdatesController::class;
    $router->post('/portal/api/booking-updates/page/', [$bookingUpdates, 'page'])->name('portal.bookings.page');
    $router->get('/portal/api/booking-updates/{id}/', [$bookingUpdates, 'detail'])->name('portal.bookings.detail');
    $router->post('/portal/api/booking-updates/{id}/read/', [$bookingUpdates, 'read'])->name('portal.bookings.read');
    $timetable = \Application\Http\Controller\PortalTimetableController::class;
    $router->map(['GET'], '/portal/api/timetable/context/', [$timetable, 'context'])->name('portal.timetable.context');
    $router->map(['POST'], '/portal/api/timetable/terms/', [$timetable, 'terms'])->name('portal.timetable.terms');
    $router->map(['POST'], '/portal/api/timetable/view/', [$timetable, 'view'])->name('portal.timetable.view');
    $router->map(["GET"], "/portal/sign-in/", [Portal::class, "landing"])->name("portal.landing");
    $router->map(["GET"], "/portal/courses/", [Portal::class, "catalog"])->name("portal.courses");
    $router->map(["GET"], "/portal/news/", [Portal::class, "news"])->name("portal.news");
    $router->map(["GET"], "/portal/news/{code}/", [Portal::class, "article"])->name("portal.article");
    $router->map(["GET"], "/portal/apply/", [Portal::class, "apply"])->name("portal.apply");
    $router->map(["GET"], "/portal/student/", [Portal::class, "student"])->name("portal.student");
    $router
        ->map(["GET"], "/portal/student/{section}/", [Portal::class, "student"])
        ->name("portal.student.section");
    $router->map(["GET"], "/portal/api/public/", [Portal::class, "publicData"])->name("portal.public");
    $router->map(["GET"], "/portal/api/programs/", [Portal::class, "programs"])->name("portal.programs");
    $router
        ->map(["GET"], "/portal/api/campus-news/", [Portal::class, "campusNews"])
        ->name("portal.campus.news");
    $router->map(["GET"], "/portal/api/courses/", [Portal::class, "courses"])->name("portal.catalog");
    $progress = \Application\Http\Controller\PortalStudentProgressController::class;
    $router->map(["POST"], "/portal/api/students/{student}/degree-programs/", [$progress, "programs"])
        ->name("portal.degree.programs");
    $router->map(
        ["POST"],
        "/portal/api/students/{student}/programs/{assignment}/degree-progress/",
        [$progress, "review"],
    )->name("portal.degree.progress");
    $router->map(["GET"], "/portal/api/me/", [Portal::class, "me"])->name("portal.me");
    $router->map(["GET"], "/portal/api/applications/", [Portal::class, "applications"])
        ->name("portal.applications");
    $router->map(["POST"], "/portal/api/contact/", [Portal::class, "contact"])->name("portal.contact");
    $router
        ->map(["GET"], "/portal/api/payments/status/", [Payments::class, "status"])
        ->name("portal.payments.status");
    $router
        ->map(["POST"], "/portal/api/payments/checkout/", [Payments::class, "checkout"])
        ->name("portal.payments.checkout");
    $router->map(["POST"], "/portal/api/register/", [Portal::class, "register"])->name("portal.register");
    $router->post('/portal/api/registration-plan/review/', [Portal::class, 'reviewRegistrationPlan']);
    $router->post('/portal/api/registration-plan/commit/', [Portal::class, 'registerPlan']);
    $router->post('/portal/api/registration/review/', [Portal::class, 'reviewRegistration'])
        ->name('portal.registration.review');
    $router
        ->map(["POST"], "/portal/api/drop-review/", [Portal::class, "reviewDrop"])
        ->name("portal.drop.review");
    $router
        ->map(["GET"], "/portal/api/drop-receipts/{operation}/", [Portal::class, "dropReceipt"])
        ->name("portal.drop.receipt");
    $router->map(["POST"], "/portal/api/drop/", [Portal::class, "drop"])->name("portal.drop");
    $router
        ->map(["GET"], "/portal/api/students/{id}/transcript/", [Portal::class, "transcript"])
        ->name("portal.transcript");
    $router
        ->map(["GET"], "/portal/api/students/{id}/sections/{section}/scores/", [Portal::class, "scores"])
        ->name("portal.scores");
    $router
        ->map(["GET"], "/portal/api/accounts/{id}/", [Portal::class, "statement"])
        ->name("portal.statement");
    $router
        ->map(["POST"], "/portal/api/transcripts/", [Portal::class, "requestTranscript"])
        ->name("portal.transcript.request");
    $router
        ->map(["GET"], "/portal/api/transcripts/{id}/", [Portal::class, "transcriptRequest"])
        ->name("portal.transcript.show");
    $router
        ->map(["POST"], "/portal/api/transcripts/{id}/status/", [Portal::class, "transitionTranscript"])
        ->name("portal.transcript.status");
    $router
        ->map(["GET"], "/sis/transcript-requests/", [Portal::class, "transcriptQueue"])
        ->name("sis.transcript.queue");
    $router->map(["GET"], "/sis/portal/editor/{id}/", [Editor::class, "get"])->name("sis.portal.editor.get");
    $router->map(["POST"], "/sis/portal/editor/", [Editor::class, "save"])->name("sis.portal.editor.save");
};
