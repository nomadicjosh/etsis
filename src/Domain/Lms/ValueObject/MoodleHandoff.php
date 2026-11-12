<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

/** Short-lived browser credential. Only render into the private POST handoff. */
final readonly class MoodleHandoff implements \JsonSerializable
{
    public function __construct(public MoodleSiteUrl $site, #[\SensitiveParameter] public string $ticket)
    {
    }
    /** @return array<string,string> */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ["handoff" => "[redacted]"];
    }
}
