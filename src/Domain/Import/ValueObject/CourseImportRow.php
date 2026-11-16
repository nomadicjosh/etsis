<?php

declare(strict_types=1);

namespace Domain\Import\ValueObject;

use Domain\Catalog\Course;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** A validated source record, without allocating a persistent course identity during review. */
final readonly class CourseImportRow
{
    public function __construct(
        public int $row,
        public string $externalId,
        public string $code,
        public string $title,
        public int $creditsMilli,
    ) {
        if (
            $row < 2 || preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $externalId) !== 1
            || !mb_check_encoding($code . $title, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $code . $title)
        ) {
            throw new InvalidArgumentException('A source ID and printable course details are required.');
        }
        $this->draft();
    }

    public function draft(): Course
    {
        return new Course(EntityId::generate(), $this->code, $this->title, $this->creditsMilli, false);
    }

    public function fingerprint(): string
    {
        // Preserve the meaning of existing external-identity receipts.
        return hash('sha256', json_encode([$this->code, $this->title, $this->creditsMilli], JSON_THROW_ON_ERROR));
    }
}
