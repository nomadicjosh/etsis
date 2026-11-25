<?php

declare(strict_types=1);

namespace Domain\Transcript\ValueObject;

use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans_html;

/** Presentation at the retained preparation cutoff, never a new standing determination. */
final readonly class TranscriptStandingPresentation
{
    public function __construct(public string $periods = 'through_cutoff', public bool $showDates = true)
    {
        if (!in_array($periods, ['through_cutoff', 'effective_at_cutoff'], true)) {
            self::invalid();
        }
    }

    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 2 || array_diff(array_keys($data), ['periods', 'show_dates']) !== []
            || !is_string($data['periods'] ?? null) || !is_bool($data['show_dates'] ?? null)
        ) {
            self::invalid();
        }
        return new self($data['periods'], $data['show_dates']);
    }

    public function describe(): array
    {
        return ['periods' => $this->periods, 'show_dates' => $this->showDates];
    }

    /** The caller supplies the verified, minimized source at its original cutoff. */
    public function apply(#[\SensitiveParameter] array $source): array
    {
        if ($this->periods === 'effective_at_cutoff') {
            $source['periods'] = array_values(array_filter($source['periods'], static fn(array $row): bool =>
                $row['ends_on'] === null || $row['ends_on'] >= $source['through']));
        }
        $source['presentation'] = $this->describe();
        return $source;
    }

    private static function invalid(): never
    {
        throw new ValidationException([['field' => 'standing_presentation', 'message' => trans_html(
            'Choose all periods through preparation or only the effective standing, ' .
            'and an explicit date display choice.'
        )]]);
    }
}
