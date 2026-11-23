<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Service\NavigationService;
use Domain\Portal\Service\PagePublicationService;
use Domain\Portal\ValueObject\PublicationWindow;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlNavigationRepository;
use Infrastructure\Persistence\Repository\SqlPagePublicationRepository;
use SisFixture;

final class WebsiteFixture
{
    public SisFixture $sis;
    public PagePublicationService $pages;
    public NavigationService $menus;

    public function __construct(?AuditTrail $audit = null, ?SisFixture $sis = null)
    {
        require_once __DIR__ . '/SisFixture.php';
        $this->sis = $s = $sis ?? new SisFixture();
        self::schema($s);
        $this->pages = $this->pageService($audit);
        $this->menus = $this->menuService($audit);
    }

    public static function schema(SisFixture $s): void
    {
        $s->connection->pdo->exec('CREATE TABLE pages (id VARCHAR(191) PRIMARY KEY, name VARCHAR(191), '
            . 'layout VARCHAR(191), show_in_nav VARCHAR(10), nav_position INTEGER, nav_type VARCHAR(30), data TEXT)');
        $s->connection->pdo->exec('CREATE TABLE page_translations (id VARCHAR(191) PRIMARY KEY, page_id VARCHAR(191), '
            . 'locale VARCHAR(10), title VARCHAR(191), route VARCHAR(191), '
            . 'meta_title VARCHAR(191), meta_description TEXT)');
    }

    public function pageService(?AuditTrail $audit = null): PagePublicationService
    {
        $s = $this->sis;
        return new PagePublicationService(
            new SqlPagePublicationRepository($s->sql),
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            $s->clock,
            new ReviewProof($s->dropCipher, $s->clock)
        );
    }

    public function menuService(?AuditTrail $audit = null): NavigationService
    {
        $s = $this->sis;
        return new NavigationService(
            new SqlNavigationRepository($s->sql),
            $this->pages,
            $s->access,
            $s->transactions,
            $audit ?? $s->audit,
            new ReviewProof($s->dropCipher, $s->clock)
        );
    }

    public function draft(string $id = '1'): string
    {
        $this->sis->sql->insert('pages', ['id' => $id, 'name' => 'Fictional campus page', 'layout' => 'portal',
            'show_in_nav' => 'yes', 'nav_position' => 1, 'nav_type' => 'primary',
            'data' => json_encode(\Application\Service\CampusPageTemplate::data(), JSON_THROW_ON_ERROR)]);
        $this->sis->sql->insert('page_translations', ['id' => $id, 'page_id' => $id, 'locale' => 'en',
            'title' => 'Fictional campus page', 'route' => '/fictional-campus-' . $id . '/',
            'meta_title' => 'Fictional title', 'meta_description' => 'Fictional public information.']);
        return $id;
    }

    public function transition(
        string $page,
        string $action,
        ?PublicationWindow $window = null,
        ?string $note = null,
    ): array {
        $r = $this->pages->review(
            $page,
            $action,
            $this->pages->get($page)['revision'],
            $window ?? new PublicationWindow(null, null),
            $note
        );
        return $this->confirm($r);
    }

    public function confirm(array $r, ?PagePublicationService $service = null): array
    {
        return ($service ?? $this->pages)->confirm(
            $r['page_id'],
            new EntityId($r['operation_id']),
            $r['action'],
            $r['revision'],
            new PublicationWindow(...array_values($r['window'])),
            $r['note'],
            $r['review_token']
        );
    }

    public static function item(
        string $id = 'fictional-link',
        string $type = 'link',
        ?string $page = null,
        array $children = []
    ): array {
        return ['id' => $id, 'type' => $type, 'label' => 'Fictional link', 'description' => '',
            'page_id' => $type === 'page' ? $page : null, 'url' => $type === 'link' ? '/portal/courses/' : null,
            'icon' => '', 'new_tab' => false, 'enabled' => true, 'children' => $children];
    }
}
