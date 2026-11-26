<?php

declare(strict_types=1);

// Actual providers, routes, middleware and worker command; fictional credentials and injected delivery only.
$newsletterStaff = $sis->access->staff;
$newsletterGrants = $sis->access->grants;
$newsletterConfig = $app->make(\Qubus\Config\ConfigContainer::class);
$newsletterHosts = $newsletterConfig->getConfigKey('sis.newsletter_hosts', []);
$newsletterEnabled = $newsletterConfig->getConfigKey('sis.newsletter_enabled', false);
$newsletterConfig->setConfigKey('sis', ['newsletter_hosts' => ['newsletter.example.invalid'],
    'newsletter_enabled' => true]);
$newsletterPermission = $sis->sql->one('sis_access_permissions', ['name' => 'sis:website:forms:integrations']);
$formRoles->assign(
    \Domain\Access\Enum\GrantKind::UserPermission,
    $sis->access->actor,
    new \Domain\Shared\ValueObject\EntityId($newsletterPermission['id']),
    true
);
$sis->access->staff = false;
$sis->access->grants = ['vihzhuo:manage', 'sis:portal:publish', 'sis:website:forms:read',
    'sis:website:forms:manage', 'sis:website:forms:integrations', 'admin:access:manage'];
$newsletterProfile = $api('/sis/website/newsletters/', ['id' => null, 'version' => null,
    'name' => 'Fictional newsletter', 'enabled' => true, 'provider' => 'sendy',
    'url' => 'https://newsletter.example.invalid', 'list' => 'fictional_list',
        'secret' => 'fictional-api-key-never-live']);
$newsletterMetadata = $read('/sis/website/newsletters/' . $newsletterProfile['id'] . '/');
if (str_contains(json_encode($newsletterMetadata), 'fictional-api-key-never-live')) {
    throw new RuntimeException('Newsletter credentials were exposed in a private response.');
}
$newsletterPage = $websiteFixture->draft('newsletter-form');
$newsletterDefinition = \Domain\WebsiteForms\ValueObject\WebsiteFormDefinition::starter();
$newsletterDefinition['fields'][] = ['name' => 'subscribe', 'type' => 'checkbox',
    'label' => 'Receive fictional campus newsletters', 'required' => false];
$newsletterDefinition['newsletter'] = ['connection_id' => $newsletterProfile['id'],
    'target_hash' => $newsletterProfile['target_hash'], 'email_field' => 'email', 'name_field' => null,
    'consent_field' => 'subscribe'];
$sis->sql->update('pages', ['data' => json_encode(['blocks' => ['en' => ['IDnewsletter' => [
    'slug' => 'regular-form', 'settings' => ['attributes' => [
        'website-definition' => json_encode($newsletterDefinition, JSON_THROW_ON_ERROR),
    ]],
]]]], JSON_THROW_ON_ERROR)], ['id' => $newsletterPage]);
$websiteFixture->transition($newsletterPage, 'submit');
$websiteFixture->transition($newsletterPage, 'approve');
$newsletterProof = $read('/portal/api/website-forms/' . $newsletterPage . '/contact/prepare/?locale=en');
$api('/portal/api/website-forms/' . $newsletterPage . '/contact/submit/', ['ticket' => $newsletterProof['ticket'],
    'answers' => ['name' => 'Fictional prospect', 'email' => 'newsletter@example.invalid',
        'message' => 'Fictional inquiry',
        'subscribe' => true], 'website_url' => '']);
$newsletterJobs = $app->make(\Domain\WebsiteForms\Repository\NewsletterRepository::class);
$newsletterJob = $sis->sql->rows('sis_website_subscriptions')[0];
$app->delegate(
    \Domain\WebsiteForms\Service\NewsletterGateway::class,
    static fn() => new class implements \Domain\WebsiteForms\Service\NewsletterGateway {
        public function subscribe(
            #[\SensitiveParameter] \Domain\WebsiteForms\ValueObject\NewsletterConnection $connection,
            #[\SensitiveParameter] array $contact,
            \Domain\Shared\ValueObject\EntityId $operation
        ): string {
            return 'accepted';
        }
    }
);
$newsletterCommand = new \Symfony\Component\Console\Tester\CommandTester(
    new \Application\Console\Commands\DeliverWebsiteSubscriptionsCommand($app)
);
if (
    $newsletterCommand->execute(['--limit' => '4']) !== 2 || $newsletterCommand->execute([]) !== 0
    || $newsletterJobs->job(new \Domain\Shared\ValueObject\EntityId($newsletterJob['id']))['state'] !== 'queued'
    || $newsletterCommand->execute(['--apply' => true, '--limit' => '1']) !== 0
    || $newsletterJobs->job(new \Domain\Shared\ValueObject\EntityId($newsletterJob['id']))['state'] !== 'completed'
) {
    throw new RuntimeException('The actual newsletter worker lost its bounds, dry run or completion.');
}
$api('/sis/website/form-access/members/', ['kind' => 'form', 'scope' => $newsletterPage, 'form' => 'contact',
    'user_id' => $sis->access->actor->value, 'grant' => true, 'expected' => null]);
$newsletterDetail = $read('/sis/website/form-inbox/' . $newsletterJob['id'] . '/');
if ($newsletterDetail['newsletter']['state'] !== 'completed') {
    throw new RuntimeException('The existing inquiry lost its private newsletter status.');
}
$api('/sis/website/form-inbox/' . $newsletterJob['id'] . '/', ['version' => 0, 'action' => 'delete']);
if ($sis->sql->rows('sis_website_subscriptions') !== []) {
    throw new RuntimeException('Deleting an inquiry left its delivery job behind.');
}
$sis->access->grants = ['sis:website:forms:read'];
$read('/sis/website/newsletters/', 403);
$newsletterConfig->setConfigKey('sis', ['newsletter_hosts' => $newsletterHosts,
    'newsletter_enabled' => $newsletterEnabled]);
$sis->access->staff = $newsletterStaff;
$sis->access->grants = $newsletterGrants;
