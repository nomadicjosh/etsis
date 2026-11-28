<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Codefy\Domain\Metadata;
use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Person\Event\PersonPhotoWasRecorded;
use Domain\Person\Person;
use Domain\Person\PhotoHistory;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\Repository\PhotoRepository;
use Domain\Person\ValueObject\PhotoHistoryId;
use Domain\Person\ValueObject\ProfilePhoto;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventEvidence;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

final readonly class PersonPhotoService
{
    public const string MANAGE = "sis:person:photo:manage";

    public function __construct(
        private PhotoRepository $records,
        private PersonProfileRepository $people,
        private PersonProfileAccess $profiles,
        private StudentAccess $access,
        private \Domain\User\Repository\LoginAccountStateReader $accounts,
        private AccessRepository $roles,
        private HistoricalEventStore $history,
        private HistoricalEventEvidence $evidence,
        private PhotoStorage $storage,
        private PhotoProcessor $processor,
        private GravatarReader $gravatar,
        private PhotoPolicy $policy,
        private MessageCipher $cipher,
        private ReviewProof $proofs,
        private Clock $clock,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    public function forAccess(StudentAccess $access): self
    {
        return new self(
            $this->records,
            $this->people,
            $this->profiles->forAccess($access),
            $access,
            $this->accounts,
            $this->roles,
            $this->history,
            $this->evidence,
            $this->storage,
            $this->processor,
            $this->gravatar,
            $this->policy,
            $this->cipher,
            $this->proofs,
            $this->clock,
            $this->transactions,
            $this->audit,
        );
    }

    public function authorizeImport(): EntityId
    {
        $actor = $this->access->requirePermission("sis:import:manage");
        $this->staffAuthority($actor, true, true);
        if (
            $this->accounts->current($actor) === null ||
            !$this->roles->allows($actor->value, "sis:import:manage")
        ) {
            throw new AccessDeniedException();
        }
        $permissions = ["sis:person:manage", self::MANAGE];
        foreach ($permissions as $permission) {
            if (
                $this->access->requirePermission($permission)->value !==
                $actor->value
            ) {
                throw new AccessDeniedException();
            }
        }
        $this->policy->requireEnabled();
        return $actor;
    }

    private function withPerson(
        ?EntityId $id,
        bool $staff,
        bool $write,
        callable $action,
    ): mixed {
        $actor = $this->profiles->actor();
        return $this->transactions->run(function () use (
            $id,
            $actor,
            $staff,
            $write,
            $action,
        ): mixed {
            $this->roles->lock();
            $login = $this->accounts->current($actor);
            if ($login === null) {
                throw new AccessDeniedException();
            }
            $before = $this->profiles->person($actor, $id, $staff);
            $this->staffAuthority($actor, $staff, $write);
            $this->people->lock($before->id);
            $before = $this->profiles->person($actor, $id, $staff);
            if (
                $staff &&
                $write &&
                $this->access->requirePermission(self::MANAGE)->value !==
                    $actor->value
            ) {
                throw new AccessDeniedException();
            }
            $result = $action($before, $actor);
            $after = $this->profiles->person($actor, $before->id, $staff);
            $this->staffAuthority($actor, $staff, $write);
            if (
                $staff &&
                $write &&
                $this->access->requirePermission(self::MANAGE)->value !==
                    $actor->value
            ) {
                throw new AccessDeniedException();
            }
            if (
                $this->accounts->current($actor) != $login ||
                $before->identityVersion !== $after->identityVersion ||
                $before->ownerUserId?->value !== $after->ownerUserId?->value
            ) {
                throw new ConcurrentModificationException(
                    "The person changed. Review the photo again.",
                );
            }
            return $result;
        });
    }

    private function staffAuthority(
        EntityId $actor,
        bool $staff,
        bool $write,
    ): void {
        if ($staff) {
            $permissions = ["sis:person:manage", ...$write ? [self::MANAGE] : []];
            foreach ($permissions as $permission) {
                if (!$this->roles->allows($actor->value, $permission)) {
                    throw new AccessDeniedException();
                }
            }
        }
    }

    private function stream(EntityId $person): StreamReference
    {
        return new StreamReference(
            new PhotoHistoryId($person->value),
            "person_photo",
        );
    }

    private function selected(EntityId $person): array
    {
        $stored = $this->history->latest($this->stream($person));
        $active = $this->records->active($person);
        if ($stored === null) {
            if ($active !== []) {
                throw new CorruptHistoryException();
            }
            return ["version" => 0, "facts" => null];
        }
        if ($active !== []) {
            $this->manifest($active[0]);
        }
        $stored = $this->evidence->recordedEvent(
            new EntityId((string) $stored->event->eventId()),
        );
        $p = $stored->event->payload();
        PhotoHistory::validate($p);
        if (
            !($stored->event instanceof PersonPhotoWasRecorded) ||
            $p["person_id"] !== $person->value ||
            $stored->context->actor === null ||
            $stored->context->reason === null ||
            $stored->context->origin !== "command" ||
            $stored->context->correlation?->value !== $p["operation_id"] ||
            !hash_equals(
                $p["intent"],
                PhotoHistory::intent(
                    $p,
                    $stored->context->reason,
                    $stored->event->playhead() - 1,
                ),
            ) ||
            count($active) !== ($p["photo_id"] === null ? 0 : 1) ||
            ($p["photo_id"] !== null && $active[0]["id"] !== $p["photo_id"])
        ) {
            throw new CorruptHistoryException();
        }
        return ["version" => $stored->event->playhead(), "facts" => $p];
    }

    public function snapshot(?EntityId $id, bool $staff): array
    {
        return $this->withPerson($id, $staff, true, function (
            Person $person,
        ) use ($staff): array {
            $this->policy->requireEnabled();
            $current = $this->selected($person->id);
            if (!$staff && ($current["facts"]["institutional"] ?? false)) {
                throw new ValidationException([
                    [
                        "field" => "photo",
                        "message" =>
                            trans("This photo is managed by your institution. Contact staff for a correction."),
                    ],
                ]);
            }
            return $current + [
                "person_id" => $person->id->value,
                "owner" => $person->ownerUserId->value,
                "person_label" =>
                    $person->givenName . " " . $person->familyName,
                "identity_version" => $person->identityVersion,
            ];
        });
    }

    public function view(?EntityId $id, bool $staff): array
    {
        return $this->withPerson($id, $staff, false, function (
            Person $person,
            EntityId $actor,
        ) use ($staff): array {
            $current = $this->selected($person->id);
            $facts = $current["facts"];
            return [
                "version" => $current["version"],
                "has_photo" => $facts !== null && $facts["photo_id"] !== null,
                "institutional" => $facts["institutional"] ?? false,
                "source" => $facts["source"] ?? null,
                "enabled" => $this->policy->available(),
                "gravatar_enabled" => !$staff && $this->policy->gravatarEnabled,
                "can_edit" =>
                    $this->policy->available() &&
                    ($staff
                        ? $this->roles->allows($actor->value, self::MANAGE)
                        : !($facts["institutional"] ?? false)),
            ];
        });
    }

    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === "" ||
            mb_strlen($reason) > 500 ||
            !mb_check_encoding($reason, "UTF-8") ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                [
                    "field" => "reason",
                    "message" =>
                        trans("Enter a photo-change reason up to 500 characters."),
                ],
            ]);
        }
        return $reason;
    }

    public function review(
        ?EntityId $person,
        bool $staff,
        int $version,
        string $source,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $bytes = "",
        string $filename = "",
        bool $consent = false,
    ): array {
        $reason = $this->reason($reason);
        $basis = $this->snapshot($person, $staff);
        if (
            $source === "removed" &&
            ($basis["facts"]["photo_id"] ?? null) === null
        ) {
            throw new ValidationException([
                [
                    "field" => "photo",
                    "message" => trans("There is no current photo to remove."),
                ],
            ]);
        }
        if (
            $version !== $basis["version"] ||
            !in_array(
                $source,
                ["upload", "camera", "gravatar", "removed"],
                true,
            )
        ) {
            throw new ConcurrentModificationException(
                "The photo changed. Reload before reviewing.",
            );
        }
        $emailHash = null;
        if ($source === "gravatar") {
            if ($staff || !$consent || !$this->policy->gravatarEnabled) {
                throw new AccessDeniedException(
                    "Gravatar requires enabled owner consent.",
                );
            }
            $email = $this->records->email(new EntityId($basis["owner"]));
            $emailHash = hash("sha256", $email);
            $bytes = $this->gravatar->image($email);
            $type = @getimagesizefromstring($bytes);
            $filename = ($type[2] ?? null) === IMAGETYPE_PNG ? "gravatar.png" : "gravatar.jpg";
        }
        $image = $source === "removed" ? null : $this->processor->normalize($bytes, $filename);
        return $this->stage(
            $basis,
            $staff,
            $source,
            $this->reason($reason),
            $image,
            $emailHash,
            EntityId::generate(),
        );
    }

    public function importPhoto(
        EntityId $person,
        EntityId $operation,
        array $basis,
        #[\SensitiveParameter] string $bytes,
        string $filename,
        #[\SensitiveParameter] string $reason,
        array $origin,
    ): void {
        $this->authorizeImport();
        $reason = $this->reason($reason);
        $accepted = $this->withPerson($person, true, true, function (
            Person $person,
            EntityId $actor,
        ) use (
            $operation,
            $basis,
            $origin,
            $reason,
        ): bool {
            $prior = $this->evidence->operationEvents($operation);
            if ($prior === null) {
                return false;
            }
            $stored = $prior[0];
            $p = $stored->event->payload();
            if (
                !($stored->event instanceof PersonPhotoWasRecorded) ||
                $p["source"] !== "import" ||
                $p["person_id"] !== $person->id->value ||
                $p["owner_user_id"] !== $basis["owner"] ||
                $p["identity_version"] !== $basis["identity_version"] ||
                !hash_equals(
                    PhotoHistory::hash($p["import_ref"]),
                    PhotoHistory::hash($origin),
                ) ||
                $stored->context->actor?->value !== $actor->value ||
                $stored->context->reason !== $reason ||
                $stored->event->playhead() !== $basis["version"] + 1 ||
                !hash_equals(
                    $p["intent"],
                    PhotoHistory::intent($p, $reason, $basis["version"]),
                )
            ) {
                throw new ConcurrentModificationException();
            }
            return true;
        });
        if ($accepted) {
            return;
        }
        $image = $this->processor->normalize($bytes, $filename);
        if (!hash_equals($origin["digest"], hash("sha256", $bytes))) {
            throw new CorruptHistoryException();
        }
        $draft = $this->stage(
            $basis,
            true,
            "import",
            $reason,
            $image,
            null,
            $operation,
            $origin,
        );
        $this->confirm($person, true, $operation, $draft["review_token"]);
    }

    private function stage(
        array $basis,
        bool $staff,
        string $source,
        string $reason,
        ?ProfilePhoto $image,
        ?string $emailHash,
        EntityId $id,
        ?array $importRef = null,
    ): array {
        return $this->withPerson(
            new EntityId($basis["person_id"]),
            $staff,
            true,
            function (
                Person $person,
                EntityId $actor,
            ) use (
                $basis,
                $staff,
                $source,
                $reason,
                $image,
                $emailHash,
                $id,
                $importRef,
            ): array {
                $this->policy->requireEnabled();
                if (
                    count($this->records->pending($actor, $this->stamp())) >= 5
                ) {
                    throw new ValidationException([
                        [
                            "field" => "photo",
                            "message" =>
                                trans(
                                    "Discard an unfinished photo review or wait for it to expire "
                                    . "before starting another."
                                ),
                        ],
                    ]);
                }
                $current = $this->selected($person->id);
                if (
                    $current["version"] !== $basis["version"] ||
                    $person->identityVersion !== $basis["identity_version"] ||
                    $person->ownerUserId->value !== $basis["owner"] ||
                    (!$staff && ($current["facts"]["institutional"] ?? false))
                ) {
                    throw new ConcurrentModificationException(
                        "The person or photo changed. Review again.",
                    );
                }
                $facts = [
                    "person_id" => $person->id->value,
                    "owner_user_id" => $person->ownerUserId->value,
                    "identity_version" => $person->identityVersion,
                    "photo_id" => $image === null ? null : $id->value,
                    "institutional" => $staff && $image !== null,
                    "source" => $source,
                    "image_hash" => $image?->digest(),
                    "mode" => $staff ? "staff" : "owner",
                    "operation_id" => $id->value,
                    "import_ref" => $importRef,
                ];
                $facts["intent"] = PhotoHistory::intent(
                    $facts,
                    $reason,
                    $basis["version"],
                );
                PhotoHistory::validate($facts);
                $expires = $this->stamp("+20 minutes");
                $manifest = [
                    "id" => $id->value,
                    "actor_id" => $actor->value,
                    "person_id" => $person->id->value,
                    "state" => "review",
                    "expires_at" => $expires,
                    "facts" => $facts,
                    "version" => $basis["version"],
                    "reason" => $reason,
                    "email_hash" => $emailHash,
                ];
                $this->records->add([
                    "id" => $id->value,
                    "person_id" => $person->id->value,
                    "actor_id" => $actor->value,
                    "state" => "review",
                    "expires_at" => $expires,
                    "encrypted_manifest" => $this->cipher->seal(
                        json_encode($manifest, JSON_THROW_ON_ERROR),
                    ),
                ]);
                if ($image !== null) {
                    $this->storage->put($id, $image->bytes);
                }
                $this->audit->record(
                    $actor,
                    "person.photo_reviewed",
                    $person->id,
                );
                if (
                    $this->selected($person->id)["version"] !==
                    $basis["version"]
                ) {
                    throw new ConcurrentModificationException();
                }
                return [
                    "draft_id" => $id->value,
                    "version" => $basis["version"],
                    "source" => $source,
                    "institutional" => $facts["institutional"],
                    "removed" => $image === null,
                    "review_token" => $this->proofs->issue(
                        "person.photo",
                        $facts["mode"],
                        $person->id,
                        $actor,
                        $id,
                        $facts["intent"],
                    ),
                ];
            },
        );
    }

    private function manifest(array $row): array
    {
        if (strlen($row["encrypted_manifest"]) > 16384) {
            throw new CorruptHistoryException();
        }
        $manifest = json_decode(
            $this->cipher->open($row["encrypted_manifest"]),
            true,
            12,
            JSON_THROW_ON_ERROR,
        );
        $fields = ["id", "person_id", "actor_id", "state", "expires_at"];
        foreach ($fields as $field) {
            if (
                !array_key_exists($field, $manifest) ||
                $manifest[$field] !== $row[$field]
            ) {
                throw new CorruptHistoryException();
            }
        }
        return $manifest;
    }

    public function confirm(
        ?EntityId $person,
        bool $staff,
        EntityId $id,
        #[\SensitiveParameter] string $token,
    ): array {
        return $this->withPerson($person, $staff, true, function (
            Person $person,
            EntityId $actor,
        ) use (
            $staff,
            $id,
            $token,
        ): array {
            $mode = $staff ? "staff" : "owner";
            $prior = $this->evidence->operationEvents($id);
            if ($prior !== null) {
                $stored = $prior[0];
                $facts = $stored->event->payload();
                if (
                    !($stored->event instanceof PersonPhotoWasRecorded) ||
                    $stored->context->actor?->value !== $actor->value ||
                    $facts["person_id"] !== $person->id->value ||
                    $facts["mode"] !== $mode
                ) {
                    throw new AccessDeniedException();
                }
                $this->proofs->validate(
                    $token,
                    "person.photo",
                    $mode,
                    $person->id,
                    $actor,
                    $id,
                    $facts["intent"],
                    true,
                );
                return [
                    "version" => $stored->event->playhead(),
                    "accepted" => true,
                ];
            }
            $this->policy->requireEnabled();
            $row = $this->records->file($id);
            if (
                $row === null ||
                $row["person_id"] !== $person->id->value ||
                $row["actor_id"] !== $actor->value ||
                $row["state"] !== "review" ||
                $row["expires_at"] <= $this->stamp()
            ) {
                throw new ConcurrentModificationException(
                    "This photo review expired. Review again.",
                );
            }
            $draft = $this->manifest($row);
            $facts = $draft["facts"];
            PhotoHistory::validate($facts);
            $current = $this->selected($person->id);
            if (
                $facts["owner_user_id"] !== $person->ownerUserId->value ||
                $facts["identity_version"] !== $person->identityVersion ||
                $facts["person_id"] !== $person->id->value ||
                $facts["mode"] !== $mode ||
                $draft["version"] !== $current["version"] ||
                (!$staff && ($current["facts"]["institutional"] ?? false)) ||
                ($draft["email_hash"] !== null &&
                    !hash_equals(
                        $draft["email_hash"],
                        hash(
                            "sha256",
                            $this->records->email($person->ownerUserId),
                        ),
                    ))
            ) {
                throw new ConcurrentModificationException(
                    "The photo source or person changed. Review again.",
                );
            }
            $intent = PhotoHistory::intent(
                $facts,
                $draft["reason"],
                $current["version"],
            );
            $this->proofs->validate(
                $token,
                "person.photo",
                $mode,
                $person->id,
                $actor,
                $id,
                $intent,
            );
            if (
                !hash_equals($intent, $facts["intent"]) ||
                ($facts["photo_id"] !== null &&
                    !hash_equals(
                        $facts["image_hash"],
                        hash("sha256", $this->storage->read($id)),
                    ))
            ) {
                throw new CorruptHistoryException();
            }
            $event = PersonPhotoWasRecorded::occur(
                new PhotoHistoryId($person->id->value),
                $facts,
                [Metadata::AGGREGATE_TYPE => "person_photo"],
            )->withPlayhead($current["version"] + 1);
            $this->history->append(
                $this->stream($person->id),
                $id,
                $current["version"],
                new EventContext(
                    $actor,
                    $this->clock->now(),
                    $id,
                    reason: $draft["reason"],
                ),
                [$event],
            );
            if (($old = $current["facts"]["photo_id"] ?? null) !== null) {
                $oldId = new EntityId($old);
                $retired = $this->manifest($this->records->file($oldId));
                $retired["kind"] = "retired-photo";
                $retired["state"] = "retired";
                $retired["expires_at"] = $this->stamp(
                    "+" . $this->policy->retentionDays . " days",
                );
                $this->records->update($oldId, [
                    "state" => "retired",
                    "expires_at" => $retired["expires_at"],
                    "encrypted_manifest" => $this->cipher->seal(
                        json_encode($retired, JSON_THROW_ON_ERROR),
                    ),
                ]);
            }
            if ($facts["photo_id"] === null) {
                $this->records->remove($id);
            } else {
                $this->records->update($id, [
                    "state" => "active",
                    "expires_at" => null,
                    "encrypted_manifest" => $this->cipher->seal(
                        json_encode(
                            [
                                "kind" => "accepted-photo",
                                "id" => $id->value,
                                "person_id" => $person->id->value,
                                "actor_id" => $actor->value,
                                "state" => "active",
                                "expires_at" => null,
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                    ),
                ]);
            }
            $this->audit->record($actor, "person.photo_recorded", $person->id);
            if (
                $this->selected($person->id)["version"] !==
                $current["version"] + 1
            ) {
                throw new ConcurrentModificationException();
            }
            return ["version" => $current["version"] + 1, "accepted" => true];
        });
    }

    public function content(
        ?EntityId $person,
        bool $staff,
        ?EntityId $preview = null,
    ): string {
        return $this->withPerson($person, $staff, $preview !== null, function (
            Person $person,
            EntityId $actor,
        ) use (
            $preview,
            $staff,
        ): string {
            $current = $this->selected($person->id);
            if ($preview !== null) {
                $row = $this->records->file($preview);
                if (
                    $row === null ||
                    $row["person_id"] !== $person->id->value ||
                    $row["actor_id"] !== $actor->value ||
                    $row["state"] !== "review" ||
                    $row["expires_at"] <= $this->stamp()
                ) {
                    throw new AccessDeniedException();
                }
                $draft = $this->manifest($row);
                $facts = $draft["facts"];
                if (
                    $draft["version"] !== $current["version"] ||
                    $facts["owner_user_id"] !== $person->ownerUserId->value ||
                    $facts["identity_version"] !== $person->identityVersion
                ) {
                    throw new ConcurrentModificationException();
                }
            } else {
                $facts = $current["facts"];
            }
            if (($facts["photo_id"] ?? null) === null) {
                throw new \Domain\Shared\Exception\RecordNotFoundException(
                    "No current photo.",
                );
            }
            $bytes = $this->storage->read(new EntityId($facts["photo_id"]));
            if (!hash_equals($facts["image_hash"], hash("sha256", $bytes))) {
                throw new CorruptHistoryException();
            }
            if ($staff) {
                $this->audit->record(
                    $actor,
                    "person.photo_viewed",
                    $person->id,
                );
            }
            if (
                $this->selected($person->id)["version"] !== $current["version"]
            ) {
                throw new ConcurrentModificationException();
            }
            return $bytes;
        });
    }

    public function discard(?EntityId $person, bool $staff, EntityId $id): void
    {
        $this->withPerson($person, $staff, true, function (
            Person $person,
            EntityId $actor,
        ) use ($id): void {
            $row = $this->records->file($id);
            if ($row === null) {
                return;
            }
            if (
                $row["person_id"] !== $person->id->value ||
                $row["actor_id"] !== $actor->value
            ) {
                throw new AccessDeniedException();
            }
            if ($row["state"] !== "review") {
                return;
            }
            $this->storage->remove($id);
            $this->records->remove($id);
            $this->audit->record(
                $actor,
                "person.photo_review_discarded",
                $person->id,
            );
        });
    }

    public function purge(int $limit = 20): int
    {
        $actor = $this->access->requirePermission("sis:person:manage");
        $this->staffAuthority($actor, true, true);
        if ($this->accounts->current($actor) === null) {
            throw new AccessDeniedException();
        }
        if (
            $this->access->requirePermission(self::MANAGE)->value !==
                $actor->value ||
            $limit < 1 ||
            $limit > 20
        ) {
            throw new AccessDeniedException();
        }
        $removed = 0;
        $due = array_slice($this->records->due($this->stamp()), 0, $limit);
        foreach ($due as $row) {
            $removed += $this->transactions->run(function () use (
                $row,
                $actor,
            ): int {
                $this->roles->lock();
                $this->staffAuthority($actor, true, true);
                $this->access->requirePermission("sis:person:manage");
                $this->access->requirePermission(self::MANAGE);
                if ($row["person_id"] !== null) {
                    $person = new EntityId($row["person_id"]);
                    $this->profiles->person($actor, $person, true);
                    $this->people->lock($person);
                    if (
                        ($this->selected($person)["facts"]["photo_id"] ??
                            null) ===
                        $row["id"]
                    ) {
                        throw new CorruptHistoryException();
                    }
                }
                $id = new EntityId($row["id"]);
                $current = $this->records->file($id);
                if (
                    $current !== $row ||
                    $current["expires_at"] === null ||
                    $current["expires_at"] > $this->stamp()
                ) {
                    return 0;
                }
                $this->manifest($current);
                $this->storage->remove($id);
                $this->records->remove($id);
                $this->audit->record(
                    $actor,
                    "person.photo_artifact_expired",
                    $id,
                );
                $this->staffAuthority($actor, true, true);
                if (
                    $this->access->requirePermission(self::MANAGE)->value !==
                        $actor->value ||
                    $this->access->requirePermission("sis:person:manage")
                        ->value !== $actor->value
                ) {
                    throw new AccessDeniedException();
                }
                return 1;
            });
        }
        return $removed;
    }

    public function stamp(string $change = "now"): string
    {
        return $this->clock
            ->now()
            ->modify($change)
            ->setTimezone(new \DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s.u");
    }
}
