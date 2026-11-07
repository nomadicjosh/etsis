<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DomainException;
use Domain\Catalog\Course;
use Domain\Import\Repository\CatalogImportRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCatalogImportRepository implements CatalogImportRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function import(string $source, string $externalId, Course $course): bool
    {
        $hash = hash('sha256', json_encode(
            [$course->code, $course->title, $course->creditsMilli],
            JSON_THROW_ON_ERROR
        ));
        $criteria = ['source' => $source, 'external_id' => $externalId];
        $existing = $this->sql->rows('sis_import_mappings', $criteria);
        if ($existing !== []) {
            if ($existing[0]['content_hash'] !== $hash) {
                throw new DomainException('An external import identity already refers to different data.');
            }
            return false;
        }
        $this->sql->insert('sis_courses', ['id' => $course->id->value, 'code' => $course->code,
            'title' => $course->title, 'credits_milli' => $course->creditsMilli, 'active' => (int) $course->active]);
        $this->sql->insert('sis_import_mappings', ['id' => EntityId::generate()->value] + $criteria
            + ['content_hash' => $hash, 'course_id' => $course->id->value]);

        return true;
    }
}
