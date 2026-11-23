<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Portal\ValueObject\NavigationTree;
use Domain\Portal\ValueObject\PublicationWindow;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use Tests\Support\WebsiteFixture;

final class WebsitePublicationTest extends TestCase
{
    public function testOneHundredDetailedItemsSurviveDraftAndPublicationStorage(): void
    {
        $f = new WebsiteFixture();
        $items = [];
        for ($index = 0; $index < 100; $index++) {
            $item = WebsiteFixture::item('large-link-' . $index);
            $item['url'] = '/fictional-' . $index . '/' . str_repeat('x', 1000);
            $items[] = $item;
        }
        $saved = $f->menus->save('primary', new NavigationTree($items), $f->menus->get('primary')['revision']);
        $row = $f->sis->sql->one('sis_navigation_menus', ['slot' => 'primary']);
        self::assertGreaterThan(65535, strlen($row['draft_items']));
        self::assertSame($items, $saved['items']);
        $r = $f->menus->review('primary', $saved['revision']);
        $f->menus->publish('primary', new EntityId($r['operation_id']), $r['revision'], $r['review_token']);
        self::assertSame($items, $f->menus->publicItems('primary'));
    }

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/WebsiteFixture.php';
    }

    public function testPageConfirmationRollsBackAuditAuthoritySourceAndExpiredReviews(): void
    {
        foreach (['audit', 'authority', 'source', 'expiry'] as $failure) {
            $f = new WebsiteFixture();
            $page = $f->draft();
            $s = $f->sis;
            $f->transition($page, 'submit');
            $review = $f->pages->review(
                $page,
                'approve',
                $f->pages->get($page)['revision'],
                new PublicationWindow(null, null),
                null
            );
            $tables = ['pages', 'page_translations', 'sis_page_publications', 'sis_page_publication_operations',
                'sis_audit', 'sis_history_events'];
            $before = array_map($s->sql->rows(...), $tables);
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($failure, $s, $page): void {
                match ($failure) {
                    'audit' => throw new \RuntimeException('Fictional audit failure.'),
                    'authority' => $s->access->staff = false,
                    'source' => $s->sql->update(
                        'page_translations',
                        ['title' => 'Fictional changed title'], ['page_id' => $page],
                    ),
                    'expiry' => $s->clock->time = $s->clock->time->modify('+20 minutes'),
                };
            });
            try {
                $f->confirm($review, $f->pageService($audit));
                self::fail('An incomplete page approval committed.');
            } catch (\RuntimeException | \DomainException) {
                self::assertSame($before, array_map($s->sql->rows(...), $tables));
            }
        }
    }

    public function testMenuPublicationRollsBackAuditAuthoritySourceAndExpiry(): void
    {
        foreach (['audit', 'authority', 'source', 'expiry'] as $failure) {
            $f = new WebsiteFixture();
            $s = $f->sis;
            $saved = $f->menus->save(
                'primary',
                new NavigationTree([WebsiteFixture::item()]),
                $f->menus->get('primary')['revision']
            );
            $r = $f->menus->review('primary', $saved['revision']);
            $tables = ['sis_navigation_menus', 'sis_navigation_operations', 'sis_audit', 'sis_history_events'];
            $before = array_map($s->sql->rows(...), $tables);
            $audit = $this->createStub(\Domain\Audit\Repository\AuditTrail::class);
            $audit->method('record')->willReturnCallback(static function () use ($failure, $s): void {
                match ($failure) {
                    'audit' => throw new \RuntimeException('Fictional audit failure.'),
                    'authority' => $s->access->staff = false,
                    'source' => $s->sql->update('sis_navigation_menus', ['draft_items' => '[]'], ['slot' => 'primary']),
                    'expiry' => $s->clock->time = $s->clock->time->modify('+20 minutes'),
                };
            });
            try {
                $f->menuService($audit)->publish(
                    'primary',
                    new EntityId($r['operation_id']),
                    $r['revision'],
                    $r['review_token'],
                );
                self::fail('An incomplete menu publication committed.');
            } catch (\RuntimeException | \DomainException) {
                self::assertSame($before, array_map($s->sql->rows(...), $tables));
            }
        }
    }

    public function testSavedPagesNeedIndependentApprovalAndExactWindowWithNoInferredHistory(): void
    {
        $f = new WebsiteFixture();
        $page = $f->draft();
        $s = $f->sis;
        $events = $s->sql->rows('sis_history_events');
        self::assertNull($f->pages->visible($page));
        $s->access->staff = false;
        $s->access->grants = ['vihzhuo:manage'];
        $f->transition($page, 'submit');
        try {
            $f->transition($page, 'approve');
            self::fail('An author published without permission.');
        } catch (AccessDeniedException) {
            self::assertSame('review', $f->pages->get($page)['status']);
        }
        $s->access->grants[] = 'sis:portal:publish';
        $s->access->actor = $s->user();
        $window = new PublicationWindow('2026-08-11T17:00:00Z', '2026-08-12T17:00:00Z');
        $review = $f->pages->review($page, 'approve', $f->pages->get($page)['revision'], $window, null);
        $receipt = $f->confirm($review);
        self::assertNull($f->pages->visible($page));
        $s->clock->time = new \DateTimeImmutable('2026-08-11T17:00:00Z');
        self::assertNotNull($f->pages->visible($page));
        self::assertCount(1, $f->pages->navigation());
        $s->clock->time = new \DateTimeImmutable('2026-08-12T17:00:00Z');
        self::assertNull($f->pages->visible($page));
        self::assertSame([], $f->pages->navigation());
        self::assertSame($receipt, $f->confirm($review));
        self::assertSame($events, $s->sql->rows('sis_history_events'));
    }

    public function testBuilderAndTranslatedRouteChangesRequireNewReviewAndPreserveReceipts(): void
    {
        $f = new WebsiteFixture();
        $page = $f->draft();
        $f->transition($page, 'submit');
        $review = $f->pages->review(
            $page,
            'approve',
            $f->pages->get($page)['revision'],
            new PublicationWindow(null, null),
            null
        );
        $first = $f->confirm($review);
        $f->sis->sql->update('page_translations', ['route' => '/changed-fictional/'], ['page_id' => $page]);
        self::assertNull($f->pages->visible($page));
        self::assertSame('draft', $f->pages->get($page)['status']);
        self::assertSame($first, $f->confirm($review));
        $f->transition($page, 'submit');
        $f->transition($page, 'return', note: 'Fictional correction needed.');
        self::assertSame('Fictional correction needed.', $f->pages->get($page)['review_note']);
        $f->transition($page, 'submit');
        $stale = $f->pages->review(
            $page,
            'approve',
            $f->pages->get($page)['revision'],
            new PublicationWindow(null, null),
            null
        );
        $f->sis->sql->update('pages', ['data' => '{"fictional":"revised"}'], ['id' => $page]);
        $this->expectException(ConcurrentModificationException::class);
        $f->confirm($stale);
    }

    public function testMenuDraftPublicationAndCurrentPageVisibilityStaySeparate(): void
    {
        $f = new WebsiteFixture();
        $page = $f->draft();
        $tree = new NavigationTree([WebsiteFixture::item('group', 'group', children: [
            WebsiteFixture::item('page', 'page', $page), WebsiteFixture::item(),
        ])]);
        $saved = $f->menus->save('primary', $tree, $f->menus->get('primary')['revision']);
        self::assertSame([], $f->menus->publicItems('primary'));
        $r = $f->menus->review('primary', $saved['revision']);
        $receipt = $f->menus->publish('primary', new EntityId($r['operation_id']), $r['revision'], $r['review_token']);
        self::assertCount(1, $f->menus->publicItems('primary')[0]['children']);
        $f->transition($page, 'submit');
        $f->transition($page, 'approve');
        self::assertCount(2, $f->menus->publicItems('primary')[0]['children']);
        $f->transition($page, 'archive');
        self::assertCount(1, $f->menus->publicItems('primary')[0]['children']);
        $f->menus->save('primary', new NavigationTree([]), $f->menus->get('primary')['revision']);
        self::assertCount(1, $f->menus->publicItems('primary'));
        self::assertSame($receipt, $f->menus->publish(
            'primary',
            new EntityId($r['operation_id']),
            $r['revision'],
            $r['review_token']
        ));
    }
}
