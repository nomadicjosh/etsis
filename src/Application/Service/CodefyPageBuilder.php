<?php

declare(strict_types=1);

namespace Application\Service;

use Vihzhuo\Vihzhuo;
use Vihzhuo\Contracts\PageTranslationContract;
use Vihzhuo\Page;
use Domain\Portal\Service\PagePublicationService;
use Codefy\Framework\Proxy\Codefy;

final class CodefyPageBuilder extends Vihzhuo
{
    public function __construct(?array $config = null)
    {
        $config ??= [];
        if ($config !== []) {
            // A cached document must never bypass current approval, draft or visibility-window checks.
            $config['cache']['enabled'] = false;
        }
        parent::__construct($config);
    }

    protected function resolvePageLanguageVariantFromUrl(string $url): ?PageTranslationContract
    {
        $translation = parent::resolvePageLanguageVariantFromUrl($url);
        if ($translation === null) {
            return null;
        }
        $source = Codefy::$PHP->make(PagePublicationService::class)->visible($translation->getPageId());
        if ($source === null) {
            return null;
        }
        foreach ($source['translations'] as $variant) {
            if (
                $variant['id'] === $translation->getId() && $variant['route'] === $translation->getRoute()
                && $variant['locale'] === $translation->getLocale()
            ) {
                $page = new Page();
                $page->setData($source['page']);
                $page->setTranslations(array_column($source['translations'], null, 'locale'));
                return new PublishedPageTranslation($page, $variant);
            }
        }
        return null;
    }
}
