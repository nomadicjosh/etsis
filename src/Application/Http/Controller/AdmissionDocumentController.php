<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Admissions\Service\AdmissionDocumentPolicy;
use Domain\Admissions\Service\AdmissionDocumentService;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Exception\ValidationException;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Qubus\Http\ServerRequest;

final readonly class AdmissionDocumentController
{
    public function __construct(private AdmissionDocumentService $service)
    {
    }
    public function listing(string $application): ResponseInterface
    {
        return new JsonResponse($this->service->listing(new EntityId($application), true));
    }
    public function listingMine(string $application): ResponseInterface
    {
        return new JsonResponse($this->service->listing(new EntityId($application), false));
    }
    public function uploadMine(#[\SensitiveParameter] ServerRequest $request, string $application): ResponseInterface
    {
        return $this->uploadDocument($request, $application, false);
    }
    public function upload(#[\SensitiveParameter] ServerRequest $request, string $application): ResponseInterface
    {
        return $this->uploadDocument($request, $application, true);
    }
    private function uploadDocument(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        bool $staff
    ): ResponseInterface {
        $file = $request->getUploadedFiles()['document'] ?? null;
        if (
            !$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK
            || ($file->getSize() ?? AdmissionDocumentPolicy::MAX_BYTES + 1) > AdmissionDocumentPolicy::MAX_BYTES
        ) {
            throw new ValidationException([['field' => 'document',
                'message' => 'Choose one PDF, JPEG or PNG of up to 5 MiB.']]);
        }
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = $stream->read(AdmissionDocumentPolicy::MAX_BYTES + 1);
        return new JsonResponse($this->service->upload(
            new EntityId($application),
            FormInput::from($request)->id('upload_id'),
            $file->getClientFilename() ?? '',
            $bytes,
            $staff,
            $staff ? FormInput::from($request)->boolean('visible') : true
        ), 201);
    }
    public function download(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        string $id
    ): ResponseInterface {
        return $this->downloadDocument($application, $id, true);
    }
    public function downloadMine(string $application, string $id): ResponseInterface
    {
        return $this->downloadDocument($application, $id, false);
    }
    private function downloadDocument(string $application, string $id, bool $staff): ResponseInterface
    {
        $data = $this->service->download(new EntityId($application), new EntityId($id), $staff);
        $stream = new Stream('php://memory', 'w+');
        $stream->write($data['bytes']);
        $stream->rewind();
        return new Response($stream, 200, ['Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $data['filename'] . '"',
            'X-Content-Type-Options' => 'nosniff', 'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'none'"]);
    }
    public function rescan(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        string $id
    ): ResponseInterface {
        $this->service->rescan(
            new EntityId($application),
            new EntityId($id),
            FormInput::from($request)->integer('version')
        );
        return new JsonResponse(['status' => 'quarantined']);
    }
    public function retain(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        string $id
    ): ResponseInterface {
        $f = FormInput::from($request);
        $this->service->retain(
            new EntityId($application),
            new EntityId($id),
            $f->integer('version'),
            $f->requiredBoolean('hold'),
            $f->optionalText('until', 10),
            $f->requiredBoolean('visible')
        );
        return new JsonResponse(['status' => 'saved']);
    }
    public function reviewPurge(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        string $id
    ): ResponseInterface {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->reviewPurge(
            new EntityId($application),
            new EntityId($id),
            $f->integer('version'),
            $f->text('reason', 1000)
        ));
    }
    public function purge(
        #[\SensitiveParameter] ServerRequest $request,
        string $application,
        string $id
    ): ResponseInterface {
        $this->service->purge(
            new EntityId($application),
            new EntityId($id),
            FormInput::from($request)->text('review_token', 20000)
        );
        return new JsonResponse(['status' => 'removal_requested']);
    }
}
