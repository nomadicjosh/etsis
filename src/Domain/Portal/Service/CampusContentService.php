<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Portal\ValueObject\ContentAudience;
use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\PortalPost;
use Domain\Portal\Repository\CampusRepository;
use Domain\Portal\Repository\PortalRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Domain\Portal\ValueObject\PublicationWindow;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;

use function Codefy\Framework\Helpers\trans;

final readonly class CampusContentService
{
    public function __construct(
        private PortalRepository $posts,
        private CampusRepository $campus,
        private PortalService $portal,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private ContentMarkup $markup,
        private \Domain\Portal\Repository\ContentAudienceRepository $audiences,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(EntityId $id): array
    {
        $actor = $this->access->requirePermission("sis:portal:manage");
        $post = $this->posts->get($id);
        $this->authorize("read", $post, $actor);
        $data = $this->data($post);
        $data["can_edit"] = $this->editable($post, $actor);
        $data["can_publish"] = $this->publisher($actor);
        $data["visibility"] = $post->visibility($this->clock->now());
        $data["revision"] = $this->revision($post);
        $this->authorize("read", $post, $actor);
        return $data;
    }

    public function review(
        EntityId $id,
        string $action,
        string $revision,
        PublicationWindow $window,
        #[\SensitiveParameter] ?string $note,
    ): array {
        $actor = $this->access->requirePermission("sis:portal:manage");
        return $this->transactions->run(function () use (
            $id,
            $action,
            $revision,
            $window,
            $note,
            $actor,
        ): array {
            $this->posts->lock($id);
            $post = $this->posts->get($id);
            $this->authorize($action, $post, $actor);
            $note = $this->note($action, $note);
            $this->check($post, $revision);
            $this->assess($post, $actor, $action, $window, $note);
            $operation = EntityId::generate();
            $intent = $this->intent($action, $revision, $window, $note);
            $this->authorize($action, $post, $actor);
            return [
                "operation_id" => $operation->value,
                "revision" => $revision,
                "action" => $action,
                "window" => $window->data(),
                "note" => $note,
                "post" => $this->data($post),
                "review_token" => $this->proofs->issue(
                    "campus-content",
                    $action,
                    $id,
                    $actor,
                    $operation,
                    $intent,
                ),
            ];
        });
    }

    public function confirm(
        EntityId $id,
        EntityId $operation,
        string $action,
        string $revision,
        PublicationWindow $window,
        #[\SensitiveParameter] ?string $note,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->access->requirePermission("sis:portal:manage");
        return $this->transactions->run(function () use (
            $id,
            $operation,
            $action,
            $revision,
            $window,
            $note,
            $token,
            $actor,
        ): array {
            $this->posts->lock($id);
            $post = $this->posts->get($id);
            $this->authorize($action, $post, $actor);
            $note = $this->note($action, $note);
            $intent = $this->intent($action, $revision, $window, $note);
            $receipt = $this->posts->receipt($operation);
            if (
                $receipt !== null &&
                ($receipt["actor_id"] !== $actor->value ||
                    $receipt["post_id"] !== $id->value ||
                    !hash_equals($receipt["intent"], $intent))
            ) {
                throw new AccessDeniedException();
            }
            $this->proofs->validate(
                $token,
                "campus-content",
                $action,
                $id,
                $actor,
                $operation,
                $intent,
                $receipt !== null,
            );
            if ($receipt !== null) {
                return $receipt["result"];
            }
            $this->check($post, $revision);
            $this->assess($post, $actor, $action, $window, $note);
            $next = $post->transition($action, $window, $note);
            $audience = $this->campus->audience($id);
            $roles = $this->audiences->selected($id);
            $programs = $this->audiences->selectedPrograms($id);
            $this->posts->save($next, $post->version);
            $result = [
                "id" => $id->value,
                "status" => $next->status,
                "version" => $next->version,
                "action" => $action,
            ];
            $this->posts->saveReceipt($operation, $id, $actor, $intent, $result);
            $this->audit->record(
                $actor,
                "portal." .
                    match ($action) {
                        "approve" => "published",
                        "submit" => "submitted",
                        "return" => "returned",
                        "archive" => "archived",
                    },
                $id,
            );
            $this->authorize($action, $next, $actor);
            if ($action === "approve") {
                $window->requireFuture($this->clock->now());
            }
            $this->proofs->validate($token, "campus-content", $action, $id, $actor, $operation, $intent);
            if (in_array($action, ['approve', 'submit'], true)) {
                $this->verifyAudience($next);
            }
            if (
                $this->posts->get($id) != $next ||
                $this->campus->audience($id) !== $audience ||
                $this->audiences->selected($id) !== $roles ||
                $this->audiences->selectedPrograms($id) !== $programs ||
                $this->posts->receipt($operation) !== [
                    "post_id" => $id->value,
                    "actor_id" => $actor->value,
                    "intent" => $intent,
                    "result" => $result,
                ]
            ) {
                throw new ConcurrentModificationException(
                    "Content changed during confirmation. Review it again.",
                );
            }
            return $result;
        });
    }

    private function assess(
        #[\SensitiveParameter] PortalPost $post,
        EntityId $actor,
        string $action,
        PublicationWindow $window,
        ?string $note,
    ): void {
        if (in_array($action, ['approve', 'submit'], true)) {
            $this->verifyAudience($post);
        }
        if ($action === "approve") {
            if ($post->status === "draft" && !$this->editable($post, $actor)) {
                throw new AccessDeniedException();
            }
            $window->requireFuture($this->clock->now());
        } elseif ($window->data() !== ["visible_from" => null, "visible_until" => null]) {
            self::invalid("Use publication times only when approving content.");
        }
        $post->transition($action, $window, $note);
    }

    private function authorize(
        string $action,
        #[\SensitiveParameter] PortalPost $post,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->requirePermission("sis:portal:manage");
        if (
            $this->access->authenticatedUserId()->value !== $actor->value ||
            ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        if ($action === "submit" || $action === "edit") {
            if (!$this->editable($post, $actor)) {
                throw new AccessDeniedException();
            }
        } elseif ($action === "read") {
            if (!$this->editable($post, $actor) && !$this->publisher($actor)) {
                throw new AccessDeniedException();
            }
        } elseif (in_array($action, ["approve", "return", "archive"], true)) {
            if (!$this->publisher($actor)) {
                throw new AccessDeniedException();
            }
        } else {
            self::invalid("Choose a valid content action.");
        }
        return $actor;
    }

    private function editable(#[\SensitiveParameter] PortalPost $post, EntityId $actor): bool
    {
        try {
            return $this->access->requireOwnerOrPermission($post->ownerUserId, "sis:portal:admin")->value ===
            $actor->value;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function publisher(EntityId $actor): bool
    {
        try {
            return $this->access->requirePermission("sis:portal:publish")->value === $actor->value;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    private function note(string $action, #[\SensitiveParameter] ?string $note): ?string
    {
        if ($action !== "return") {
            if ($note !== null) {
                self::invalid("Use a review note only when returning content to its author.");
            }
            return null;
        }
        if (
            $note === null ||
            trim($note) === "" ||
            !mb_check_encoding($note, "UTF-8") ||
            mb_strlen($note) > 1000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)
        ) {
            self::invalid("Enter a review note up to 1,000 characters.");
        }
        return trim($note);
    }

    private function data(#[\SensitiveParameter] PortalPost $post): array
    {
        return [
            "id" => $post->id->value,
            "kind" => $post->kind,
            "code" => $post->code,
            "title" => $post->title,
            "content" => $post->content,
            "status" => $post->status,
            "version" => $post->version,
            "audience" => $this->campus->audience($post->id),
            "audience_roles" => $this->audiences->selected($post->id),
            "visible_from" => $post->visibleFrom,
            "visible_until" => $post->visibleUntil,
            "review_note" => $post->reviewNote,
            "content_format" => $post->contentFormat,
            "content_html" =>
                $post->contentFormat === "html" ? $this->markup->sanitize($post->content) : null,
        ] + ($this->campus->audience($post->id) === 'programs'
            ? ['audience_programs' => $this->audiences->selectedPrograms($post->id)] : []);
    }

    private function revision(#[\SensitiveParameter] PortalPost $post): string
    {
        return hash(
            "sha256",
            json_encode([$post->ownerUserId->value, $this->data($post)], JSON_THROW_ON_ERROR),
        );
    }

    private function check(#[\SensitiveParameter] PortalPost $post, string $revision): void
    {
        if (!hash_equals($this->revision($post), $revision)) {
            throw new ConcurrentModificationException(
                "The content or audience changed. Reopen it and review again.",
            );
        }
    }

    private function intent(
        string $action,
        string $revision,
        PublicationWindow $window,
        ?string $note,
    ): string {
        return hash("sha256", json_encode([$action, $revision, $window->data(), $note], JSON_THROW_ON_ERROR));
    }

    private static function invalid(string $message): never
    {
        throw new ValidationException([["field" => "publication", "message" => $message]]);
    }

    /** Role labels are authoring metadata; this endpoint exposes no grants or account membership. */
    public function audienceRoles(string $search, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission('sis:portal:manage');
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search', 'message' => trans(
                'Search account role names using up to eighty characters.',
            )]]);
        }
        $roles = $this->audiences->roles(trim($search), $after);
        $more = count($roles) > 20;
        $roles = array_slice($roles, 0, 20);
        if (
            $this->access->requirePermission('sis:portal:manage')->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return ['items' => $roles, 'next_after' => $more ? end($roles)['id'] : null];
    }

    /** Program directory labels only; no student populations or membership counts. */
    public function audiencePrograms(string $search, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission('sis:portal:manage');
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search', 'message' => trans(
                'Search program codes or titles using up to eighty characters.',
            )]]);
        }
        $programs = $this->audiences->programs(trim($search), $after);
        $more = count($programs) > 20;
        $programs = array_slice($programs, 0, 20);
        if (
            $this->access->requirePermission('sis:portal:manage')->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return ['items' => $programs, 'next_after' => $more ? end($programs)['id'] : null];
    }

    private function programDefinitions(ContentAudience $selection): array
    {
        $programs = $this->audiences->programDefinitions($selection->programs);
        if (array_column($programs, 'id') !== $selection->programs) {
            throw new ValidationException([['field' => 'audience_programs', 'message' => trans(
                'Some selected programs are unavailable. Review the audience again.',
            )]]);
        }
        return $programs;
    }

    private function roleDefinitions(ContentAudience $selection): array
    {
        $roles = $this->audiences->definitions($selection->roles);
        if (array_column($roles, 'id') !== $selection->roles) {
            throw new ValidationException([['field' => 'audience_roles', 'message' => trans(
                'Some selected account roles are unavailable. Review the audience again.',
            )]]);
        }
        return $roles;
    }

    private function verifyAudience(PortalPost $post): void
    {
        $roles = $this->audiences->selected($post->id);
        $programs = $this->audiences->selectedPrograms($post->id);
        $selection = new ContentAudience(
            $this->campus->audience($post->id),
            $post->kind,
            array_column($roles, 'id'),
            array_column($programs, 'id'),
        );
        if ($this->programDefinitions($selection) !== $programs) {
            throw new ConcurrentModificationException(trans(
                'The selected programs changed. Save and review the audience again.',
            ));
        }
        if ($this->roleDefinitions($selection) !== $roles) {
            throw new ConcurrentModificationException(trans(
                'The selected account roles changed. Save and review the audience again.',
            ));
        }
    }

    /** @param array<mixed> $audienceRoles
     * @param array<mixed> $audiencePrograms
     */
    public function save(
        ?EntityId $id,
        string $kind,
        string $code,
        string $title,
        #[\SensitiveParameter] string $content,
        string $audience,
        ?int $version,
        string $contentFormat = "plain",
        array $audienceRoles = [],
        array $audiencePrograms = [],
    ): EntityId {
        $this->access->requirePermission("sis:portal:manage");
        if (
            !in_array($contentFormat, ["plain", "html"], true) ||
            ($kind !== "news" && $contentFormat !== "plain")
        ) {
            self::invalid("Use rich text only for news.");
        }
        if ($contentFormat === "html") {
            $content = $this->markup->sanitize($content);
            if (trim($this->markup->plainText($content)) === "") {
                throw new ValidationException([
                    ["field" => "content", "message" => "Add some news text before saving."],
                ]);
            }
        }
        PortalPost::requireWritableContent($content);
        $selection = new ContentAudience($audience, $kind, $audienceRoles, $audiencePrograms);
        return $this->transactions->run(function () use (
            $id,
            $kind,
            $code,
            $title,
            $content,
            $audience,
            $version,
            $contentFormat,
            $selection,
        ): EntityId {
            $actor = $this->access->requirePermission("sis:portal:manage");
            $roles = $this->roleDefinitions($selection);
            $programs = $this->programDefinitions($selection);
            if ($id === null) {
                $post = $this->portal->create($kind, $code, $title, $content, $contentFormat);
                $id = $post->id;
            } else {
                $this->posts->lock($id);
                $existing = $this->posts->get($id);
                $this->authorize("edit", $existing, $actor);
                if ($kind !== $existing->kind || $code !== $existing->code || $version === null) {
                    throw new InvalidArgumentException(
                        "Keep the original content type and code when editing.",
                    );
                }
                $post = new PortalPost(
                    $id,
                    $existing->ownerUserId,
                    $kind,
                    $code,
                    $title,
                    $content,
                    "draft",
                    $version + 1,
                    contentFormat: $contentFormat,
                );
                $this->posts->revise($post, $version);
                $this->audit->record($actor, "portal.revised", $id);
            }
            $this->campus->setAudience($id, $audience);
            $this->audiences->replace($id, $roles);
            $this->audiences->replacePrograms($id, $programs);
            $this->authorize("edit", $post, $actor);
            $this->verifyAudience($post);
            if (
                $this->posts->get($id) != $post || $this->campus->audience($id) !== $audience ||
                $this->audiences->selected($id) !== $roles ||
                $this->audiences->selectedPrograms($id) !== $programs
            ) {
                throw new ConcurrentModificationException(
                    "Content changed while saving. Reopen it before editing.",
                );
            }
            return $id;
        });
    }
}
