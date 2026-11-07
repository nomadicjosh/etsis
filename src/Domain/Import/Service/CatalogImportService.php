<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Course;
use Domain\Import\Repository\CatalogImportRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class CatalogImportService
{
    public function __construct(
        private CatalogImportRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    /** Restricted CSV schema, no caller-supplied SQL, table name or filesystem path. */
    public function courses(string $source, #[\SensitiveParameter] string $csv): int
    {
        $actor = $this->access->requirePermission('sis:import:manage');
        if (
            preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $source) !== 1 || strlen($csv) > 1_048_576
            || !mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")
        ) {
            throw new InvalidArgumentException('A bounded UTF-8 CSV source is required.');
        }
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to prepare import.');
        }
        $courses = [];
        try {
            fwrite($stream, $csv);
            rewind($stream);
            if (fgetcsv($stream, null, ',', '"', '') !== ['external_id', 'code', 'title', 'credits_milli']) {
                throw new InvalidArgumentException('The CSV header does not match the course import schema.');
            }
            while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if (
                    count($row) !== 4 || count($courses) >= 1000 || $row[0] === null
                    || preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $row[0]) !== 1
                    || $row[1] === null || $row[2] === null || $row[3] === null
                    || preg_match('/^[0-9]{1,6}$/D', $row[3]) !== 1
                ) {
                    throw new InvalidArgumentException('A CSV row is invalid.');
                }
                $courses[] = [$row[0], new Course(EntityId::generate(), $row[1], $row[2], (int) $row[3])];
            }
        } finally {
            fclose($stream);
        }

        return $this->transactions->run(function () use ($source, $courses, $actor): int {
            $count = 0;
            foreach ($courses as [$external, $course]) {
                if ($this->repository->import($source, $external, $course)) {
                    ++$count;
                    $this->audit->record($actor, 'course.imported', $course->id);
                }
            }

            return $count;
        });
    }
}
