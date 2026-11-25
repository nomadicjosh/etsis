<?php

declare(strict_types=1);

namespace Domain\Advising;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Original operational booking rules; no academic eligibility or meeting-frequency requirement. */
final readonly class BookingPolicySnapshot
{
    public const array DEFAULTS = ['maximum_active' => 20, 'notice_minutes' => 0, 'horizon_days' => 366];
    public array $rules;

    public function __construct(public ?EntityId $publication, public int $revision, array $rules)
    {
        if (
            $revision < 0 || $revision > 1000000 || ($publication === null) !== ($revision === 0)
            || array_diff(array_keys(self::DEFAULTS), array_keys($rules)) !== []
            || array_diff(array_keys($rules), [...array_keys(self::DEFAULTS), 'student_class_mode',
                'advisor_teaching_mode']) !== []
        ) {
            throw new ValidationException([['field' => 'rules',
                'message' => trans('Review complete appointment booking rules.')]]);
        }
        $bounds = ['maximum_active' => [1, 20], 'notice_minutes' => [0, 43200], 'horizon_days' => [1, 366]];
        foreach ($bounds as $key => [$min, $max]) {
            if (!is_int($rules[$key]) || $rules[$key] < $min || $rules[$key] > $max) {
                throw new ValidationException([['field' => $key,
                    'message' => trans('Use an appointment limit within the displayed range.')]]);
            }
        }
        if ($rules['notice_minutes'] >= $rules['horizon_days'] * 1440) {
            throw new ValidationException([['field' => 'notice_minutes',
                'message' => trans('Minimum advance notice must be shorter than the booking horizon.')]]);
        }
        $normalized = array_replace(self::DEFAULTS, array_intersect_key($rules, self::DEFAULTS));
        foreach (['student_class_mode', 'advisor_teaching_mode'] as $key) {
            if (array_key_exists($key, $rules)) {
                $normalized[$key] = $rules[$key];
            }
        }
        if (
            array_key_exists('student_class_mode', $rules)
            && !in_array($rules['student_class_mode'], ['allow', 'block'], true)
        ) {
            throw new ValidationException([['field' => 'student_class_mode',
                'message' => trans('Choose whether published student class overlaps are allowed or blocked.')]]);
        }
        if (
            array_key_exists('advisor_teaching_mode', $rules)
            && !in_array($rules['advisor_teaching_mode'], ['allow', 'block'], true)
        ) {
            throw new ValidationException([['field' => 'advisor_teaching_mode',
                'message' => trans('Choose whether published advisor teaching overlaps are allowed or blocked.')]]);
        }
        $this->rules = $normalized;
        if ($publication === null && $this->rules !== self::DEFAULTS) {
            throw new ValidationException([['field' => 'rules',
                'message' => trans('Unconfigured booking rules must use the documented defaults.')]]);
        }
    }

    public static function defaults(): self
    {
        return new self(null, 0, self::DEFAULTS);
    }

    public static function from(array $data): self
    {
        if (count($data) !== 3 || array_diff(['publication_id', 'revision', 'rules'], array_keys($data)) !== []) {
            throw new \InvalidArgumentException('Use a complete retained booking policy.');
        }
        return new self(
            $data['publication_id'] === null ? null : new EntityId($data['publication_id']),
            $data['revision'],
            $data['rules']
        );
    }

    public function facts(): array
    {
        return ['publication_id' => $this->publication?->value, 'revision' => $this->revision, 'rules' => $this->rules];
    }

    public function blocksStudentClasses(): bool
    {
        return ($this->rules['student_class_mode'] ?? 'allow') === 'block';
    }

    public function blocksAdvisorTeaching(): bool
    {
        return ($this->rules['advisor_teaching_mode'] ?? 'allow') === 'block';
    }

    public function permits(AppointmentTime $time, DateTimeImmutable $now): bool
    {
        $start = new DateTimeImmutable($time->startsAt);
        $localNow = $now->setTimezone(new DateTimeZone($time->timezone));
        return $start > $now && $start->getTimestamp() >= $now->getTimestamp() + $this->rules['notice_minutes'] * 60
        && $start <= $localNow->modify('+' . $this->rules['horizon_days'] . ' days');
    }

    public function requireTime(AppointmentTime $time, DateTimeImmutable $now): void
    {
        if (!$this->permits($time, $now)) {
            throw new ValidationException([['field' => 'start', 'message' => trans(
                'Choose a future time within the institution booking horizon and minimum advance notice.'
            )]]);
        }
    }
}
