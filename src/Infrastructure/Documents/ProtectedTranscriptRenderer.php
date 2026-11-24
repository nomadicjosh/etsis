<?php

declare(strict_types=1);

namespace Infrastructure\Documents;

use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Domain\Transcript\Service\TranscriptLayoutCatalog;
use Domain\Transcript\ValueObject\TranscriptLayout;
use Dompdf\Dompdf;
use Dompdf\Options;
use Qubus\EventDispatcher\ActionFilter\Filter;

use function dirname;

final readonly class ProtectedTranscriptRenderer implements TranscriptDocumentRenderer
{
    public function __construct(private TranscriptLayoutCatalog $layouts = new RegisteredTranscriptLayouts())
    {
    }

    public function pdf(#[\SensitiveParameter] array $document): string
    {
        $layout = $this->layout($document);
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setAllowedProtocols([]);
        $options->setChroot(dirname(__DIR__, 3) . "/vendor/dompdf/dompdf/lib/fonts");
        $options->setDefaultFont("DejaVu Sans");
        $pdf = new Dompdf($options);
        $pdf->setPaper($layout->paper);
        $pdf->loadHtml($this->html($document), "UTF-8");
        $pdf->render();
        return $pdf->output();
    }

    public function html(#[\SensitiveParameter] array $document): string
    {
        $layout = $this->layout($document);
        $escape = static fn(mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            "UTF-8",
        );
        $credits = static fn(int $value): string => number_format($value / 1000, 3, ".", "");
        ob_start();
        try {
            $ceu = $layout->purpose === 'continuing_education';
            $transcriptDocument = Filter::getInstance()->applyFilter(
                $ceu ? 'ceu_transcript' : 'transcript',
                dirname(__DIR__, 3) . '/resources/views/backend/' .
                    ($ceu ? 'ceu-transcript-document.phtml' : 'transcript-document.phtml'),
            );
            require $transcriptDocument;
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /** @param array<string,mixed> $document */
    private function layout(#[\SensitiveParameter] array $document): TranscriptLayout
    {
        $retained = isset($document['presentation'])
        ? TranscriptLayout::fromArray($document['presentation'])
        : $this->layouts->get();
        if (($document['purpose'] ?? 'academic') !== $retained->purpose) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        if ($retained->describe() !== $this->layouts->get($retained->code)->describe()) {
            throw new \Domain\Shared\Exception\ConcurrentModificationException(
                'This transcript layout changed. Review the preparation again.'
            );
        }
        return $retained;
    }
}
