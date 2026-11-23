<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\Repository\PagePublicationRepository;
use Domain\Portal\ValueObject\PublicationWindow;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

/** Ordinary website content uses audited CRUD; no academic history is inferred from saved pages. */
final readonly class PagePublicationService
{
    public function __construct(
        private PagePublicationRepository $pages,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }

    public function directory(?string $after = null): array
    {
        $actor = $this->authorize();
        if ($after !== null) {
            $this->identity($after);
        }
        $result = $this->pages->directory($after);
        $this->authorize(expected: $actor);
        return $result;
    }

    public function get(string $page): array
    {
        $actor = $this->authorize();
        $context = $this->context($page);
        $source = $context['source'];
        $result = ['page_id' => $page, 'name' => $source['page']['name'],
            'status' => $context['status'], 'revision' => $context['revision'],
            'translations' => array_map(static fn (array $t): array => array_intersect_key(
                $t,
                array_flip(['locale', 'title', 'route'])
            ), $source['translations']),
            'visible_from' => $context['state']['visible_from'] ?? null,
            'visible_until' => $context['state']['visible_until'] ?? null,
            'review_note' => $context['status'] === 'draft' ? ($context['state']['review_note'] ?? null) : null,
            'can_publish' => $this->publisher($actor)];
        $this->authorize(expected: $actor);
        return $result;
    }

    public function review(
        string $page,
        string $action,
        string $revision,
        PublicationWindow $window,
        ?string $note,
    ): array {
        $actor = $this->authorize($action);
        return $this->transactions->run(function () use ($page, $action, $revision, $window, $note, $actor): array {
            $this->pages->lock($this->identity($page));
            $context = $this->context($page);
            $note = $this->assess($context, $action, $revision, $window, $note);
            $operation = EntityId::generate();
            $intent = $this->intent($page, $action, $revision, $window, $note);
            $this->authorize($action, $actor);
            return ['page_id' => $page, 'name' => $context['source']['page']['name'],
                'operation_id' => $operation->value, 'action' => $action, 'revision' => $revision,
                'window' => $window->data(), 'note' => $note,
                'review_token' => $this->proofs->issue('site-page', $action, $operation, $actor, $operation, $intent)];
        });
    }

    public function confirm(
        string $page,
        EntityId $operation,
        string $action,
        string $revision,
        PublicationWindow $window,
        ?string $note,
        #[\SensitiveParameter] string $token
    ): array {
        $actor = $this->authorize($action);
        return $this->transactions->run(function () use (
            $page,
            $operation,
            $action,
            $revision,
            $window,
            $note,
            $token,
            $actor
): array {
            $this->pages->lock($this->identity($page));
            $intent = $this->intent($page, $action, $revision, $window, $note);
            $existing = $this->pages->receipt($operation);
            if (
                $existing !== null && ($existing['page_id'] !== $page || $existing['actor_id'] !== $actor->value
                || !hash_equals($existing['intent'], $intent))
            ) {
                throw new AccessDeniedException();
            }
            $this->proofs->validate(
                $token,
                'site-page',
                $action,
                $operation,
                $actor,
                $operation,
                $intent,
                $existing !== null
            );
            if ($existing !== null) {
                return $existing['result'];
            }
            $context = $this->context($page);
            $note = $this->assess($context, $action, $revision, $window, $note);
            $next = ['id' => $context['state']['id'] ?? EntityId::generate()->value, 'page_id' => $page,
                'version' => ($context['state']['version'] ?? 0) + 1,
                'status' => match ($action) {
                'submit' => 'review', 'approve' => 'published',
                    'return' => 'draft', 'archive' => 'archived'
                },
                'source_hash' => $this->hash($context['source']), 'review_note' => $note,
                'visible_from' => $window->data()['visible_from'], 'visible_until' => $window->data()['visible_until']];
            ksort($next);
            $this->pages->save($page, $next);
            $result = ['page_id' => $page, 'status' => $next['status'],
                'version' => $next['version'], 'action' => $action];
            $receipt = ['page_id' => $page, 'actor_id' => $actor->value, 'intent' => $intent, 'result' => $result];
            $this->pages->saveReceipt($operation, $receipt);
            // Audit references the ULID publication record, not the builder's legacy numeric page identity.
            $this->audit->record($actor, 'website.page.' . $action, new EntityId($next['id']));
            $this->authorize($action, $actor);
            if ($action === 'approve') {
                $window->requireFuture($this->clock->now());
            }
            $this->proofs->validate($token, 'site-page', $action, $operation, $actor, $operation, $intent);
            if (
                $this->pages->state($page) !== $next || $this->pages->source($page) !== $context['source']
                || $this->pages->receipt($operation) !== $receipt
            ) {
                throw new ConcurrentModificationException('The saved page changed before completion. Review it again.');
            }
            return $result;
        });
    }

    /** The returned page and translations are one captured approved source, never later live draft reads. */
    public function visible(string $page): ?array
    {
        try {
            $context = $this->context($page);
        } catch (RecordNotFoundException | ValidationException) {
            return null;
        }
        $state = $context['state'];
        return $context['status'] === 'published'
        && new PublicationWindow($state['visible_from'], $state['visible_until'])->visible($this->clock->now())
        ? $context['source'] : null;
    }

    public function navigation(): array
    {
        $links = [];
        foreach ($this->pages->navigationCandidates() as $page) {
            $source = $this->visible((string) $page);
            if ($source === null || $source['page']['show_in_nav'] !== 'yes') {
                continue;
            }
            foreach ($source['translations'] as $translation) {
                $links[] = array_intersect_key($source['page'], array_flip([
                    'id', 'show_in_nav', 'nav_position', 'nav_type',
                ]))
                + array_intersect_key($translation, array_flip(['title', 'route', 'locale']));
            }
        }
        return $links;
    }

    private function context(string $page): array
    {
        $source = $this->pages->source($this->identity($page));
        $state = $this->pages->state($page);
        $status = $state === null || !hash_equals($state['source_hash'], $this->hash($source))
        ? 'draft' : $state['status'];
        return ['source' => $source, 'state' => $state, 'status' => $status,
            'revision' => $this->hash([$source, $state])];
    }

    private function assess(
        array $context,
        string $action,
        string $revision,
        PublicationWindow $window,
        ?string $note
    ): ?string {
        if (!hash_equals($context['revision'], $revision)) {
            throw new ConcurrentModificationException('The saved page changed. Reload and review it again.');
        }
        $allowed = match ($action) {
            'submit' => ['draft', 'archived'], 'approve', 'return' => ['review'],
            'archive' => ['published'], default => []
        };
        if (!in_array($context['status'], $allowed, true) || $context['source']['translations'] === []) {
            self::invalid('Choose an action available for this saved page.');
        }
        if ($action === 'approve') {
            $window->requireFuture($this->clock->now());
            foreach ($context['source']['translations'] as $translation) {
                if (preg_match('~^/(?!/)[^\x00-\x20\\\\]*$~D', $translation['route']) !== 1) {
                    self::invalid('Each page translation needs a safe local website route.');
                }
            }
        } elseif ($window->data() !== ['visible_from' => null, 'visible_until' => null]) {
            self::invalid('Use visibility dates only for publication approval.');
        }
        if ($action === 'return') {
            if (
                $note === null || trim($note) === '' || mb_strlen($note) > 1000 || !mb_check_encoding($note, 'UTF-8')
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)
            ) {
                self::invalid('Enter a review note up to 1,000 characters.');
            }
            return trim($note);
        }
        if ($note !== null) {
            self::invalid('Use review notes only when returning a page to its author.');
        }
        return null;
    }

    private function authorize(?string $action = null, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('vihzhuo:manage');
        if (
            $actor->value !== $this->access->authenticatedUserId()->value
            || ($expected !== null && $actor->value !== $expected->value)
            || (in_array($action, ['approve', 'return', 'archive'], true) && !$this->publisher($actor))
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

    private function identity(string $page): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,191}$/D', $page) !== 1) {
            self::invalid('Choose a valid website page.');
        }
        return $page;
    }

    private function intent(
        string $page,
        string $action,
        string $revision,
        PublicationWindow $window,
        ?string $note,
    ): string {
        return $this->hash([$page, $action, $revision, $window->data(), $note]);
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([['field' => 'publication', 'message' => $message]]);
    }
}
