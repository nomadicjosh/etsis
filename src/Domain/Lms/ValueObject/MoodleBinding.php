<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Initial institutional approval; no account link or academic authority is implied. */
final readonly class MoodleBinding
{
    public function __construct(
        public EntityId $id,
        public string $siteFingerprint,
        public string $instanceFingerprint,
        public EntityId $approvedBy,
        public string $approvedAt,
    ) {
        foreach ([$siteFingerprint, $instanceFingerprint] as $fingerprint) {
            if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException("A valid installation fingerprint is required.");
            }
        }
    }

    public function matches(MoodleInstallation $installation): bool
    {
        return hash_equals($this->siteFingerprint, $installation->siteFingerprint()) &&
        hash_equals($this->instanceFingerprint, $installation->instanceFingerprint());
    }

    /** @return array<string,string> */
    public function describe(): array
    {
        return [
            "id" => $this->id->value,
            "site_fingerprint" => $this->siteFingerprint,
            "instance_fingerprint" => $this->instanceFingerprint,
            "approved_at" => $this->approvedAt,
        ];
    }
}
