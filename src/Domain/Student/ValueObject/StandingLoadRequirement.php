<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\Exception\ValidationException;

use function Codefy\Framework\Helpers\trans_html;

/** Explicit policy gate on one retained determination, never today's live registrations. */
final readonly class StandingLoadRequirement
{
    public function __construct(public array $facts)
    {
        if (
            count($facts) !== 3 || array_diff(array_keys($facts), [
                'minimum_credits_milli', 'maximum_credits_milli', 'classification_code',
            ]) !== [] || !is_int($facts['minimum_credits_milli'] ?? null)
            || $facts['minimum_credits_milli'] < 0 || $facts['minimum_credits_milli'] > 999999
            || !array_key_exists('maximum_credits_milli', $facts)
            || ($facts['maximum_credits_milli'] !== null && (!is_int($facts['maximum_credits_milli'])
                || $facts['maximum_credits_milli'] < $facts['minimum_credits_milli']
                || $facts['maximum_credits_milli'] > 999999))
            || !array_key_exists('classification_code', $facts)
            || ($facts['classification_code'] !== null && (!is_string($facts['classification_code'])
                || preg_match('~^[A-Z0-9][A-Z0-9_.\/-]{0,31}$~D', $facts['classification_code']) !== 1))
        ) {
            throw new ValidationException([['field' => 'load_requirement', 'message' => trans_html(
                'Use inclusive registered-credit bounds from 0 to 999.999 and an optional exact '
                . 'load classification code.'
            )]]);
        }
    }

    public function accepts(LoadDeterminationSnapshot $snapshot): bool
    {
        return $snapshot->creditsMilli >= $this->facts['minimum_credits_milli']
        && ($this->facts['maximum_credits_milli'] === null
                || $snapshot->creditsMilli <= $this->facts['maximum_credits_milli'])
        && ($this->facts['classification_code'] === null
                || ($snapshot->classification['code'] ?? null) === $this->facts['classification_code']);
    }
}
