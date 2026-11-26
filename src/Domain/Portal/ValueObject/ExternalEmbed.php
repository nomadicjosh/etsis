<?php

declare(strict_types=1);

namespace Domain\Portal\ValueObject;

/** Approved external destinations only; constructing this value never contacts a provider. */
final readonly class ExternalEmbed
{
    private function __construct(public string $source, public string $link)
    {
    }

    public static function youtube(string $url): ?self
    {
        $parts = self::parts($url);
        if (
            $parts === null
            || !in_array($parts['host'], ['youtube.com', 'www.youtube.com', 'www.youtube-nocookie.com'], true)
            || preg_match('~\A/embed/([A-Za-z0-9_-]{11})\z~D', $parts['path'], $match) !== 1
        ) {
            return null;
        }
        $query = self::query($parts);
        if ($query === null) {
            return null;
        }
        $parameters = ['autoplay' => '0', 'controls' => '1', 'disablekb' => '0', 'playsinline' => '1',
            'cc_load_policy' => '1', 'rel' => '0'];
        foreach (['start', 'end'] as $key) {
            if (!isset($query[$key])) {
                continue;
            }
            if (preg_match('/\A[0-9]{1,5}\z/D', $query[$key]) !== 1 || (int) $query[$key] > 86400) {
                return null;
            }
            $parameters[$key] = (string) (int) $query[$key];
        }
        if (isset($parameters['end']) && (int) $parameters['end'] <= (int) ($parameters['start'] ?? 0)) {
            return null;
        }
        $link = 'https://www.youtube.com/watch?v=' . $match[1];
        if (isset($parameters['start'])) {
            $link .= '&t=' . $parameters['start'];
        }
        return new self(
            'https://www.youtube-nocookie.com/embed/' . $match[1] . '?'
                . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            $link
        );
    }

    public static function map(string $url, string $address = ''): ?self
    {
        $parts = self::parts($url);
        if ($parts === null || $parts['host'] !== 'www.google.com') {
            return null;
        }
        $query = self::query($parts);
        if ($query === null) {
            return null;
        }
        if ($parts['path'] === '/maps/embed') {
            if (empty($query['pb'])) {
                return null;
            }
            $query = ['pb' => $query['pb']];
        } elseif (
            preg_match('~\A/maps/embed/v1/(place|view|directions|streetview|search)\z~D', $parts['path']) !== 1
            || empty($query['key'])
        ) {
            return null;
        }
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $source = 'https://www.google.com' . $parts['path'] . '?' . $encoded;
        $address = trim($address);
        $link = $source;
        if ($address !== '') {
            $query = http_build_query(['api' => '1', 'query' => $address], '', '&', PHP_QUERY_RFC3986);
            $link = 'https://www.google.com/maps/search/?' . $query;
        }
        return new self($source, $link);
    }

    /** @return array{host: string, path: string, query?: string}|null */
    private static function parts(string $url): ?array
    {
        if (
            strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) !== 0
            || preg_match('/[\x00-\x1f\x7f]/', rawurldecode($url)) !== 0
        ) {
            return null;
        }
        $parts = parse_url($url);
        if (
            $parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'], $parts['path'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
        ) {
            return null;
        }
        return $parts;
    }

    /**
     * @param array{query?: string} $parts
     * @return array<string, string>|null
     */
    private static function query(array $parts): ?array
    {
        parse_str($parts['query'] ?? '', $query);
        if (count($query) > 30) {
            return null;
        }
        if (array_any($query, fn($value) => !is_string($value))) {
            return null;
        }
        return $query;
    }
}
