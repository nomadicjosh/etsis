<?php

declare(strict_types=1);

namespace Domain\Lms\ValueObject;

/** A returned record is not an identity link, permission grant or academic decision. */
final readonly class MoodleRecordCheck
{
    /** @var array<string,?bool> */
    private array $flags;
    /** @param array<string,mixed> $flags */
    public function __construct(public MoodleResourceKind $kind, public bool $returned, array $flags = [])
    {
        $allowed = $kind === MoodleResourceKind::User ? ["suspended", "deleted"] : ["visible"];
        if (array_diff(array_keys($flags), $allowed) !== []) {
            throw new \InvalidArgumentException("Unsupported Moodle status flag.");
        }
        $status = [];
        foreach ($allowed as $flag) {
            $value = $flags[$flag] ?? null;
            if ($value !== null && !is_bool($value)) {
                throw new \InvalidArgumentException("Use a known status or leave it unknown.");
            }
            $status[$flag] = $returned ? $value : null;
        }
        $this->flags = $status;
    }
    /** @return array<string,?bool> */
    public function describe(): array
    {
        return $this->flags;
    }
}
