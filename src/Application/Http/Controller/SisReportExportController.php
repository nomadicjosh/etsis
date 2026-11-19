<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Reporting\Service\ReportExportService;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisReportExportController
{
    public function __construct(private ReportExportService $exports)
    {
    }

    public function latest(string $id): ResponseInterface
    {
        return new JsonResponse(['export' => $this->exports->latest(new EntityId($id))]);
    }

    public function request(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse(['export' => $this->exports->request(
            new EntityId($id),
            $f->id('operation_id'),
            $f->integer('version')
        )], 202);
    }

    public function cancel(string $id, string $export): ResponseInterface
    {
        return new JsonResponse(['export' => $this->exports->cancel(new EntityId($id), new EntityId($export))]);
    }

    public function download(string $id, string $export): ResponseInterface
    {
        $download = $this->exports->download(new EntityId($id), new EntityId($export));
        // This deliberately stays in bounded process memory, never an unencrypted temporary file.
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Private download storage is unavailable.');
        }
        foreach ($download->chunks as $chunk) {
            if (fwrite($stream, $chunk) !== strlen($chunk)) {
                fclose($stream);
                throw new \RuntimeException('Private download could not be completed.');
            }
        }
        rewind($stream);
        return new Response($stream, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="report-results.csv"',
            'Content-Length' => (string) $download->bytes,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
        ]);
    }
}
