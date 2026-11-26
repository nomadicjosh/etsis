<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

/** Immutable provider/destination; only the credential, display label and enabled flag may change. */
final readonly class NewsletterConnection
{
    public string $targetHash;
    public string $url;
    public string $host;

    public function __construct(
        public EntityId $id,
        public string $provider,
        #[\SensitiveParameter] string $url,
        public string $list,
        #[\SensitiveParameter] public string $secret,
        #[\SensitiveParameter] public string $username = '',
    ) {
        $parts = parse_url($url);
        if (
            !in_array($provider, ['sendy', 'mailchimp', 'mautic', 'webhook'], true)
            || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($url))
            || $parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$/D', $parts['host'])
            || !preg_match('/^[a-zA-Z0-9_-]{1,120}$/D', $list)
            || $secret === '' || strlen($secret) > 4096 || !mb_check_encoding($secret, 'UTF-8')
            || ($provider === 'webhook' && strlen($secret) < 32) || preg_match($provider === 'mautic'
                && $username !== '' ? '/[\x00-\x1f\x7f]/' : '/[\x00-\x20\x7f]/', $secret)
            || strlen($username) > 191 || preg_match('/[\x00-\x1f\x7f:]/', $username)
        ) {
            self::invalid();
        }
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            if (in_array(rawurldecode($segment), ['.', '..'], true)) {
                self::invalid();
            }
        }
        $this->host = strtolower($parts['host']);
        if (
            ($provider === 'mailchimp' && (!preg_match('/^us[0-9]+\.api\.mailchimp\.com$/D', $this->host)
            || rtrim($parts['path'] ?? '', '/') !== ''))
            || ($provider === 'mautic' && preg_match('/^[1-9][0-9]{0,9}$/D', $list) !== 1)
        ) {
            self::invalid();
        }
        $this->url = $provider === 'webhook' ? $url : rtrim($url, '/');
        $this->targetHash = hash('sha256', json_encode([$provider, $this->url, $list], JSON_THROW_ON_ERROR));
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'connection', 'message' => trans_html(
            'Choose a supported provider, HTTPS destination, list reference and valid server credentials.'
        )]]);
    }
}
