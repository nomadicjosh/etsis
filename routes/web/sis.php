<?php

declare(strict_types=1);

use Application\Http\Controller\SisAdmissionsController;
use Application\Http\Controller\SisFinanceController;
use Application\Http\Controller\SisCatalogController;
use Qubus\Routing\Interfaces\Mappable;
use Qubus\Routing\Psr7Router;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
    $router->map(['POST'], '/sis/programs/', [SisCatalogController::class, 'program'])
        ->name('sis.programs.create');
    $router->map(['POST'], '/sis/terms/', [SisCatalogController::class, 'term'])
        ->name('sis.terms.create');
    $router->map(['POST'], '/sis/accounts/', [SisFinanceController::class, 'open'])
        ->name('sis.accounts.open');
    $router->map(['GET'], '/sis/accounts/{id}/', [SisFinanceController::class, 'show'])
        ->name('sis.accounts.show');
    $router->map(['POST'], '/sis/accounts/{id}/entries/', [SisFinanceController::class, 'post'])
        ->name('sis.accounts.post');
    $router->map(['POST'], '/sis/persons/', [SisAdmissionsController::class, 'createPerson'])
        ->name('sis.persons.create');
    $router->map(['POST'], '/sis/applications/', [SisAdmissionsController::class, 'apply'])
        ->name('sis.applications.create');
    $router->map(['POST'], '/sis/applications/{id}/decision/', [SisAdmissionsController::class, 'decide'])
        ->name('sis.applications.decide');
    $router->map(['POST'], '/sis/applications/{id}/matriculate/', [SisAdmissionsController::class, 'matriculate'])
        ->name('sis.applications.matriculate');
    $router->map(['GET'], '/sis/students/{id}/', [SisAdmissionsController::class, 'student'])
        ->name('sis.students.show');
    $router->map(['GET'], '/sis/persons/{id}/', [SisAdmissionsController::class, 'person'])
        ->name('sis.persons.show');
    $router->map(
        ['POST'],
        '/sis/persons/{id}/institutional-id/',
        [SisAdmissionsController::class, 'updateInstitutionalId'],
    )
        ->name('sis.persons.update_institutional_id');
    $router->map(['POST'], '/sis/persons/lookup/', [SisAdmissionsController::class, 'lookupPerson'])
        ->name('sis.persons.lookup');
};
