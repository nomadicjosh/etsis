<?php

declare(strict_types=1);

use Application\Http\Controller\SisAccessController;
use Qubus\Routing\Interfaces\Mappable;
use Qubus\Routing\Psr7Router;

return static function (Psr7Router $router): void {
    /** @var Psr7Router&Mappable $router */
    $router->map(['POST'], '/sis/access/directory/{kind}/', [SisAccessController::class, 'directory'])
        ->name('sis.access.directory');
    foreach (['roles' => 'role', 'permissions' => 'permission'] as $plural => $singular) {
        $router->map(['GET', 'POST'], '/sis/access/' . $plural . '/', [SisAccessController::class, $plural])
            ->name('sis.access.' . $plural);
        $router->map(['PATCH', 'DELETE'], '/sis/access/' . $plural . '/{id}/', [SisAccessController::class, $singular])
            ->name('sis.access.' . $singular);
    }
    foreach (
        [
        'roles/{id}/permissions' => ['rolePermissions', 'rolePermission'],
        'users/{id}/roles' => ['userRoles', 'userRole'],
        'users/{id}/permissions' => ['userPermissions', 'userPermission'],
        ] as $path => [$listing, $assignment]
    ) {
        $router->map(['GET'], '/sis/access/' . $path . '/', [SisAccessController::class, $listing])
            ->name('sis.access.' . $listing);
        $router->map(
            ['PUT', 'DELETE'],
            '/sis/access/' . $path . '/{target}/',
            [SisAccessController::class, $assignment],
        )
            ->name('sis.access.' . $assignment);
    }
};
