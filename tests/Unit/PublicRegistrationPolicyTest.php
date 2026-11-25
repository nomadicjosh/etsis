<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\User\ValueObject\PublicRegistrationPolicy;
use Infrastructure\Security\ConfiguredPublicRegistrationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Config\Collection;

final class PublicRegistrationPolicyTest extends TestCase
{
    public function testDefaultPolicyIsOpenAndDomainRulesAreExactAndNormalized(): void
    {
        $policy = PublicRegistrationPolicy::from([]);
        self::assertTrue($policy->enabled);
        self::assertSame([], $policy->emailDomains);
        self::assertTrue($policy->permits('fictional@example.invalid'));
        $policy = PublicRegistrationPolicy::from(['email_domains' => [' Institution.Example ', 'alumni.example']]);
        self::assertSame(['alumni.example', 'institution.example'], $policy->emailDomains);
        self::assertTrue($policy->permits('student@INSTITUTION.EXAMPLE'));
        self::assertTrue($policy->permits('student+application@alumni.example'));
        self::assertFalse(PublicRegistrationPolicy::from(['enabled' => false])->permits('student@institution.example'));
    }

    public static function disallowedEmails(): array
    {
        return array_map(static fn(mixed $email): array => [$email], [
            null, [], 123, '', 'student@sub.institution.example', 'student@institution.example.evil.invalid',
            'student@evil-institution.example', 'institution.example@evil.invalid', 'student@institution.example.',
            'student@institution.example@evil.invalid', 'Name <student@institution.example>',
            "student@institution.example\r\nPRIVATE header", 'student@іnstitution.example',
            'student@institution.example ', 'student@[192.0.2.1]', str_repeat('x', 180) . '@institution.example',
        ]);
    }

    #[DataProvider('disallowedEmails')]
    public function testRestrictedPolicyRejectsInvalidOrUnlistedAddresses(mixed $email): void
    {
        $policy = PublicRegistrationPolicy::from(['email_domains' => ['institution.example']]);
        self::assertFalse($policy->permits($email));
    }

    public static function malformedPolicies(): array
    {
        return array_map(static fn(mixed $settings): array => [$settings], [
            null, 'PRIVATE', ['enabled' => null], ['enabled' => 'false'], ['enabled' => 1],
            ['email_domains' => null], ['email_domains' => 'institution.example'], ['unknown' => true],
            ['email_domains' => ['institution.example' => true]], ['email_domains' => [null]],
            ['email_domains' => ['*.institution.example']], ['email_domains' => ['@institution.example']],
            ['email_domains' => ['https://institution.example']], ['email_domains' => ['school']],
            ['email_domains' => ['institution.example', 'INSTITUTION.EXAMPLE']],
            ['email_domains' => ['192.0.2.1']], ['email_domains' => [str_repeat('x', 190) . '.example']],
            ['email_domains' => array_map(static fn(int $i): string => 'school' . $i . '.example', range(1, 21))],
        ]);
    }

    #[DataProvider('malformedPolicies')]
    public function testMalformedConfigurationNeverFallsBackToOpenRegistration(mixed $settings): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PublicRegistrationPolicy::from($settings);
    }

    public function testConfiguredPolicyReadsCurrentSettingsAndPermitsExplicitInternationalizedDnsNames(): void
    {
        $config = Collection::factory([]);
        $provider = new ConfiguredPublicRegistrationPolicy($config);
        self::assertTrue($provider->current()->enabled);
        $config->setConfigKey('registration', ['enabled' => false]);
        self::assertFalse($provider->current()->enabled);
        $config->setConfigKey('registration', ['enabled' => true, 'email_domains' => ['xn--bcher-kva.example']]);
        self::assertTrue($provider->current()->permits('fictional@xn--bcher-kva.example'));
        $config->setConfigKey('registration', ['enabled' => null]);
        $this->expectException(\InvalidArgumentException::class);
        $provider->current();
    }
}
