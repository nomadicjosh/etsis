<?php

declare(strict_types=1);

namespace Application\Provider;

use Codefy\Framework\Support\CodefyServiceProvider;
use Qubus\Routing\Psr7Router;
use Qubus\Routing\Interfaces\Mappable;

final class ApiRouteServiceProvider extends CodefyServiceProvider
{
    public function boot(): void
    {
        /** @var Psr7Router&Mappable $router */
        $router = $this->codefy->make(name: Psr7Router::class);
    }
}
