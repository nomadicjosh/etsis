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
    $websiteForms = \Application\Http\Controller\WebsiteFormController::class;
    $router->get('/portal/api/website-forms/{page}/{form}/prepare/', [$websiteForms, 'prepare'])
        ->name('portal.website-forms.prepare');
    $router->post('/portal/api/website-forms/{page}/{form}/submit/', [$websiteForms, 'submit'])
        ->name('portal.website-forms.submit');

    $standingUpdates = \Application\Http\Controller\StandingUpdatesController::class;
    $router->get('/portal/api/standing-updates/', [$standingUpdates, 'page'])->name('portal.standing-updates');
    $router->get('/portal/api/standing-updates/{id}/', [$standingUpdates, 'detail'])
        ->name('portal.standing-updates.detail');
    $router->post('/portal/api/standing-updates/{id}/read/', [$standingUpdates, 'read'])
        ->name('portal.standing-updates.read');
    $router->post('/portal/api/standing-updates/{id}/delete/', [$standingUpdates, 'delete'])
        ->name('portal.standing-updates.delete');
    $transcriptUpdates = \Application\Http\Controller\TranscriptUpdatesController::class;
    $router->get('/portal/api/transcript-updates/', [$transcriptUpdates, 'page'])
        ->name('portal.transcript-updates');
    $router->get('/portal/api/transcript-updates/{id}/', [$transcriptUpdates, 'detail'])
        ->name('portal.transcript-updates.detail');
    $router->post('/portal/api/transcript-updates/{id}/read/', [$transcriptUpdates, 'read'])
        ->name('portal.transcript-updates.read');
    $router->post('/portal/api/transcript-updates/{id}/delete/', [$transcriptUpdates, 'delete'])
        ->name('portal.transcript-updates.delete');
    $academicUpdates = \Application\Http\Controller\AcademicUpdatesController::class;
    $router->get('/portal/api/academic-updates/', [$academicUpdates, 'page'])->name('portal.academic-updates');
    $router->get('/portal/api/academic-updates/{id}/', [$academicUpdates, 'detail'])
        ->name('portal.academic-updates.detail');
    $router->post('/portal/api/academic-updates/{id}/read/', [$academicUpdates, 'read'])
        ->name('portal.academic-updates.read');
    $router->post('/portal/api/academic-updates/{id}/delete/', [$academicUpdates, 'delete'])
        ->name('portal.academic-updates.delete');
    $notificationPreferences = \Application\Http\Controller\NotificationPreferencesController::class;
    $router->get('/portal/api/notification-preferences/', [$notificationPreferences, 'view'])
        ->name('portal.notification-preferences');
    $router->post('/portal/api/notification-preferences/', [$notificationPreferences, 'save'])
        ->name('portal.notification-preferences.save');

    $graduateApproval = \Application\Http\Controller\SisGraduateApprovalController::class;
    $router->post('/portal/api/students/{student}/graduate-courses/{section}/current/', [$graduateApproval, 'view'])
        ->name('portal.graduate.current');
    $router->post('/portal/api/students/{student}/graduate-courses/{section}/review/', [$graduateApproval, 'review'])
        ->name('portal.graduate.review');
    $router->post('/portal/api/students/{student}/graduate-courses/{section}/confirm/', [$graduateApproval, 'confirm'])
        ->name('portal.graduate.confirm');
    $advisingUpdates = \Application\Http\Controller\AdvisingUpdatesController::class;
    $router->get('/portal/api/advising-updates/', [$advisingUpdates, 'page']);
    $router->post('/portal/api/advising-updates/{id}/read/', [$advisingUpdates, 'read']);
    $router->post('/portal/api/advising-updates/{id}/delete/', [$advisingUpdates, 'delete']);
    $enrollmentUpdates = \Application\Http\Controller\EnrollmentUpdatesController::class;
    $router->get('/portal/api/enrollment-updates/', [$enrollmentUpdates, 'page']);
    $router->post('/portal/api/enrollment-updates/{id}/read/', [$enrollmentUpdates, 'read']);
    $router->post('/portal/api/enrollment-updates/{id}/delete/', [$enrollmentUpdates, 'delete']);
    $registrationUpdates = \Application\Http\Controller\RegistrationUpdatesController::class;
    $router->get('/portal/api/registration-updates/', [$registrationUpdates, 'page']);
    $router->post('/portal/api/registration-updates/{id}/read/', [$registrationUpdates, 'read']);
    $router->post('/portal/api/registration-updates/{id}/delete/', [$registrationUpdates, 'delete']);
    $announcements = \Application\Http\Controller\CampusAnnouncementController::class;
    $router->get('/portal/api/announcements/', [$announcements, 'page'])->name('portal.announcements.page');
    $router->post('/portal/api/announcements/{id}/delete/', [$announcements, 'delete']);
    $router->get('/portal/api/announcements/{id}/', [$announcements, 'detail'])->name('portal.announcements.detail');
    $router->post('/portal/api/announcements/{id}/read/', [$announcements, 'read'])
        ->name('portal.announcements.read');
    $profileRequests = \Application\Http\Controller\ProfileUpdateRequestController::class;
    $router->get('/portal/api/profile-update-requests/', [$profileRequests, 'mine']);
    $router->get('/portal/api/profile-update-requests/identity/', [$profileRequests, 'mineIdentity']);
    $router->post('/portal/api/profile-update-requests/{requestId}/respond/', [$profileRequests, 'respond']);
    $revisions = \Application\Http\Controller\ApplicationRevisionController::class;
    $changes = \Application\Http\Controller\AdmissionChangeRequestController::class;
    $router->get('/portal/api/applications/{id}/change-requests/', [$changes, 'mine']);
    $router->post('/portal/api/applications/{id}/change-requests/request/', [$changes, 'request']);
    $router->post('/portal/api/applications/{id}/change-requests/cancel/', [$changes, 'cancel']);
    $dropPlans = \Application\Http\Controller\SisCourseDropPlanController::class;
    $router->post('/portal/api/drop-plan/choices/', [$dropPlans, 'ownerChoices']);
    $router->post('/portal/api/drop-plan/review/', [$dropPlans, 'ownerReview']);
    $router->post('/portal/api/drop-plan/commit/', [$dropPlans, 'ownerCommit']);
    $router->get('/portal/api/drop-plan/receipts/{id}/', [$dropPlans, 'ownerReceipt']);
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
    $router->get('/portal/api/applications/{id}/history/', [
        \Application\Http\Controller\ApplicantApplicationHistoryController::class, 'page',
    ])->name('portal.applications.history');
    $router->map(['GET'], '/portal/api/admissions-updates/', [AdmissionUpdates::class, 'page'])
        ->name('portal.admissionsupdates.page');
    $router->map(['POST'], '/portal/api/admissions-updates/{id}/read/', [AdmissionUpdates::class, 'read'])
        ->name('portal.admissionsupdates.read');
    $router->map(['GET'], '/portal/api/admissions-updates/{id}/', [AdmissionUpdates::class, 'detail'])
        ->name('portal.admissionsupdates.detail');
    $router->map(['POST'], '/portal/api/admissions-updates/{id}/delete/', [AdmissionUpdates::class, 'delete'])
        ->name('portal.admissionsupdates.delete');
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

    $verification = \Application\Http\Controller\EmailVerificationController::class;
    $router->get('/portal/verify-email/', [$verification, 'page'])->name('portal.email_verification');
    $router->get('/portal/api/email-verification/', [$verification, 'status'])
        ->name('portal.email_verification.status');
    $router->post('/portal/api/email-verification/request/', [$verification, 'request'])
        ->name('portal.email_verification.request')->middleware(['rate.limiter']);
    $router->post('/portal/api/email-verification/confirm/', [$verification, 'confirm'])
        ->name('portal.email_verification.confirm')->middleware(['rate.limiter']);

    $moodle = \Application\Http\Controller\PortalMoodleController::class;
    $router->map(["GET"], "/portal/moodle/", [$moodle, "page"])->name("portal.moodle");
    $router->map(["POST"], "/portal/moodle/launch/", [$moodle, "launch"])->name("portal.moodle.launch");
    $router
        ->map(["POST"], "/sis/moodle/session-channel/", [$moodle, "channel"])
        ->name("moodle.session.channel");
    $router->map(["GET"], "/portal/", [Portal::class, "home"])->name("portal.home");
    $events = \Application\Http\Controller\CampusEventController::class;
    $router->map(['GET'], '/portal/events/', [$events, 'page'])->name('portal.events');
    $router->map(['GET', 'HEAD'], '/portal/events/calendar.ics', [
        \Application\Http\Controller\CampusEventFeedController::class, 'feed',
    ])->name('portal.events.feed');
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
    $router->post('/portal/api/timetable/export/', [$timetable, 'export'])->name('portal.timetable.export');
    $router->map(["GET"], "/portal/sign-in/", [Portal::class, "landing"])->name("portal.landing");
    $router->map(["GET"], "/portal/courses/", [Portal::class, "catalog"])->name("portal.courses");
    $programCatalog = \Application\Http\Controller\ProgramDiscoveryController::class;
    $router->get('/portal/programs/', [$programCatalog, 'page'])->name('portal.program.catalog');
    $router->get('/portal/programs/{id}/', [$programCatalog, 'detail'])->name('portal.program.detail');
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
    $load = \Application\Http\Controller\SisEnrollmentLoadController::class;
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/load/determinations/', [
        $load, 'ownerRecords',
    ]);
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/load/determinations/', [
        $load, 'advisorRecords',
    ]);
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/load/terms/', [
        $load, 'ownerTerms',
    ])->name('owner.load.terms');
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/load/review/', [
        $load, 'ownerLoad',
    ])->name('owner.load.review');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/load/terms/', [
        $load, 'advisorTerms',
    ])->name('advisor.load.terms');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/load/review/', [
        $load, 'advisorLoad',
    ])->name('advisor.load.review');
    $standing = \Application\Http\Controller\SisStudentStandingController::class;
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/standing/', [
        $standing, 'owner',
    ])
        ->name('portal.standing.owner');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/standing/', [
        $standing, 'advisor',
    ])
        ->name('portal.standing.advisor');
    $availability = \Application\Http\Controller\AdvisingAvailabilityController::class;
    $router->map(['POST'], '/portal/api/advising-availability/{action}/', [$availability, 'mine'])
        ->name('advising.availability.mine');
    $appointments = \Application\Http\Controller\AdvisingAppointmentController::class;
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/appointments/calendar/', [
        $appointments, 'ownerCalendar',
    ])->name('owner.appointments.calendar');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/appointments/calendar/', [
        $appointments, 'advisorCalendar',
    ])->name('advisor.appointments.calendar');
    foreach (['bookingChoices' => 'available-times', 'book' => 'book'] as $method => $suffix) {
        $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/appointments/' . $suffix . '/', [
            $appointments, $method,
        ])->name('owner.appointments.' . $method);
    }
    $router->map(['POST'], '/portal/api/advising-appointments/calendar/', [
        $appointments, 'myCalendar',
    ])->name('owner.appointments.my_calendar');
    $router->map(['POST'], '/portal/api/advising-appointments/export/', [
        $appointments, 'export',
    ])->name('owner.appointments.export');
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/appointments/list/', [
        $appointments, 'ownerList',
    ])->name('owner.appointments.list');
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/appointments/act/', [
        $appointments, 'ownerAct',
    ])->name('owner.appointments.act');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/appointments/list/', [
        $appointments, 'advisorList',
    ])->name('advisor.appointments.list');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/appointments/schedule/', [
        $appointments, 'advisorSchedule',
    ])->name('advisor.appointments.schedule');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/appointments/act/', [
        $appointments, 'advisorAct',
    ])->name('advisor.appointments.act');
    $checkins = \Application\Http\Controller\AdvisingCheckInController::class;
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/checkins/list/', [
        $checkins, 'ownerList',
    ])
        ->name('owner.checkins.list');
    $router->map(['POST'], '/portal/api/students/{student}/programs/{pursuit}/checkins/act/', [
        $checkins, 'ownerAct',
    ])
        ->name('owner.checkins.act');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/checkins/list/', [
        $checkins, 'advisorList',
    ])
        ->name('advisor.checkins.list');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/checkins/record/', [
        $checkins, 'advisorRecord',
    ])
        ->name('advisor.checkins.record');
    $router->map(['POST'], '/portal/api/advising/students/{student}/programs/{pursuit}/checkins/act/', [
        $checkins, 'advisorAct',
    ])
        ->name('advisor.checkins.act');
    $advising = \Application\Http\Controller\SisAdvisingController::class;
    $router->map(['POST'], '/portal/api/advising/', [$advising, 'mine'])->name('portal.advising.mine');
    $router->map(
        ['POST'],
        '/portal/api/advising/students/{student}/programs/{pursuit}/progress/',
        [$advising, 'progress']
    )->name('portal.advising.progress');
    $router->map(
        ['POST'],
        '/portal/api/students/{student}/programs/{pursuit}/advisors/',
        [$advising, 'owner']
    )->name('portal.advisors.owner');
    $recordedProgress = \Application\Http\Controller\SisDegreeProgressRecordController::class;
    $router->post(
        '/portal/api/students/{student}/programs/{assignment}/degree-progress-records/page/',
        [$recordedProgress, 'ownerPage']
    );
    $router->get(
        '/portal/api/students/{student}/programs/{assignment}/degree-progress-records/{id}/',
        [$recordedProgress, 'ownerShow']
    );
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
    $router->post('/portal/api/section-changes/choices/', [
        \Application\Http\Controller\SectionChangeController::class, 'studentChoices',
    ]);
    $router->post('/portal/api/section-changes/review/', [
        \Application\Http\Controller\SectionChangeController::class, 'studentReview',
    ]);
    $router->post('/portal/api/section-changes/commit/', [
        \Application\Http\Controller\SectionChangeController::class, 'studentCommit',
    ]);
    $router->post('/portal/api/registration-plan/review/', [Portal::class, 'reviewRegistrationPlan']);
    $router->post('/portal/api/registration-plan/commit/', [Portal::class, 'registerPlan']);
    $waitlists = \Application\Http\Controller\SisWaitlistController::class;
    $router->get('/portal/api/waitlists/{student}/', [$waitlists, 'mine'])->name('portal.waitlist.mine');
    $router->get('/portal/api/waitlists/{student}/sections/{id}/', [$waitlists, 'ownerView']);
    $router->post('/portal/api/waitlists/{student}/sections/{id}/review/', [$waitlists, 'ownerReview']);
    $router->post('/portal/api/waitlists/{student}/sections/{id}/confirm/', [$waitlists, 'ownerConfirm']);
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
    $router->map(['GET'], '/sis/portal/audience-roles/', [Editor::class, 'roles'])->name('sis.portal.audience.roles');
    $router->map(['GET'], '/sis/portal/audience-programs/', [Editor::class, 'programs'])
        ->name('sis.portal.audience.programs');
    $router->map(["GET"], "/sis/portal/editor/{id}/", [Editor::class, "get"])->name("sis.portal.editor.get");
    $router->map(["POST"], "/sis/portal/editor/", [Editor::class, "save"])->name("sis.portal.editor.save");
};
