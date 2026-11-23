<?php

declare(strict_types=1);

namespace Domain\Portal\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Small typed trees, never executable markup, SQL or arbitrary component definitions. */
final readonly class NavigationTree
{
    public function __construct(public array $items, bool $publishing = false)
    {
        $count = 0;
        $ids = [];
        $this->validate($items, 1, $count, $ids, $publishing);
    }

    private function validate(array $items, int $depth, int &$count, array &$ids, bool $publishing): void
    {
        if (!array_is_list($items) || ($items !== [] && $depth > 3)) {
            self::invalid('Menus support three levels of navigation.');
        }
        foreach ($items as $item) {
            if (
                ++$count > 100 || !is_array($item) || count($item) !== 10
                || array_diff(array_keys($item), ['id', 'type', 'label', 'page_id', 'url', 'new_tab',
                    'icon', 'enabled', 'children', 'description']) !== []
                || !is_string($item['id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,50}$/D', $item['id']) !== 1
                || isset($ids[$item['id']])
                || !in_array($item['type'] ?? null, ['page', 'link', 'group', 'divider'], true)
                || !is_string($item['label'] ?? null) || !is_string($item['description'] ?? null)
                || !mb_check_encoding($item['label'] . $item['description'], 'UTF-8')
                || mb_strlen($item['label']) > 100 || mb_strlen($item['description']) > 200
                || preg_match('/[\x00-\x1F\x7F]/', $item['label'] . $item['description'])
                || !is_bool($item['enabled'] ?? null) || !is_bool($item['new_tab'] ?? null)
                || !is_string($item['icon'] ?? null)
                || preg_match('/^(?:[a-z0-9]+(?:-[a-z0-9]+)*)?$/D', $item['icon']) !== 1
                || strlen($item['icon']) > 50 || !is_array($item['children'] ?? null)
            ) {
                self::invalid('Use up to 100 uniquely identified menu items with valid labels and settings.');
            }
            $ids[$item['id']] = true;
            if ($item['type'] !== 'divider' && trim($item['label']) === '') {
                self::invalid('Every navigation link or group needs a label.');
            }
            if ($item['type'] === 'page') {
                if (
                    !is_string($item['page_id']) || preg_match('/^[a-zA-Z0-9_-]{1,191}$/D', $item['page_id']) !== 1
                    || $item['url'] !== null
                ) {
                    self::invalid('Choose a website page for this link.');
                }
            } elseif ($item['page_id'] !== null) {
                self::invalid('Use page references only for website page items.');
            }
            if ($item['type'] === 'link') {
                self::requireUrl($item['url']);
            } elseif ($item['url'] !== null) {
                self::invalid('Use URLs only for custom links.');
            }
            if ($item['type'] === 'divider' && ($depth === 1 || $item['children'] !== [])) {
                self::invalid('Place dividers inside a dropdown menu.');
            }
            if ($publishing && $item['enabled'] && $item['type'] === 'group' && $item['children'] === []) {
                self::invalid('Add links to each enabled dropdown group before publishing.');
            }
            $this->validate($item['children'], $depth + 1, $count, $ids, $publishing);
        }
    }

    public static function requireUrl(mixed $url): void
    {
        if (
            !is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)
            || !(preg_match('~^/(?!/)~D', $url) === 1
                || preg_match('/^#[a-zA-Z][a-zA-Z0-9_.:-]{0,190}$/D', $url) === 1
                || (filter_var($url, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                    && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null)
                || (str_starts_with($url, 'mailto:') && filter_var(substr($url, 7), FILTER_VALIDATE_EMAIL) !== false)
                || preg_match('~^tel:\+?[0-9().-]+$~D', $url) === 1)
        ) {
            self::invalid('Use a local path, an HTTP(S) address, an email link or a telephone link.');
        }
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([['field' => 'items', 'message' => $message]]);
    }
}
