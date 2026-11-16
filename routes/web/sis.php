<?php

declare(strict_types=1);

use Application\Http\Controller\SisAdmissionsController;
use Application\Http\Controller\SisFinanceController;
use Application\Http\Controller\SisCatalogController;
use Qubus\Routing\Interfaces\Mappable;
use Qubus\Routing\Psr7Router;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
    $router
        ->map(["POST"], "/sis/programs/", [SisCatalogController::class, "program"])
        ->name("sis.programs.create");
    $router
        ->map(["POST"], "/sis/terms/", [
            \Application\Http\Controller\SisAcademicCalendarController::class,
            "saveTerm",
        ])
        ->name("sis.terms.create");
    $router
        ->map(["POST"], "/sis/accounts/", [SisFinanceController::class, "open"])
        ->name("sis.accounts.open");
    $router
        ->map(["GET"], "/sis/accounts/{id}/", [SisFinanceController::class, "show"])
        ->name("sis.accounts.show");
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/history/', [SisFinanceController::class, 'history'])
        ->name('sis.accounts.entry.history');
    $router
        ->map(["POST"], "/sis/accounts/{id}/entries/", [SisFinanceController::class, "post"])
        ->name("sis.accounts.post");
    $router
        ->post('/sis/accounts/{id}/entries/review/', [SisFinanceController::class, 'reviewPosting'])
        ->name('sis.accounts.posting.review');
    $router
        ->post('/sis/accounts/{id}/entries/commit/', [SisFinanceController::class, 'commitPosting'])
        ->name('sis.accounts.posting.commit');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/correction/review/', [
            SisFinanceController::class, 'reviewCorrection',
        ])
        ->name('sis.accounts.correction.review');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/correction/commit/', [
            SisFinanceController::class, 'commitCorrection',
        ])
        ->name('sis.accounts.correction.commit');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/receipt/', [SisFinanceController::class, 'receipt'])
        ->name('sis.accounts.receipt.read');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/receipt/review/', [
            SisFinanceController::class, 'reviewPaymentDetails',
        ])
        ->name('sis.accounts.receipt.review');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/receipt/commit/', [
            SisFinanceController::class, 'commitPaymentDetails',
        ])
        ->name('sis.accounts.receipt.commit');
    $router
        ->map(["POST"], "/sis/persons/", [SisAdmissionsController::class, "createPerson"])
        ->name("sis.persons.create");
    $router
        ->map(["POST"], "/sis/applications/", [SisAdmissionsController::class, "apply"])
        ->name("sis.applications.create");
    $router
        ->map(["POST"], "/sis/applications/{id}/decision/review/", [
            \Application\Http\Controller\SisAdmissionDecisionController::class,
            "review",
        ])
        ->name("sis.applications.decision.review");
    $router
        ->map(["POST"], "/sis/applications/{id}/decision/commit/", [
            \Application\Http\Controller\SisAdmissionDecisionController::class,
            "commit",
        ])
        ->name("sis.applications.decision.commit");
    $router
        ->map(["GET"], "/sis/applications/{id}/decision/history/", [
            \Application\Http\Controller\SisAdmissionDecisionController::class,
            "history",
        ])
        ->name("sis.applications.decision.history");
    $router
        ->map(["POST"], "/sis/applications/{id}/decision/history/page/", [
            \Application\Http\Controller\SisAdmissionDecisionController::class,
            "page",
        ])
        ->name("sis.applications.decision.history.page");
    $router
        ->map(["POST"], "/sis/applications/{id}/decision/correction/review/", [
            \Application\Http\Controller\SisAdmissionCorrectionController::class,
            "review",
        ])
        ->name("sis.applications.correction.review");
    $router
        ->map(["POST"], "/sis/applications/{id}/decision/correction/commit/", [
            \Application\Http\Controller\SisAdmissionCorrectionController::class,
            "commit",
        ])
        ->name("sis.applications.correction.commit");
    $router
        ->map(["POST"], "/sis/applications/{id}/matriculate/review/", [
            \Application\Http\Controller\SisMatriculationController::class,
            "review",
        ])
        ->name("sis.applications.matriculate.review");
    $router
        ->map(["POST"], "/sis/applications/{id}/matriculate/commit/", [
            \Application\Http\Controller\SisMatriculationController::class,
            "commit",
        ])
        ->name("sis.applications.matriculate.commit");
    $router
        ->map(["GET"], "/sis/applications/{id}/matriculate/history/", [
            \Application\Http\Controller\SisMatriculationController::class,
            "history",
        ])
        ->name("sis.applications.matriculate.history");
    $router
        ->map(["GET"], "/sis/students/{id}/", [SisAdmissionsController::class, "student"])
        ->name("sis.students.show");
    $router
        ->map(["GET"], "/sis/persons/{id}/", [SisAdmissionsController::class, "person"])
        ->name("sis.persons.show");
    $router
        ->map(["POST"], "/sis/persons/{id}/institutional-id/", [
            SisAdmissionsController::class,
            "updateInstitutionalId",
        ])
        ->name("sis.persons.update_institutional_id");
    $router
        ->map(["POST"], "/sis/persons/lookup/", [SisAdmissionsController::class, "lookupPerson"])
        ->name("sis.persons.lookup");
};
