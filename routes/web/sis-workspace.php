<?php

declare(strict_types=1);

use Application\Http\Controller\PersonAddressController as Addresses;
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
use Qubus\Routing\Interfaces\Mappable;
use Application\Http\Controller\PersonEmergencyContactsController as Emergency;
use Application\Http\Controller\PersonEducationController as Education;
use Application\Http\Controller\AdmissionCaseworkController as Casework;
use Application\Http\Controller\AdmissionDocumentController as Documents;
use Qubus\Routing\Psr7Router;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
    $website = \Application\Http\Controller\WebsitePublicationController::class;
    $router->get('/sis/website/pages/', [$website, 'pages']);
    $router->get('/sis/website/pages/{id}/', [$website, 'page']);
    $router->post('/sis/website/pages/{id}/review/', [$website, 'reviewPage']);
    $router->post('/sis/website/pages/{id}/confirm/', [$website, 'confirmPage']);
    $router->get('/sis/website/menus/{slot}/', [$website, 'menu']);
    $router->post('/sis/website/menus/{slot}/', [$website, 'saveMenu']);
    $router->post('/sis/website/menus/{slot}/review/', [$website, 'reviewMenu']);
    $router->post('/sis/website/menus/{slot}/publish/', [$website, 'publishMenu']);
    $credits = \Application\Http\Controller\SisCreditPolicyController::class;
    $router->get('/sis/ui/catalog/{kind}/{id}/credits/', [$credits, 'show']);
    $router->post('/sis/ui/catalog/{kind}/{id}/credits/', [$credits, 'save']);
    $profileRequests = \Application\Http\Controller\ProfileUpdateRequestController::class;
    $router->get('/sis/ui/people/{id}/update-requests/', [$profileRequests, 'staff']);
    $router->get('/sis/ui/people/{id}/update-requests/identity/', [$profileRequests, 'staffIdentity']);
    $router->post('/sis/ui/people/{id}/update-requests/', [$profileRequests, 'create']);
    $router->post('/sis/ui/people/{id}/update-requests/{requestId}/resolve/', [$profileRequests, 'resolve']);
    $router->map(['GET'], '/sis/applications/{application}/documents/', [Documents::class, 'listing']);
    $router->map(['POST'], '/sis/applications/{application}/documents/', [Documents::class, 'upload']);
    $holds = \Application\Http\Controller\AdmissionDocumentHoldController::class;
    $router->get('/sis/applications/{application}/document-holds/', [$holds, 'history']);
    $router->post('/sis/applications/{application}/document-holds/review/', [$holds, 'review']);
    $router->post('/sis/applications/{application}/document-holds/confirm/', [$holds, 'confirm']);
    $retention = \Application\Http\Controller\AdmissionRetentionController::class;
    $router->get('/sis/document-retention/', [$retention, 'configuration']);
    $router->get('/sis/document-retention/history/', [$retention, 'policies']);
    $router->post('/sis/document-retention/review/', [$retention, 'reviewPolicy']);
    $router->post('/sis/document-retention/confirm/', [$retention, 'publishPolicy']);
    $router->get('/sis/applications/{application}/documents/{id}/category/', [$retention, 'history']);
    $router->post(
        '/sis/applications/{application}/documents/{id}/category/review/',
        [$retention, 'reviewClassification']
    );
    $router->post('/sis/applications/{application}/documents/{id}/category/confirm/', [$retention, 'classify']);
    $router->map(['GET'], '/sis/applications/{application}/documents/{id}/download/', [Documents::class, 'download']);
    $router->map(['POST'], '/sis/applications/{application}/documents/{id}/rescan/', [Documents::class, 'rescan']);
    $router->map(['POST'], '/sis/applications/{application}/documents/{id}/retain/', [Documents::class, 'retain']);
    $router->map(
        ['POST'],
        '/sis/applications/{application}/documents/{id}/purge-review/',
        [Documents::class, 'reviewPurge']
    );
    $router->map(['POST'], '/sis/applications/{application}/documents/{id}/purge/', [Documents::class, 'purge']);

    $router->map(['GET'], '/sis/applications/{id}/casework/', [Casework::class, 'view'])
        ->name('sis.admissions.casework');
    $router->map(['POST'], '/sis/applications/{id}/casework/', [Casework::class, 'save'])
        ->name('sis.admissions.casework.save');
    $router->map(['GET'], '/sis/admissions/follow-ups/', [Casework::class, 'queue'])
        ->name('sis.admissions.followups');
    $router->map(['GET'], '/sis/admissions/assignees/', [Casework::class, 'assignees'])
        ->name('sis.admissions.assignees');
    $router->map(['GET'], '/sis/admissions/checklists/', [Casework::class, 'presets'])
        ->name('sis.admissions.checklists');
    $router->map(['GET'], '/sis/admissions/checklists/{id}/', [Casework::class, 'preset'])
        ->name('sis.admissions.checklist');
    $router->map(['POST'], '/sis/admissions/checklists/{id}/', [Casework::class, 'savePreset'])
        ->name('sis.admissions.checklist.save');
    $router->map(['GET'], '/sis/ui/people/{id}/education/', [Education::class, 'staff'])
        ->name('sis.ui.person.education');
    $router->map(['POST'], '/sis/ui/people/{id}/education/', [Education::class, 'saveStaff'])
        ->name('sis.ui.person.education.save');
    $router->map(['POST'], '/sis/ui/people/{id}/education/review/', [Education::class, 'review'])
        ->name('sis.ui.person.education.review');
    $router->map(['GET'], '/sis/ui/people/{id}/addresses/', [Addresses::class, 'staff'])
        ->name('sis.ui.person.addresses');
    $router->map(['POST'], '/sis/ui/people/{id}/addresses/', [Addresses::class, 'saveStaff'])
        ->name('sis.ui.person.addresses.save');
    $router->map(['GET'], '/sis/ui/people/{id}/emergency-contacts/', [Emergency::class, 'staff'])
        ->name('sis.ui.person.emergency');
    $router->map(['POST'], '/sis/ui/people/{id}/emergency-contacts/', [Emergency::class, 'saveStaff'])
        ->name('sis.ui.person.emergency.save');

    $router->map(['GET'], '/sis/ui/people/{id}/profile/', [SisPersonEditorController::class, 'profile'])
        ->name('sis.ui.person.profile');
    $router->map(['POST'], '/sis/ui/people/{id}/profile/', [SisPersonEditorController::class, 'saveProfile'])
        ->name('sis.ui.person.profile.save');
    $router->map(['POST'], '/sis/ui/people/{id}/history/', [SisPersonEditorController::class, 'history'])
        ->name('sis.ui.person.history');
    $router->map(['GET'], '/sis/ui/people/{id}/contact/', [SisPersonEditorController::class, 'contact'])
        ->name('sis.ui.person.contact');
    $router->map(['POST'], '/sis/ui/people/{id}/contact/', [SisPersonEditorController::class, 'saveContact'])
        ->name('sis.ui.person.contact.save');

    $router->map(
        ['GET'],
        '/sis/ui/people/{id}/password-setup/',
        [\Application\Http\Controller\AccountSetupController::class,
        'status']
    )->name('sis.person.setup.status');
    $router->map(
        ['POST'],
        '/sis/ui/people/{id}/password-setup/',
        [\Application\Http\Controller\AccountSetupController::class,
        'reissue']
    )->name('sis.person.setup.reissue')->middleware(['rate.limiter']);
    $router->map(['GET'], '/sis/ui/people/{id}/account/', [SisPersonEditorController::class, 'account'])
        ->name('sis.ui.person.account');
    $router->map(['POST'], '/sis/ui/people/{id}/account/sign-in/', [SisPersonEditorController::class, 'signIn'])
        ->name('sis.ui.person.account.signin');
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
    $router->map(['GET'], '/sis/forms/academic-levels/', [SisFormBuilderController::class, 'levels'])
        ->name('sis.forms.levels');
    $router->map(['GET'], '/sis/forms/{id}/', [SisFormBuilderController::class, 'show'])
        ->name('sis.forms.show');
    $router->map(['POST'], '/sis/forms/{id}/publish/', [SisFormBuilderController::class, 'publish'])
        ->name('sis.forms.publish');
    $router->map(['POST'], '/sis/forms/programs/{id}/disable/', [SisFormBuilderController::class, 'disable'])
        ->name('sis.forms.disable');
    $router->map(
        ['POST'],
        '/sis/forms/academic-levels/{id}/disable/',
        [SisFormBuilderController::class, 'disableLevel']
    )
        ->name('sis.forms.level.disable');
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
    $router->map(
        ['POST'],
        '/sis/course-rules/page/',
        [\Application\Http\Controller\SisCourseRuleController::class,
        'page']
    )
        ->name('sis.course-rules.page');
    $router->map(
        ['GET'],
        '/sis/course-rules/{id}/',
        [\Application\Http\Controller\SisCourseRuleController::class,
        'get']
    )
        ->name('sis.course-rules.get');
    $router->map(
        ['POST'],
        '/sis/course-rules/{id}/revisions/',
        [\Application\Http\Controller\SisCourseRuleController::class,
        'versions']
    )
        ->name('sis.course-rules.versions');
    $router->map(
        ['POST'],
        '/sis/course-rules/save/',
        [\Application\Http\Controller\SisCourseRuleController::class,
        'save']
    )
        ->name('sis.course-rules.save');
    $router->map(
        ['POST'],
        '/sis/course-rules/preview/',
        [\Application\Http\Controller\SisCourseRuleController::class,
        'preview']
    )
        ->name('sis.course-rules.preview');
    $router->map(['GET'], '/sis/rules/{id}/', [SisRulesController::class, 'get'])
        ->name('sis.rules.get');
    $router->map(['POST'], '/sis/rules/preview/', [SisRulesController::class, 'preview'])
        ->name('sis.rules.preview');
};
