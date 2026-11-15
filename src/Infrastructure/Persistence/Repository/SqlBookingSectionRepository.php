<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\Service\SectionOfferingSelection;
use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Scheduling\Repository\BookingSectionRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlBookingSectionRepository implements BookingSectionRepository
{
    public function __construct(
        private SqlRecords $sql,
        private SectionVersionRepository $versions,
        private HistoricalEventStore $history
    ) {
    }

    public function selected(EntityId $section, bool $lock): array
    {
        if ($lock) {
            $this->sql->lock('sis_sections', $section->value, 'capacity');
        }
        $row = $this->sql->one('sis_sections', ['id' => $section->value]);
        $selected = SectionOfferingSelection::verify($section, $this->versions, $this->history);
        if ($selected === null || !(bool) $row['active']) {
            throw new ValidationException([['field' => 'section_id',
                'message' => 'Choose an active section with a selected published offering.']]);
        }
        return $this->details($selected);
    }

    public function lock(EntityId $section): void
    {
        $this->sql->lock('sis_sections', $section->value, 'capacity');
    }

    public function describe(EntityId $publication): array
    {
        return $this->details($this->versions->find($publication) ?? throw new CorruptHistoryException());
    }

    /** @return array<string,string> */
    private function details(PublishedSectionDefinition $selected): array
    {
        $d = $selected->definition->data;
        return ['id' => $selected->section->value, 'publication_id' => $selected->publication->value,
            'code' => $d['code'], 'term_code' => $d['term_code'], 'delivery_mode' => $d['delivery_mode'],
            'instruction_starts_on' => $d['instruction_starts_on'], 'instruction_ends_on' => $d['instruction_ends_on']];
    }
}
