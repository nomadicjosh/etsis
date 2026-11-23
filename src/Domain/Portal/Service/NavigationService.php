<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Repository\NavigationRepository;
use Domain\Portal\ValueObject\NavigationTree;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

final readonly class NavigationService
{
    public function __construct(
        private NavigationRepository $menus,
        private PagePublicationService $pages,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private ReviewProof $proofs
    ) {
    }

    public function get(string $slot): array
    {
        $actor = $this->authorize();
        $row = $this->menus->get($this->slot($slot));
        $result = ['slot' => $slot, 'items' => $row['draft_items'], 'revision' => $this->revision($row),
            'has_publication' => $row['published_items'] !== null, 'can_publish' => $this->publisher($actor)];
        $this->authorize(expected: $actor);
        return $result;
    }

    public function save(string $slot, NavigationTree $tree, string $revision): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($slot, $tree, $revision, $actor): array {
            $this->menus->lock($this->slot($slot));
            $row = $this->menus->get($slot);
            $this->check($row, $revision);
            $next = array_replace($row, ['draft_items' => $tree->items, 'version' => $row['version'] + 1]);
            $this->menus->save($slot, ['draft_items' => $tree->items, 'version' => $next['version']]);
            $this->audit->record($actor, 'website.menu.draft_saved', new EntityId($row['id']));
            $this->authorize(expected: $actor);
            if ($this->menus->get($slot) !== $next) {
                throw new ConcurrentModificationException('The menu changed before completion. Reload it.');
            }
            return $this->get($slot);
        });
    }

    public function review(string $slot, string $revision): array
    {
        $actor = $this->authorize(true);
        $row = $this->menus->get($this->slot($slot));
        $this->check($row, $revision);
        new NavigationTree($row['draft_items'], publishing: true);
        $operation = EntityId::generate();
        $this->authorize(true, $actor);
        return ['slot' => $slot, 'revision' => $revision, 'operation_id' => $operation->value,
            'items' => $row['draft_items'], 'review_token' => $this->proofs->issue(
                'site-menu',
                'publish',
                new EntityId($row['id']),
                $actor,
                $operation,
                $revision
            )];
    }

    public function publish(
        string $slot,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize(true);
        return $this->transactions->run(function () use ($slot, $operation, $revision, $token, $actor): array {
            $this->menus->lock($this->slot($slot));
            $row = $this->menus->get($slot);
            $receipt = $this->menus->receipt($operation);
            if (
                $receipt !== null && ($receipt['slot'] !== $slot || $receipt['actor_id'] !== $actor->value
                || !hash_equals($receipt['revision'], $revision))
            ) {
                throw new AccessDeniedException();
            }
            $this->proofs->validate(
                $token,
                'site-menu',
                'publish',
                new EntityId($row['id']),
                $actor,
                $operation,
                $revision,
                $receipt !== null
            );
            if ($receipt !== null) {
                return $receipt['result'];
            }
            $this->check($row, $revision);
            new NavigationTree($row['draft_items'], publishing: true);
            $next = array_replace($row, ['published_items' => $row['draft_items'], 'version' => $row['version'] + 1]);
            $this->menus->save($slot, ['published_items' => $next['published_items'], 'version' => $next['version']]);
            $result = ['slot' => $slot, 'version' => $next['version']];
            $accepted = ['slot' => $slot, 'actor_id' => $actor->value, 'revision' => $revision, 'result' => $result];
            $this->menus->saveReceipt($operation, $accepted);
            $this->audit->record($actor, 'website.menu.published', new EntityId($row['id']));
            $this->authorize(true, $actor);
            $this->proofs->validate(
                $token,
                'site-menu',
                'publish',
                new EntityId($row['id']),
                $actor,
                $operation,
                $revision,
            );
            if ($this->menus->get($slot) !== $next || $this->menus->receipt($operation) !== $accepted) {
                throw new ConcurrentModificationException('The menu changed before completion. Review it again.');
            }
            return $result;
        });
    }

    public function publicItems(string $slot, string $locale = 'en'): array
    {
        $row = $this->menus->get($this->slot($slot));
        if ($row['published_items'] === null) {
            $links = [];
            foreach ($this->pages->navigation() as $page) {
                if (
                    $page['nav_type'] !== $slot || str_contains($page['route'], '{')
                    || str_contains($page['route'], '*')
                ) {
                    continue;
                }
                if (isset($links[$page['id']]) && $page['locale'] !== $locale) {
                    continue;
                }
                $links[$page['id']] = ['id' => 'page-' . $page['id'], 'type' => 'page', 'label' => $page['title'],
                    'description' => '', 'url' => $page['route'], 'page_id' => (string) $page['id'],
                    'icon' => '', 'enabled' => true, 'new_tab' => false, 'children' => []];
            }
            return array_values($links);
        }
        $tree = new NavigationTree($row['published_items'], publishing: true);
        $sources = [];
        $filter = function (array $items) use (&$filter, &$sources, $locale): array {
            $visible = [];
            foreach ($items as $item) {
                if (!$item['enabled']) {
                    continue;
                }
                $item['children'] = $filter($item['children']);
                if ($item['type'] === 'page') {
                    if (!array_key_exists($item['page_id'], $sources)) {
                        $sources[$item['page_id']] = $this->pages->visible($item['page_id']);
                    }
                    $source = $sources[$item['page_id']];
                    if ($source === null) {
                        continue;
                    }
                    $variants = array_column($source['translations'], null, 'locale');
                    $variant = $variants[$locale] ?? reset($variants);
                    $item['url'] = $variant['route'];
                    // Builder routes can be parameterized. Only concrete safe URLs belong in menus.
                    if (str_contains($item['url'], '{') || str_contains($item['url'], '*')) {
                        continue;
                    }
                    NavigationTree::requireUrl($item['url']);
                }
                if ($item['type'] === 'group' && $item['children'] === []) {
                    continue;
                }
                if ($item['type'] === 'divider' && ($visible === [] || end($visible)['type'] === 'divider')) {
                    continue;
                }
                $visible[] = $item;
            }
            if ($visible !== [] && end($visible)['type'] === 'divider') {
                array_pop($visible);
            }
            return $visible;
        };
        return $filter($tree->items);
    }

    private function authorize(bool $publish = false, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('vihzhuo:manage');
        if (
            $actor->value !== $this->access->authenticatedUserId()->value
            || ($expected !== null && $expected->value !== $actor->value)
            || ($publish && !$this->publisher($actor))
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function publisher(EntityId $actor): bool
    {
        try {
            return $this->access->requirePermission('sis:portal:publish')->value === $actor->value;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function slot(string $slot): string
    {
        if (!in_array($slot, ['primary', 'secondary', 'footer'], true)) {
            throw new ValidationException([['field' => 'slot', 'message' => 'Choose a website menu.']]);
        }
        return $slot;
    }

    private function revision(array $row): string
    {
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    private function check(array $row, string $revision): void
    {
        if (!hash_equals($this->revision($row), $revision)) {
            throw new ConcurrentModificationException('The saved menu changed. Reload it before continuing.');
        }
    }
}
