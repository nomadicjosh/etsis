<?php

declare(strict_types=1);

namespace Infrastructure\Projection;

use Domain\Registration\Repository\MeetingPlanVerifier;
use Domain\Registration\SectionMeetingPlan;
use Domain\Registration\SectionPublication;
use Domain\Registration\Service\MeetingPlanOfferingReader;
use Domain\Registration\ValueObject\MeetingPlanCheck;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Registration\ValueObject\SectionMeetingPlanId;
use Domain\Registration\ValueObject\SectionPublicationId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use Throwable;

final readonly class SqlMeetingPlanVerifier implements MeetingPlanVerifier
{
    public function __construct(private SqlRecords $sql, private HistoricalEventStore $history)
    {
    }

    public function verify(EntityId $section, int $eventLimit = 500): MeetingPlanCheck
    {
        if ($eventLimit < 1 || $eventLimit > 2000) {
            throw new InvalidArgumentException('Use a meeting-plan verification allowance of 1–2000 events.');
        }
        if ($this->sql->inTransaction()) {
            return new MeetingPlanCheck($section, 'unverified', 0, issues: ['unavailable']);
        }
        $budget = new HistoryReadBudget(
            maximumEvents: $eventLimit,
            maximumBytes: 4_194_304,
            maximumMilliseconds: 5000
        );
        $before = null;
        $status = 'unverified';
        $issue = 'identity';
        $publications = $meetings = null;
        try {
            $before = $this->boundary($section);
            if (count($before['identity']) !== 1 || count($before['course']) !== 1 || count($before['term']) !== 1) {
                throw new CorruptHistoryException();
            }
            $issue = 'journal';
            if ($this->sql->tableExists('event_store')) {
                throw new CorruptHistoryException();
            }
            $root = SectionMeetingPlan::root(new SectionMeetingPlanId($section->value));
            $stream = new StreamReference($root->aggregateId(), 'section_meeting_plan');
            $cursor = null;
            $seen = [];
            do {
                $page = $budget->page($this->history, $stream, $cursor);
                foreach ($page->events as $stored) {
                    $root->acceptHistorical($stored->event);
                    if (isset($seen[$root->publication()])) {
                        throw new CorruptHistoryException();
                    }
                    $seen[$root->publication()] = true;
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null);
            if ($root->playhead() !== $this->position($before, 'section_meeting_plan')) {
                throw new CorruptHistoryException();
            }
            $issue = 'projection';
            $pointer = $before['pointer'];
            if ($root->definition() === null) {
                if ($pointer !== []) {
                    throw new CorruptHistoryException();
                }
                $status = 'observed_empty';
            } else {
                if (
                    count($pointer) !== 1 || !isset($pointer[0]['version'])
                    || (string) (int) $pointer[0]['version'] !== (string) $pointer[0]['version']
                ) {
                    throw new CorruptHistoryException();
                }
                $row = $pointer[0];
                $row['version'] = (int) $row['version'];
                $expected = ['id' => $section->value, 'publication_id' => $root->publication(),
                    'version' => $root->playhead(), 'definition_hash' => $root->definition()->fingerprint()];
                ksort($row);
                ksort($expected);
                if ($row !== $expected) {
                    throw new CorruptHistoryException();
                }
                $issue = 'offering_reference';
                $this->offerings($section, $root->definition(), $before, $budget);
                $publications = $root->playhead();
                $meetings = count($root->definition()->data['meetings']);
                $status = 'verified';
            }
            $budget->checkTime();
        } catch (HistoryReadLimitException) {
            $status = 'limited';
        } catch (CorruptHistoryException | ValidationException) {
            $status = 'unverified';
        } catch (Throwable) {
            $status = 'unverified';
            $issue = 'unavailable';
        }
        if ($before !== null) {
            try {
                if ($before !== $this->boundary($section)) {
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
        return new MeetingPlanCheck(
            $section,
            $status,
            $budget->eventsRead(),
            $status === 'verified' ? $publications : null,
            $status === 'verified' ? $meetings : null,
            $status === 'unverified' ? [$issue] : []
        );
    }

    private function offerings(
        EntityId $section,
        SectionMeetingPlanDefinition $plan,
        #[\SensitiveParameter] array $before,
        HistoryReadBudget $budget,
    ): void {
        $needed = array_fill_keys(array_column($plan->data['meetings'], 'offering_publication_id'), true);
        if ($needed === []) {
            return;
        }
        $root = SectionPublication::root(new SectionPublicationId($section->value));
        $cursor = null;
        $definitions = $seen = [];
        do {
            $page = $budget->page(
                $this->history,
                new StreamReference($root->aggregateId(), 'section_publication'),
                $cursor
            );
            foreach ($page->events as $stored) {
                $root->acceptHistorical($stored->event);
                $value = $root->latest();
                if (
                    $value === null || isset($seen[$value['version_id']])
                    || $value['definition']['course_id'] !== $before['identity'][0]['course_id']
                    || $value['definition']['term_id'] !== $before['identity'][0]['term_id']
                ) {
                    throw new CorruptHistoryException();
                }
                $seen[$value['version_id']] = true;
                if (isset($needed[$value['version_id']])) {
                    $definitions[$value['version_id']] = $value['definition'];
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $this->position($before, 'section_publication')) {
            throw new CorruptHistoryException();
        }
        MeetingPlanOfferingReader::validate($plan, $definitions);
    }

    private function position(#[\SensitiveParameter] array $boundary, string $type): int
    {
        $rows = $boundary['heads'][$type];
        if ($rows === []) {
            return 0;
        }
        $version = $rows[0]['version'] ?? null;
        if (
            count($rows) !== 1 || !is_scalar($version) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $version)
            || (string) (int) $version !== (string) $version || (int) $version > 2147483646
        ) {
            throw new CorruptHistoryException();
        }
        return (int) $version;
    }

    private function boundary(EntityId $section): array
    {
        $q = $this->sql->quote(...);
        $identity = $this->sql->fetch('SELECT ' . implode(', ', array_map($q, ['id', 'course_id', 'term_id']))
            . ' FROM ' . $q('sis_sections') . ' WHERE ' . $q('id') . ' = ?', [$section->value], 2);
        $result = ['identity' => $identity, 'course' => [], 'term' => [],
            'pointer' => $this->sql->rows('sis_section_meeting_plans', ['id' => $section->value], 2), 'heads' => []];
        if (count($identity) === 1) {
            foreach (['course' => 'sis_courses', 'term' => 'sis_terms'] as $kind => $table) {
                $result[$kind] = $this->sql->fetch('SELECT ' . $q('id') . ' FROM ' . $q($table)
                    . ' WHERE ' . $q('id') . ' = ?', [$identity[0][$kind . '_id']], 2);
            }
        }
        foreach (['section_meeting_plan', 'section_publication'] as $type) {
            $result['heads'][$type] = $this->sql->rows(
                'sis_history_streams',
                ['stream_type' => $type, 'aggregate_id' => $section->value],
                2
            );
        }
        return $result;
    }
}
