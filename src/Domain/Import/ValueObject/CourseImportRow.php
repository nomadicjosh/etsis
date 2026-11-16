<?php

declare(strict_types=1);

namespace Domain\Import\ValueObject;

use Domain\Catalog\Course;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** A validated source record, without allocating a persistent course identity during review. */
final readonly class CourseImportRow
{
    public const array REFERENCE_LABELS = [
        'subject' => 'Subject', 'department' => 'Department',
        'academic_level' => 'Academic level', 'course_level' => 'Course level',
    ];
    /** @var array<string,string> Explicit source codes; omissions never infer or clear a classification. */
    public array $references;

    /** @param array<array-key,mixed> $references */
    public function __construct(
        public int $row,
        public string $externalId,
        public string $code,
        public string $title,
        public int $creditsMilli,
        array $references = [],
    ) {
        if (
            $row < 2 || preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $externalId) !== 1
            || !mb_check_encoding($code . $title, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $code . $title)
        ) {
            throw new InvalidArgumentException('A source ID and printable course details are required.');
        }
        $clean = [];
        foreach ($references as $kind => $code) {
            if (!is_string($kind) || !isset(self::REFERENCE_LABELS[$kind]) || !is_string($code)) {
                throw new InvalidArgumentException('Use known catalog reference codes.');
            }
            $code = strtoupper(trim($code));
            if ($code === '') {
                continue;
            }
            if (preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,39}$~D', $code) !== 1) {
                throw new InvalidArgumentException('Use classification codes of up to 40 letters, digits or . _ / -.');
            }
            $clean[$kind] = $code;
        }
        ksort($clean, SORT_STRING);
        $this->references = $clean;
        $this->draft();
    }

    public function draft(): Course
    {
        return new Course(EntityId::generate(), $this->code, $this->title, $this->creditsMilli, false);
    }

    public function fingerprint(): string
    {
        $facts = [$this->code, $this->title, $this->creditsMilli];
        // Existing source receipts attest to these original three facts; preserve their actual meaning.
        if ($this->references !== []) {
            $facts[] = $this->references;
        }
        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }
}
