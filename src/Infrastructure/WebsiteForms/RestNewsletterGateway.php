<?php

declare(strict_types=1);

namespace Infrastructure\WebsiteForms;

use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Service\NewsletterGateway;
use Domain\WebsiteForms\Service\NewsletterPolicy;
use Domain\WebsiteForms\ValueObject\NewsletterConnection;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/** Dedicated bounded HTTP client: no framework observers, debug collectors, cookies or redirects. */
final readonly class RestNewsletterGateway implements NewsletterGateway
{
    public function __construct(private NewsletterPolicy $policy, private ClientInterface $http = new Client())
    {
    }

    public function subscribe(
        #[\SensitiveParameter] NewsletterConnection $connection,
        #[\SensitiveParameter] array $contact,
        EntityId $operation
    ): string {
        if (
            !$this->policy->enabled() || !$this->policy->permitsHost($connection->host)
            || filter_var($contact['email'] ?? '', FILTER_VALIDATE_EMAIL) === false
        ) {
            return 'rejected';
        }
        try {
            return match ($connection->provider) {
                'mailchimp' => $this->mailchimp($connection, $contact),
                'sendy' => $this->sendy($connection, $contact),
                'mautic' => $this->mautic($connection, $contact),
                'webhook' => $this->webhook($connection, $contact, $operation),
                default => 'rejected',
            };
        } catch (Throwable) {
            // An interrupted mutation can already have taken effect. Never log an upstream error or request.
            return 'uncertain';
        }
    }

    private function request(
        string $method,
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] array $options
    ): array {
        $options += ['headers' => []];
        $options['headers'] += ['Accept' => 'application/json', 'Accept-Encoding' => 'identity'];
        $response = $this->http->request($method, $url, $options + [
            'allow_redirects' => false, 'http_errors' => false, 'verify' => true, 'debug' => false,
            'cookies' => false, 'proxy' => '', 'connect_timeout' => 2, 'timeout' => 3, 'decode_content' => false,
            'on_headers' => static function (ResponseInterface $response): void {
                if (
                    (int) $response->getHeaderLine('Content-Length') > 32768
                    || !in_array(strtolower($response->getHeaderLine('Content-Encoding')), ['', 'identity'], true)
                ) {
                    throw new \RuntimeException('Newsletter response exceeded its bounds.');
                }
            },
            'progress' => static function ($total, $downloaded): void {
                if ($downloaded > 32768) {
                    throw new \RuntimeException('Newsletter response exceeded its bounds.');
                }
            },
        ]);
        try {
            $body = $response->getBody()->read(32769);
            if (strlen($body) > 32768 || !$response->getBody()->eof()) {
                throw new \RuntimeException('Newsletter response exceeded its bounds.');
            }
            return [$response->getStatusCode(), $body];
        } finally {
            $response->getBody()->close();
        }
    }

    private function mailchimp(NewsletterConnection $c, #[\SensitiveParameter] array $contact): string
    {
        $payload = ['email_address' => $contact['email'], 'status_if_new' => 'pending'];
        if ($contact['name'] !== '') {
            $payload['merge_fields'] = ['FNAME' => $contact['name']];
        }
        [$status, $body] = $this->request('PUT', $c->url . '/3.0/lists/' . rawurlencode($c->list)
            . '/members/' . md5(strtolower($contact['email'])), [
                'headers' => ['Authorization' => 'Bearer ' . $c->secret], 'json' => $payload,
            ]);
        if ($status === 429 || $status >= 500) {
            return 'retry';
        }
        if ($status !== 200) {
            return 'rejected';
        }
        $result = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (
            strtolower($result['email_address'] ?? '') !== strtolower($contact['email'])
            || ($result['list_id'] ?? null) !== $c->list
        ) {
            return 'uncertain';
        }
        return match ($result['status'] ?? '') {
            'subscribed' => 'already_member', 'pending' => 'pending',
            'unsubscribed', 'cleaned', 'transactional' => 'suppressed', default => 'uncertain',
        };
    }

    private function sendy(NewsletterConnection $c, #[\SensitiveParameter] array $contact): string
    {
        try {
            [$status, $body] = $this->request('POST', $c->url . '/api/subscribers/subscription-status.php', [
                'form_params' => ['api_key' => $c->secret, 'email' => $contact['email'], 'list_id' => $c->list],
            ]);
        } catch (Throwable) {
            return 'retry';
        }
        $state = strtolower(trim($body, " \t\r\n."));
        if ($status === 429 || $status >= 500) {
            return 'retry';
        }
        if ($status !== 200) {
            return 'rejected';
        }
        if (in_array($state, ['subscribed', 'unconfirmed'], true)) {
            return $state === 'subscribed' ? 'already_member' : 'pending';
        }
        if (in_array($state, ['unsubscribed', 'bounced', 'soft bounced', 'complained'], true)) {
            return 'suppressed';
        }
        if ($state !== 'email does not exist in list') {
            return 'rejected';
        }
        [$status, $body] = $this->request('POST', $c->url . '/subscribe', [
            'form_params' => ['api_key' => $c->secret, 'email' => $contact['email'], 'name' => $contact['name'],
                'list' => $c->list, 'boolean' => 'true', 'silent' => 'false', 'hp' => ''],
        ]);
        $result = strtolower(trim($body, " \t\r\n."));
        if ($status === 429) {
            return 'retry';
        }
        if ($status >= 500) {
            return 'uncertain';
        }
        if ($status !== 200) {
            return 'rejected';
        }
        return match ($result) {
            'true', '1' => 'accepted', 'already subscribed' => 'already_member',
            'bounced email address', 'email is suppressed' => 'suppressed', default => 'rejected',
        };
    }

    private function mautic(NewsletterConnection $c, #[\SensitiveParameter] array $contact): string
    {
        $auth = $c->username === '' ? ['headers' => ['Authorization' => 'Bearer ' . $c->secret]]
        : ['auth' => [$c->username, $c->secret]];
        try {
            [$status, $body] = $this->request('GET', $c->url . '/api/contacts', $auth + [
                'query' => ['search' => 'email:"' . addcslashes($contact['email'], '\\"') . '"', 'limit' => 2],
            ]);
        } catch (Throwable) {
            return 'retry';
        }
        if ($status === 429 || $status >= 500) {
            return 'retry';
        }
        if ($status !== 200) {
            return 'rejected';
        }
        $found = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
        if (
            !is_array($found['contacts'] ?? null) || !in_array($found['total'] ?? null, [0, 1, '0', '1'], true)
            || (int) $found['total'] > 1 || count($found['contacts']) > 1
                || (int) $found['total'] !== count($found['contacts'])
        ) {
            return 'uncertain';
        }
        $record = array_values($found['contacts'])[0] ?? null;
        if ($record === null) {
            [$status, $body] = $this->request('POST', $c->url . '/api/contacts/new', $auth + [
                'form_params' => ['email' => $contact['email'], 'firstname' => $contact['name'],
                    'overwriteWithBlank' => false],
            ]);
            if ($status !== 201) {
                return $status >= 500 ? 'uncertain' : 'rejected';
            }
            $record = json_decode($body, true, 24, JSON_THROW_ON_ERROR)['contact'] ?? null;
        }
        if (
            !is_array($record) || !ctype_digit((string) ($record['id'] ?? ''))
            || strtolower($record['fields']['all']['email'] ?? '') !== strtolower($contact['email'])
        ) {
            return 'uncertain';
        }
        foreach ($record['doNotContact'] ?? [] as $restriction) {
            if (($restriction['channel'] ?? '') === 'email') {
                return 'suppressed';
            }
        }
        [$status, $body] = $this->request('POST', $c->url . '/api/segments/' . $c->list . '/contact/'
            . $record['id'] . '/add', $auth);
        if ($status === 429 || $status >= 500) {
            return 'retry';
        }
        if ($status !== 200) {
            return 'rejected';
        }
        return (json_decode($body, true, 8, JSON_THROW_ON_ERROR)['success'] ?? null) === 1 ? 'accepted' : 'uncertain';
    }

    private function webhook(
        NewsletterConnection $c,
        #[\SensitiveParameter] array $contact,
        EntityId $operation
    ): string {
        $body = json_encode(['operation_id' => $operation->value, 'list' => $c->list,
            'email' => $contact['email'], 'name' => $contact['name'], 'consented_at' => $contact['consented_at'],
            'consent_label' => $contact['consent_label']], JSON_THROW_ON_ERROR);
        [$status] = $this->request('POST', $c->url, ['headers' => [
            'Content-Type' => 'application/json', 'Idempotency-Key' => $operation->value,
            'X-ETSIS-Signature' => 'sha256=' . hash_hmac('sha256', $body, $c->secret),
        ], 'body' => $body]);
        return $status >= 200 && $status < 300 ? 'accepted'
        : ($status === 429 || $status >= 500 ? 'retry' : 'rejected');
    }
}
