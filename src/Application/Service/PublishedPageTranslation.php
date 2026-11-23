<?php

declare(strict_types=1);

namespace Application\Service;

use Vihzhuo\Contracts\PageContract;
use Vihzhuo\PageTranslation;

/** Rendering uses the approved snapshot already checked by the public reader. */
final class PublishedPageTranslation extends PageTranslation
{
    public function __construct(private readonly PageContract $page, array $translation)
    {
        $this->setData($translation);
    }

    public function getPage(): ?PageContract
    {
        return $this->page;
    }
}
