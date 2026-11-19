<?php

declare(strict_types=1);

namespace Infrastructure\Reporting;

use Domain\Communications\Service\MessageCipher;
use Domain\Reporting\Repository\ReportExportCapture;
use Domain\Reporting\Service\ReportSnapshotReader;
use Domain\Reporting\ValueObject\ReportExportArtifact;
use Domain\Reporting\ValueObject\ReportQuery;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use League\Csv\EscapeFormula;
use League\Csv\Writer;

/** Encrypt bounded CSV chunks before retaining them; no plaintext artifact is written to disk. */
final readonly class SqlReportExportCapture implements ReportExportCapture
{
    public function __construct(
        private SqlReportResultReader $source,
        private ReportSnapshotReader $snapshots,
        private MessageCipher $cipher,
    ) {
    }

    public function capture(
        EntityId $job,
        EntityId $report,
        #[\SensitiveParameter] ReportQuery $query,
        #[\SensitiveParameter] ?array $staticState,
    ): ReportExportArtifact {
        $chunks = $hashes = [];
        $rows = $bytes = 0;
        $accept = function (
            array $page,
            bool $header = false
        ) use (
            $job,
            $report,
            &$chunks,
            &$hashes,
            &$rows,
            &$bytes
        ): void {
            $csv = Writer::fromString();
            $csv->setEscape('');
            $formatter = new EscapeFormula("'", [' ', "\n"]);
            $csv->addFormatter($formatter->escapeRecord(...));
            foreach ($page as $row) {
                $csv->insertOne(array_map(
                    static fn(mixed $v): mixed => is_bool($v) ? ($v ? 'Yes' : 'No') : $v,
                    array_values($row)
                ));
            }
            $content = $csv->toString();
            $rows += $header ? 0 : count($page);
            $bytes += strlen($content);
            if ($rows > ReportExportArtifact::MAX_ROWS || $bytes > ReportExportArtifact::MAX_BYTES) {
                throw new ValidationException([['field' => 'report', 'message' =>
                    'This complete export exceeds 25,000 rows or 16 MiB. Narrow the report and try again.']]);
            }
            $packet = json_encode(['job_id' => $job->value, 'report_id' => $report->value,
                'chunk' => count($chunks), 'content' => $content], JSON_THROW_ON_ERROR);
            $hashes[] = hash('sha256', $packet);
            $chunks[] = $this->cipher->seal($packet);
        };
        $columns = array_column($query->definition['columns'], 'label');
        if ($staticState !== null) {
            $manifest = $this->snapshots->manifest($report, $staticState);
            $accept([$manifest['columns']], true);
            for ($offset = 0; $offset < $manifest['row_count']; $offset += 100) {
                $page = $this->snapshots->page($report, $manifest, $offset);
                if ($offset + 50 < $manifest['row_count']) {
                    array_push($page, ...$this->snapshots->page($report, $manifest, $offset + 50));
                }
                $accept($page);
            }
        } else {
            $accept([$columns], true);
            $this->source->read($query, $accept, ReportExportArtifact::MAX_ROWS, ReportExportArtifact::MAX_BYTES, 30);
        }
        return new ReportExportArtifact($chunks, $hashes, $rows, $bytes);
    }
}
