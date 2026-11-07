<?php

declare(strict_types=1);

use Application\Http\Controller\SisWorkspaceController;
use Application\Http\Controller\SisPersonEditorController;
use Application\Http\Controller\SisReportController;
use Application\Http\Controller\SisOperationsController;
use Application\Http\Controller\SisCatalogEditorController;
use Application\Http\Controller\SisCampaignController;
use Application\Http\Controller\SisRegistrationController;
use Application\Http\Controller\SisFormBuilderController;
use Application\Http\Controller\SisMappedImportController;
use Application\Http\Controller\SisRulesController;
use Application\Http\Controller\SisPersonAccountController;
use Qubus\Routing\Interfaces\Mappable;
use Qubus\Routing\Psr7Router;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
    $router->map(['GET'], '/sis/ui/people/{id}/profile/', [SisPersonEditorController::class, 'profile'])
        ->name('sis.ui.person.profile');
    $router->map(['POST'], '/sis/ui/people/{id}/profile/', [SisPersonEditorController::class, 'saveProfile'])
        ->name('sis.ui.person.profile.save');
    $router->map(['GET'], '/sis/ui/people/{id}/account/', [SisPersonEditorController::class, 'account'])
        ->name('sis.ui.person.account');
    $router->map(['POST'], '/sis/ui/people/{id}/account/email/', [SisPersonEditorController::class, 'email'])
        ->name('sis.ui.person.account.email');
    $router->map(['POST'], '/sis/ui/people/{id}/account/password/', [SisPersonEditorController::class, 'password'])
        ->name('sis.ui.person.account.password');
    $router->map(['GET'], '/sis/ui/catalog/{kind}/{id}/', [SisCatalogEditorController::class, 'show'])
        ->name('sis.ui.catalog.show');
    $router->map(['POST'], '/sis/ui/catalog/{kind}/{id}/', [SisCatalogEditorController::class, 'save'])
        ->name('sis.ui.catalog.save');
    $router->map(['POST'], '/sis/ui/context/{context}/{id}/', [SisOperationsController::class, 'page'])
        ->name('sis.ui.context');
    $router->map(['POST'], '/sis/ui/exports/roster/{id}/', [SisOperationsController::class, 'rosterCsv'])
        ->name('sis.ui.roster-csv');
    $router->map(['GET'], '/sis/ui/operation-id/', [SisOperationsController::class, 'operationId'])
        ->name('sis.ui.operation-id');
    $router->map(
        ['POST'],
        '/sis/courses/{id}/prerequisites/{required}/remove/',
        [SisRegistrationController::class, 'removePrerequisite']
    )->name('sis.ui.prerequisite.remove');
    $router->map(['POST'], '/sis/campaigns/{id}/draft/', [SisCampaignController::class, 'edit'])
        ->name('sis.campaign.edit');
    $router->map(['GET'], '/sis/campaigns/{id}/', [SisCampaignController::class, 'show'])
        ->name('sis.campaign.show');
    $router->map(['GET'], '/admin/workspace/{section}/', [SisWorkspaceController::class, 'page'])
        ->name('sis.workspace')->middleware(['gate:admin:dashboard,/login/']);
    $router->map(['GET'], '/sis/ui/me/', [SisWorkspaceController::class, 'me'])
        ->name('sis.ui.me');
    $router->map(['POST'], '/sis/ui/people/{id}/account/', [SisPersonAccountController::class, 'enable'])
        ->name('sis.ui.account');
    $router->map(['GET'], '/sis/ui/metrics/', [SisWorkspaceController::class, 'metrics'])
        ->name('sis.ui.metrics');
    $router->map(['POST'], '/sis/ui/data/{dataset}/', [SisWorkspaceController::class, 'records'])
        ->name('sis.ui.data');
    $router->map(['GET'], '/sis/ui/people/{id}/', [SisWorkspaceController::class, 'person'])
        ->name('sis.ui.person');
    $router->map(['GET'], '/sis/applications/{id}/review/', [SisFormBuilderController::class, 'reviewApplication'])
        ->name('sis.applications.review');
    $router->map(['POST'], '/sis/reports/{id}/shares/list/', [SisReportController::class, 'shares'])
        ->name('sis.reports.shares');
    $router->map(['POST'], '/sis/reports/{id}/shares/{user}/revoke/', [SisReportController::class, 'unshare'])
        ->name('sis.reports.unshare');
    $router->map(['GET'], '/sis/forms/', [SisFormBuilderController::class, 'listing'])
        ->name('sis.forms.list');
    $router->map(['POST'], '/sis/forms/', [SisFormBuilderController::class, 'save'])
        ->name('sis.forms.save');
    $router->map(['GET'], '/sis/forms/{id}/', [SisFormBuilderController::class, 'show'])
        ->name('sis.forms.show');
    $router->map(['POST'], '/sis/forms/{id}/publish/', [SisFormBuilderController::class, 'publish'])
        ->name('sis.forms.publish');
    $router->map(['POST'], '/sis/forms/programs/{id}/disable/', [SisFormBuilderController::class, 'disable'])
        ->name('sis.forms.disable');
    $router->map(['GET'], '/sis/forms/programs/{id}/active/', [SisFormBuilderController::class, 'active'])
        ->name('sis.forms.active');
    $router->map(['POST'], '/sis/forms/{id}/submissions/', [SisFormBuilderController::class, 'submit'])
        ->name('sis.forms.submit');
    $router->map(['GET'], '/sis/form-submissions/{id}/', [SisFormBuilderController::class, 'submission'])
        ->name('sis.forms.submission');
    $router->map(['POST'], '/sis/imports/preview/', [SisMappedImportController::class, 'preview'])
        ->name('sis.import.preview');
    $router->map(['POST'], '/sis/imports/commit/', [SisMappedImportController::class, 'commit'])
        ->name('sis.import.commit');
    $router->map(['POST'], '/sis/rules/', [SisRulesController::class, 'save'])
        ->name('sis.rules.save');
    $router->map(['GET'], '/sis/rules/{id}/', [SisRulesController::class, 'get'])
        ->name('sis.rules.get');
    $router->map(['POST'], '/sis/rules/preview/', [SisRulesController::class, 'preview'])
        ->name('sis.rules.preview');
};
