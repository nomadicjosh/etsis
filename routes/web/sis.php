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
        ->post('/sis/accounts/{id}/entries/{operation}/reverse/review/', [
            SisFinanceController::class, 'reviewReversal',
        ])
        ->name('sis.accounts.reversal.review');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/reverse/commit/', [
            SisFinanceController::class, 'commitReversal',
        ])
        ->name('sis.accounts.reversal.commit');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/credit/review/', [
            SisFinanceController::class, 'reviewChargeCredit',
        ])->name('sis.accounts.charge_credit.review');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/credit/commit/', [
            SisFinanceController::class, 'commitChargeCredit',
        ])->name('sis.accounts.charge_credit.commit');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/refund/review/', [
            SisFinanceController::class, 'reviewRefund',
        ])->name('sis.accounts.payment_refund.review');
    $router
        ->post('/sis/accounts/{id}/entries/{operation}/refund/commit/', [
            SisFinanceController::class, 'commitRefund',
        ])->name('sis.accounts.payment_refund.commit');
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
    $revisions = \Application\Http\Controller\ApplicationRevisionController::class;
    $router->map(['GET'], '/sis/applications/{id}/answers/', [$revisions, 'current']);
    $router->map(['POST'], '/sis/applications/{id}/answers/review/', [$revisions, 'review']);
    $router->map(['POST'], '/sis/applications/{id}/answers/commit/', [$revisions, 'commit']);
    $entry = \Application\Http\Controller\AdmissionDeferralController::class;
    $router->map(['GET'], '/sis/applications/{id}/entry/', [$entry, 'current']);
    $router->map(['GET'], '/sis/applications/{id}/entry/terms/', [$entry, 'choices']);
    $router->map(['POST'], '/sis/applications/{id}/entry/review/', [$entry, 'review']);
    $router->map(['POST'], '/sis/applications/{id}/entry/commit/', [$entry, 'commit']);
    $offers = \Application\Http\Controller\AdmissionOfferController::class;
    $admissionPolicy = \Application\Http\Controller\AdmissionPolicyController::class;
    $router->map(['GET'], '/sis/admission-policy/', [$admissionPolicy, 'current']);
    $router->map(['POST'], '/sis/admission-policy/', [$admissionPolicy, 'save']);
    $withdrawals = \Application\Http\Controller\AdmissionWithdrawalController::class;
    $router->map(['GET'], '/sis/admissions/withdrawal-requests/', [$withdrawals, 'queue']);
    $router->map(['GET'], '/sis/applications/{id}/withdrawal/', [$withdrawals, 'current']);
    $router->map(['POST'], '/sis/applications/{id}/withdrawal/review/', [$withdrawals, 'review']);
    $router->map(['POST'], '/sis/applications/{id}/withdrawal/commit/', [$withdrawals, 'commit']);
    $router->map(['GET'], '/sis/applications/{id}/offer/', [$offers, 'current']);
    $router->map(['POST'], '/sis/applications/{id}/offer/review/', [$offers, 'review']);
    $router->map(['POST'], '/sis/applications/{id}/offer/commit/', [$offers, 'commit']);
    $router->post('/sis/applications/{id}/review-state/review/', [
        \Application\Http\Controller\SisAdmissionReviewStateController::class, 'review',
    ])->name('sis.applications.review_state.review');
    $router->post('/sis/applications/{id}/review-state/commit/', [
        \Application\Http\Controller\SisAdmissionReviewStateController::class, 'commit',
    ])->name('sis.applications.review_state.commit');
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
