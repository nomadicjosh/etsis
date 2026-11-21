<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

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
            if (
                $this->posts->get($id) != $next ||
                $this->campus->audience($id) !== $audience ||
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
            "visible_from" => $post->visibleFrom,
            "visible_until" => $post->visibleUntil,
            "review_note" => $post->reviewNote,
            "content_format" => $post->contentFormat,
            "content_html" =>
                $post->contentFormat === "html" ? $this->markup->sanitize($post->content) : null,
        ];
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

    public function save(
        ?EntityId $id,
        string $kind,
        string $code,
        string $title,
        #[\SensitiveParameter] string $content,
        string $audience,
        ?int $version,
        string $contentFormat = "plain",
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
        if (!in_array($audience, ["public", "campus"], true)) {
            throw new InvalidArgumentException("Choose a public or campus audience.");
        }
        return $this->transactions->run(function () use (
            $id,
            $kind,
            $code,
            $title,
            $content,
            $audience,
            $version,
            $contentFormat,
        ): EntityId {
            $actor = $this->access->requirePermission("sis:portal:manage");
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
            $this->authorize("edit", $post, $actor);
            if ($this->posts->get($id) != $post || $this->campus->audience($id) !== $audience) {
                throw new ConcurrentModificationException(
                    "Content changed while saving. Reopen it before editing.",
                );
            }
            return $id;
        });
    }
}
