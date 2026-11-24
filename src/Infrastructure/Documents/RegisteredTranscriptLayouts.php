<?php

declare(strict_types=1);

namespace Infrastructure\Documents;

use Domain\Shared\Exception\ValidationException;
use Domain\Transcript\Service\TranscriptLayoutCatalog;
use Domain\Transcript\ValueObject\TranscriptLayout;

use function Codefy\Framework\Helpers\trans_html;

/** Registered academic and CEU formats. Stored documents retain their actual rendered bytes. */
final readonly class RegisteredTranscriptLayouts implements TranscriptLayoutCatalog
{
    private const array LAYOUTS = [
        'ceu-letter' => ['CEU transcript · Letter', 'letter', 'continuing_education'],
        'ceu-a4' => ['CEU transcript · A4', 'a4', 'continuing_education'],
        'classic-letter' => ['Classic · Letter', 'letter'],
        'classic-a4' => ['Classic · A4', 'a4'],
        'modern-letter' => ['Modern · Letter', 'letter'],
        'modern-a4' => ['Modern · A4', 'a4'],
    ];

    public function __construct(private string $defaultCode = 'classic-letter')
    {
    }

    public function get(?string $code = null): TranscriptLayout
    {
        $code ??= $this->defaultCode;
        $definition = self::LAYOUTS[$code] ?? throw new ValidationException([
            ['field' => 'layout_code', 'message' => 'Choose an available transcript layout.'],
        ]);
        $purpose = $definition[2] ?? 'academic';
        $file = $purpose === 'academic' ? 'transcript-document.phtml' : 'ceu-transcript-document.phtml';
        $template = hash_file('sha256', dirname(__DIR__, 3) . '/resources/views/backend/' . $file);
        $renderer = hash_file('sha256', __DIR__ . '/ProtectedTranscriptRenderer.php');
        if ($template === false || $renderer === false) {
            throw new \RuntimeException('Transcript layout resources are unavailable.');
        }
        return new TranscriptLayout($code, match ($code) {
            'ceu-letter' => trans_html('CEU transcript · Letter'),
            'ceu-a4' => trans_html('CEU transcript · A4'),
            default => $definition[0],
        }, $definition[1], hash(
            'sha256',
            json_encode([$code, $definition, $template, $renderer], JSON_THROW_ON_ERROR)
        ), $purpose);
    }

    public function available(): array
    {
        return array_map($this->get(...), array_keys(self::LAYOUTS));
    }
}
