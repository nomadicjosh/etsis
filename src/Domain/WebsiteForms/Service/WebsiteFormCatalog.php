<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

use Domain\Portal\Service\PagePublicationService;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;

use function Codefy\Framework\Helpers\trans_html;

final readonly class WebsiteFormCatalog
{
    public function __construct(private PagePublicationService $pages)
    {
    }

    public function resolve(string $page, string $key, string $locale): array
    {
        $source = $this->pages->visible($page) ?? throw new RecordNotFoundException();
        if (!in_array($locale, array_column($source['translations'], 'locale'), true)) {
            throw new RecordNotFoundException();
        }
        $matches = self::definitions($source, $locale);
        if (!isset($matches[$key])) {
            throw new RecordNotFoundException();
        }
        return ['definition' => $matches[$key], 'page_title' => $source['page']['name'],
            'source_hash' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR))];
    }
    /** Validate every configured form before publication; no current definition fills missing saved facts. */
    public static function definitions(array $source, string $locale): array
    {
        if (($source['page']['data'] ?? null) === null || $source['page']['data'] === '') {
            return [];
        }
        try {
            $data = json_decode($source['page']['data'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }
        if (!is_array($data)) {
            self::invalid();
        }
        $blocks = $data['blocks'][$locale] ?? [];
        $matches = [];
        $visited = 0;
        $walk = static function (mixed $items, int $depth = 0) use (&$walk, &$matches, &$visited): void {
            if (!is_array($items) || $depth > 25) {
                throw new \Domain\Shared\Exception\ValidationException([['field' => 'forms',
                    'message' =>
                        trans_html('This page exceeds the supported website-form nesting limit.')]]);
            }
            foreach ($items as $item) {
                if (++$visited > 500 || !is_array($item)) {
                    throw new RecordNotFoundException();
                }
                if (($item['slug'] ?? null) === 'regular-form') {
                    $encoded = $item['settings']['attributes']['website-definition'] ?? null;
                    if (!is_string($encoded) || strlen($encoded) > 65536) {
                        throw new \Domain\Shared\Exception\ValidationException([['field' => 'forms',
                            'message' =>
                                trans_html('Configure each website form before publishing this page.')]]);
                    }
                    try {
                        $facts = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        self::invalid();
                    }
                    if (!is_array($facts)) {
                        self::invalid();
                    }
                    $form = new WebsiteFormDefinition($facts);
                    if (isset($matches[$form->facts['key']]) || count($matches) >= 20) {
                        throw new \Domain\Shared\Exception\ValidationException([['field' => 'forms',
                            'message' => trans_html('Use up to twenty website '
                                . 'forms with unique keys on each translated page.')]]);
                    }
                    $matches[$form->facts['key']] = $form;
                }
                if (isset($item['blocks'])) {
                    $walk($item['blocks'], $depth + 1);
                }
            }
        };
        $walk($blocks);
        return $matches;
    }
    private static function invalid(): never
    {
        throw new \Domain\Shared\Exception\ValidationException([['field' => 'forms',
            'message' => trans_html('Configure each website form before publishing this page.')]]);
    }
}
