<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Import\Service\ImportQueueService;
use Domain\Import\ValueObject\ImportPlanFingerprint;
use Domain\Person\Repository\PhotoRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;

/** Matching/review is bounded working data; every actual import is performed by the existing server worker. */
final readonly class PhotoImportService
{
    public function __construct(
        private PersonPhotoService $photos,
        private PhotoRepository $records,
        private PhotoStorage $storage,
        private PhotoArchive $archive,
        private ImportQueueService $queue,
        private MessageCipher $cipher,
        private UnitOfWork $transactions,
    ) {
    }

    public function forAccess(StudentAccess $access): self
    {
        return new self(
            $this->photos->forAccess($access),
            $this->records,
            $this->storage,
            $this->archive,
            $this->queue,
            $this->cipher,
            $this->transactions,
        );
    }

    public function authorize(): EntityId
    {
        return $this->photos->authorizeImport();
    }

    public function review(
        #[\SensitiveParameter] string $bytes,
        string $filename,
        string $mode,
        string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = trim($reason);
        if (
            !in_array($mode, ["id", "name"], true) ||
            trim($reason) === "" ||
            mb_strlen($reason) > 500 ||
            !mb_check_encoding($reason, 'UTF-8') ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new \InvalidArgumentException(
                "Choose an ID or name match and give an import reason.",
            );
        }
        $entries = $this->archive->entries($bytes, $filename);
        $seen = [];
        $plan = [];
        $ready = true;
        foreach ($entries as $entry) {
            $matches = $this->records->matches(
                pathinfo($entry["filename"], PATHINFO_FILENAME),
                $mode,
            );
            $issue =
            count($matches) !== 1
            ? "unmatched"
            : (isset($seen[$matches[0]["id"]])
                        ? "duplicate"
                        : null);
            $basis = null;
            if ($issue === null) {
                $id = new EntityId($matches[0]["id"]);
                $basis = $this->photos->snapshot($id, true);
                unset($basis["facts"]);
                $seen[$id->value] = true;
            }
            $ready = $ready && $issue === null;
            $plan[] = $entry + ["basis" => $basis, "issue" => $issue];
        }
        $preview = ["ready" => $ready, "plan" => $plan, "review_token" => null];
        if (!$ready) {
            return $preview;
        }
        return $this->transactions->run(function () use (
            $actor,
            $bytes,
            $filename,
            $mode,
            $reason,
            $plan,
            $preview,
        ): array {
            $this->authorize();
            $id = EntityId::generate();
            $expires = $this->photos->stamp("+2 days");
            $this->records->add([
                "id" => $id->value,
                "person_id" => null,
                "actor_id" => $actor->value,
                "state" => "archive",
                "expires_at" => $expires,
                "encrypted_manifest" => $this->cipher->seal(
                    json_encode(
                        [
                            "kind" => "photo-import",
                            "id" => $id->value,
                            "actor_id" => $actor->value,
                            "person_id" => null,
                            "state" => "archive",
                            "expires_at" => $expires,
                            "filename" => $filename,
                            "mode" => $mode,
                            "digest" => hash("sha256", $bytes),
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
            ]);
            $this->storage->put($id, $bytes);
            $result = $this->queue->stage(
                "photos",
                [
                    "archive_id" => $id->value,
                    "items" => $plan,
                    "reason" => $reason,
                ],
                array_replace($preview, [
                    "review_token" => "server-prepared-photo-plan",
                ]),
            );
            if ($this->authorize()->value !== $actor->value) {
                throw new ConcurrentModificationException();
            }
            return $result;
        });
    }

    public function process(
        array $request,
        array $expected,
        int $offset,
        int $limit,
    ): array {
        $actor = $this->authorize();
        if (
            $limit !== 1 ||
            count($expected) !== 1 ||
            !isset($request["items"][$offset])
        ) {
            throw new ConcurrentModificationException();
        }
        $item = $request["items"][$offset];
        if (
            $expected[0] !== ImportPlanFingerprint::of($item) ||
            $item["issue"] !== null
        ) {
            throw new ConcurrentModificationException();
        }
        $id = new EntityId($request["archive_id"]);
        $row = $this->records->file($id);
        if (
            $row === null ||
            $row["state"] !== "archive" ||
            $row["actor_id"] !== $actor->value ||
            $row["expires_at"] <= $this->photos->stamp()
        ) {
            throw new ConcurrentModificationException();
        }
        $manifest = json_decode(
            $this->cipher->open($row["encrypted_manifest"]),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        $bytes = $this->storage->read($id);
        $matches = $this->records->matches(
            pathinfo($item["filename"], PATHINFO_FILENAME),
            $manifest["mode"],
        );
        if (
            $manifest["id"] !== $id->value ||
            $manifest["actor_id"] !== $actor->value ||
            $manifest["state"] !== $row["state"] ||
            $manifest["person_id"] !== null ||
            $manifest["expires_at"] !== $row["expires_at"] ||
            $manifest["kind"] !== "photo-import" ||
            !hash_equals($manifest["digest"], hash("sha256", $bytes)) ||
            count($matches) !== 1 ||
            $matches[0]["id"] !== $item["basis"]["person_id"]
        ) {
            throw new ConcurrentModificationException(
                "The photo import source changed after review.",
            );
        }
        $entry = ["index" => $item["index"], "filename" => $item["filename"]];
        $photo = $this->archive->read($bytes, $manifest["filename"], $entry);
        $this->photos->importPhoto(
            new EntityId($item["basis"]["person_id"]),
            DerivedIdentity::from($id, "photo." . $item["index"]),
            $item["basis"],
            $photo,
            $item["filename"],
            $request["reason"],
            [
                "archive_id" => $id->value,
                "ordinal" => $item["index"],
                "digest" => hash("sha256", $photo),
            ],
        );
        if ($offset + 1 === count($request["items"])) {
            $this->storage->remove($id);
            $this->records->remove($id);
        }
        return ["imported" => 1, "linked" => 0, "skipped" => 0, "omitted" => 0];
    }
}
