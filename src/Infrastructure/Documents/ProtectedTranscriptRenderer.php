<?php

declare(strict_types=1);

namespace Infrastructure\Documents;

use Domain\Transcript\Service\TranscriptDocumentRenderer;
use Dompdf\Dompdf;
use Dompdf\Options;

final readonly class ProtectedTranscriptRenderer implements TranscriptDocumentRenderer
{
    public function pdf(array $document): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsFontSubsettingEnabled(true);
        $options->setAllowedProtocols([]);
        $options->setChroot(dirname(__DIR__, 3) . "/vendor/dompdf/dompdf/lib/fonts");
        $options->setDefaultFont("DejaVu Sans");
        $pdf = new Dompdf($options);
        $pdf->setPaper("letter");
        $pdf->loadHtml($this->html($document), "UTF-8");
        $pdf->render();
        return $pdf->output();
    }

    public function html(array $document): string
    {
        $escape = static fn(mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            "UTF-8",
        );
        $credits = static fn(int $value): string => number_format($value / 1000, 3, ".", "");
        ob_start();
        try {
            require dirname(__DIR__, 3) . "/resources/views/backend/transcript-document.phtml";
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
