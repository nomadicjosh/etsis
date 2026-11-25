<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Catalog\CoursePublication;
use Domain\Catalog\Repository\CatalogProjectionVerifier;
use Domain\Catalog\ValueObject\CatalogProjectionCheck;
use Domain\Catalog\ValueObject\CoursePublicationId;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Registration\Event\SectionCourseVersionWasBound;
use Domain\Registration\Repository\SectionCourseBindingRepository;
use Domain\Registration\Repository\SectionVersionRepository;
use Domain\Registration\SectionOfferingBinding;
use Domain\Registration\SectionPublication;
use Domain\Registration\ValueObject\PublishedSectionDefinition;
use Domain\Registration\ValueObject\SectionClassifications;
use Domain\Registration\ValueObject\SectionCourseBindingId;
use Domain\Registration\ValueObject\SectionDefinitionV1;
use Domain\Registration\ValueObject\SectionOfferingBindingId;
use Domain\Registration\ValueObject\SectionPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\Repository\SqlCourseVersionProjection;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

/** Outside a snapshot, compare bounded reference inventories and protected source heads before/after replay. */
final readonly class SqlCatalogProjectionVerifier implements CatalogProjectionVerifier
{
    public function __construct(
        private SqlRecords $sql,
        private HistoricalEventStore $history,
        private SqlCourseVersionProjection $courses,
        private SectionVersionRepository $sections,
        private SectionCourseBindingRepository $bindings,
    ) {
    }

    public function course(EntityId $course, int $eventLimit = 500): CatalogProjectionCheck
    {
        return $this->verify('course', $course, $eventLimit);
    }

    public function section(EntityId $section, int $eventLimit = 500): CatalogProjectionCheck
    {
        return $this->verify('section', $section, $eventLimit);
    }

    private function verify(string $kind, EntityId $id, int $limit): CatalogProjectionCheck
    {
        if ($limit < 1 || $limit > 2000) {
            throw new InvalidArgumentException('Use a catalog verification allowance of 1–2000 events.');
        }
        if ($this->sql->inTransaction()) {
            return new CatalogProjectionCheck($kind, $id, 'unverified', 0, issues: ['unavailable']);
        }
        $budget = new HistoryReadBudget(maximumEvents: $limit, maximumBytes: 4_194_304, maximumMilliseconds: 5000);
        $before = null;
        $status = 'unverified';
        $issue = 'catalog_identity';
        $count = null;
        try {
            if ($this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $before = $this->boundary($kind, $id);
            if ($before['identity'] === []) {
                throw new CorruptHistoryException();
            }
            $course = $kind === 'course' ? $id : new EntityId($before['identity'][0]['course_id']);
            $issue = 'course_reference';
            $bound = $kind === 'section' ? $this->bindings->bound($id) : null;
            if (
                $kind === 'section'
                && (count($before['course_identity']) !== 1 || count($before['term_identity']) !== 1)
            ) {
                throw new CorruptHistoryException();
            }
            [$courseCount, $foundBound] = $this->courseSource($course, $before, $bound, $budget);
            $issue = 'publication';
            if ($kind === 'course') {
                $count = $courseCount;
            } else {
                $issue = 'course_binding';
                $this->binding($id, $course, $bound, $foundBound, $before, $budget);
                $issue = 'publication';
                $count = $this->sectionSource($id, $before, $bound, $budget);
                $issue = 'offering_selection';
                $this->selection($id, $before, $budget);
            }
            $budget->checkTime();
            $status = $count === 0 ? 'observed_empty' : 'verified';
        } catch (HistoryReadLimitException) {
            $status = 'limited';
        } catch (CorruptHistoryException) {
            $status = 'unverified';
        } catch (Throwable) {
            $status = 'unverified';
            $issue = 'unavailable';
        }
        if ($before !== null) {
            try {
                if ($before !== $this->boundary($kind, $id)) {
                    $status = 'changed';
                }
                $budget->checkTime();
            } catch (HistoryReadLimitException) {
                if ($status !== 'changed') {
                    $status = 'limited';
                }
            } catch (Throwable) {
                $status = 'unverified';
                $issue = 'unavailable';
            }
        }
        return new CatalogProjectionCheck(
            $kind,
            $id,
            $status,
            $budget->eventsRead(),
            $status === 'verified' ? $count : null,
            $status === 'unverified' ? [$issue] : []
        );
    }

    /** @return array{int,bool} */
    private function courseSource(
        EntityId $course,
        #[\SensitiveParameter] array $before,
        ?PublishedCourseDefinition $bound,
        HistoryReadBudget $budget,
    ): array {
        $root = CoursePublication::root(new CoursePublicationId($course->value));
        $cached = [];
        foreach ($before['courses'] as $row) {
            $cached[$row['id']] = true;
        }
        $found = false;
        $seen = [];
        $cursor = null;
        do {
            $page = $budget->page(
                $this->history,
                new StreamReference($root->aggregateId(), 'course_publication'),
                $cursor
            );
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $publication = PublishedCourseDefinition::fromPublication($course, $stored->event->payload());
                if (isset($seen[$publication->versionId->value])) {
                    throw new CorruptHistoryException();
                }
                $seen[$publication->versionId->value] = true;
                if (isset($cached[$publication->versionId->value])) {
                    if (
                        ProtectedEventCodec::canonical($publication->describe())
                        !== ProtectedEventCodec::canonical($this->courses->get($publication->versionId)->describe())
                    ) {
                        throw new CorruptHistoryException();
                    }
                    unset($cached[$publication->versionId->value]);
                }
                if ($bound?->versionId->value === $publication->versionId->value) {
                    if (
                        $found || ProtectedEventCodec::canonical($bound->describe())
                        !== ProtectedEventCodec::canonical($publication->describe())
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $found = true;
                }
                $budget->checkTime();
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($cached !== [] || $root->playhead() !== $this->position($before, 'course_publication')) {
            throw new CorruptHistoryException();
        }
        return [$root->playhead(), $found];
    }

    private function binding(
        EntityId $section,
        EntityId $course,
        ?PublishedCourseDefinition $bound,
        bool $found,
        #[\SensitiveParameter] array $before,
        HistoryReadBudget $budget,
    ): void {
        $stream = new StreamReference(new SectionCourseBindingId($section->value), 'section_course_binding');
        if ($bound === null && $before['bound'] === [] && $this->position($before, $stream->type) === 0) {
            return;
        }
        $page = $budget->page($this->history, $stream);
        if ($bound === null && $page->events === [] && $this->position($before, $stream->type) === 0) {
            return;
        }
        $event = $page->events[0]->event ?? null;
        if (
            !$found || $bound === null || count($page->events) !== 1 || $page->nextCursor !== null
            || $this->position($before, $stream->type) !== 1 || !$event instanceof SectionCourseVersionWasBound
            || !$event->aggregateId()->equals($stream->aggregateId) || $event->playhead() !== 1
            || $event->payload() !== ['course_id' => $course->value, 'course_version_id' => $bound->versionId->value]
        ) {
            throw new CorruptHistoryException();
        }
    }

    private function sectionSource(
        EntityId $section,
        #[\SensitiveParameter] array $before,
        ?PublishedCourseDefinition $bound,
        HistoryReadBudget $budget,
    ): int {
        $root = SectionPublication::root(new SectionPublicationId($section->value));
        $cached = array_fill_keys(array_column($before['sections'], 'id'), true);
        $cursor = null;
        do {
            $page = $budget->page(
                $this->history,
                new StreamReference($root->aggregateId(), 'section_publication'),
                $cursor
            );
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $p = $stored->event->payload();
                $classes = $stored->event->metaParam('offering_classifications', null);
                $publication = new PublishedSectionDefinition(
                    $section,
                    new EntityId($p['version_id']),
                    $stored->event->playhead(),
                    new SectionDefinitionV1($p['definition']),
                    $classes === null ? null : SectionClassifications::fromArray($classes)
                );
                $projected = $this->sections->find($publication->publication);
                if (
                    !isset($cached[$publication->publication->value]) || $projected === null || $bound === null
                    || $p['definition']['course_version_id'] !== $bound->versionId->value
                    || $p['definition']['course_id'] !== $before['identity'][0]['course_id']
                    || $p['definition']['term_id'] !== $before['identity'][0]['term_id']
                    || $projected->fingerprint() !== $publication->fingerprint()
                ) {
                    throw new CorruptHistoryException();
                }
                unset($cached[$publication->publication->value]);
                $budget->checkTime();
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($cached !== [] || $root->playhead() !== $this->position($before, 'section_publication')) {
            throw new CorruptHistoryException();
        }
        return $root->playhead();
    }

    private function selection(EntityId $section, #[\SensitiveParameter] array $before, HistoryReadBudget $budget): void
    {
        $root = SectionOfferingBinding::root(new SectionOfferingBindingId($section->value));
        if ($before['selected'] === [] && $this->position($before, 'section_offering_binding') === 0) {
            return;
        }
        $cursor = null;
        do {
            $page = $budget->page(
                $this->history,
                new StreamReference($root->aggregateId(), 'section_offering_binding'),
                $cursor
            );
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $p = $stored->event->payload();
                $publication = $this->sections->find(new EntityId($p['publication_id']));
                if (
                    $publication === null || $publication->section->value !== $section->value
                    || !hash_equals($p['definition_hash'], $publication->fingerprint())
                ) {
                    throw new CorruptHistoryException();
                }
                $budget->checkTime();
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        $selected = $this->sections->selected($section);
        if (
            $root->playhead() !== $this->position($before, 'section_offering_binding')
            || $root->playhead() !== $this->sections->selectionPosition($section)
            || $root->publication() !== $selected?->publication->value
        ) {
            throw new CorruptHistoryException();
        }
    }

    private function position(#[\SensitiveParameter] array $boundary, string $type): int
    {
        $rows = $boundary['heads'][$type];
        if ($rows === []) {
            return 0;
        }
        $version = $rows[0]['version'];
        if (
            count($rows) !== 1 || !is_scalar($version) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $version)
            || (string) (int) $version !== (string) $version || (int) $version > 2147483646
        ) {
            throw new CorruptHistoryException();
        }
        return (int) $version;
    }

    /** Bounded projection snapshot. Mutable catalog descriptions/fees are deliberately outside historical equality. */
    private function boundary(string $kind, EntityId $id): array
    {
        $q = $this->sql->quote(...);
        $identity = $this->sql->fetch('SELECT ' . ($kind === 'course' ? $q('id') : implode(
            ', ',
            array_map($q, ['id', 'course_id', 'term_id'])
        )) . ' FROM ' . $q($kind === 'course' ? 'sis_courses' : 'sis_sections')
            . ' WHERE ' . $q('id') . ' = ?', [$id->value], 2);
        $course = $kind === 'course' ? $id->value : ($identity[0]['course_id'] ?? $id->value);
        $result = ['identity' => $identity, 'course_identity' => $this->sql->fetch('SELECT ' . $q('id') . ' FROM '
            . $q('sis_courses') . ' WHERE ' . $q('id') . ' = ?', [$course], 2)];
        $result['courses'] = $this->sql->rows('sis_course_versions', ['course_id' => $course], 201);
        foreach (['subjects', 'classes'] as $suffix) {
            $result['course_' . $suffix] = $this->sql->fetch('SELECT * FROM ' . $q('sis_course_version_' . $suffix)
                . ' WHERE ' . $q('id') . ' IN (SELECT ' . $q('id') . ' FROM ' . $q('sis_course_versions')
                . ' WHERE ' . $q('course_id') . ' = ?)', [$course], 201);
        }
        $result['heads'] = [];
        $streams = ['course_publication' => $course];
        if ($kind === 'section') {
            $result['term_identity'] = $identity === [] ? [] : $this->sql->fetch('SELECT ' . $q('id')
                . ' FROM ' . $q('sis_terms') . ' WHERE ' . $q('id') . ' = ?', [$identity[0]['term_id']], 2);
            $result['sections'] = $this->sql->rows('sis_section_versions', ['section_id' => $id->value], 201);
            $result['section_classes'] = $this->sql->fetch('SELECT * FROM ' . $q('sis_section_version_classes')
                . ' WHERE ' . $q('id') . ' IN (SELECT ' . $q('id') . ' FROM ' . $q('sis_section_versions')
                . ' WHERE ' . $q('section_id') . ' = ?)', [$id->value], 201);
            $result['bound'] = $this->sql->rows('sis_section_course_versions', ['id' => $id->value], 2);
            $result['selected'] = $this->sql->rows('sis_section_offering_versions', ['id' => $id->value], 2);
            $streams += ['section_publication' => $id->value, 'section_course_binding' => $id->value,
                'section_offering_binding' => $id->value];
        }
        foreach ($streams as $type => $aggregate) {
            $result['heads'][$type] = $this->sql->rows('sis_history_streams', [
                'stream_type' => $type, 'aggregate_id' => $aggregate], 2);
        }
        foreach ($result as $key => &$rows) {
            if ($key === 'heads') {
                continue;
            }
            if (count($rows) > 200) {
                throw new HistoryReadLimitException();
            }
            usort($rows, static fn(array $a, array $b): int => strcmp($a['id'], $b['id']));
        }
        unset($rows);
        if (strlen(serialize($result)) > 4_194_304) {
            throw new HistoryReadLimitException();
        }
        return $result;
    }
}
