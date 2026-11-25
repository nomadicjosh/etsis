<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Transcript\ValueObject\TranscriptStandingPresentation;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Transcript\Repository\TranscriptReleasePolicyRepository;
use Domain\Transcript\ValueObject\TranscriptReleasePolicy;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTranscriptReleasePolicyRepository implements TranscriptReleasePolicyRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    public function current(): TranscriptReleasePolicy
    {
        return $this->decode(
            $this->sql->rows("sis_transcript_policy", ["id" => self::POLICY_ID], 1)[0] ?? null,
        );
    }

    public function lock(): void
    {
        $this->sql->lock("sis_transcript_policy", self::POLICY_ID, "id");
    }

    public function save(TranscriptReleasePolicy $policy, int $expectedRevision): void
    {
        $packet = json_encode(
            ["kind" => "transcript-release-policy", "id" => self::POLICY_ID] + $policy->describe(),
            JSON_THROW_ON_ERROR,
        );
        if ($policy->revision !== $expectedRevision + 1 || strlen($packet) > 65536) {
            throw new \InvalidArgumentException("Use a bounded next release-policy revision.");
        }
        if (
            $this->sql->update(
                "sis_transcript_policy",
                ["revision" => $policy->revision, "encrypted_definition" => $this->cipher->seal($packet)],
                ["id" => self::POLICY_ID, "revision" => $expectedRevision],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The institutional release checks changed. Reload their setup.",
            );
        }
    }

    public function assertCurrent(TranscriptReleasePolicy $expected): void
    {
        // current() acquires the database row lock and bypasses a repeatable-read snapshot.
        $current = $this->decode($this->sql->current("sis_transcript_policy", self::POLICY_ID));
        if ($current->describe() !== $expected->describe()) {
            throw new ConcurrentModificationException(
                "The institutional release checks changed. Review them again.",
            );
        }
    }

    /** @param array<string,mixed>|null $row */
    private function decode(?array $row): TranscriptReleasePolicy
    {
        $revision = filter_var($row["revision"] ?? null, FILTER_VALIDATE_INT);
        if ($row === null || $revision === false || $revision < 0 || $revision > 2000000000) {
            throw new \RuntimeException("Institutional release checks are unavailable.");
        }
        if ($revision === 0 && $row["encrypted_definition"] === null) {
            return new TranscriptReleasePolicy();
        }
        $packet = $this->cipher->open($row["encrypted_definition"] ?? "");
        if (strlen($packet) > 65536) {
            throw new \RuntimeException("The institutional release policy is invalid.");
        }
        $data = json_decode($packet, true, 12, JSON_THROW_ON_ERROR);
        if (
            !is_array($data) ||
            !in_array(count($data), [4, 5, 6, 7], true) ||
            array_diff(array_keys($data), [
                "kind", "id", "revision", "checks", "include_standing", "standing_definitions", "standing_presentation",
            ]) !== [] ||
            (array_key_exists("include_standing", $data) && !is_bool($data["include_standing"])) ||
            ($data["kind"] ?? null) !== "transcript-release-policy" ||
            ($data["id"] ?? null) !== self::POLICY_ID ||
            ($data["revision"] ?? null) !== $revision ||
            !is_array($data["checks"] ?? null)
        ) {
            throw new \RuntimeException("The institutional release policy could not be verified.");
        }
        return new TranscriptReleasePolicy(
            $revision,
            $data["checks"],
            $data["include_standing"] ?? false,
            $data['standing_definitions'] ?? null,
            array_key_exists('standing_presentation', $data)
                ? TranscriptStandingPresentation::fromArray($data['standing_presentation'])
                : new TranscriptStandingPresentation(),
        );
    }
}
