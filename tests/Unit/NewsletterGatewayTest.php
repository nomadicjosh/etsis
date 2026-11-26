<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Service\NewsletterPolicy;
use Domain\WebsiteForms\ValueObject\NewsletterConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Infrastructure\WebsiteForms\RestNewsletterGateway;
use PHPUnit\Framework\TestCase;

final class NewsletterGatewayTest extends TestCase
{
    private array $history = [];
    private array $contact = ['email' => 'fictional@example.invalid', 'name' => 'Fictional Alex',
        'consented_at' => '2026-11-26T12:00:00Z', 'consent_label' => 'Receive fictional newsletters'];

    private function gateway(array $responses): RestNewsletterGateway
    {
        $policy = new class implements NewsletterPolicy {
            public function enabled(): bool
            {
                return true;
            }
            public function permitsHost(string $host): bool
            {
                return true;
            }
        };
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new RestNewsletterGateway($policy, new Client(['handler' => $stack]));
    }

    private function connection(string $provider): NewsletterConnection
    {
        return new NewsletterConnection(
            new EntityId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
            $provider,
            $provider === 'mailchimp' ? 'https://us1.api.mailchimp.com' : 'https://newsletter.example.invalid',
            $provider === 'mautic' ? '7' : 'fictional_list',
            'fictional-secret-never-live-32-characters',
            $provider === 'mautic' ? 'fictional-api-user' : ''
        );
    }

    private function operation(): EntityId
    {
        return new EntityId('01ARZ3NDEKTSV4RRFFQ69G5FAW');
    }

    public function testMailchimpUsesDeterministicMemberPutAndPreservesAnExistingSuppression(): void
    {
        $gateway = $this->gateway([
            new Response(200, [], json_encode(['email_address' => $this->contact['email'],
                'list_id' => 'fictional_list', 'status' => 'pending'])),
            new Response(200, [], json_encode(['email_address' => $this->contact['email'],
                'list_id' => 'fictional_list', 'status' => 'unsubscribed'])),
        ]);
        self::assertSame('pending', $gateway->subscribe(
            $this->connection('mailchimp'),
            $this->contact,
            $this->operation()
        ));
        self::assertSame('suppressed', $gateway->subscribe(
            $this->connection('mailchimp'),
            $this->contact,
            $this->operation()
        ));
        foreach ($this->history as $call) {
            self::assertSame('PUT', $call['request']->getMethod());
            self::assertStringEndsWith(md5($this->contact['email']), $call['request']->getUri()->getPath());
            $body = json_decode((string) $call['request']->getBody(), true);
            self::assertSame('pending', $body['status_if_new']);
            self::assertArrayNotHasKey('status', $body);
            self::assertSame(false, $call['options']['allow_redirects']);
            self::assertSame(false, $call['options']['debug']);
            self::assertSame(true, $call['options']['verify']);
            self::assertSame(3, $call['options']['timeout']);
        }
    }

    public function testSendyRespectsTheListOptInAndChecksStatusBeforePosting(): void
    {
        $gateway = $this->gateway([new Response(200, [], 'Email does not exist in list'), new Response(200, [], 'true'),
            new Response(200, [], 'Unconfirmed'), new Response(200, [], 'Unsubscribed')]);
        self::assertSame('accepted', $gateway->subscribe(
            $this->connection('sendy'),
            $this->contact,
            $this->operation()
        ));
        self::assertSame('pending', $gateway->subscribe(
            $this->connection('sendy'),
            $this->contact,
            $this->operation()
        ));
        self::assertSame('suppressed', $gateway->subscribe(
            $this->connection('sendy'),
            $this->contact,
            $this->operation()
        ));
        self::assertCount(4, $this->history);
        parse_str((string) $this->history[1]['request']->getBody(), $body);
        self::assertSame('false', $body['silent']);
        self::assertSame('fictional_list', $body['list']);
        self::assertArrayNotHasKey('gdpr', $body);
        self::assertArrayNotHasKey('ipaddress', $body);
    }

    public function testMauticCreatesThenAddsToTheSelectedSegmentWithoutLeakingTheMessage(): void
    {
        $contact = ['id' => 8, 'fields' => ['all' => ['email' => $this->contact['email']]], 'doNotContact' => []];
        $gateway = $this->gateway([new Response(200, [], '{"total":0,"contacts":[]}'),
            new Response(201, [], json_encode(['contact' => $contact])), new Response(200, [], '{"success":1}')]);
        self::assertSame('accepted', $gateway->subscribe(
            $this->connection('mautic'),
            $this->contact,
            $this->operation()
        ));
        self::assertSame('/api/contacts', $this->history[0]['request']->getUri()->getPath());
        self::assertSame('/api/contacts/new', $this->history[1]['request']->getUri()->getPath());
        self::assertSame('/api/segments/7/contact/8/add', $this->history[2]['request']->getUri()->getPath());
        self::assertStringNotContainsString('consent_label', (string) $this->history[1]['request']->getBody());
        self::assertStringStartsWith('Basic ', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testMauticSuppressedAndAmbiguousContactsAreNotAddedOrDuplicated(): void
    {
        $contact = ['id' => 8, 'fields' => ['all' => ['email' => $this->contact['email']]],
            'doNotContact' => [['channel' => 'email']]];
        $gateway = $this->gateway([new Response(200, [], json_encode(['total' => 1, 'contacts' => [$contact]])),
            new Response(200, [], '{"total":1,"contacts":[]}')]);
        self::assertSame('suppressed', $gateway->subscribe(
            $this->connection('mautic'),
            $this->contact,
            $this->operation()
        ));
        self::assertSame('uncertain', $gateway->subscribe(
            $this->connection('mautic'),
            $this->contact,
            $this->operation()
        ));
        self::assertCount(2, $this->history);
        self::assertSame(
            ['GET', 'GET'],
            array_map(static fn(array $call): string => $call['request']->getMethod(), $this->history)
        );
    }

    public function testSignedWebhookKeepsTheOperationBodyAndSignatureIdenticalAcrossRetries(): void
    {
        $gateway = $this->gateway([new Response(503), new Response(202)]);
        self::assertSame('retry', $gateway->subscribe(
            $this->connection('webhook'),
            $this->contact,
            $this->operation()
        ));
        self::assertSame('accepted', $gateway->subscribe(
            $this->connection('webhook'),
            $this->contact,
            $this->operation()
        ));
        $first = $this->history[0]['request'];
        $second = $this->history[1]['request'];
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame($this->operation()->value, $first->getHeaderLine('Idempotency-Key'));
        self::assertSame(
            'sha256=' . hash_hmac('sha256', (string) $first->getBody(), 'fictional-secret-never-live-32-characters'),
            $first->getHeaderLine('X-ETSIS-Signature')
        );
        self::assertSame($first->getHeaderLine('X-ETSIS-Signature'), $second->getHeaderLine('X-ETSIS-Signature'));
        self::assertStringNotContainsString('fictional-secret', (string) $first->getBody());
    }

    public function testUnexpectedOversizedAndRedirectResponsesFailWithoutFollowingOrExposingThem(): void
    {
        $gateway = $this->gateway([new Response(200, [], str_repeat('x', 32769)),
            new Response(302, ['Location' => 'https://untrusted.example.invalid']), new Response(429)]);
        self::assertSame('uncertain', $gateway->subscribe(
            $this->connection('mailchimp'),
            $this->contact,
            $this->operation()
        ));
        self::assertSame('rejected', $gateway->subscribe(
            $this->connection('mailchimp'),
            $this->contact,
            $this->operation()
        ));
        self::assertSame('retry', $gateway->subscribe(
            $this->connection('mailchimp'),
            $this->contact,
            $this->operation()
        ));
        self::assertCount(3, $this->history);
    }
    public function testMauticSupportsBearerAccessTokensWithoutAStoredBasicUsername(): void
    {
        $contact = ['id' => 8, 'fields' => ['all' => ['email' => $this->contact['email']]], 'doNotContact' => []];
        $gateway = $this->gateway([new Response(200, [], json_encode(['total' => 1, 'contacts' => [$contact]])),
            new Response(200, [], '{"success":1}')]);
        $connection = new NewsletterConnection(
            $this->operation(),
            'mautic',
            'https://newsletter.example.invalid',
            '7',
            'fictional-bearer-access-token'
        );
        self::assertSame('accepted', $gateway->subscribe($connection, $this->contact, $this->operation()));
        self::assertSame(
            'Bearer fictional-bearer-access-token',
            $this->history[0]['request']->getHeaderLine('Authorization')
        );
    }
}
