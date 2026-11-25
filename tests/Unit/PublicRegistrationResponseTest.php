<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PublicRegistrationResponseTest extends TestCase
{
    public static function outcomes(): array
    {
        $cases = [];
        foreach (['closed' => 403, 'domain' => 422, 'unavailable' => 503] as $reason => $status) {
            foreach (['html', 'json'] as $format) {
                $cases[$reason . ' ' . $format] = [$reason, $status, $format];
            }
        }
        return $cases;
    }

    private function response(string $mode, string $format): array
    {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'zend.exception_ignore_args=1',
            dirname(__DIR__) . '/Support/RegistrationPauseFixture.php', $mode, $format]);
        $process->setTimeout(5);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        return [json_decode($process->getErrorOutput(), true, flags: JSON_THROW_ON_ERROR), $process->getOutput()];
    }

    #[DataProvider('outcomes')]
    public function testPolicyRefusalsArePrivateAndNeverEchoAccountDetails(
        string $reason,
        int $status,
        string $format,
    ): void {
        [$metadata, $body] = $this->response('policy-' . $reason, $format);
        self::assertSame($status, $metadata['status']);
        $headers = array_change_key_case($metadata['headers']);
        self::assertStringContainsString('private, no-store', $headers['cache-control']);
        self::assertSame('no-referrer', $headers['referrer-policy']);
        self::assertStringContainsString("script-src 'none'", $headers['content-security-policy']);
        self::assertSame($status === 503 ? '5' : null, $headers['retry-after'] ?? null);
        $privateValues = ['PRIVATE', 'fictional-private', 'Fictional-private-password', '192.0.2.10', 'fictional-key'];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, $body);
        }
        if ($format === 'json') {
            $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['error', 'code'], array_keys($data));
            self::assertSame(match ($reason) {
                'closed' => 'public_registration_closed', 'domain' => 'registration_email_domain',
                default => 'public_registration_unavailable',
            }, $data['code']);
            return;
        }
        self::assertDoesNotMatchRegularExpression('/<(?:script|form|input)\b/i', $body);
        self::assertStringContainsString('/staff-access/?continue=apply&amp;program=', $body);
        self::assertSame($reason !== 'closed', str_contains($body, 'Return to account creation'));
    }

    public function testPausedCounterRecoveryPreservesOnlyValidatedPublicApplicationHints(): void
    {
        [$metadata, $body] = $this->response('target', 'html');
        self::assertSame(429, $metadata['status']);
        preg_match_all('/href="([^"]+)"/', $body, $matches);
        $links = array_map(static fn(string $link): string => html_entity_decode($link, ENT_QUOTES), $matches[1]);
        $login = array_find($links, static fn(string $link): bool => str_starts_with($link, '/staff-access/'));
        $register = array_find($links, static fn(string $link): bool => str_starts_with($link, '/register/'));
        parse_str(parse_url($login, PHP_URL_QUERY), $query);
        self::assertSame(['continue' => 'apply', 'program' => '01K00000000000000000000001',
            'term' => '01K00000000000000000000002'], $query);
        parse_str(parse_url($register, PHP_URL_QUERY), $query);
        self::assertSame(['program' => '01K00000000000000000000001', 'term' => '01K00000000000000000000002'], $query);
        self::assertStringNotContainsString('PRIVATE', $body);
        self::assertStringNotContainsString('fictional-private', $body);
    }
}
