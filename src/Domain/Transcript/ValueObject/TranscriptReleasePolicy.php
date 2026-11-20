<?php

declare(strict_types=1);

namespace Domain\Transcript\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Audited institutional configuration; applied snapshots remain in the existing protected artifacts. */
final readonly class TranscriptReleasePolicy
{
    /** @var list<array{id:string,label:string,details:string,stage:string}> */
    public array $checks;

    /** @param list<array{id:string,label:string,details:string,stage:string}> $checks */
    public function __construct(
        public int $revision = 0,
        array $checks = [],
        public bool $includeStanding = false,
    ) {
        if ($revision < 0 || $revision > 2000000000 || !array_is_list($checks) || count($checks) > 12) {
            self::invalid();
        }
        $ids = [];
        foreach ($checks as $check) {
            if (
                !is_array($check) ||
                count($check) !== 4 ||
                array_diff(array_keys($check), ["id", "label", "details", "stage"]) !== [] ||
                !array_all($check, static fn(mixed $v): bool => is_string($v)) ||
                !in_array($check["stage"], ["preparation", "delivery", "both"], true) ||
                preg_match('/[\x00-\x1F\x7F]/', $check["label"]) ||
                trim($check["label"]) === "" ||
                trim($check["label"]) !== $check["label"] ||
                mb_strlen($check["label"]) > 160 ||
                mb_strlen($check["details"]) > 500 ||
                !mb_check_encoding($check["label"] . $check["details"], "UTF-8") ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $check["label"] . $check["details"])
            ) {
                self::invalid();
            }
            new EntityId($check["id"]);
            $ids[] = $check["id"];
        }
        if (count(array_unique($ids)) !== count($ids)) {
            self::invalid();
        }
        $this->checks = array_map(
            static fn(array $c): array => [
                "id" => $c["id"],
                "label" => $c["label"],
                "details" => $c["details"],
                "stage" => $c["stage"],
            ],
            $checks,
        );
    }

    /** @return array{revision:int,checks:array,include_standing:bool} */
    public function describe(): array
    {
        return [
            "revision" => $this->revision,
            "checks" => $this->checks,
            "include_standing" => $this->includeStanding,
        ];
    }

    /** @return list<array{id:string,label:string,details:string,stage:string}> */
    public function required(string $stage): array
    {
        if (!in_array($stage, ["preparation", "delivery"], true)) {
            throw new \InvalidArgumentException("Choose preparation or delivery.");
        }
        return array_values(
            array_filter(
                $this->checks,
                static fn(array $c): bool => $c["stage"] === $stage || $c["stage"] === "both",
            ),
        );
    }

    /** @return list<string> */
    public function confirm(string $stage, ?TranscriptReleaseConfirmation $confirmation = null): array
    {
        $ids = array_column($this->required($stage), "id");
        sort($ids, SORT_STRING);
        if ($confirmation !== null && $confirmation->revision !== $this->revision) {
            throw new ConcurrentModificationException(
                "The institutional release checks changed. Review them again.",
            );
        }
        if (($confirmation?->checks ?? []) !== $ids) {
            throw new ValidationException([
                [
                    "field" => "release_checks",
                    "message" => "Confirm each institutional release check before continuing.",
                ],
            ]);
        }
        return $ids;
    }

    /** @return array<string,mixed> */
    public function evidence(
        string $stage,
        EntityId $actor,
        string $at,
        ?TranscriptReleaseConfirmation $confirmation = null,
    ): array {
        return [
            "stage" => $stage,
            "policy" => $this->describe(),
            "confirmed" => $this->confirm($stage, $confirmation),
            "actor_id" => $actor->value,
            "confirmed_at" => $at,
        ];
    }

    /** Validate retained evidence without consulting today's policy.
     * @param array<string,mixed> $evidence */
    public static function validateEvidence(array $evidence, string $stage, string $actor, string $at): void
    {
        if (
            count($evidence) !== 5 ||
            ($evidence["stage"] ?? null) !== $stage ||
            ($evidence["actor_id"] ?? null) !== $actor ||
            ($evidence["confirmed_at"] ?? null) !== $at ||
            !is_array($evidence["policy"] ?? null) ||
            !in_array(count($evidence["policy"]), [2, 3], true) ||
            array_diff(array_keys($evidence["policy"]), ["revision", "checks", "include_standing"]) !== [] ||
            (array_key_exists("include_standing", $evidence["policy"]) &&
                !is_bool($evidence["policy"]["include_standing"])) ||
            !is_int($evidence["policy"]["revision"] ?? null) ||
            !is_array($evidence["policy"]["checks"] ?? null) ||
            !is_array($evidence["confirmed"] ?? null) ||
            new DateTimeImmutable($at)->format(DATE_ATOM) !== $at
        ) {
            throw new \RuntimeException("The retained release confirmation is invalid.");
        }
        new EntityId($actor);
        $policy = new self(
            $evidence["policy"]["revision"],
            $evidence["policy"]["checks"],
            $evidence["policy"]["include_standing"] ?? false,
        );
        $confirmation = new TranscriptReleaseConfirmation($policy->revision, $evidence["confirmed"]);
        if ($confirmation->checks !== $evidence["confirmed"]) {
            throw new \RuntimeException("The retained release confirmation is not canonical.");
        }
        $policy->confirm($stage, $confirmation);
    }

    /** Curated staff view; protected actor references stay in the stored evidence.
     * @param array<string,mixed> $evidence
     * @return array<string,mixed> */
    public static function summary(array $evidence): array
    {
        $policy = new self(
            $evidence["policy"]["revision"],
            $evidence["policy"]["checks"],
            $evidence["policy"]["include_standing"] ?? false,
        );
        return [
            "revision" => $policy->revision,
            "confirmed_at" => $evidence["confirmed_at"],
            "checks" => $policy->required($evidence["stage"]),
            "include_standing" => $policy->includeStanding,
        ];
    }

    private static function invalid(): never
    {
        throw new ValidationException([
            [
                "field" => "checks",
                "message" =>
                    "Use up to 12 distinct release checks, with a title, instructions and an applicable stage.",
            ],
        ]);
    }
}
