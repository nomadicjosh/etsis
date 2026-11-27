<?php

declare(strict_types=1);

namespace Infrastructure\Security;

use Domain\User\Service\EmailVerificationPolicy;
use InvalidArgumentException;
use Qubus\Config\ConfigContainer;

final readonly class ConfiguredEmailVerificationPolicy implements EmailVerificationPolicy
{
    public function __construct(private ConfigContainer $config)
    {
    }

    public function requiredForApplications(): bool
    {
        $value = $this->config->getConfigKey('auth.application_email_verification_required', false);
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Email verification policy is unavailable.');
        }
        return $value;
    }

    public function deliveryEnabled(): bool
    {
        return $this->config->getConfigKey('sis.mail_enabled', false) === true;
    }

    public function origin(): string
    {
        $value = $this->config->string('app.url', '');
        $parts = parse_url($value);
        if (
            strlen($value) > 2048 || !is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            throw new InvalidArgumentException('Configure a canonical HTTPS application URL.');
        }
        return rtrim($value, '/');
    }
}
