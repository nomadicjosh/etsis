<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Access\Enum\GrantKind;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Service\NewsletterGateway;
use Domain\WebsiteForms\Service\NewsletterPolicy;
use Domain\WebsiteForms\Service\NewsletterService;
use Domain\WebsiteForms\Service\NewsletterWorker;
use Domain\WebsiteForms\ValueObject\NewsletterConnection;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlNewsletterRepository;

final class NewsletterFixture
{
    public WebsiteFormFixture $forms;
    public NewsletterService $connections;
    public SqlNewsletterRepository $jobs;
    public NewsletterGateway $gateway;
    public NewsletterPolicy $policy;
    public NewsletterWorker $worker;
    public array $connection;

    public function __construct(string $provider = 'sendy')
    {
        require_once __DIR__ . '/WebsiteFormFixture.php';
        $this->forms = new WebsiteFormFixture();
        $s = $this->forms->sis;
        $permission = $s->sql->one('sis_access_permissions', ['name' => NewsletterService::MANAGE]);
        (new SqlAccessRepository($s->sql))->assign(
            GrantKind::UserPermission,
            $s->access->actor,
            new EntityId($permission['id']),
            true
        );
        $this->jobs = new SqlNewsletterRepository($s->sql);
        $this->connections = $this->forms->website->newsletters();
        $this->connection = $this->connections->save(null, 'Fictional newsletter', true, [
            'provider' => $provider, 'url' => $provider === 'mailchimp' ? 'https://us1.api.mailchimp.com'
                : 'https://newsletter.example.invalid',
            'list' => $provider === 'mautic' ? '7' : 'fictional_list',
            'secret' => 'fictional-secret-never-live-32-characters',
                'username' => $provider === 'mautic' ? 'fictional-api-user' : '',
            'idempotent_receiver' => true,
        ], null);
        $definition = WebsiteFormDefinition::starter();
        $definition['fields'][] = ['name' => 'newsletter_opt_in', 'type' => 'checkbox',
            'label' => 'Receive fictional campus newsletters', 'required' => false];
        $definition['newsletter'] = ['connection_id' => $this->connection['id'],
            'target_hash' => $this->connection['target_hash'], 'email_field' => 'email',
            'name_field' => 'name', 'consent_field' => 'newsletter_opt_in'];
        $this->forms->definition($definition);
        $this->forms->website->transition('1', 'submit');
        $this->forms->website->transition('1', 'approve');
        $this->gateway = new class implements NewsletterGateway {
            public array $calls = [];
            public array $results = ['accepted'];
            public function subscribe(
                #[\SensitiveParameter] NewsletterConnection $connection,
                #[\SensitiveParameter] array $contact,
                EntityId $operation
            ): string {
                $this->calls[] = ['id' => $operation->value, 'contact' => $contact,
                    'target' => $connection->targetHash];
                return count($this->results) > 1 ? array_shift($this->results) : $this->results[0];
            }
        };
        $this->policy = new class implements NewsletterPolicy {
            public bool $active = true;
            public function enabled(): bool
            {
                return $this->active;
            }
            public function permitsHost(string $host): bool
            {
                return true;
            }
        };
        $this->worker = new NewsletterWorker(
            $this->jobs,
            $this->forms->records,
            $this->connections,
            $this->policy,
            $this->gateway,
            $s->dropCipher,
            $s->transactions,
            $s->clock
        );
    }

    public function submit(bool $consent = true, ?array $proof = null): EntityId
    {
        $this->forms->submit($proof, $this->forms->answers + ['newsletter_opt_in' => $consent]);
        return new EntityId($this->forms->inbox->page()['items'][0]['id']);
    }
}
