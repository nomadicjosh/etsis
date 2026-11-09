<?php

declare(strict_types=1);

use Application\Http\Controller\SisDegreeAuditController;
use Application\Http\Controller\SisStudentRequirementsController;
use Application\Http\Controller\SisRequirementAdjustmentController;
use Application\Http\Controller\SisCourseGroupController;
use Application\Http\Controller\SisRegistrationController;
use Application\Http\Controller\SisCoursePublicationController;
use Application\Http\Controller\SisCourseTextController;
use Application\Http\Controller\SisSectionCourseBindingController;
use Application\Http\Controller\SisCatalogWorkflowController;
use Application\Http\Controller\SisFinancialReviewController;
use Application\Http\Controller\SisTranscriptFulfillmentController;
use Application\Http\Controller\SisRestrictionController;
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
use Application\Http\Controller\SisImportController;
use Application\Http\Controller\SisCampaignController;
use Application\Http\Controller\SisPortalController;
use Application\Http\Controller\SisAuditController;
use Qubus\Routing\Interfaces\Mappable;
use Qubus\Routing\Psr7Router;
use Application\Http\Controller\SisRelationalReportController;
use Application\Http\Controller\SisStudentCatalogController;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
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
    $router
        ->map(["POST"], "/sis/terms/{term}/drop-policy/", [
            \Application\Http\Controller\SisDropPolicyController::class,
            "save",
        ])
        ->name("sis.drop.policy.save");
    $router
        ->map(["GET"], "/sis/students/{student}/transfer-awards/{award}/", [
            \Application\Http\Controller\SisTransferAwardController::class,
            "show",
        ])
        ->name("sis.transfer.award.show");
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
        ->map(["POST"], "/sis/report-studio/{id}/export/", [SisRelationalReportController::class, "export"])
        ->name("sis.report.studio.export");

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
        ->map(["POST"], "/sis/students/{id}/programs/assign/", [SisStudentRecordController::class, "assign"])
        ->name("sis.student.program.assign");
    $router
        ->map(["POST"], "/sis/students/{id}/primary-program/", [SisStudentRecordController::class, "primary"])
        ->name("sis.student.program.primary");
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
        ->map(["POST"], "/sis/transcript-requests/{id}/documents/{document}/deliver/", [
            SisTranscriptFulfillmentController::class,
            "deliver",
        ])
        ->name("sis.transcript.fulfillment.deliver");
    $router
        ->map(["POST"], "/sis/courses/", [SisRegistrationController::class, "course"])
        ->name("sis.registration.course");
    $router
        ->map(["POST"], "/sis/sections/", [SisRegistrationController::class, "section"])
        ->name("sis.registration.section");
    $router
        ->map(["POST"], "/sis/terms/{id}/registration-window/", [SisRegistrationController::class, "window"])
        ->name("sis.registration.window");
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
    $router
        ->map(["POST"], "/sis/registrations/drop/", [SisRegistrationController::class, "drop"])
        ->name("sis.registration.drop");

    $router
        ->map(["POST"], "/sis/sections/{id}/assignments/", [SisGradebookController::class, "assignment"])
        ->name("sis.gradebook.assignment");
    $router
        ->map(["POST"], "/sis/assignments/{id}/scores/", [SisGradebookController::class, "score"])
        ->name("sis.gradebook.score");
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
    $router->get("/sis/courses/{id}/text/", [SisCourseTextController::class, "show"]);
    $router->post("/sis/courses/{id}/text/", [SisCourseTextController::class, "save"]);
    $router->post("/sis/courses/{id}/publication/review/", [SisCoursePublicationController::class, "review"]);
    $router->post("/sis/courses/{id}/publication/commit/", [
        SisCoursePublicationController::class,
        "publish",
    ]);
    $router->post("/sis/courses/{id}/publication/page/", [SisCoursePublicationController::class, "page"]);
    $router->map(["POST"], "/sis/rooms/", [SisBookingController::class, "room"])->name("sis.booking.room");
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
    $router
        ->map(["POST"], "/sis/bookings/", [SisBookingController::class, "request"])
        ->name("sis.booking.request");
    $router
        ->map(["POST"], "/sis/bookings/{id}/approve/", [SisBookingController::class, "approve"])
        ->name("sis.booking.approve");
    $router
        ->map(["POST"], "/sis/bookings/{id}/cancel/", [SisBookingController::class, "cancel"])
        ->name("sis.booking.cancel");
    $router
        ->map(["GET"], "/sis/bookings/{id}/", [SisBookingController::class, "show"])
        ->name("sis.booking.show");
    $router
        ->map(["POST"], "/sis/employees/", [SisTimesheetController::class, "employee"])
        ->name("sis.timesheet.employee");
    $router
        ->map(["POST"], "/sis/employees/{id}/edit/", [SisTimesheetController::class, "editEmployee"])
        ->name("sis.employee.edit");
    $router
        ->map(["POST"], "/sis/employees/{id}/time-entries/", [SisTimesheetController::class, "entries"])
        ->name("sis.employee.entries");
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
        ->map(["POST"], "/sis/imports/courses/{source}/", [SisImportController::class, "courses"])
        ->name("sis.import.courses");
    $router
        ->map(["POST"], "/sis/campaigns/", [SisCampaignController::class, "create"])
        ->name("sis.campaign.create");
    $router
        ->map(["POST"], "/sis/campaigns/{id}/queue/", [SisCampaignController::class, "queue"])
        ->name("sis.campaign.queue");
    $router
        ->map(["POST"], "/sis/campaigns/{id}/pause/", [SisCampaignController::class, "pause"])
        ->name("sis.campaign.pause");
    $router
        ->map(["POST"], "/sis/deliveries/{id}/dispatch/", [SisCampaignController::class, "dispatch"])
        ->name("sis.campaign.dispatch");
    $router
        ->map(["POST"], "/sis/portal/posts/", [SisPortalController::class, "create"])
        ->name("sis.portal.create");
    $router
        ->map(["POST"], "/sis/portal/posts/{id}/publication/", [SisPortalController::class, "publish"])
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
        ->map(["POST"], "/sis/billing-batches/", [SisLedgerController::class, "generateBills"])
        ->name("sis.billing.generate");
    $router
        ->map(["GET"], "/sis/billing-rules/{id}/", [SisLedgerController::class, "billingRule"])
        ->name("sis.billing.show");
    $router
        ->map(["GET"], "/sis/employees/{id}/", [SisTimesheetController::class, "employeeProfile"])
        ->name("sis.timesheet.employee_profile");
    $router->post("/sis/students/{student}/programs/{assignment}/degree-adjustments/replacement-courses/", [
        SisRequirementAdjustmentController::class,
        "replacementCourses",
    ]);
    $router->post(
        "/sis/students/{student}/programs/{assignment}/degree-adjustments/replacement-courses/{course}/versions/",
        [SisRequirementAdjustmentController::class, "replacementVersions"],
    );
    $router->get("/sis/students/{student}/programs/{assignment}/degree-adjustments/", [
        SisRequirementAdjustmentController::class,
        "view",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-adjustments/review/", [
        SisRequirementAdjustmentController::class,
        "review",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-adjustments/revoke-review/", [
        SisRequirementAdjustmentController::class,
        "revokeReview",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-adjustments/commit/", [
        SisRequirementAdjustmentController::class,
        "commit",
    ]);
    $router->post("/sis/students/{student}/programs/{assignment}/degree-adjustments/history/", [
        SisRequirementAdjustmentController::class,
        "history",
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
    $router->get("/sis/degree-audits/{id}/", [SisDegreeAuditController::class, "view"]);
    $router->post("/sis/degree-audits/{id}/save/", [SisDegreeAuditController::class, "save"]);
    $router->post("/sis/degree-audits/{id}/clone/", [SisDegreeAuditController::class, "clone"]);
    $router->post("/sis/degree-audits/{id}/review/", [SisDegreeAuditController::class, "review"]);
    $router->post("/sis/degree-audits/{id}/publish/", [SisDegreeAuditController::class, "publish"]);
    $router->post("/sis/degree-audits/{id}/versions/", [SisDegreeAuditController::class, "versions"]);
    $router->get("/sis/degree-audits/{id}/versions/{version}/", [SisDegreeAuditController::class, "version"]);
    $router->post("/sis/degree-audits/{id}/reuse-groups/", [SisDegreeAuditController::class, "reuse"]);
    $router->post("/sis/course-groups/page/", [SisCourseGroupController::class, "page"]);
    $router->post("/sis/course-groups/create/", [SisCourseGroupController::class, "create"]);
    $router->get("/sis/course-groups/{id}/", [SisCourseGroupController::class, "view"]);
    $router->post("/sis/course-groups/{id}/save/", [SisCourseGroupController::class, "save"]);
    $router->post("/sis/course-groups/{id}/review/", [SisCourseGroupController::class, "review"]);
    $router->post("/sis/course-groups/{id}/publish/", [SisCourseGroupController::class, "publish"]);
    $router->post("/sis/course-groups/{id}/versions/", [SisCourseGroupController::class, "versions"]);
    $router->get("/sis/course-groups/{id}/versions/{version}/", [SisCourseGroupController::class, "version"]);
};
