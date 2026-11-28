<?php

declare(strict_types=1);

use Application\Http\Controller\SisDegreeAuditController;
use Application\Http\Controller\SisStudentRequirementsController;
use Application\Http\Controller\SisRequirementAdjustmentController;
use Application\Http\Controller\SisCourseGroupController;
use Application\Http\Controller\SisRegistrationController;
use Application\Http\Controller\SisCoursePublicationController;
use Application\Http\Controller\SisCoursePrerequisiteController;
use Application\Http\Controller\SisCourseTextController;
use Application\Http\Controller\SisCourseAssistanceController;
use Application\Http\Controller\SisSectionAssistanceController;
use Application\Http\Controller\SisDegreeAssistanceController;
use Application\Http\Controller\SisMoodleController;
use Application\Http\Controller\SisMoodleCategoryController;
use Application\Http\Controller\SisMoodleSectionController;
use Application\Http\Controller\SisMoodleBindingController;
use Application\Http\Controller\SisMoodlePersonLinkController;
use Application\Http\Controller\SisMoodleAccountRequestController;
use Application\Http\Controller\SisSectionCourseBindingController;
use Application\Http\Controller\SisCatalogWorkflowController;
use Application\Http\Controller\SisFinancialReviewController;
use Application\Http\Controller\SisTranscriptFulfillmentController;
use Application\Http\Controller\SisTranscriptReleasePolicyController;
use Application\Http\Controller\SisRestrictionController;
use Application\Http\Controller\SisOfficeController;
use Application\Http\Controller\SisStudentRecordController;
use Application\Http\Controller\SisHiatusController;
use Application\Http\Controller\SisHiatusWorkflowController;
use Application\Http\Controller\SisHiatusReturnController;
use Application\Http\Controller\SisRegistrationBatchController;
use Application\Http\Controller\SisGradebookController;
use Application\Http\Controller\SisBookingController;
use Application\Http\Controller\SisRoomController;
use Application\Http\Controller\SisTimesheetController;
use Application\Http\Controller\SisTransferController;
use Application\Http\Controller\SisTransferCatalogController;
use Application\Http\Controller\SisLedgerController;
use Application\Http\Controller\SisReportController;
use Application\Http\Controller\SisCampaignController;
use Application\Http\Controller\SisPortalController;
use Application\Http\Controller\SisAuditController;
use Qubus\Routing\Interfaces\Mappable;
use Qubus\Routing\Psr7Router;
use Application\Http\Controller\SisRelationalReportController;
use Application\Http\Controller\SisStudentCatalogController;

return static function (Psr7Router $router): void {
    $dropPlans = \Application\Http\Controller\SisCourseDropPlanController::class;
    $router->post('/sis/drop-plans/choices/', [$dropPlans, 'staffChoices']);
    $router->post('/sis/drop-plans/review/', [$dropPlans, 'staffReview']);
    $router->post('/sis/drop-plans/commit/', [$dropPlans, 'staffCommit']);
    $router->get('/sis/drop-plans/receipts/{id}/', [$dropPlans, 'staffReceipt']);
    $router->get('/sis/courses/{id}/prerequisite-groups/', [SisCoursePrerequisiteController::class, 'current']);
    $router->post('/sis/courses/{id}/prerequisite-groups/', [SisCoursePrerequisiteController::class, 'save']);
    $router->post('/sis/prerequisite-labels/', [SisCoursePrerequisiteController::class, 'labels']);
    $router->post('/sis/section-changes/choices/', [
        \Application\Http\Controller\SectionChangeController::class, 'staffChoices',
    ]);
    $router->post('/sis/section-changes/review/', [
        \Application\Http\Controller\SectionChangeController::class, 'staffReview',
    ]);
    $router->post('/sis/section-changes/commit/', [
        \Application\Http\Controller\SectionChangeController::class, 'staffCommit',
    ]);
    $router->post('/sis/registration-plans/review/', [SisRegistrationBatchController::class, 'reviewPlan']);
    $router->post('/sis/registration-plans/commit/', [SisRegistrationBatchController::class, 'commitPlan']);

    /** @var Psr7Router&Mappable $router */
    $transcriptWork = \Application\Http\Controller\TranscriptWorklistController::class;
    $router->get('/sis/transcript-worklist/', [$transcriptWork, 'page']);
    $router->get('/sis/transcript-worklist/assignees/', [$transcriptWork, 'assignees']);
    $router->get('/sis/transcript-requests/{id}/assignment/', [$transcriptWork, 'view']);
    $router->post('/sis/transcript-requests/{id}/assignment/', [$transcriptWork, 'save']);

    $router->post('/sis/offices/', [SisOfficeController::class, 'choices']);
    $router->post('/sis/offices/{id}/save/', [SisOfficeController::class, 'save']);
    $router->post('/sis/offices/{id}/delegates/', [SisOfficeController::class, 'delegates']);
    $router->post('/sis/offices/{id}/delegates/save/', [SisOfficeController::class, 'delegate']);
    $router->post('/sis/offices/accounts/', [SisOfficeController::class, 'accounts']);
    $router->post('/sis/offices/people/', [SisOfficeController::class, 'people']);
    $router->post('/sis/restrictions/queue/', [SisRestrictionController::class, 'queue']);
    $router->post('/sis/restrictions/{id}/office/', [SisRestrictionController::class, 'bind']);
    $router->get('/sis/restrictions/{id}/follow-up/', [SisRestrictionController::class, 'followUp']);
    $router->post('/sis/restrictions/{id}/follow-up/people/', [SisRestrictionController::class, 'followUpPeople']);
    $router->post('/sis/restrictions/{id}/follow-up/save/', [SisRestrictionController::class, 'saveFollowUp']);
    $router->post('/sis/restrictions/{id}/handoff/review/', [SisRestrictionController::class, 'reviewHandoff']);
    $router->post('/sis/restrictions/{id}/handoff/confirm/', [SisRestrictionController::class, 'confirmHandoff']);
    $router
        ->map(["POST"], "/sis/students/{student}/registration-history/", [
            \Application\Http\Controller\SisEnrollmentHistoryController::class,
            "student",
        ])
        ->name("sis.registration.history");
    $router
        ->map(["POST"], "/sis/grading-scales/global/review/", [
            \Application\Http\Controller\SisGradingScaleController::class,
            "review",
        ])
        ->name("sis.grading.global.review");
    $router
        ->map(["POST"], "/sis/grading-scales/global/commit/", [
            \Application\Http\Controller\SisGradingScaleController::class,
            "commit",
        ])
        ->name("sis.grading.global.commit");
    $router
        ->map(["GET"], "/sis/grading-scales/global/", [
            \Application\Http\Controller\SisGradingScaleController::class,
            "global",
        ])
        ->name("sis.grading.global");
    $router
        ->map(["POST"], "/sis/sections/{id}/students/{student}/grade-choices/", [
            SisGradebookController::class,
            "gradeChoices",
        ])
        ->name("sis.gradebook.choices");
    $router->post("/sis/sections/{id}/students/{student}/academic-details/review/", [
        SisGradebookController::class,
        "reviewAcademicDetails",
    ]);
    $router->post("/sis/sections/{id}/students/{student}/academic-details/commit/", [
        SisGradebookController::class,
        "commitAcademicDetails",
    ]);
    $router->post('/sis/sections/{id}/students/{student}/academic-dates/review/', [
        SisGradebookController::class, 'reviewChronology',
    ]);
    $router->post('/sis/sections/{id}/students/{student}/academic-dates/commit/', [
        SisGradebookController::class, 'commitChronology',
    ]);
    $router->post('/sis/sections/{id}/students/{student}/academic-period/review/', [
        SisGradebookController::class, 'reviewAcademicPeriod',
    ]);
    $router->post('/sis/sections/{id}/students/{student}/academic-period/commit/', [
        SisGradebookController::class, 'commitAcademicPeriod',
    ]);
    $router->post('/sis/sections/{id}/students/{student}/course-publication/courses/', [
        SisGradebookController::class, 'academicCourseSearch',
    ]);
    $router->post('/sis/sections/{id}/students/{student}/course-publication/choices/', [
        SisGradebookController::class, 'coursePublications',
    ]);
    $router->post('/sis/sections/{id}/students/{student}/course-publication/review/', [
        SisGradebookController::class, 'reviewCoursePublication',
    ]);
    $router->post('/sis/sections/{id}/students/{student}/course-publication/commit/', [
        SisGradebookController::class, 'commitCoursePublication',
    ]);
    $router
        ->map(["POST"], "/sis/sections/{id}/students/{student}/completion-date/review/", [
            SisGradebookController::class,
            "reviewCompletion",
        ])
        ->name("sis.gradebook.completion.review");
    $router
        ->map(["POST"], "/sis/sections/{id}/students/{student}/completion-date/commit/", [
            SisGradebookController::class,
            "commitCompletion",
        ])
        ->name("sis.gradebook.completion.commit");
    $router
        ->map(["GET"], "/sis/programs/{program}/grading-scale/", [
            \Application\Http\Controller\SisGradingScaleController::class,
            "view",
        ])
        ->name("sis.grading.program");
    $router
        ->map(["POST"], "/sis/programs/{program}/grading-scale/review/", [
            \Application\Http\Controller\SisGradingScaleController::class,
            "review",
        ])
        ->name("sis.grading.program.review");
    $router
        ->map(["POST"], "/sis/programs/{program}/grading-scale/commit/", [
            \Application\Http\Controller\SisGradingScaleController::class,
            "commit",
        ])
        ->name("sis.grading.program.commit");
    $router
        ->map(["POST"], "/sis/catalog/offering-templates/page/", [
            \Application\Http\Controller\SisOfferingTemplateController::class,
            "page",
        ])
        ->name("sis.offering.templates.page");
    $router
        ->map(["POST"], "/sis/catalog/offering-templates/review/", [
            \Application\Http\Controller\SisOfferingTemplateController::class,
            "review",
        ])
        ->name("sis.offering.templates.review");
    $router
        ->map(["GET"], "/sis/catalog/offering-templates/{id}/", [
            \Application\Http\Controller\SisOfferingTemplateController::class,
            "show",
        ])
        ->name("sis.offering.templates.show");
    $router
        ->map(["POST"], "/sis/catalog/offering-templates/{id}/save/", [
            \Application\Http\Controller\SisOfferingTemplateController::class,
            "save",
        ])
        ->name("sis.offering.templates.save");
    $router
        ->map(["POST"], "/sis/catalog/offering-templates/{id}/prepare/", [
            \Application\Http\Controller\SisOfferingTemplateController::class,
            "prepare",
        ])
        ->name("sis.offering.templates.prepare");
    $router
        ->map(["POST"], "/sis/catalog/offering-templates/{id}/status/", [
            \Application\Http\Controller\SisOfferingTemplateController::class,
            "status",
        ])
        ->name("sis.offering.templates.status");
    $router
        ->map(["POST"], "/sis/catalog/offering-templates/{id}/history/", [
            \Application\Http\Controller\SisOfferingTemplateController::class,
            "history",
        ])
        ->name("sis.offering.templates.history");
    $router
        ->map(["GET"], "/sis/catalog/offering-templates/{id}/receipts/{operation}/", [
            \Application\Http\Controller\SisOfferingTemplateController::class,
            "receipt",
        ])
        ->name("sis.offering.templates.receipt");

    $router
        ->map(["POST"], "/sis/report-requests/", [
            \Application\Http\Controller\SisReportRequestController::class,
            "create",
        ])
        ->name("sis.report.requests.create");
    $router
        ->map(["POST"], "/sis/report-requests/page/", [
            \Application\Http\Controller\SisReportRequestController::class,
            "page",
        ])
        ->name("sis.report.requests.page");
    $router
        ->map(["GET"], "/sis/report-requests/{id}/", [
            \Application\Http\Controller\SisReportRequestController::class,
            "show",
        ])
        ->name("sis.report.requests.show");
    $router
        ->map(["POST"], "/sis/report-requests/{id}/history/", [
            \Application\Http\Controller\SisReportRequestController::class,
            "history",
        ])
        ->name("sis.report.requests.history");
    $router
        ->map(["POST"], "/sis/report-requests/{id}/delivery-review/", [
            \Application\Http\Controller\SisReportRequestController::class,
            "review",
        ])
        ->name("sis.report.requests.review");
    $router
        ->map(["POST"], "/sis/report-requests/{id}/actions/", [
            \Application\Http\Controller\SisReportRequestController::class,
            "change",
        ])
        ->name("sis.report.requests.change");
    $router
        ->map(["GET"], "/sis/report-requests/{id}/receipts/{operation}/", [
            \Application\Http\Controller\SisReportRequestController::class,
            "receipt",
        ])
        ->name("sis.report.requests.receipt");

    $router
        ->map(["POST"], "/sis/students/{student}/sections/{section}/drop-review/", [
            SisRegistrationController::class,
            "reviewDrop",
        ])
        ->name("sis.drop.review");
    $router
        ->map(["GET"], "/sis/course-drops/{operation}/", [SisRegistrationController::class, "dropReceipt"])
        ->name("sis.drop.receipt");
    $router
        ->map(["GET"], "/sis/terms/{term}/drop-policy/", [
            \Application\Http\Controller\SisDropPolicyController::class,
            "show",
        ])
        ->name("sis.drop.policy");
    $router->map(['POST'], '/sis/terms/{term}/drop-policy/review/', [
        \Application\Http\Controller\SisDropPolicyController::class, 'review',
    ])->name('sis.drop.policy.review');
    $router->map(['POST'], '/sis/terms/{term}/drop-policy/commit/', [
        \Application\Http\Controller\SisDropPolicyController::class, 'commit',
    ])->name('sis.drop.policy.commit');
    $router->map(['GET'], '/sis/terms/{term}/drop-policy/history/', [
        \Application\Http\Controller\SisDropPolicyController::class, 'history',
    ])->name('sis.drop.policy.history');
    foreach (
        ['show' => ['', 'GET'], 'review' => ['review/', 'POST'],
        'commit' => ['commit/', 'POST'], 'history' => ['history/', 'GET']] as $method => [$suffix, $verb]
    ) {
        $router->map([$verb], '/sis/terms/{term}/sections/{section}/drop-policy/' . $suffix, [
            \Application\Http\Controller\SisDropPolicyController::class, $method,
        ])->name('sis.section.drop.policy.' . $method);
    }
    $router
        ->map(["GET"], "/sis/students/{student}/transfer-awards/{award}/", [
            \Application\Http\Controller\SisTransferAwardController::class,
            "show",
        ])
        ->name("sis.transfer.award.show");
    $router
        ->map(["POST"], "/sis/transfer-equivalencies/{id}/course-versions/", [
            \Application\Http\Controller\SisTransferController::class, "courseVersions",
        ])->name("sis.transfer.courseversions");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/preview/", [
            \Application\Http\Controller\SisTransferAwardController::class,
            "preview",
        ])
        ->name("sis.transfer.award.preview");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/confirm/", [
            \Application\Http\Controller\SisTransferAwardController::class,
            "confirm",
        ])
        ->name("sis.transfer.award.confirm");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/history/", [
            \Application\Http\Controller\SisTransferAwardController::class,
            "history",
        ])
        ->name("sis.transfer.award.history");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/accepted-course/search/", [
            \Application\Http\Controller\SisTransferAwardController::class, "courseSearch",
        ])->name("sis.transfer.course.search");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/accepted-course/choices/", [
            \Application\Http\Controller\SisTransferAwardController::class, "courseChoices",
        ])->name("sis.transfer.course.choices");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/accepted-course/review/", [
            \Application\Http\Controller\SisTransferAwardController::class, "reviewCourse",
        ])->name("sis.transfer.course.review");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/accepted-course/commit/", [
            \Application\Http\Controller\SisTransferAwardController::class, "commitCourse",
        ])->name("sis.transfer.course.commit");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/accepted-course/history/", [
            \Application\Http\Controller\SisTransferAwardController::class, "courseHistory",
        ])->name("sis.transfer.course.history");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/source-period/review/", [
            \Application\Http\Controller\SisTransferAwardController::class, "reviewPeriod",
        ])->name("sis.transfer.period.review");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/source-period/commit/", [
            \Application\Http\Controller\SisTransferAwardController::class, "commitPeriod",
        ])->name("sis.transfer.period.commit");
    $router
        ->map(["POST"], "/sis/students/{student}/transfer-awards/{award}/source-period/history/", [
            \Application\Http\Controller\SisTransferAwardController::class, "periodHistory",
        ])->name("sis.transfer.period.history");
    $router
        ->map(["GET"], "/sis/students/{student}/transfer-awards/{award}/changes/{operation}/", [
            \Application\Http\Controller\SisTransferAwardController::class,
            "receipt",
        ])
        ->name("sis.transfer.award.receipt");
    /** @var Psr7Router&Mappable $router */
    $router
        ->map(["GET"], "/sis/report-studio/catalog/", [SisRelationalReportController::class, "catalog"])
        ->name("sis.report.studio.catalog");
    $router
        ->map(['POST'], '/sis/report-studio/graph/', [SisRelationalReportController::class, 'graph'])
        ->name('sis.report.studio.graph');
    $router
        ->map(["POST"], "/sis/report-studio/preview/", [SisRelationalReportController::class, "preview"])
        ->name("sis.report.studio.preview");
    $router
        ->map(["POST"], "/sis/report-studio/save/", [SisRelationalReportController::class, "save"])
        ->name("sis.report.studio.save");
    $router
        ->map(["GET"], "/sis/report-studio/{id}/", [SisRelationalReportController::class, "show"])
        ->name("sis.report.studio.show");
    $router
        ->map(["POST"], "/sis/report-studio/{id}/run/", [SisRelationalReportController::class, "run"])
        ->name("sis.report.studio.run");
    $router
        ->map(["POST"], "/sis/report-studio/{id}/population/", [SisRelationalReportController::class, "population"])
        ->name("sis.report.studio.population");
    $router
        ->map(["POST"], "/sis/report-studio/{id}/population/select/", [
            SisRelationalReportController::class, "selectStudents",
        ])
        ->name("sis.report.studio.population.select");
    $router
        ->map(["POST"], "/sis/report-studio/{id}/export/", [SisRelationalReportController::class, "export"])
        ->name("sis.report.studio.export");
    $router->get('/sis/report-studio/{id}/settings/', [SisRelationalReportController::class, 'settings']);
    $router->post('/sis/report-studio/{id}/settings/', [SisRelationalReportController::class, 'configure']);
    $router->get('/sis/report-studio/{id}/exports/', [
        \Application\Http\Controller\SisReportExportController::class, 'latest',
    ]);
    $router->post('/sis/report-studio/{id}/exports/', [
        \Application\Http\Controller\SisReportExportController::class, 'request',
    ]);
    $router->post('/sis/report-studio/{id}/exports/{export}/cancel/', [
        \Application\Http\Controller\SisReportExportController::class, 'cancel',
    ]);
    $router->post('/sis/report-studio/{id}/exports/{export}/download/', [
        \Application\Http\Controller\SisReportExportController::class, 'download',
    ]);

    $router
        ->map(["POST"], "/sis/term-types/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "types",
        ])
        ->name("sis.calendar.types");
    $router
        ->map(["POST"], "/sis/term-types/{id}/preview/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "previewType",
        ])
        ->name("sis.calendar.types.preview");
    $router
        ->map(["POST"], "/sis/term-types/{id}/save/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "saveType",
        ])
        ->name("sis.calendar.types.save");
    $router
        ->map(["POST"], "/sis/academic-years/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "years",
        ])
        ->name("sis.calendar.years");
    $router
        ->map(["POST"], "/sis/academic-years/review/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "review",
        ])
        ->name("sis.calendar.years.review");
    $router
        ->map(["POST"], "/sis/academic-years/create/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "create",
        ])
        ->name("sis.calendar.years.create");
    $router
        ->map(["GET"], "/sis/academic-years/{id}/receipts/{operation}/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "receipt",
        ])
        ->name("sis.calendar.years.receipt");
    $router
        ->map(["POST"], "/sis/calendar/terms/create/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "saveTerm",
        ])
        ->name("sis.calendar.terms.create");
    $router
        ->map(["GET"], "/sis/calendar/terms/{id}/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "term",
        ])
        ->name("sis.calendar.terms.show");
    $router
        ->map(["POST"], "/sis/calendar/terms/{id}/save/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "saveTerm",
        ])
        ->name("sis.calendar.terms.save");
    $router
        ->map(["POST"], "/sis/students/{student}/programs/{assignment}/catalog/", [
            SisStudentCatalogController::class,
            "show",
        ])
        ->name("sis.student.catalog");
    $router
        ->map(["POST"], "/sis/students/{student}/programs/{assignment}/catalog/history/", [
            SisStudentCatalogController::class,
            "history",
        ])
        ->name("sis.student.catalog.history");
    foreach (['choices', 'review', 'commit'] as $action) {
        $router->map(
            ['POST'],
            '/sis/students/{student}/programs/{assignment}/catalog/' . $action . '/',
            [SisStudentCatalogController::class, $action]
        )->name('sis.student.catalog.' . $action);
    }

    $router
        ->map(["POST"], "/sis/students/{student}/hiatus-workflow/review/", [
            SisHiatusWorkflowController::class,
            "review",
        ])
        ->name("sis.hiatus.workflow.review");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatus-workflow/commit/", [
            SisHiatusWorkflowController::class,
            "commit",
        ])
        ->name("sis.hiatus.workflow.commit");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatuses/{id}/return/review/", [
            SisHiatusReturnController::class,
            "review",
        ])
        ->name("sis.hiatus.return.review");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatuses/{id}/return/commit/", [
            SisHiatusReturnController::class,
            "commit",
        ])
        ->name("sis.hiatus.return.commit");
    $router
        ->map(["GET"], "/sis/students/{student}/hiatuses/{id}/returns/{operation}/", [
            SisHiatusReturnController::class,
            "show",
        ])
        ->name("sis.hiatus.return.show");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatuses/{id}/finance-review/", [
            SisFinancialReviewController::class,
            "request",
        ])
        ->name("sis.finance.review.request");
    $router
        ->map(["GET"], "/sis/students/{student}/hiatuses/{id}/finance-review/", [
            SisFinancialReviewController::class,
            "progress",
        ])
        ->name("sis.finance.review.progress");
    $router
        ->map(["POST"], "/sis/finance-reviews/", [SisFinancialReviewController::class, "page"])
        ->name("sis.finance.review.page");
    $router
        ->map(["GET"], "/sis/finance-reviews/{id}/", [SisFinancialReviewController::class, "show"])
        ->name("sis.finance.review.show");
    $router
        ->post('/sis/finance-reviews/{id}/accounts/', [SisFinancialReviewController::class, 'accounts'])
        ->name('sis.finance.review.accounts');
    $router
        ->map(["POST"], "/sis/finance-reviews/{id}/change/", [SisFinancialReviewController::class, "change"])
        ->name("sis.finance.review.change");
    $router
        ->map(["POST"], "/sis/finance-reviews/{id}/history/", [
            SisFinancialReviewController::class,
            "history",
        ])
        ->name("sis.finance.review.history");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatuses/", [SisHiatusController::class, "page"])
        ->name("sis.hiatus.page");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatuses/create/", [SisHiatusController::class, "create"])
        ->name("sis.hiatus.create");
    $router
        ->map(["GET"], "/sis/students/{student}/hiatuses/{id}/", [SisHiatusController::class, "show"])
        ->name("sis.hiatus.show");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatuses/{id}/end/", [SisHiatusController::class, "end"])
        ->name("sis.hiatus.end");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatuses/{id}/void/", [SisHiatusController::class, "void"])
        ->name("sis.hiatus.void");
    $router
        ->map(["POST"], "/sis/students/{student}/hiatuses/{id}/history/", [
            SisHiatusController::class,
            "history",
        ])
        ->name("sis.hiatus.history");
    $router
        ->map(["POST"], "/sis/registration-batches/review/", [
            SisRegistrationBatchController::class,
            "review",
        ])
        ->name("sis.registration.batch.review");
    $router
        ->map(["POST"], "/sis/registration-batches/commit/", [
            SisRegistrationBatchController::class,
            "commit",
        ])
        ->name("sis.registration.batch.commit");
    $router
        ->map(["GET"], "/sis/registration-batches/{id}/", [SisRegistrationBatchController::class, "show"])
        ->name("sis.registration.batch.show");
    $router
        ->map(["GET"], "/sis/students/{id}/record/", [SisStudentRecordController::class, "show"])
        ->name("sis.student.record");
    $router
        ->map(["POST"], "/sis/students/{id}/activity/review/", [
            \Application\Http\Controller\SisStudentActivityController::class,
            "review",
        ])
        ->name("sis.student.activity.review");
    $router
        ->map(["POST"], "/sis/students/{id}/activity/commit/", [
            \Application\Http\Controller\SisStudentActivityController::class,
            "commit",
        ])
        ->name("sis.student.activity.commit");
    $router
        ->map(["POST"], "/sis/students/{id}/activity/history/", [
            \Application\Http\Controller\SisStudentActivityController::class,
            "history",
        ])
        ->name("sis.student.activity.history");
    $router
        ->map(["POST"], "/sis/students/{id}/programs/", [SisStudentRecordController::class, "programs"])
        ->name("sis.student.programs");
    $router
        ->map(["POST"], "/sis/students/{id}/programs/assign/review/", [
            \Application\Http\Controller\SisStudentProgramAssignmentController::class,
            "review",
        ])
        ->name("sis.student.program.assign.review");
    $router
        ->map(["POST"], "/sis/students/{id}/programs/assign/commit/", [
            \Application\Http\Controller\SisStudentProgramAssignmentController::class,
            "commit",
        ])
        ->name("sis.student.program.assign.commit");
    $router
        ->map(["POST"], "/sis/students/{id}/primary-program/review/", [
            \Application\Http\Controller\SisStudentPrimaryProgramController::class,
            "review",
        ])
        ->name("sis.student.program.primary.review");
    $router
        ->map(["POST"], "/sis/students/{id}/primary-program/commit/", [
            \Application\Http\Controller\SisStudentPrimaryProgramController::class,
            "commit",
        ])
        ->name("sis.student.program.primary.commit");
    $router
        ->map(["POST"], "/sis/students/{id}/primary-program/history/", [
            \Application\Http\Controller\SisStudentPrimaryProgramController::class,
            "history",
        ])
        ->name("sis.student.program.primary.history");
    $router
        ->map(["POST"], "/sis/students/{id}/programs/{assignment}/status/review/", [
            \Application\Http\Controller\SisStudentProgramStatusController::class,
            "review",
        ])
        ->name("sis.student.program.status.review");
    $router
        ->map(["POST"], "/sis/students/{id}/programs/{assignment}/status/commit/", [
            \Application\Http\Controller\SisStudentProgramStatusController::class,
            "commit",
        ])
        ->name("sis.student.program.status.commit");
    $router
        ->map(["POST"], "/sis/students/{id}/programs/{assignment}/status/history/", [
            \Application\Http\Controller\SisStudentProgramStatusController::class,
            "history",
        ])
        ->name("sis.student.program.status.history");
    $router
        ->map(["GET"], "/sis/courses/{id}/clone/review/", [
            SisCatalogWorkflowController::class,
            "cloneReview",
        ])
        ->name("sis.catalog.clone.review");
    $router
        ->map(["POST"], "/sis/courses/{id}/clone/commit/", [
            SisCatalogWorkflowController::class,
            "cloneCommit",
        ])
        ->name("sis.catalog.clone.commit");
    $router
        ->map(["GET"], "/sis/catalog/clones/{operation}/", [
            SisCatalogWorkflowController::class,
            "cloneSaved",
        ])
        ->name("sis.catalog.clone.saved");
    $router
        ->map(["POST"], "/sis/catalog/offerings/review/", [
            SisCatalogWorkflowController::class,
            "offeringReview",
        ])
        ->name("sis.catalog.offering.review");
    $router
        ->map(["POST"], "/sis/catalog/offerings/save/", [SisCatalogWorkflowController::class, "offeringSave"])
        ->name("sis.catalog.offering.save");
    $router
        ->map(["POST"], "/sis/catalog/offerings/", [SisCatalogWorkflowController::class, "offeringPage"])
        ->name("sis.catalog.offering.page");
    $router
        ->map(["GET"], "/sis/catalog/offerings/{operation}/", [
            SisCatalogWorkflowController::class,
            "offeringSaved",
        ])
        ->name("sis.catalog.offering.saved");
    $router
        ->map(["POST"], "/sis/catalog/offerings/{operation}/publication-setup/", [
            SisCatalogWorkflowController::class,
            "publicationSetup",
        ])
        ->name("sis.catalog.offerings.publication-setup");
    $router
        ->map(["POST"], "/sis/catalog/offerings/{operation}/publication-review/", [
            SisCatalogWorkflowController::class,
            "publicationReview",
        ])
        ->name("sis.catalog.offering.publication.review");
    $router
        ->map(["POST"], "/sis/catalog/offerings/{operation}/publish/", [
            SisCatalogWorkflowController::class,
            "publish",
        ])
        ->name("sis.catalog.offering.publish");
    $router
        ->map(["GET"], "/sis/transcript-requests/{id}/fulfillment/", [
            SisTranscriptFulfillmentController::class,
            "current",
        ])
        ->name("sis.transcript.fulfillment.current");
    $router
        ->map(["POST"], "/sis/transcript-requests/{id}/review/", [
            SisTranscriptFulfillmentController::class,
            "review",
        ])
        ->name("sis.transcript.fulfillment.review");
    $router->get('/sis/transcript-policy/check-id/', [SisTranscriptReleasePolicyController::class, 'checkId']);
    $router->get('/sis/transcript-policy/standings/', [SisTranscriptReleasePolicyController::class, 'standings']);
    $router->get('/sis/transcript-policy/', [SisTranscriptReleasePolicyController::class, 'current']);
    $router->post('/sis/transcript-policy/', [SisTranscriptReleasePolicyController::class, 'save']);
    $router->post('/sis/transcript-requests/{id}/preview/', [SisTranscriptFulfillmentController::class, 'preview']);
    $router
        ->map(["POST"], "/sis/transcript-requests/{id}/prepare/", [
            SisTranscriptFulfillmentController::class,
            "prepare",
        ])
        ->name("sis.transcript.fulfillment.prepare");
    $router
        ->map(["GET"], "/sis/transcript-requests/{id}/documents/{document}/", [
            SisTranscriptFulfillmentController::class,
            "show",
        ])
        ->name("sis.transcript.fulfillment.show");
    $router
        ->map(["GET"], "/sis/transcript-requests/{id}/documents/{document}/view/", [
            SisTranscriptFulfillmentController::class,
            "html",
        ])
        ->name("sis.transcript.fulfillment.html");
    $router
        ->map(["POST"], "/sis/transcript-requests/{id}/documents/{document}/pdf/", [
            SisTranscriptFulfillmentController::class,
            "pdf",
        ])
        ->name("sis.transcript.fulfillment.pdf");
    $router
        ->map(["POST"], "/sis/transcript-requests/{id}/documents/{document}/delivery-review/", [
            SisTranscriptFulfillmentController::class,
            "reviewDelivery",
        ])
        ->name("sis.transcript.fulfillment.delivery-review");
    $router
        ->map(["POST"], "/sis/transcript-requests/{id}/documents/{document}/deliver/", [
            SisTranscriptFulfillmentController::class,
            "deliver",
        ])
        ->name("sis.transcript.fulfillment.deliver");
    $router->map(["POST"], "/sis/registration-schedule/review/", [
        \Application\Http\Controller\SisRegistrationController::class, "reviewSchedule",
    ])->name("sis.registration.schedule.review");
    $router->map(["GET"], "/sis/registration-policy/", [
        \Application\Http\Controller\SisRegistrationPolicyController::class, "current",
    ])->name("sis.registration.policy.current");
    $router->map(["POST"], "/sis/registration-policy/", [
        \Application\Http\Controller\SisRegistrationPolicyController::class, "save",
    ])->name("sis.registration.policy.save");
    $router
        ->map(["POST"], "/sis/courses/", [SisRegistrationController::class, "course"])
        ->name("sis.registration.course");
    $exceptions = \Application\Http\Controller\SisPrerequisitePermissionController::class;
    $router->map(
        ['POST'],
        '/sis/students/{student}/programs/{pursuit}/prerequisite-exceptions/sections/',
        [$exceptions, 'sections'],
    )
        ->name('sis.prerequisite.exceptions.sections');
    foreach (['view', 'review', 'commit', 'history'] as $method) {
        $router->map(
            ['POST'],
            '/sis/students/{student}/programs/{pursuit}/prerequisite-exceptions/{section}/' . $method . '/',
            [$exceptions, $method],
        )
            ->name('sis.prerequisite.exceptions.' . $method);
    }
    foreach (['view', 'review', 'confirm', 'history'] as $method) {
        $router->map(
            ['POST'],
            '/sis/students/{student}/sections/{section}/enrollments/{enrollment}/registered-credits/' .
                $method . '/',
            [\Application\Http\Controller\SisRegisteredCreditController::class, $method],
        )->name('sis.registered.credits.' . $method);
    }
    foreach (['view', 'review', 'confirm', 'history'] as $method) {
        $router->post(
            '/sis/students/{student}/sections/{section}/enrollments/{enrollment}/earned-ceus/' . $method . '/',
            [\Application\Http\Controller\SisEarnedCeuController::class, $method],
        );
    }
    $router->post(
        '/sis/students/{student}/earned-ceus/records/',
        [\Application\Http\Controller\SisEarnedCeuController::class, 'records']
    );
    $load = \Application\Http\Controller\SisEnrollmentLoadController::class;
    foreach (
        [
        'record-review' => 'recordReview',
        'record-confirm' => 'recordConfirm',
        'determinations' => 'staffRecords',
        'policy-choices' => 'policyChoices',
        ] as $suffix => $method
    ) {
        $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/load/' . $suffix . '/', [$load, $method]);
    }
    foreach (['view', 'review', 'publish', 'history', 'programs'] as $method) {
        $router->map(['POST'], '/sis/enrollment-load/policy/' . $method . '/', [$load, $method])
            ->name('sis.load.policy.' . $method);
    }
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/load/terms/', [
        $load, 'staffTerms',
    ])->name('staff.load.terms');
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/load/review/', [
        $load, 'staffLoad',
    ])->name('staff.load.review');
    $standingPolicy = \Application\Http\Controller\StandingProposalPolicyController::class;
    foreach (
        ['view' => '', 'review' => 'review/', 'publish' => 'publish/', 'targets' => 'targets/'] as $method => $suffix
    ) {
        $router->post('/sis/academic-standing/proposal-policy/' . $suffix, [$standingPolicy, $method])
            ->name('sis.standing.proposal-policy.' . $method);
    }
    $standing = \Application\Http\Controller\SisStudentStandingController::class;
    foreach (['definitions', 'choices', 'save'] as $method) {
        $router->map(['POST'], '/sis/academic-standing/' . $method . '/', [$standing, $method])
            ->name('sis.standing.' . $method);
    }
    foreach (
        ['view' => ['', 'GET'], 'review' => ['review/', 'POST'], 'commit' => ['commit/', 'POST'],
        'propose' => ['propose/', 'POST'], 'proposalTerms' => ['proposal-terms/', 'POST'],
        'timeline' => ['history/', 'POST']] as $method => [$suffix, $verb]
    ) {
        $router->map([$verb], '/sis/students/{student}/programs/{pursuit}/standing/' . $suffix, [
            $standing, $method,
        ])
            ->name('sis.standing.' . $method);
    }
    $availability = \Application\Http\Controller\AdvisingAvailabilityController::class;
    $router->map(['POST'], '/sis/advising-booking-policy/{action}/', [
        \Application\Http\Controller\BookingPolicyController::class, 'handle',
    ])->name('advising.booking_policy');
    $router->map(['POST'], '/sis/advising-availability/candidates/', [$availability, 'candidates'])
        ->name('advising.availability.candidates');
    $router->map(['POST'], '/sis/advising-availability/{host}/{action}/', [$availability, 'staff'])
        ->name('advising.availability.staff');
    $appointments = \Application\Http\Controller\AdvisingAppointmentController::class;
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/appointments/calendar/', [
        $appointments, 'staffCalendar',
    ])->name('staff.appointments.calendar');
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/appointments/list/', [
        $appointments, 'staffList',
    ])->name('staff.appointments.list');
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/appointments/schedule/', [
        $appointments, 'staffSchedule',
    ])->name('staff.appointments.schedule');
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/appointments/act/', [
        $appointments, 'staffAct',
    ])->name('staff.appointments.act');
    $checkins = \Application\Http\Controller\AdvisingCheckInController::class;
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/checkins/list/', [$checkins, 'staffList'])
        ->name('staff.checkins.list');
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/checkins/record/', [$checkins, 'staffRecord'])
        ->name('staff.checkins.record');
    $router->map(['POST'], '/sis/students/{student}/programs/{pursuit}/checkins/act/', [$checkins, 'staffAct'])
        ->name('staff.checkins.act');
    $changes = \Application\Http\Controller\AdmissionChangeRequestController::class;
    $router->get('/sis/applications/{id}/change-requests/', [$changes, 'staff']);
    $router->post('/sis/applications/{id}/change-requests/close/', [$changes, 'close']);
    foreach (['run', 'history'] as $method) {
        $router->map(['POST'], '/sis/history-checks/{kind}/{id}/' . $method . '/', [
            \Application\Http\Controller\HistoryCheckController::class, $method,
        ])->name('sis.history-checks.' . $method);
    }
    $advising = \Application\Http\Controller\SisAdvisingController::class;
    $router->map(['POST'], '/sis/advisors/candidates/', [$advising, 'candidates'])->name('sis.advisors.candidates');
    foreach (
        ['view' => ['', 'GET'], 'review' => ['review/', 'POST'], 'commit' => ['commit/', 'POST'],
        'timeline' => ['history/', 'POST']] as $method => [$suffix, $verb]
    ) {
        $router->map(
            [$verb],
            '/sis/students/{student}/programs/{pursuit}/advisors/' . $suffix,
            [$advising, $method]
        )->name('sis.advisors.' . $method);
    }
    $router->map(['POST'], '/sis/sections/enrollment-review/', [
        \Application\Http\Controller\SisSectionEnrollmentReviewController::class, 'page',
    ])->name('sis.sections.enrollment-review');
    $router
        ->map(["POST"], "/sis/sections/", [SisRegistrationController::class, "section"])
        ->name("sis.registration.section");
    foreach (
        ['show' => ['', 'GET'], 'review' => ['review/', 'POST'],
        'publish' => ['publish/', 'POST'], 'page' => ['page/', 'POST']] as $method => [$suffix, $verb]
    ) {
        $router->map([$verb], '/sis/terms/{id}/registration-window/' . $suffix, [
            \Application\Http\Controller\SisRegistrationWindowController::class, $method,
        ])->name('sis.registration.window.' . $method);
    }
    $router
        ->map(["POST"], "/sis/courses/{id}/prerequisites/", [
            SisRegistrationController::class,
            "prerequisite",
        ])
        ->name("sis.registration.prerequisite");
    $router
        ->map(["GET"], "/sis/restrictions/{id}/", [SisRestrictionController::class, "show"])
        ->name("sis.restriction.show");
    $router
        ->map(["POST"], "/sis/restrictions/{id}/history/", [SisRestrictionController::class, "history"])
        ->name("sis.restriction.history");
    $router
        ->map(["POST"], "/sis/persons/{id}/restrictions/", [SisRestrictionController::class, "page"])
        ->name("sis.restriction.page");
    $router
        ->map(["POST"], "/sis/restrictions/{id}/notes/", [SisRestrictionController::class, "notes"])
        ->name("sis.restriction.notes");
    $router
        ->map(["POST"], "/sis/restrictions/{id}/notes/append/", [
            SisRestrictionController::class,
            "appendNote",
        ])
        ->name("sis.restriction.note.append");
    $router
        ->map(["POST"], "/sis/restrictions/", [SisRestrictionController::class, "create"])
        ->name("sis.registration.restriction");
    $router
        ->map(["POST"], "/sis/registrations/", [SisRegistrationController::class, "register"])
        ->name("sis.registration.register");
    $reinstatements = \Application\Http\Controller\SisEnrollmentReinstatementController::class;
    $router->post('/sis/students/{student}/sections/{section}/enrollments/{enrollment}/reinstatement/review/', [
        $reinstatements, 'review',
    ]);
    $router->post('/sis/students/{student}/sections/{section}/enrollments/{enrollment}/reinstatement/confirm/', [
        $reinstatements, 'confirm',
    ]);
    $waitlists = \Application\Http\Controller\SisWaitlistController::class;
    $bypasses = \Application\Http\Controller\SisWaitlistBypassController::class;
    $router->get('/sis/sections/{id}/waitlist/bypass/history/', [$bypasses, 'history']);
    $router->post('/sis/sections/{id}/waitlist/bypass/review/', [$bypasses, 'review']);
    $router->post('/sis/sections/{id}/waitlist/bypass/confirm/', [$bypasses, 'confirm']);
    $router->get('/sis/sections/{id}/waitlist/policy/', [$waitlists, 'policy'])->name('sis.waitlist.policy');
    $router->post('/sis/sections/{id}/waitlist/policy/review/', [$waitlists, 'reviewPolicy']);
    $router->post('/sis/sections/{id}/waitlist/policy/confirm/', [$waitlists, 'confirmPolicy']);
    $router->get('/sis/sections/{id}/waitlist/', [$waitlists, 'listing'])->name('sis.waitlist.queue');
    $router->post('/sis/sections/{id}/waitlist/context/', [$waitlists, 'staffView']);
    $router->post('/sis/sections/{id}/waitlist/review/', [$waitlists, 'staffReview']);
    $router->post('/sis/sections/{id}/waitlist/confirm/', [$waitlists, 'staffConfirm']);
    $router->post('/sis/registrations/review/', [SisRegistrationController::class, 'reviewRegistration'])
        ->name('sis.registration.review');
    $router
        ->map(["POST"], "/sis/registrations/drop/", [SisRegistrationController::class, "drop"])
        ->name("sis.registration.drop");

    $router
        ->map(["POST"], "/sis/sections/{id}/assignments/", [SisGradebookController::class, "assignment"])
        ->name("sis.gradebook.assignment");
    $gradeChanges = \Application\Http\Controller\SisGradeChangeController::class;
    $router->post('/sis/grade-changes/', [$gradeChanges, 'page'])->name('sis.grade-changes.page');
    $router->get('/sis/grade-changes/{id}/', [$gradeChanges, 'show'])->name('sis.grade-changes.show');
    $router->post('/sis/grade-changes/{id}/review/', [$gradeChanges, 'review'])->name('sis.grade-changes.review');
    $router->post('/sis/grade-changes/{id}/commit/', [$gradeChanges, 'commit'])->name('sis.grade-changes.commit');
    $router->get('/sis/grade-change-policy/', [$gradeChanges, 'policy'])->name('sis.grade-changes.policy');
    $router->post('/sis/grade-change-policy/', [$gradeChanges, 'savePolicy'])->name('sis.grade-changes.policy.save');
    $router->get(
        '/sis/sections/{id}/grade-certification/',
        [\Application\Http\Controller\SisGradeCertificationController::class, 'readiness'],
    )->name('sis.gradebook.certification');
    $router->post(
        '/sis/sections/{id}/grade-certification/review/',
        [\Application\Http\Controller\SisGradeCertificationController::class, 'review'],
    )->name('sis.gradebook.certification.review');
    $router->post(
        '/sis/sections/{id}/grade-certification/confirm/',
        [\Application\Http\Controller\SisGradeCertificationController::class, 'confirm'],
    )->name('sis.gradebook.certification.confirm');
    $router->get(
        '/sis/sections/{id}/grade-certification/history/',
        [\Application\Http\Controller\SisGradeCertificationController::class, 'listing'],
    )->name('sis.gradebook.certification.history');
    $router->get(
        '/sis/sections/{id}/grade-certification/history/{operation}/',
        [\Application\Http\Controller\SisGradeCertificationController::class, 'detail'],
    )->name('sis.gradebook.certification.detail');
    $router->get('/sis/sections/{id}/gradebook/calculation/', [SisGradebookController::class, 'calculationSetup'])
        ->name('sis.gradebook.calculation');
    $router->post('/sis/sections/{id}/gradebook/calculation/', [SisGradebookController::class, 'saveCalculation'])
        ->name('sis.gradebook.calculation.save');
    $router->get('/sis/sections/{id}/students/{student}/gradebook-summary/', [SisGradebookController::class, 'summary'])
        ->name('sis.gradebook.summary');
    $router
        ->map(["POST"], "/sis/assignments/{id}/scores/", [SisGradebookController::class, "score"])
        ->name("sis.gradebook.score");
    $router->post('/sis/assignments/{id}/score-roster/', [SisGradebookController::class, 'scoreRoster'])
        ->name('sis.gradebook.score-roster');
    $router->post('/sis/sections/{id}/attendance-roster/', [SisGradebookController::class, 'attendanceRoster'])
        ->name('sis.gradebook.attendance-roster');
    $router
        ->map(["POST"], "/sis/sections/{id}/final-grades/review/", [
            SisGradebookController::class,
            "reviewFinalGrade",
        ])
        ->name("sis.gradebook.finalgrade.review");
    $router
        ->map(["POST"], "/sis/sections/{id}/final-grades/commit/", [
            SisGradebookController::class,
            "commitFinalGrade",
        ])
        ->name("sis.gradebook.finalgrade.commit");
    $router
        ->map(["POST"], "/sis/sections/{id}/students/{student}/grade-history/", [
            SisGradebookController::class,
            "gradeHistory",
        ])
        ->name("sis.gradebook.history");
    $router
        ->map(["POST"], "/sis/sections/{id}/attendance/", [SisGradebookController::class, "attendance"])
        ->name("sis.gradebook.attendance");
    $router
        ->map(["GET"], "/sis/sections/{id}/students/{student}/scores/", [
            SisGradebookController::class,
            "scores",
        ])
        ->name("sis.gradebook.scores");
    $router
        ->map(["GET"], "/sis/assignments/{id}/", [SisGradebookController::class, "showAssignment"])
        ->name("sis.assignment.show");
    $router
        ->map(["POST"], "/sis/assignments/{id}/edit/", [SisGradebookController::class, "editAssignment"])
        ->name("sis.assignment.edit");
    $router
        ->map(["POST"], "/sis/sections/{id}/students/{student}/score-page/", [
            SisGradebookController::class,
            "scorePage",
        ])
        ->name("sis.gradebook.score-page");
    $router
        ->map(["POST"], "/sis/sections/{id}/students/{student}/attendance/", [
            SisGradebookController::class,
            "attendanceHistory",
        ])
        ->name("sis.gradebook.attendance-history");
    $campusEvents = \Application\Http\Controller\CampusEventController::class;
    $router->post('/sis/bookings/{id}/public-event/setup/', [$campusEvents, 'setup']);
    $router->post('/sis/bookings/{id}/public-event/announcement/', [$campusEvents, 'announcement']);
    $router->post('/sis/bookings/{id}/public-event/review/', [$campusEvents, 'review']);
    $router->post('/sis/bookings/{id}/public-event/publish/', [$campusEvents, 'publish']);
    $router->post('/sis/bookings/{id}/public-event/withdraw/', [$campusEvents, 'withdraw']);
    $auditing = \Application\Http\Controller\CourseAuditingPolicyController::class;
    $router->post('/sis/sections/{id}/auditing/current/', [$auditing, 'current']);
    $router->post('/sis/sections/{id}/auditing/save/', [$auditing, 'save']);
    $programEligibility = \Application\Http\Controller\SectionProgramPolicyController::class;
    $router->post('/sis/sections/{id}/eligible-programs/current/', [$programEligibility, 'current']);
    $router->post('/sis/sections/{id}/eligible-programs/choices/', [$programEligibility, 'choices']);
    $router->post('/sis/sections/{id}/eligible-programs/save/', [$programEligibility, 'save']);
    $sectionPublications = \Application\Http\Controller\SisSectionPublicationController::class;
    $meetingPlans = \Application\Http\Controller\SisSectionMeetingPlanController::class;
    $router->post('/sis/sections/{id}/meeting-plan/setup/', [$meetingPlans, 'setup']);
    $router->post('/sis/sections/{id}/meeting-plan/review/', [$meetingPlans, 'review']);
    $router->post('/sis/sections/{id}/meeting-plan/publish/', [$meetingPlans, 'publish']);
    $router->post('/sis/sections/{id}/meeting-plan/versions/', [$meetingPlans, 'versions']);
    $router->post("/sis/sections/{id}/publication/selection-review/", [
        $sectionPublications,
        "selectionReview",
    ]);
    $router->post("/sis/sections/{id}/publication/select/", [$sectionPublications, "select"]);
    $router->post("/sis/sections/{id}/publication/amendment-review/", [
        $sectionPublications,
        "amendmentReview",
    ]);
    $router->post("/sis/sections/{id}/publication/amend/", [$sectionPublications, "amend"]);
    $router->post("/sis/sections/{id}/publication/selection-page/", [
        $sectionPublications,
        "selectionHistory",
    ]);
    $router->post("/sis/sections/{id}/publication/setup/", [$sectionPublications, "setup"]);
    $router->post("/sis/sections/{id}/publication/review/", [$sectionPublications, "review"]);
    $router->post("/sis/sections/{id}/publication/commit/", [$sectionPublications, "publish"]);
    $router->post("/sis/sections/{id}/publication/page/", [$sectionPublications, "page"]);
    $router->post("/sis/sections/{id}/course-definition/page/", [
        SisSectionCourseBindingController::class,
        "show",
    ]);
    $router->post("/sis/sections/{id}/course-definition/review/", [
        SisSectionCourseBindingController::class,
        "review",
    ]);
    $router->post("/sis/sections/{id}/course-definition/commit/", [
        SisSectionCourseBindingController::class,
        "commit",
    ]);
    $subjectController = \Application\Http\Controller\SisSubjectController::class;
    $programPublications = \Application\Http\Controller\SisProgramPublicationController::class;
    $studentSpecializations = \Application\Http\Controller\SisStudentSpecializationController::class;
    $studentSpecializationPath = '/sis/students/{student}/programs/{assignment}/specializations/';
    $router->get($studentSpecializationPath, [$studentSpecializations, 'view']);
    $router->post($studentSpecializationPath . 'review/', [$studentSpecializations, 'review']);
    $router->post($studentSpecializationPath . 'commit/', [$studentSpecializations, 'commit']);
    $router->post($studentSpecializationPath . 'history/', [$studentSpecializations, 'history']);
    $specializationRequirements = $studentSpecializationPath . '{specialization}/requirements/';
    $router->get($specializationRequirements, [$studentSpecializations, 'requirements']);
    $router->post($specializationRequirements . 'progress/', [$studentSpecializations, 'progress']);
    $router->post($specializationRequirements . 'choices/', [$studentSpecializations, 'requirementChoices']);
    $router->post($specializationRequirements . 'review/', [$studentSpecializations, 'requirementReview']);
    $router->post($specializationRequirements . 'commit/', [$studentSpecializations, 'requirementCommit']);
    $specializations = \Application\Http\Controller\SisProgramSpecializationController::class;
    $router->post("/sis/programs/{id}/specializations/page/", [$specializations, "page"]);
    $router->post("/sis/programs/{id}/specializations/save/", [$specializations, "save"]);
    $specializationAudits = '/sis/programs/{program}/specializations/{specialization}/degree-audits/';
    $router->post($specializationAudits . 'page/', [SisDegreeAuditController::class, 'specializationPage']);
    $router->post($specializationAudits . 'open/', [SisDegreeAuditController::class, 'specializationOpen']);
    $router->get("/sis/programs/{id}/specializations/policy/", [$specializations, "policy"]);
    $router->post("/sis/programs/{id}/specializations/policy/", [$specializations, "savePolicy"]);
    $router->post("/sis/catalog/specialization-policy/", [$specializations, "saveInstitutionPolicy"]);
    $router->get("/sis/programs/{id}/specializations/{specialization}/", [$specializations, "show"]);
    $programAssistance = \Application\Http\Controller\SisProgramAssistanceController::class;
    $router->get("/sis/programs/{id}/assistance/", [$programAssistance, "preview"]);
    $router->post("/sis/programs/{id}/assistance/", [$programAssistance, "suggest"]);
    $router->get("/sis/programs/{id}/details/", [$programPublications, "draft"]);
    $router->post("/sis/programs/{id}/details/", [$programPublications, "save"]);
    $router->post("/sis/programs/{id}/publication/review/", [$programPublications, "review"]);
    $router->post("/sis/programs/{id}/publication/commit/", [$programPublications, "publish"]);
    $router->post("/sis/programs/{id}/publication/page/", [$programPublications, "page"]);
    $discovery = \Application\Http\Controller\ProgramDiscoveryController::class;
    $router->get('/sis/programs/{id}/website-catalog/', [$discovery, 'current']);
    $router->get('/sis/programs/{id}/website-catalog/choices/', [$discovery, 'choices']);
    $router->post('/sis/programs/{id}/website-catalog/review/', [$discovery, 'review']);
    $router->post('/sis/programs/{id}/website-catalog/commit/', [$discovery, 'commit']);
    $classificationController = \Application\Http\Controller\SisClassificationController::class;
    $router->get('/sis/catalog/reporting-groups/{id}/hierarchy/', [$classificationController, 'reportingHierarchy']);
    $router->post(
        '/sis/catalog/reporting-groups/{id}/hierarchy/',
        [$classificationController, 'saveReportingHierarchy'],
    );
    $router->get('/sis/terms/{id}/reporting-default/', [$classificationController, 'termDefault']);
    $router->post('/sis/terms/{id}/reporting-default/', [$classificationController, 'saveTermDefault']);
    $router->post("/sis/catalog/classifications/{kind}/page/", [$classificationController, "page"]);
    $router->post("/sis/catalog/classifications/{kind}/save/", [$classificationController, "save"]);
    $router->get("/sis/sections/{id}/classifications/", [$classificationController, "section"]);
    $router->post("/sis/sections/{id}/classifications/", [$classificationController, "assignSection"]);
    $router->get("/sis/courses/{id}/classifications/", [$classificationController, "course"]);
    $router->post("/sis/courses/{id}/classifications/", [$classificationController, "assign"]);
    $router->post("/sis/catalog/subjects/", [$subjectController, "page"]);
    $router->post("/sis/catalog/subjects/save/", [$subjectController, "save"]);
    $router->get("/sis/courses/{id}/subject/", [$subjectController, "showCourse"]);
    $router->post("/sis/courses/{id}/subject/", [$subjectController, "assign"]);
    $router->get("/sis/sections/{id}/assistance/", [SisSectionAssistanceController::class, "preview"]);
    $router->post("/sis/sections/{id}/assistance/", [SisSectionAssistanceController::class, "suggest"]);
    $router->get("/sis/moodle/status/", [SisMoodleController::class, "status"]);
    $router->get('/sis/moodle/category-settings/', [SisMoodleCategoryController::class, 'settings']);
    $router->post('/sis/moodle/category-settings/', [SisMoodleCategoryController::class, 'configure']);
    $router->get('/sis/moodle/categories/', [SisMoodleCategoryController::class, 'page']);
    $router->post('/sis/moodle/categories/{reference}/link/', [SisMoodleCategoryController::class, 'link']);
    $router->get('/sis/moodle/sections/', [SisMoodleSectionController::class, 'directory']);
    $router->post('/sis/moodle/sections/{section}/link/', [SisMoodleSectionController::class, 'link']);
    $router->post("/sis/moodle/check/", [SisMoodleController::class, "check"]);
    $router->get("/sis/moodle/installation/", [SisMoodleBindingController::class, "status"]);
    $ssn = \Application\Http\Controller\PersonSsnController::class;
    $router->get('/sis/people/{person}/ssn/', [$ssn, 'view']);
    foreach (['review', 'confirm', 'history'] as $method) {
        $router->post('/sis/people/{person}/ssn/' . $method . '/', [$ssn, $method]);
    }
    $router->get('/sis/people/{person}/moodle/', [SisMoodlePersonLinkController::class, 'status']);
    $router->get('/sis/people/{person}/moodle/account-request/', [SisMoodleAccountRequestController::class, 'status']);
    $router->post(
        '/sis/people/{person}/moodle/account-request/review/',
        [SisMoodleAccountRequestController::class, 'review'],
    );
    $router->post(
        '/sis/people/{person}/moodle/account-request/confirm/',
        [SisMoodleAccountRequestController::class, 'confirm'],
    );
    $router->post(
        '/sis/people/{person}/moodle/account-request/resolution/review/',
        [SisMoodleAccountRequestController::class, 'reviewResolution'],
    );
    $router->post(
        '/sis/people/{person}/moodle/account-request/resolution/confirm/',
        [SisMoodleAccountRequestController::class, 'confirmResolution'],
    );
    $router->post(
        '/sis/people/{person}/moodle/account-request/receipt/',
        [SisMoodleAccountRequestController::class, 'inspectReceipt'],
    );
    $router->post(
        '/sis/people/{person}/moodle/account-request/recovery/review/',
        [SisMoodleAccountRequestController::class, 'reviewRecovery'],
    );
    $router->post(
        '/sis/people/{person}/moodle/account-request/recovery/confirm/',
        [SisMoodleAccountRequestController::class, 'confirmRecovery'],
    );
    $router->post('/sis/people/{person}/moodle/review/', [SisMoodlePersonLinkController::class, 'review']);
    $router->post('/sis/people/{person}/moodle/confirm/', [SisMoodlePersonLinkController::class, 'confirm']);
    $router->post("/sis/moodle/installation/review/", [SisMoodleBindingController::class, "review"]);
    $router->post("/sis/moodle/installation/confirm/", [SisMoodleBindingController::class, "confirm"]);
    $router->post("/sis/moodle/installation/verify/", [SisMoodleBindingController::class, "verify"]);
    $router->get("/sis/courses/{id}/assistance/", [SisCourseAssistanceController::class, "preview"]);
    $router->post("/sis/courses/{id}/assistance/", [SisCourseAssistanceController::class, "suggest"]);
    $router->get("/sis/courses/{id}/text/", [SisCourseTextController::class, "show"]);
    $router->post("/sis/courses/{id}/text/", [SisCourseTextController::class, "save"]);
    $router->post("/sis/courses/{id}/publication/review/", [SisCoursePublicationController::class, "review"]);
    $router->post("/sis/courses/{id}/publication/commit/", [
        SisCoursePublicationController::class,
        "publish",
    ]);
    $router->post("/sis/courses/{id}/publication/page/", [SisCoursePublicationController::class, "page"]);
    $router->map(
        ['GET'],
        '/sis/calendar/context/',
        [\Application\Http\Controller\SisBookingCalendarController::class, 'context']
    )->name('sis.calendar.context');
    $router->map(
        ['POST'],
        '/sis/booking-categories/',
        [\Application\Http\Controller\SisBookingCategoryController::class, 'create']
    );
    $router->map(
        ['GET'],
        '/sis/booking-categories/{id}/',
        [\Application\Http\Controller\SisBookingCategoryController::class, 'show']
    );
    $router->map(
        ['POST'],
        '/sis/booking-categories/{id}/edit/',
        [\Application\Http\Controller\SisBookingCategoryController::class, 'edit']
    );
    $router->map(['GET'], '/sis/booking-sections/{id}/', [SisBookingController::class, 'sectionContext']);
    $router->map(['POST'], '/sis/bookings/{id}/category/', [SisBookingController::class, 'categorize']);
    $router->map(
        ['POST'],
        '/sis/calendar/view/',
        [\Application\Http\Controller\SisBookingCalendarController::class, 'timetable']
    )->name('sis.calendar.view');
    $router->map(['POST'], '/sis/rooms/', [SisRoomController::class, 'create'])->name('sis.room.create');
    $router->map(
        ['POST'],
        '/sis/buildings/',
        [SisRoomController::class, 'createBuilding']
    )->name('sis.building.create');
    $router->map(['GET'], '/sis/buildings/{id}/', [SisRoomController::class, 'building'])->name('sis.building.show');
    $router->map(
        ['POST'],
        '/sis/buildings/{id}/edit/',
        [SisRoomController::class, 'editBuilding']
    )->name('sis.building.edit');
    $router->map(['GET'], '/sis/bookings/planning/', [SisBookingController::class, 'planning'])
        ->name('sis.booking.planning');
    $router->map(['POST'], '/sis/bookings/planning/review/', [SisBookingController::class, 'review'])
        ->name('sis.booking.planning.review');
    $router->map(['POST'], '/sis/bookings/planning/request/', [SisBookingController::class, 'requestSeries'])
        ->name('sis.booking.planning.request');
    $router->map(["GET"], "/sis/rooms/{id}/", [SisRoomController::class, "show"])->name("sis.room.show");
    $router
        ->map(["POST"], "/sis/rooms/{id}/edit/", [SisRoomController::class, "edit"])
        ->name("sis.room.edit");
    $router
        ->map(["POST"], "/sis/rooms/{id}/availability/", [SisRoomController::class, "availability"])
        ->name("sis.room.availability");
    $router
        ->map(["POST"], "/sis/rooms/{id}/agenda/", [SisRoomController::class, "agenda"])
        ->name("sis.room.agenda");
    $router->map(['POST'], '/sis/rooms/{id}/timetable/', [SisRoomController::class, 'timetable'])
        ->name('sis.room.timetable');
    $router
        ->map(["POST"], "/sis/bookings/", [SisBookingController::class, "request"])
        ->name("sis.booking.request");
    $router
        ->map(["POST"], "/sis/bookings/{id}/approve/", [SisBookingController::class, "approve"])
        ->name("sis.booking.approve");
    $router->map(
        ['POST'],
        '/sis/bookings/{id}/occurrences/{occurrence}/cancel/',
        [SisBookingController::class, 'cancelOccurrence']
    )->name('sis.booking.occurrence.cancel');
    $router->map(
        ['POST'],
        '/sis/bookings/{id}/occurrences/{occurrence}/reschedule/review/',
        [SisBookingController::class, 'reviewReplacement']
    )->name('sis.booking.occurrence.reschedule.review');
    $router->map(
        ['POST'],
        '/sis/bookings/{id}/occurrences/{occurrence}/reschedule/confirm/',
        [SisBookingController::class, 'replaceOccurrence']
    )->name('sis.booking.occurrence.reschedule.confirm');
    $router
        ->map(["POST"], "/sis/bookings/{id}/cancel/", [SisBookingController::class, "cancel"])
        ->name("sis.booking.cancel");
    $router
        ->map(["GET"], "/sis/bookings/{id}/", [SisBookingController::class, "show"])
        ->name("sis.booking.show");
    $router->get('/sis/sections/{id}/contact-hours/', [
        \Application\Http\Controller\SisSectionContactController::class, 'view',
    ])
        ->name('sis.section.contact-hours');
    $router->post('/sis/sections/{id}/contact-hours/', [
        \Application\Http\Controller\SisSectionContactController::class, 'save',
    ])
        ->name('sis.section.contact-hours.save');
    $graduatePolicy = \Application\Http\Controller\SisGraduatePolicyController::class;
    $router->get('/sis/graduate-registration-policy/', [$graduatePolicy, 'view']);
    $router->post('/sis/graduate-registration-policy/levels/', [$graduatePolicy, 'levels']);
    $router->post('/sis/graduate-registration-policy/review/', [$graduatePolicy, 'review']);
    $router->post('/sis/graduate-registration-policy/publish/', [$graduatePolicy, 'publish']);
    $graduateApproval = \Application\Http\Controller\SisGraduateApprovalController::class;
    $router->post('/sis/students/{student}/graduate-courses/{section}/current/', [$graduateApproval, 'view']);
    $router->post('/sis/students/{student}/graduate-courses/{section}/review/', [$graduateApproval, 'review']);
    $router->post('/sis/students/{student}/graduate-courses/{section}/confirm/', [$graduateApproval, 'confirm']);
    $router->get('/sis/grade-comparison-policy/', [
        \Application\Http\Controller\SisGradeComparisonPolicyController::class, 'view',
    ])
        ->name('sis.grade-comparison-policy');
    $router->post('/sis/grade-comparison-policy/review/', [
        \Application\Http\Controller\SisGradeComparisonPolicyController::class, 'review',
    ])
        ->name('sis.grade-comparison-policy.review');
    $router->post('/sis/grade-comparison-policy/publish/', [
        \Application\Http\Controller\SisGradeComparisonPolicyController::class, 'publish',
    ])
        ->name('sis.grade-comparison-policy.publish');
    $router->get('/sis/time-policy/', [\Application\Http\Controller\SisTimePolicyController::class, 'view'])
        ->name('sis.time-policy');
    $router->post('/sis/time-policy/review/', [\Application\Http\Controller\SisTimePolicyController::class, 'review'])
        ->name('sis.time-policy.review');
    $router->post('/sis/time-policy/publish/', [\Application\Http\Controller\SisTimePolicyController::class, 'publish'])
        ->name('sis.time-policy.publish');
    $router
        ->map(["POST"], "/sis/employees/", [SisTimesheetController::class, "employee"])
        ->name("sis.timesheet.employee");
    $positions = \Application\Http\Controller\SisPositionController::class;
    $dates = \Application\Http\Controller\SisEmploymentDatesController::class;
    $primary = \Application\Http\Controller\SisPrimaryPositionController::class;
    $router->get('/sis/employees/{employee}/primary-position/', [$primary, 'get'])
        ->name('sis.employment.primary');
    $router->post('/sis/employees/{employee}/primary-position/review/', [$primary, 'review'])
        ->name('sis.employment.primary.review');
    $router->post('/sis/employees/{employee}/primary-position/confirm/', [$primary, 'confirm'])
        ->name('sis.employment.primary.confirm');
    $router->post('/sis/employees/{employee}/primary-position/history/', [$primary, 'history'])
        ->name('sis.employment.primary.history');
    $router->map(['POST'], '/sis/employees/{id}/work-positions/', [SisTimesheetController::class, 'workPositions'])
        ->name('sis.timesheet.work-positions');
    $router->map(['GET'], '/sis/employees/{employee}/employment-dates/', [$dates, 'get'])
        ->name('sis.employment.dates');
    $router->map(['POST'], '/sis/employees/{employee}/employment-dates/review/', [$dates, 'review'])
        ->name('sis.employment.dates.review');
    $router->map(['POST'], '/sis/employees/{employee}/employment-dates/confirm/', [$dates, 'confirm'])
        ->name('sis.employment.dates.confirm');
    $router->map(['POST'], '/sis/employees/{employee}/positions/page/', [$positions, 'page'])
        ->name('sis.position.page');
    $router->map(['POST'], '/sis/employees/{employee}/positions/assign/', [$positions, 'assign'])
        ->name('sis.position.assign');
    $router->map(['GET'], '/sis/employees/{employee}/positions/{id}/', [$positions, 'get'])
        ->name('sis.position.get');
    $router->map(['POST'], '/sis/employees/{employee}/positions/{id}/end/', [$positions, 'end'])
        ->name('sis.position.end');
    $router->map(['POST'], '/sis/employees/{employee}/positions/{id}/void/', [$positions, 'void'])
        ->name('sis.position.void');
    $router->map(['POST'], '/sis/hr/{kind}/page/', [\Application\Http\Controller\SisHrLibraryController::class, 'page'])
        ->name('sis.hr.library.page');
    $router->map(['POST'], '/sis/hr/{kind}/save/', [\Application\Http\Controller\SisHrLibraryController::class, 'save'])
        ->name('sis.hr.library.save');
    $router->map(['GET'], '/sis/hr/{kind}/{id}/', [\Application\Http\Controller\SisHrLibraryController::class, 'get'])
        ->name('sis.hr.library.get');
    $router
        ->map(["POST"], "/sis/employees/{id}/edit/", [SisTimesheetController::class, "editEmployee"])
        ->name("sis.employee.edit");
    $router
        ->map(["POST"], "/sis/employees/{id}/time-entries/", [SisTimesheetController::class, "entries"])
        ->name("sis.employee.entries");
    $router
        ->map(["POST"], "/sis/employees/{id}/time-week/", [SisTimesheetController::class, "week"])
        ->name("sis.employee.week");
    $router
        ->map(["POST"], "/sis/employees/{id}/history/", [SisTimesheetController::class, "history"])
        ->name("sis.employee.history");
    $router
        ->map(["POST"], "/sis/timesheets/", [SisTimesheetController::class, "submit"])
        ->name("sis.timesheet.submit");
    $router
        ->map(["POST"], "/sis/timesheets/{id}/decision/", [SisTimesheetController::class, "decide"])
        ->name("sis.timesheet.decide");
    $router
        ->map(["GET"], "/sis/timesheets/{id}/", [SisTimesheetController::class, "show"])
        ->name("sis.timesheet.show");
    $router
        ->map(["GET"], "/sis/ui/transfer/{kind}/{id}/", [SisTransferCatalogController::class, "show"])
        ->name("sis.transfer.catalog.show");
    $router
        ->map(["POST"], "/sis/ui/transfer/{kind}/{id}/edit/", [SisTransferCatalogController::class, "edit"])
        ->name("sis.transfer.catalog.edit");
    $router
        ->map(["POST"], "/sis/ui/transfer/{kind}/{id}/children/", [
            SisTransferCatalogController::class,
            "children",
        ])
        ->name("sis.transfer.catalog.children");
    $router
        ->map(["POST"], "/sis/ui/transfer/equivalencies/{id}/awards/", [
            SisTransferCatalogController::class,
            "awards",
        ])
        ->name("sis.transfer.catalog.awards");
    $router
        ->map(["POST"], "/sis/external-institutions/", [SisTransferController::class, "institution"])
        ->name("sis.transfer.institution");
    $router
        ->map(["POST"], "/sis/external-courses/", [SisTransferController::class, "course"])
        ->name("sis.transfer.course");
    $router
        ->map(["POST"], "/sis/transfer-equivalencies/", [SisTransferController::class, "equate"])
        ->name("sis.transfer.equate");
    $router
        ->map(["POST"], "/sis/transfer-awards/", [SisTransferController::class, "award"])
        ->name("sis.transfer.award");
    $router
        ->map(["GET"], "/sis/students/{id}/transcript/", [SisTransferController::class, "transcript"])
        ->name("sis.transfer.transcript");
    $router
        ->map(["POST"], "/sis/ledger-accounts/", [SisLedgerController::class, "account"])
        ->name("sis.ledger.account");
    $gateways = \Application\Http\Controller\SisPaymentGatewayController::class;
    $router->map(['GET'], '/sis/payment-setup/directory/{kind}/', [$gateways, 'directory'])
        ->name('sis.payment_setup.directory');
    $router->map(['GET'], '/sis/payment-setup/connections/{id}/', [$gateways, 'show'])
        ->name('sis.payment_setup.show');
    $router->map(['GET'], '/sis/payment-setup/routing/', [$gateways, 'routing'])->name('sis.payment_setup.routing');
    $router->map(['GET'], '/sis/payment-setup/assets/', [$gateways, 'assets'])->name('sis.payment_setup.assets');
    $router->map(['POST'], '/sis/payment-setup/review/{kind}/', [$gateways, 'review'])
        ->name('sis.payment_setup.review');
    $router->map(['POST'], '/sis/payment-setup/confirm/', [$gateways, 'confirm'])->name('sis.payment_setup.confirm');
    $activity = \Application\Http\Controller\PaymentActivityController::class;
    $router->map(['GET'], '/sis/payment-activity/{kind}/{id}/', [$activity, 'staffShow'])
        ->name('sis.payment_activity.show');
    $router->map(['POST'], '/sis/payment-activity/{id}/retry/', [$activity, 'staffRetry'])
        ->name('sis.payment_activity.retry');
    $accounting = \Application\Http\Controller\SisAccountingController::class;
    $router->map(['GET'], '/sis/accounting/', [$accounting, 'overview'])->name('sis.accounting.overview');
    $router->map(['POST'], '/sis/accounting/fees/review/', [$accounting, 'fees'])->name('sis.accounting.fees');
    $router->map(['POST'], '/sis/accounting/expense/review/', [$accounting, 'expense'])->name('sis.accounting.expense');
    $router->map(['POST'], '/sis/accounting/mapping/review/', [$accounting, 'mapping'])->name('sis.accounting.mapping');
    $router->map(['POST'], '/sis/accounting/periods/review/', [$accounting, 'period'])->name('sis.accounting.period');
    $router
        ->map(['POST'], '/sis/accounting/periods/{id}/review/', [$accounting, 'closing'])
        ->name('sis.accounting.closing');
    $router->map(['POST'], '/sis/accounting/confirm/', [$accounting, 'confirm'])->name('sis.accounting.confirm');
    $router
        ->map(['POST'], '/sis/accounting/periods/{id}/reconcile/', [$accounting, 'reconcile'])
        ->name('sis.accounting.reconcile');
    $router->map(["POST"], "/sis/journals/", [SisLedgerController::class, "post"])->name("sis.ledger.post");
    $router
        ->map(["POST"], "/sis/journals/{id}/reverse/", [SisLedgerController::class, "reverse"])
        ->name("sis.ledger.reverse");
    $router
        ->map(["GET"], "/sis/journals/{id}/", [SisLedgerController::class, "show"])
        ->name("sis.ledger.show");
    $router
        ->map(["POST"], "/sis/accounts/{id}/entries/{operation}/reverse/", [
            SisLedgerController::class,
            "reverseEntry",
        ])
        ->name("sis.ledger.reverseentry");
    $router
        ->map(["POST"], "/sis/reports/", [SisReportController::class, "create"])
        ->name("sis.report.create");
    $router
        ->map(["POST"], "/sis/reports/{id}/shares/", [SisReportController::class, "share"])
        ->name("sis.report.share");
    $router
        ->map(["GET"], "/sis/reports/{id}/", [SisReportController::class, "show"])
        ->name("sis.report.show");
    $router
        ->map(["POST"], "/sis/reports/{id}/run/", [SisReportController::class, "run"])
        ->name("sis.report.run");
    $router
        ->map(["POST"], "/sis/reports/{id}/export/", [SisReportController::class, "export"])
        ->name("sis.report.export");
    $router
        ->map(["POST"], "/sis/campaigns/", [SisCampaignController::class, "create"])
        ->name("sis.campaign.create");
    $router->post('/sis/campaigns/recipients/search/', [SisCampaignController::class, 'recipientChoices']);
    $router->post('/sis/campaigns/recipients/students/', [SisCampaignController::class, 'studentRecipients']);
    $router->get(
        '/sis/campaigns/recipients/{source}/{id}/',
        [SisCampaignController::class, 'recordRecipient']
    );
    $router->post('/sis/campaigns/templates/page/', [SisCampaignController::class, 'templates']);
    $router->post('/sis/campaigns/templates/create/', [SisCampaignController::class, 'createTemplate']);
    $router->post('/sis/campaigns/{id}/template/draft/', [SisCampaignController::class, 'draftFromTemplate']);
    $router->post('/sis/campaigns/{id}/template/status/', [SisCampaignController::class, 'retireTemplate']);
    $router->post('/sis/campaigns/{id}/template/review/', [SisCampaignController::class, 'reviewTemplate']);
    $router->post('/sis/campaigns/{id}/template/edit/', [SisCampaignController::class, 'edit']);
    $router->post('/sis/campaigns/{id}/recipient-review/', [SisCampaignController::class, 'reviewRecipients']);
    $router
        ->map(["POST"], "/sis/campaigns/{id}/queue/", [SisCampaignController::class, "queue"])
        ->name("sis.campaign.queue");
    $router
        ->map(["POST"], "/sis/campaigns/{id}/pause/", [SisCampaignController::class, "pause"])
        ->name("sis.campaign.pause");
    $router->get('/sis/campaigns/{id}/delivery-setup/', [SisCampaignController::class, 'deliverySetup']);
    $router->post('/sis/campaigns/{id}/delivery-review/', [SisCampaignController::class, 'deliveryReview']);
    $router->post('/sis/campaigns/{id}/delivery-confirm/', [SisCampaignController::class, 'deliveryConfirm']);
    $router->get('/sis/campaigns/{id}/delivery-progress/', [SisCampaignController::class, 'deliveryProgress']);
    $router->post('/sis/campaigns/{id}/delivery-cancel/', [SisCampaignController::class, 'deliveryCancel']);
    $router->get(
        '/sis/campaigns/{id}/deliveries/{delivery}/review/',
        [SisCampaignController::class, 'deliveryDecision']
    );
    $router->post(
        '/sis/campaigns/{id}/deliveries/{delivery}/review/',
        [SisCampaignController::class, 'reviewDeliveryDecision']
    );
    $router->post(
        '/sis/campaigns/{id}/deliveries/{delivery}/confirm/',
        [SisCampaignController::class, 'confirmDeliveryDecision']
    );
    $router
        ->map(["POST"], "/sis/portal/posts/", [SisPortalController::class, "create"])
        ->name("sis.portal.create");
    $router->map(['POST'], '/sis/portal/posts/{id}/publication/review/', [
        \Application\Http\Controller\SisPortalEditorController::class, 'review',
    ])->name('sis.portal.publication.review');
    $router
        ->map(["POST"], "/sis/portal/posts/{id}/publication/", [
            \Application\Http\Controller\SisPortalEditorController::class, "confirm",
        ])
        ->name("sis.portal.publish");
    $router
        ->map(["GET"], "/sis/portal/content/{code}/", [SisPortalController::class, "show"])
        ->name("sis.portal.show");
    $router
        ->map(["GET"], "/sis/audit/subjects/{id}/", [SisAuditController::class, "subject"])
        ->name("sis.audit.subject");
    $router
        ->map(["POST"], "/sis/restrictions/{id}/end/", [SisRestrictionController::class, "end"])
        ->name("sis.registration.end_restriction");
    $router
        ->map(["POST"], "/sis/billing-rules/", [SisLedgerController::class, "defineBillingRule"])
        ->name("sis.billing.define");
    $router
        ->map(["POST"], "/sis/billing-rules/{id}/retire/", [SisLedgerController::class, "retireBillingRule"])
        ->name("sis.billing.retire");
    $router
        ->map(["POST"], "/sis/billing-batches/review/", [SisLedgerController::class, "reviewBills"])
        ->name("sis.billing.review");
    $router
        ->map(["POST"], "/sis/billing-batches/", [SisLedgerController::class, "confirmBills"])
        ->name("sis.billing.confirm");
    $router
        ->map(["GET"], "/sis/billing-rules/{id}/", [SisLedgerController::class, "billingRule"])
        ->name("sis.billing.show");
    $router
        ->map(["GET"], "/sis/employees/{id}/", [SisTimesheetController::class, "employeeProfile"])
        ->name("sis.timesheet.employee_profile");
    $router->get('/sis/students/{student}/programs/{assignment}/degree-adjustments/routing/', [
        SisRequirementAdjustmentController::class, 'routing',
    ]);
    foreach (
        [
        '/sis/students/{student}/programs/{assignment}/degree-adjustments/',
        '/sis/students/{student}/programs/{assignment}/specializations/{specialization}/adjustments/',
        ] as $adjustmentsPath
    ) {
        foreach (
            [
            ['POST', 'replacement-courses/', 'replacementCourses'],
            ['POST', 'placement-groups/', 'placementGroups'],
            ['POST', 'placement-work/', 'placementWork'],
            ['POST', 'replacement-courses/{course}/versions/', 'replacementVersions'],
            ['GET', 'preview/', 'preview'], ['GET', '', 'view'],
            ['POST', 'review/', 'review'], ['POST', 'revoke-review/', 'revokeReview'],
            ['POST', 'commit/', 'commit'], ['POST', 'history/', 'history'],
            ] as [$method, $suffix, $action]
        ) {
            $router->map([$method], $adjustmentsPath . $suffix, [SisRequirementAdjustmentController::class, $action]);
        }
    }
    $router->post(
        '/sis/students/{id}/programs/change/review/',
        [\Application\Http\Controller\SisStudentProgramAssignmentController::class, 'reviewChange']
    );
    $router->post(
        '/sis/students/{id}/programs/change/commit/',
        [\Application\Http\Controller\SisStudentProgramAssignmentController::class, 'changeProgram']
    );
    foreach (
        [
            ['', 'page'],
            ['pursuits/', 'pursuits'],
            ['reuse/review/', 'reviewReuse'],
            ['reuse/commit/', 'commitReuse'],
            ['{credit}/history/', 'history'],
            ['{credit}/review/', 'review'],
            ['{credit}/commit/', 'commit'],
        ] as [$suffix, $action]
    ) {
        $router->post(
            '/sis/students/{student}/academic-work-pursuits/' . $suffix,
            [\Application\Http\Controller\SisWorkPursuitController::class, $action]
        );
    }
    $graduation = \Application\Http\Controller\SisGraduationController::class;
    $router->get('/sis/students/{student}/programs/{assignment}/graduation/', [$graduation, 'current']);
    foreach (['history', 'review', 'confirm'] as $action) {
        $router->post(
            '/sis/students/{student}/programs/{assignment}/graduation/' . $action . '/',
            [$graduation, $action]
        );
    }
    foreach (['page/' => 'queue', 'review/' => 'reviewGroup', 'confirm/' => 'confirmGroup'] as $suffix => $action) {
        $router->post('/sis/graduation-conferral/' . $suffix, [$graduation, $action]);
    }
    foreach (['page/' => 'page', 'review/' => 'review', 'confirm/' => 'confirm'] as $suffix => $action) {
        $router->post(
            '/sis/students/{student}/programs/{assignment}/degree-requirements/records/' . $suffix,
            [\Application\Http\Controller\SisDegreeProgressRecordController::class, $action]
        );
    }
    $router->get(
        '/sis/students/{student}/programs/{assignment}/degree-requirements/records/{id}/',
        [\Application\Http\Controller\SisDegreeProgressRecordController::class, 'show']
    );
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/progress/", [
        \Application\Http\Controller\SisAcademicWorkController::class,
        "progress",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/combined/", [
        \Application\Http\Controller\SisAcademicWorkController::class,
        "combined",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/allocation/", [
        \Application\Http\Controller\SisAcademicWorkController::class,
        "allocation",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/calculations/", [
        \Application\Http\Controller\SisAcademicWorkController::class,
        "calculations",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/verify/", [
        \Application\Http\Controller\SisAcademicWorkController::class,
        "verify",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/matches/", [
        \Application\Http\Controller\SisAcademicWorkController::class,
        "matches",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/academic-work/", [
        \Application\Http\Controller\SisAcademicWorkController::class,
        "page",
    ]);
    $router->get("/sis/students/{student}/programs/{assignment}/degree-requirements/", [
        SisStudentRequirementsController::class,
        "view",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/choices/", [
        SisStudentRequirementsController::class,
        "choices",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/review/", [
        SisStudentRequirementsController::class,
        "review",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/assign/", [
        SisStudentRequirementsController::class,
        "assign",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-requirements/history/", [
        SisStudentRequirementsController::class,
        "history",
    ]);
    $router->post("/sis/programs/{program}/degree-audits/page/", [SisDegreeAuditController::class, "page"]);
    $router->post("/sis/programs/{program}/degree-audits/open/", [SisDegreeAuditController::class, "open"]);
    $router->get("/sis/degree-audits/{id}/assistance/", [SisDegreeAssistanceController::class, "preview"]);
    $router->post("/sis/degree-audits/{id}/assistance/", [SisDegreeAssistanceController::class, "suggest"]);
    $router->get("/sis/degree-audits/{id}/", [SisDegreeAuditController::class, "view"]);
    $router->post("/sis/degree-audits/{id}/save/", [SisDegreeAuditController::class, "save"]);
    $router->post("/sis/degree-audits/{id}/test/", [SisDegreeAuditController::class, "test"]);
    $router->post("/sis/degree-audits/{id}/clone/", [SisDegreeAuditController::class, "clone"]);
    $router->post("/sis/degree-audits/{id}/review/", [SisDegreeAuditController::class, "review"]);
    $router->post("/sis/degree-audits/{id}/publish/", [SisDegreeAuditController::class, "publish"]);
    $router->post("/sis/degree-audits/{id}/versions/", [SisDegreeAuditController::class, "versions"]);
    $router->get("/sis/degree-audits/{id}/versions/{version}/", [SisDegreeAuditController::class, "version"]);
    $router->post("/sis/degree-audits/{id}/reuse-groups/", [SisDegreeAuditController::class, "reuse"]);
    $groupAssistance = \Application\Http\Controller\SisCourseGroupAssistanceController::class;
    $router->get("/sis/course-groups/{id}/assistance/", [$groupAssistance, "preview"]);
    $router->post("/sis/course-groups/{id}/assistance/", [$groupAssistance, "suggest"]);
    $router->post("/sis/course-groups/page/", [SisCourseGroupController::class, "page"]);
    $router->post('/sis/course-groups/transfer-institutions/', [SisCourseGroupController::class, 'institutions']);
    $router->post("/sis/course-groups/create/", [SisCourseGroupController::class, "create"]);
    $router->get("/sis/course-groups/{id}/", [SisCourseGroupController::class, "view"]);
    $router->post("/sis/course-groups/{id}/save/", [SisCourseGroupController::class, "save"]);
    $router->post("/sis/course-groups/{id}/review/", [SisCourseGroupController::class, "review"]);
    $router->post("/sis/course-groups/{id}/publish/", [SisCourseGroupController::class, "publish"]);
    $router->post("/sis/course-groups/{id}/versions/", [SisCourseGroupController::class, "versions"]);
    $router->get("/sis/course-groups/{id}/versions/{version}/", [SisCourseGroupController::class, "version"]);

    $fees = \Application\Http\Controller\SisServiceFeeController::class;
    $router->get('/sis/service-fees/policies/', [$fees, 'policies']);
    $router->post('/sis/service-fees/policies/{purpose}/', [$fees, 'policy']);
    $router->post('/sis/people/{person}/service-fees/page/', [$fees, 'page']);
    $router->post('/sis/people/{person}/service-fees/prepare/', [$fees, 'prepare']);
    $router->get('/sis/service-fees/{fee}/', [$fees, 'show']);
    foreach (['history', 'review', 'confirm'] as $action) {
        $router->post('/sis/service-fees/{fee}/' . $action . '/', [$fees, $action]);
    }

    $internal = \Application\Http\Controller\SisInternalTransferController::class;
    $base = '/sis/students/{student}/programs/{assignment}/internal-transfers/';
    foreach (['page','candidates','search','review','confirm'] as $action) {
        $router->post($base . $action . '/', [$internal,$action]);
    }
    $router->post($base . 'courses/{course}/publications/', [$internal,'publications']);
    $router->get($base . '{award}/', [$internal,'show']);
    $router->post($base . '{award}/history/', [$internal,'history']);
    $moodleGrades = \Application\Http\Controller\SisMoodleGradeImportController::class;
    $moodleGradeBase = '/sis/moodle/grade-imports/';
    $router->get($moodleGradeBase . 'notices/', [$moodleGrades, 'notices']);
    $router->post($moodleGradeBase . 'notices/', [$moodleGrades, 'notices']);
    $router->post($moodleGradeBase . 'notices/{notice}/read/', [$moodleGrades, 'read']);
    $router->post($moodleGradeBase . 'notices/{notice}/delete/', [$moodleGrades, 'delete']);
    foreach (['page', 'request', 'cancel'] as $action) {
        $router->post($moodleGradeBase . '{section}/' . $action . '/', [$moodleGrades, $action]);
    }
    $router->get($moodleGradeBase . '{section}/{candidate}/', [$moodleGrades, 'show']);
    $router->post($moodleGradeBase . '{section}/{candidate}/review/', [$moodleGrades, 'review']);
    $router->post($moodleGradeBase . '{section}/{candidate}/confirm/', [$moodleGrades, 'confirm']);
};
