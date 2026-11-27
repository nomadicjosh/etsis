<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\WebsiteForms\Service\WebsiteFormCatalog;
use Domain\WebsiteForms\Service\WebsiteFormInboxService;
use Domain\WebsiteForms\Service\WebsiteFormSubmissionService;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use Infrastructure\Persistence\Repository\SqlPagePublicationRepository;
use Infrastructure\Persistence\Repository\SqlWebsiteFormInboxRepository;
use Domain\Audit\Repository\AuditTrail;

final class WebsiteFormFixture
{
    public WebsiteFixture $website;
    public \SisFixture $sis;
    public WebsiteFormSubmissionService $submissions;
    public WebsiteFormInboxService $inbox;
    public SqlWebsiteFormInboxRepository $records;
    public array $answers = ['name' => 'Fictional Alex', 'email' => 'alex@example.invalid',
        'message' => 'A fictional private inquiry.'];

    public function __construct()
    {
        require_once __DIR__ . '/WebsiteFixture.php';
        require_once dirname(__DIR__, 2) . '/database/migrations/20261126000002_CreateSisWebsiteFormInbox.php';
        $this->website = $w = new WebsiteFixture();
        $this->sis = $s = $w->sis;
        $w->draft();
        $this->definition(WebsiteFormDefinition::starter());
        $w->transition('1', 'submit');
        $w->transition('1', 'approve');
        $s->sql->update('users', ['sign_in_enabled' => 1], ['user_id' => $s->access->actor->value]);
        $roles = new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql);
        foreach (['sis:website:forms:read', 'sis:website:forms:manage'] as $duty) {
            $permission = $s->sql->one('sis_access_permissions', ['name' => $duty]);
            $roles->assign(
                \Domain\Access\Enum\GrantKind::UserPermission,
                $s->access->actor,
                new \Domain\Shared\ValueObject\EntityId($permission['id']),
                true
            );
        }
        $this->records = new SqlWebsiteFormInboxRepository($s->sql);
        $this->submissions = new WebsiteFormSubmissionService(
            new WebsiteFormCatalog($w->pages),
            new SqlPagePublicationRepository($s->sql),
            $this->records,
            $s->transactions,
            $s->dropCipher,
            $s->clock,
            $w->formRouting(),
            $w->newsletters()
        );
        (new \Infrastructure\Persistence\Repository\SqlWebsiteFormRoutingRepository($s->sql))->save(
            'form',
            '1',
            'contact',
            $s->access->actor,
            true
        );
        $this->inbox = $this->inbox();
    }

    public function inbox(?AuditTrail $audit = null): WebsiteFormInboxService
    {
        $s = $this->sis;
        return new WebsiteFormInboxService(
            $this->records,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->dropCipher,
            new \Infrastructure\Persistence\Repository\SqlAccessRepository($s->sql),
            $this->website->formRouting(),
            $this->website->newsletters(),
            new \Infrastructure\Persistence\Repository\SqlPersonAccountRepository(
                $s->sql,
                $s->admissions,
                new \Infrastructure\Identity\RandomInstitutionalIdGenerator()
            ),
            $s->clock,
        );
    }

    public function definition(array $definition): void
    {
        $this->sis->sql->update('pages', ['data' => json_encode(
            ['html' => ['[block slug="regular-form" id="IDcontact"]'],
            'blocks' => ['en' => ['IDcontact' => ['slug' => 'regular-form',
                'settings' => ['attributes' => ['website-definition' => json_encode(
                    $definition,
                    JSON_THROW_ON_ERROR
                )]]]]]],
            JSON_THROW_ON_ERROR
        )], ['id' => '1']);
    }

    public function submit(?array $proof = null, ?array $answers = null): array
    {
        $proof ??= $this->submissions->prepare('1', 'contact', 'en', 'fictional-browser-csrf');
        return $this->submissions->submit(
            '1',
            'contact',
            'fictional-browser-csrf',
            $proof['ticket'],
            $answers ?? $this->answers
        );
    }
}
