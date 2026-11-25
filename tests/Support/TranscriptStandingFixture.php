<?php

declare(strict_types=1);

namespace Tests\Support;

// phpcs:disable PSR1.Files.SideEffects -- Isolated fictional workflow dependencies.
require_once __DIR__ . '/TranscriptFixture.php';

use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptReleasePolicyService;
use Domain\Transcript\ValueObject\TranscriptStandingPresentation;
use Infrastructure\Persistence\Repository\SqlAccessRepository;
use Infrastructure\Persistence\Repository\SqlStudentStandingRepository;
use Infrastructure\Persistence\Repository\SqlTranscriptReleasePolicyRepository;

final class TranscriptStandingFixture
{
    public \TranscriptFixture $base;
    public EntityId $request;
    public EntityId $student;
    public EntityId $pursuit;
    public array $rows = [];

    public function __construct()
    {
        $this->base = $t = new \TranscriptFixture();
        $this->request = $t->request();
        $this->student = new EntityId($t->repository->request($this->request)['student_id']);
        $this->pursuit = new EntityId($t->sis->sql->rows('sis_student_programs', [
            'student_id' => $this->student->value,
        ], 1)[0]['id']);
        foreach (['PREVIOUS', 'CURRENT', 'FUTURE'] as $i => $code) {
            $definition = $t->sis->standingService()->saveDefinition(
                null,
                $code,
                'Fictional <b>' . $code . '</b>',
                true,
                null
            );
            $this->rows[] = ['id' => EntityId::generate()->value, 'definition_id' => $definition['id'],
                'code' => $code, 'title' => $definition['title'],
                'starts_on' => ['2026-07-01', '2026-08-01', '2026-08-11'][$i],
                'ends_on' => ['2026-07-31', '2026-08-10', null][$i],
                'policy_reference' => 'Private fictional source'];
        }
        $this->standing();
    }

    public function standing(): void
    {
        $s = $this->base->sis->standingService();
        $review = $s->review(
            $this->student,
            $this->pursuit,
            $this->rows,
            $s->view($this->student, $this->pursuit)['revision'],
            'Private fictional standing evidence.'
        );
        $s->commit(
            $this->student,
            $this->pursuit,
            new EntityId($review['operation_id']),
            $review['rows'],
            $review['revision'],
            $review['reason'],
            $review['review_token']
        );
    }

    public function policy(
        string $periods = 'through_cutoff',
        bool $dates = true,
        ?array $definitions = null,
        ?\Domain\Audit\Repository\AuditTrail $audit = null,
    ): array {
        $s = $this->base->sis;
        $repository = new SqlTranscriptReleasePolicyRepository($s->sql, $this->base->campus->cipher);
        $service = new TranscriptReleasePolicyService(
            $repository,
            $s->access,
            new SqlAccessRepository($s->sql),
            $s->transactions,
            $audit ?? $s->audit,
            new SqlStudentStandingRepository($s->sql)
        );
        return $service->save(
            $repository->current()->revision,
            [],
            true,
            $definitions,
            new TranscriptStandingPresentation($periods, $dates)
        );
    }

    public function service(): \Domain\Transcript\Service\TranscriptFulfillmentService
    {
        $real = new \Infrastructure\Documents\ProtectedTranscriptRenderer();
        $renderer = new class ($real) implements TranscriptDocumentRenderer {
            public function __construct(private TranscriptDocumentRenderer $real)
            {
            }
            public function html(#[\SensitiveParameter] array $document): string
            {
                return $this->real->html($document);
            }
            public function pdf(#[\SensitiveParameter] array $document): string
            {
                return '%PDF-1.4 Fictional ' . hash('sha256', $this->html($document));
            }
        };
        return $this->base->service($renderer);
    }

    public function prepare(bool $pdf = false): array
    {
        $service = $pdf ? $this->base->service() : $this->service();
        $review = $service->review($this->request);
        $document = new EntityId($review['operation_id']);
        $result = $service->prepare($this->request, $document, $review['revision'], true, $review['review_token']);
        return [$service, $document, $review, $result];
    }

    public function facts(): array
    {
        $sql = $this->base->sis->sql;
        return [\EventStoreFixture::historyDigest($sql), ...array_map($sql->rows(...), [
            'sis_transcript_policy', 'sis_transcript_requests', 'sis_transcript_documents',
            'sis_transcript_deliveries', 'sis_student_standing', 'sis_history_operations',
        ])];
    }
}
