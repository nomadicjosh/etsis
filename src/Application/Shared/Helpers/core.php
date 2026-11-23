<?php

declare(strict_types=1);

namespace Application\Shared\Helpers;

use Qubus\Exception\Data\TypeException;
use Codefy\Framework\Proxy\Codefy;
use Domain\Portal\Service\PagePublicationService;
use Domain\Portal\Service\NavigationService;

use function Qubus\Support\Helpers\collect;
use function Qubus\Support\Helpers\is_null__;

/**
 * @param string|null $type Returns links by specified type
 *                          or all if type is not specified.
 * @throws TypeException
 * @return array<mixed>
 */
function nav_links(?string $type = null): array
{
    $navLinks = collect(Codefy::$PHP->make(PagePublicationService::class)->navigation())
        ->where(key: 'show_in_nav', operator: '=', value: 'yes')
        ->sort(function ($item1, $item2) {
            return $item1['nav_position'] <=> $item2['nav_position'];
        });

    if (!is_null__($type)) {
        $navLinks = $navLinks->where(key: 'nav_type', operator: '=', value: $type);
    }

    return $navLinks->all();
}

function menu_items(string $slot): array
{
    return Codefy::$PHP->make(NavigationService::class)->publicItems($slot);
}
