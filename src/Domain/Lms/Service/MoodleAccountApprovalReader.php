<?php

declare(strict_types=1);

namespace Domain\Lms\Service;

use Domain\Communications\Service\MessageCipher;
use Domain\Lms\ValueObject\MoodleBinding;
use Domain\Shared\Exception\AccessDeniedException;

/** Verify retained approval evidence before processing or inspecting its exact remote operation. */
final readonly class MoodleAccountApprovalReader
{
    public function __construct(private MessageCipher $cipher)
    {
    }

    /** @param array<string,mixed> $row
     * @return array<string,string> */
    public function read(#[\SensitiveParameter] array $row): array
    {
        $data = json_decode($this->cipher->open($row["encrypted_approval"]), true, 8, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) ||
            ($data["kind"] ?? null) !== "moodle-account-request" ||
            !hash_equals($row["review_hash"], hash("sha256", json_encode($data, JSON_THROW_ON_ERROR)))
        ) {
            throw new AccessDeniedException("Invalid account approval.");
        }
        $fields = [
            "id",
            "actor_id",
            "person_id",
            "binding_id",
            "binding_hash",
            "person_revision",
            "firstname",
            "lastname",
            "username",
            "email",
            "sign_in",
            "policy_hash",
        ];
        foreach ($fields as $field) {
            if (!is_string($data[$field] ?? null)) {
                throw new AccessDeniedException("Review the account policy again.");
            }
        }
        $references = [
            "id" => "id",
            "requested_by" => "actor_id",
            "person_id" => "person_id",
            "binding_id" => "binding_id",
        ];
        foreach ($references as $column => $field) {
            if ($row[$column] !== $data[$field]) {
                throw new AccessDeniedException("Invalid approval reference.");
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $data["policy_hash"]) !== 1) {
            throw new AccessDeniedException("Invalid account policy.");
        }
        return array_filter($data, is_string(...));
    }
    /** @param array<string,string> $data */
    public function requestHash(#[\SensitiveParameter] array $data, MoodleBinding $binding): string
    {
        // Match the connector's original external-function parameter order and JSON encoding exactly.
        return hash(
            "sha256",
            json_encode(
                [
                    "instance_hash" => $binding->instanceFingerprint,
                    "policy_hash" => $data["policy_hash"],
                    "operation" => $data["id"],
                    "username" => $data["username"],
                    "email" => $data["email"],
                    "firstname" => $data["firstname"],
                    "lastname" => $data["lastname"],
                    "sign_in" => $data["sign_in"],
                ],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
}
