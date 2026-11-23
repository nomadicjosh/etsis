<?php

declare(strict_types=1);

namespace Application\Service;

use Codefy\Framework\Proxy\Codefy;
use Domain\Portal\Repository\PagePublicationRepository;
use Vihzhuo\Contracts\PageTranslationContract;
use Vihzhuo\Modules\Router\DatabasePageRouter;

/** Preserve saved routes; compare canonical paths consistently on both sides of native route matching. */
final class WebsitePageRouter extends DatabasePageRouter
{
    public function resolve(string $url): ?PageTranslationContract
    {
        $path = rtrim(explode('?', $url, 2)[0], '/') ?: '/';
        $mapping = [];
        foreach (Codefy::$PHP->make(PagePublicationRepository::class)->routes() as $row) {
            $route = rtrim($row['route'], '/') ?: '/';
            $mapping[$route][] = (string) $row['id'];
        }
        $routes = array_map(static fn (string $route): array => explode('/', $route), array_keys($mapping));
        foreach ($this->getRoutesInOrder($routes) as $segments) {
            $route = implode('/', $segments);
            // Ambiguous authoring never chooses a page by accidental database ordering.
            if (count($mapping[$route]) !== 1 || !$this->onRoute(explode('/', $path), $segments)) {
                continue;
            }
            $matched = $this->getMatchedPage($route, $mapping[$route][0]);
            if ($matched !== null) {
                global $phpb_route_parameters;
                $phpb_route_parameters = $this->routeParameters;
                return $matched;
            }
        }
        return null;
    }
}
