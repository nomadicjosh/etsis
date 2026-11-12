<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

/** The canonical address and installation identity verified in one gateway operation. */
final readonly class MoodleInstallation
{
    public function __construct(private MoodleSiteUrl $site, private MoodleInstanceIdentity $identity)
    {
    }

    public function siteFingerprint(): string
    {
        return hash("sha256", $this->site->value);
    }

    public function instanceFingerprint(): string
    {
        return $this->identity->fingerprint();
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["installation" => "[private installation verification]"];
    }
}
