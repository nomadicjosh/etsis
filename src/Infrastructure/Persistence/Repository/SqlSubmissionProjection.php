<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeZone;
use Domain\Admissions\Repository\SubmissionProjection;
use Domain\Admissions\ValueObject\SubmissionFact;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSubmissionProjection implements SubmissionProjection
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function project(SubmissionFact $fact): void
    {
        $utc = new DateTimeZone('UTC');
        $data = [
            'id' => $fact->application->value,
            'source_event_id' => $fact->event->value,
            'submitted_on' => $fact->submittedAt->format('Y-m-d'),
            'submitted_at' => $fact->submittedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'submission_timezone' => $fact->submittedAt->getTimezone()->getName(),
            'recorded_on' => $fact->recordedAt->setTimezone($utc)->format('Y-m-d'),
            'recorded_at' => $fact->recordedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
            'channel' => $fact->channel,
            'submitted_term_id' => $fact->entryTerm->value,
            'submitted_term_code' => $fact->entryTermCode,
        ];
        $existing = $this->sql->rows('sis_application_intake', ['id' => $fact->application->value], 1)[0] ?? null;
        if ($existing === null) {
            $this->sql->insert('sis_application_intake', $data);
            return;
        }
        $fill = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['submitted_term_id', 'submitted_term_code'], true) && $existing[$key] === null) {
                $fill[$key] = $value;
                continue;
            }
            if ($existing[$key] !== $value) {
                throw new CorruptHistoryException();
            }
        }
        if ($fill !== []) {
            // Populate missing index fields only after verifying the actual original submission event and
            // existing index.
            $this->sql->update('sis_application_intake', $fill, ['id' => $fact->application->value]);
        }
    }
}
