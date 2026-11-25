<?php

declare(strict_types=1);

namespace Domain\User\ValueObject;

use InvalidArgumentException;

/** Eligibility for public signup, never email verification, identity matching or an access grant. */
final readonly class PublicRegistrationPolicy
{
    private function __construct(public bool $enabled, public array $emailDomains)
    {
    }

    public static function from(#[\SensitiveParameter] mixed $settings): self
    {
        if (!is_array($settings) || array_diff(array_keys($settings), ['enabled', 'email_domains']) !== []) {
            throw new InvalidArgumentException('Public registration settings are unavailable.');
        }
        $enabled = array_key_exists('enabled', $settings) ? $settings['enabled'] : true;
        $domains = array_key_exists('email_domains', $settings) ? $settings['email_domains'] : [];
        if (!is_bool($enabled) || !is_array($domains) || !array_is_list($domains) || count($domains) > 20) {
            throw new InvalidArgumentException('Use bounded public registration settings.');
        }
        $normalized = [];
        foreach ($domains as $domain) {
            if (!is_string($domain)) {
                throw new InvalidArgumentException('Use exact email domains.');
            }
            $domain = strtolower(trim($domain));
            if (
                strlen($domain) > 189 || !str_contains($domain, '.')
                || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || filter_var('x@' . $domain, FILTER_VALIDATE_EMAIL) === false
                || filter_var($domain, FILTER_VALIDATE_IP) !== false
                || isset($normalized[$domain])
            ) {
                throw new InvalidArgumentException('Use distinct exact email domains.');
            }
            $normalized[$domain] = true;
        }
        $domains = array_keys($normalized);
        sort($domains, SORT_STRING);
        return new self($enabled, $domains);
    }

    public function permits(#[\SensitiveParameter] mixed $email): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->emailDomains === []) {
            // Ordinary account validation still owns email validity when no domain restriction is configured.
            return true;
        }
        if (!is_string($email) || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        return in_array($domain, $this->emailDomains, true);
    }
}
