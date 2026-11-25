<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

/** Explicit institutional cumulative-GPA floors; no implicit institutional standing is supplied. */
final readonly class StandingProposalRules
{
    public const array DEFAULT = ['enabled' => false, 'minimum_gpa_credits_milli' => 0, 'bands' => []];

    public function __construct(public array $facts)
    {
        try {
            if (
                count($facts) !== 3 || array_diff(array_keys($facts), array_keys(self::DEFAULT)) !== []
                || !is_bool($facts['enabled']) || !is_int($facts['minimum_gpa_credits_milli'])
                || $facts['minimum_gpa_credits_milli'] < 0 || $facts['minimum_gpa_credits_milli'] > 999999
                || !is_array($facts['bands']) || !array_is_list($facts['bands']) || count($facts['bands']) > 20
                || ($facts['enabled'] && ($facts['bands'] === []
                    || ($facts['bands'][0]['minimum_gpa_milli'] ?? null) !== 0))
            ) {
                throw new \InvalidArgumentException();
            }
            $floor = -1;
            foreach ($facts['bands'] as $band) {
                if (
                    !is_array($band) || count($band) !== 4
                    || array_diff(array_keys($band), ['minimum_gpa_milli', 'definition_id', 'code', 'title']) !== []
                    || !is_int($band['minimum_gpa_milli']) || $band['minimum_gpa_milli'] <= $floor
                    || $band['minimum_gpa_milli'] > 10000 || !is_string($band['definition_id'])
                    || new EntityId($band['definition_id'])->value !== $band['definition_id']
                ) {
                    throw new \InvalidArgumentException();
                }
                StandingPeriods::label($band['code'], $band['title']);
                $floor = $band['minimum_gpa_milli'];
            }
        } catch (\Throwable) {
            throw new ValidationException([['field' => 'bands', 'message' => trans_html(
                'Use up to twenty increasing GPA floors from 0.000 to 10.000, starting at zero when enabled, '
                . 'with complete standing labels and nonnegative minimum GPA credits.'
            )]]);
        }
    }

    public function band(int $gpaMilli): array
    {
        $chosen = null;
        foreach ($this->facts['bands'] as $band) {
            if ($band['minimum_gpa_milli'] <= $gpaMilli) {
                $chosen = $band;
            }
        }
        return $chosen ?? throw new \InvalidArgumentException('A matching standing GPA band is required.');
    }

    public static function reason(string $reason): string
    {
        $reason = trim($reason);
        if (
            $reason === '' || mb_strlen($reason) > 1000 || !mb_check_encoding($reason, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([['field' => 'reason',
                'message' => trans_html('Enter a valid reason of up to 1000 characters.')]]);
        }
        return $reason;
    }

    public static function hash(array $data): string
    {
        $canonical = static function (array $values) use (&$canonical): array {
            if (!array_is_list($values)) {
                ksort($values, SORT_STRING);
            }
            return array_map(static fn($value) => is_array($value) ? $canonical($value) : $value, $values);
        };
        return hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR));
    }
}
