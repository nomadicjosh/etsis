<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use Domain\Import\ValueObject\CourseImportRow;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogWorkflowRepository;
use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Communications\Service\MessageCipher;
use Domain\Import\Repository\CatalogImportRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use PDOException;

/** Reviewed draft imports and external identity reconciliation; publication remains a separate decision. */
final readonly class MappedImportService
{
    public function __construct(
        private CourseCsvMapper $mapper,
        private CatalogImportRepository $repository,
        private CatalogWorkflowRepository $operations,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @param array<string,int> $mapping
     * @param array<array-key,mixed> $choices
     * @return array<string,mixed> */
    public function preview(
        string $source,
        #[\SensitiveParameter] string $csv,
        array $mapping,
        string $delimiter = ',',
        array $choices = [],
    ): array {
        $actor = $this->actor();
        $this->source($source);
        $source = strtolower($source);
        $prepared = $this->mapper->prepare($csv, $mapping, $delimiter);
        $rows = $prepared['rows'];
        unset($prepared['rows']);
        if (!$prepared['ready']) {
            $this->actor($actor);
            return $prepared + ['plan' => [], 'review_token' => null];
        }
        $selected = $this->choices($rows, $choices);
        $plan = $this->plan($rows, $this->repository->inspect($source, $rows), $selected);
        $prepared['ready'] = $plan['summary']['match'] === 0 && $plan['summary']['conflict'] === 0;
        $token = null;
        if ($prepared['ready']) {
            $now = $this->clock->now()->getTimestamp();
            $token = $this->cipher->seal(json_encode([
                'purpose' => 'catalog.course-import', 'actor_id' => $actor->value,
                'operation_id' => EntityId::generate()->value, 'source' => $source,
                'request_hash' => $this->hash([$source, $prepared['fingerprint'], $selected]),
                'plan_hash' => $this->hash($plan), 'issued_at' => $now, 'expires_at' => $now + 1200,
            ], JSON_THROW_ON_ERROR));
        }
        $this->actor($actor);
        return $prepared + $plan + ['review_token' => $token, 'review_minutes' => 20];
    }

    /** @param array<string,int> $mapping
     * @param array<array-key,mixed> $choices
     * @return array<string,int|string> */
    public function commit(
        string $source,
        #[\SensitiveParameter] string $csv,
        array $mapping,
        #[\SensitiveParameter] string $token,
        string $delimiter = ',',
        array $choices = [],
    ): array {
        $actor = $this->actor();
        $this->source($source);
        $source = strtolower($source);
        $prepared = $this->mapper->prepare($csv, $mapping, $delimiter);
        if (!$prepared['ready']) {
            throw new ValidationException($prepared['errors']);
        }
        $rows = $prepared['rows'];
        $selected = $this->choices($rows, $choices);
        $request = $this->hash([$source, $prepared['fingerprint'], $selected]);
        $ticket = $this->ticket($token, $source, $actor, $request);
        $operation = new EntityId($ticket['operation_id']);
        return $this->transactions->run(function () use (
            $source,
            $rows,
            $selected,
            $request,
            $ticket,
            $operation,
            $actor,
        ): array {
            $this->actor($actor);
            $old = $this->operations->operation($operation);
            if ($old !== null) {
                $receipt = json_decode($this->cipher->open($old['encrypted_content']), true, 8, JSON_THROW_ON_ERROR);
                if (
                    $old['actor_id'] !== $actor->value || $old['kind'] !== 'course_import'
                    || ($receipt['request_hash'] ?? null) !== $request
                ) {
                    throw new ConcurrentModificationException('This receipt belongs to another catalog request.');
                }
                $this->actor($actor);
                return $receipt['result'];
            }
            $this->expiry($ticket);
            $snapshot = $this->repository->inspect($source, $rows);
            $ids = [];
            foreach ($snapshot['courses'] as $courses) {
                foreach ($courses as $course) {
                    $ids[] = $course['id'];
                }
            }
            // A locking reread sees catalog edits committed during a wait, even under repeatable-read isolation.
            $withReferences = false;
            foreach ($rows as $row) {
                $withReferences = $withReferences || $row->references !== [];
            }
            $snapshot['courses'] = [];
            foreach ($this->repository->lockCourses($ids, $withReferences) as $course) {
                $snapshot['courses'][mb_strtolower($course['code'])][] = $course;
            }
            $references = [];
            foreach ($snapshot['references'] as $matches) {
                foreach ($matches as $reference) {
                    $references[] = $reference;
                }
            }
            if ($references !== []) {
                $snapshot['references'] = [];
                foreach ($this->repository->lockReferences($references) as $reference) {
                    $key = $reference['kind'] . ':' . strtoupper($reference['code']);
                    $snapshot['references'][$key][] = $reference;
                }
            }
            $plan = $this->plan($rows, $snapshot, $selected);
            if (!hash_equals($ticket['plan_hash'], $this->hash($plan))) {
                throw new ConcurrentModificationException(
                    'The reviewed catalog matches changed. Review the import again.'
                );
            }
            if ($plan['summary']['match'] !== 0 || $plan['summary']['conflict'] !== 0) {
                throw new ValidationException([['field' => 'file', 'message' => 'Resolve every import row first.']]);
            }
            foreach ($rows as $index => $row) {
                $action = $plan['plan'][$index];
                try {
                    if ($action['action'] === 'create') {
                        $id = $this->repository->create($source, $row, $action['references']);
                        $this->audit->record($actor, 'course.imported', $id);
                    } elseif ($action['action'] === 'link') {
                        $id = new EntityId($action['course_id']);
                        $this->repository->link($source, $row, $id);
                        $this->audit->record($actor, 'course.import_linked', $id);
                    }
                } catch (PDOException $error) {
                    if (!in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                        throw $error;
                    }
                    throw new ValidationException([[
                        'field' => 'row_' . $row->row,
                        'message' => 'Row ' . $row->row . ': its code or source ID changed. Review the import again.',
                    ]]);
                }
            }
            $result = ['operation_id' => $operation->value, 'imported' => $plan['summary']['create'],
                'linked' => $plan['summary']['link'], 'skipped' => $plan['summary']['skip'],
                'omitted' => $plan['summary']['omitted'], 'total' => count($rows)];
            $this->operations->record($operation, $actor, 'course_import', $this->cipher->seal(json_encode([
                'request_hash' => $request, 'result' => $result,
            ], JSON_THROW_ON_ERROR)), $this->clock->now()->format(DATE_ATOM));
            $this->audit->record($actor, 'catalog.import_completed', $operation);
            $this->actor($actor);
            $this->expiry($ticket);
            return $result;
        });
    }

    /** @param list<CourseImportRow> $rows
     * @param array{
     *     mappings:array<string,array<string,mixed>>,courses:array<string,list<array<string,mixed>>>,
     *     references:array<string,list<array<string,mixed>>>
     * } $snapshot
     * @param array<string,array{action:string,course_id:?string}> $choices
     * @return array{plan:list<array<string,mixed>>,summary:array<string,int>} */
    private function plan(array $rows, array $snapshot, array $choices): array
    {
        $plan = [];
        $counts = array_fill_keys(['create', 'link', 'skip', 'omitted', 'match', 'conflict'], 0);
        $newCodes = [];
        foreach ($rows as $row) {
            $key = 'external:' . $row->externalId;
            $choice = $choices[$key] ?? null;
            $mapping = $snapshot['mappings']['external:' . strtolower($row->externalId)] ?? null;
            $matches = $snapshot['courses'][mb_strtolower($row->code)] ?? [];
            $course = count($matches) === 1 ? $matches[0] : null;
            $resolved = ['references' => [], 'errors' => []];
            if ($mapping === null && ($choice['action'] ?? null) !== 'skip') {
                $resolved = $this->resolveReferences($row, $snapshot['references']);
            }
            $sameCore = $course !== null && $course['title'] === $row->title
            && (int) $course['credits_milli'] === $row->creditsMilli;
            $same = $sameCore && $resolved['errors'] === [];
            foreach ($resolved['references'] as $kind => $reference) {
                $same = $same && ($course[$kind . '_id'] ?? null) === $reference['id'];
            }
            $courseId = null;
            $matched = null;
            if (($choice['action'] ?? null) === 'skip') {
                $action = 'omitted';
                $message = 'Left out of this import.';
            } elseif (
                $mapping !== null && (($mapping['ambiguous'] ?? false)
                || $mapping['external_id'] !== $row->externalId)
            ) {
                $action = 'conflict';
                $message = 'Recorded source IDs differ only by letter case. '
                . 'Resolve the source identity or leave this row out.';
            } elseif ($mapping !== null) {
                $action = hash_equals($mapping['content_hash'], $row->fingerprint()) ? 'skip' : 'conflict';
                $courseId = $mapping['course_id'];
                $message = $action === 'skip' ? 'Previously imported; the existing course is left unchanged.'
                : 'This source ID already identifies different course data. Leave it out or correct the source file.';
                if ($choice !== null) {
                    $action = 'conflict';
                    $message = 'This source ID is already mapped. Remove the link choice and review again.';
                }
            } elseif (count($matches) > 1) {
                $action = 'conflict';
                $message = 'Several catalog courses use this code. Resolve the ambiguity before linking.';
            } elseif ($course !== null) {
                $courseId = $course['id'];
                $matched = ['id' => $courseId, 'code' => $course['code'], 'title' => $course['title'],
                    'credits_milli' => (int) $course['credits_milli'], 'active' => (bool) $course['active']];
                $action = $same ? ($choice === null ? 'match' : 'link') : 'conflict';
                $message = $same ? 'An existing course matches. Approve linking its source ID, or leave this row out.'
                : ($sameCore
                    ? 'The existing course has different classifications. Use the catalog editor or leave it out.'
                    : 'This code already has a different title or credits. Existing details will not be overwritten.');
                if (
                    $choice !== null && ($choice['action'] !== 'link' || $choice['course_id'] !== $courseId || !$same)
                ) {
                    $action = 'conflict';
                    $message = 'The selected link does not match this row. Review it again or leave the row out.';
                }
            } elseif ($choice !== null) {
                $action = 'conflict';
                $message = 'The selected course no longer matches. Remove the link choice and review again.';
            } elseif (isset($newCodes[mb_strtolower($row->code)])) {
                $action = 'conflict';
                $message = 'Another included row uses this code. Leave one out or correct the source file.';
            } else {
                $action = 'create';
                $newCodes[mb_strtolower($row->code)] = true;
                $message = 'Create an inactive course draft; publication and activation remain separate.';
            }
            if ($resolved['errors'] !== []) {
                $action = 'conflict';
                $message = 'Resolve the catalog classifications or leave this row out. '
                . implode(' ', $resolved['errors']);
            }
            ++$counts[$action];
            $plan[] = ['row' => $row->row, 'external_id' => $row->externalId, 'code' => $row->code,
                'title' => $row->title, 'credits_milli' => $row->creditsMilli, 'action' => $action,
                'course_id' => $courseId, 'matched_course' => $matched, 'message' => $message,
                'source_references' => $row->references, 'references' => $resolved['references'],
                'reference_errors' => $resolved['errors']];
        }
        return ['plan' => $plan, 'summary' => $counts];
    }

    /** @param array<string,list<array<string,mixed>>> $available
     * @return array{references:array<string,array{id:string,code:string,title:string}>,errors:list<string>} */
    private function resolveReferences(CourseImportRow $row, array $available): array
    {
        $references = [];
        $errors = [];
        foreach ($row->references as $kind => $code) {
            $matches = $available[$kind . ':' . $code] ?? [];
            $label = CourseImportRow::REFERENCE_LABELS[$kind];
            if (count($matches) !== 1) {
                $errors[] = $label . ' ' . $code . (count($matches) > 1 ? ' is ambiguous.' : ' was not found.');
                continue;
            }
            $reference = $matches[0];
            if (!(bool) $reference['active']) {
                $errors[] = $label . ' ' . $code . ' is retired.';
                continue;
            }
            try {
                if (
                    ($reference['kind'] ?? null) !== $kind || !is_string($reference['id'] ?? null)
                    || !is_string($reference['code'] ?? null) || !is_string($reference['title'] ?? null)
                ) {
                    throw new InvalidArgumentException();
                }
                $data = ['id' => $reference['id'], 'code' => $reference['code'], 'title' => $reference['title']];
                $references[$kind] = ($kind === 'subject' ? SubjectSnapshot::fromArray($data)
                    : ClassificationSnapshot::fromArray($data))->describe();
            } catch (InvalidArgumentException) {
                $errors[] = $label . ' ' . $code . ' needs catalog review.';
            }
        }
        return ['references' => $references, 'errors' => $errors];
    }

    /** @param list<CourseImportRow> $rows
     * @param array<array-key,mixed> $choices
     * @return array<string,array{action:string,course_id:?string}> */
    private function choices(array $rows, array $choices): array
    {
        if (!array_is_list($choices) || count($choices) > 1000) {
            throw new InvalidArgumentException('Choose a bounded list of row actions.');
        }
        $known = [];
        foreach ($rows as $row) {
            $known['external:' . $row->externalId] = true;
        }
        $selected = [];
        foreach ($choices as $choice) {
            if (
                !is_array($choice) || count($choice) !== 3 || !is_string($choice['external_id'] ?? null)
                || !in_array($choice['action'] ?? null, ['link', 'skip'], true)
                || !array_key_exists('course_id', $choice)
            ) {
                throw new InvalidArgumentException('A row choice is invalid.');
            }
            $key = 'external:' . $choice['external_id'];
            if (
                !isset($known[$key]) || isset($selected[$key])
                || ($choice['action'] === 'skip' ? $choice['course_id'] !== null : !is_string($choice['course_id']))
            ) {
                throw new InvalidArgumentException('Choose each known source row once.');
            }
            if ($choice['action'] === 'link') {
                new EntityId($choice['course_id']);
            }
            $selected[$key] = ['action' => $choice['action'], 'course_id' => $choice['course_id']];
        }
        ksort($selected, SORT_STRING);
        return $selected;
    }

    private function source(string $source): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $source) !== 1) {
            throw new InvalidArgumentException(
                'Use a stable source name of up to 80 letters, numbers, dots or hyphens.'
            );
        }
    }

    private function actor(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:import:manage');
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException('The import actor changed.');
        }
        return $actor;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function ticket(
        #[\SensitiveParameter] string $token,
        string $source,
        EntityId $actor,
        string $request,
    ): array {
        try {
            if (strlen($token) > 100000) {
                throw new InvalidArgumentException();
            }
            $ticket = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($ticket) || count($ticket) !== 8 || ($ticket['purpose'] ?? null) !== 'catalog.course-import'
                || ($ticket['source'] ?? null) !== $source || ($ticket['actor_id'] ?? null) !== $actor->value
                || ($ticket['request_hash'] ?? null) !== $request || !is_string($ticket['operation_id'] ?? null)
                || !is_string($ticket['plan_hash'] ?? null) || !is_int($ticket['issued_at'] ?? null)
                || !is_int($ticket['expires_at'] ?? null) || $ticket['expires_at'] - $ticket['issued_at'] !== 1200
            ) {
                throw new InvalidArgumentException();
            }
            new EntityId($ticket['operation_id']);
            return $ticket;
        } catch (\Throwable) {
            throw new ValidationException([['field' => 'review_token', 'message' => 'Review this import again.']]);
        }
    }

    /** @param array<string,mixed> $ticket */
    private function expiry(array $ticket): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($now < $ticket['issued_at'] || $now > $ticket['expires_at']) {
            throw new ConcurrentModificationException('The import review expired. Review the file and choices again.');
        }
    }
}
