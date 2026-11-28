<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\PersonPhotoService;
use Domain\Person\Service\PhotoImportService;
use Domain\Person\Service\PhotoPolicy;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans;

final readonly class PersonPhotoController
{
    public function __construct(
        private PersonPhotoService $photos,
        private PhotoImportService $imports,
    ) {
    }

    public function view(?string $id = null): ResponseInterface
    {
        return new JsonResponse(
            $this->photos->view(
                $id === null ? null : new EntityId($id),
                $id !== null,
            ),
        );
    }

    private function upload(ServerRequest $request, int $maximum): array
    {
        $file = $request->getUploadedFiles()["photo"] ?? null;
        if (
            !($file instanceof UploadedFileInterface) ||
            $file->getError() !== UPLOAD_ERR_OK ||
            ($file->getSize() ?? $maximum + 1) > $maximum
        ) {
            throw new ValidationException([
                [
                    "field" => "photo",
                    "message" =>
                        trans("Choose one photo or archive within the stated size limit."),
                ],
            ]);
        }
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        return [$stream->read($maximum + 1), $file->getClientFilename() ?? ""];
    }

    public function review(
        #[\SensitiveParameter] ServerRequest $request,
        ?string $id = null,
    ): ResponseInterface {
        $form = FormInput::from($request);
        $source = $form->text("source", 16);
        [$bytes, $filename] = in_array($source, ["upload", "camera"], true)
        ? $this->upload($request, PhotoPolicy::MAX_IMAGE)
        : ["", ""];
        return new JsonResponse(
            $this->photos->review(
                $id === null ? null : new EntityId($id),
                $id !== null,
                $form->integer("version", 0),
                $source,
                $form->bodyText("reason"),
                $bytes,
                $filename,
                $form->boolean("consent"),
            ),
        );
    }

    public function confirm(
        #[\SensitiveParameter] ServerRequest $request,
        ?string $id = null,
    ): ResponseInterface {
        $form = FormInput::from($request);
        return new JsonResponse(
            $this->photos->confirm(
                $id === null ? null : new EntityId($id),
                $id !== null,
                $form->id("draft_id"),
                $form->text("review_token", 8192),
            ),
        );
    }

    public function content(
        ?string $id = null,
        ?string $draft = null,
    ): ResponseInterface {
        $bytes = $this->photos->content(
            $id === null ? null : new EntityId($id),
            $id !== null,
            $draft === null ? null : new EntityId($draft),
        );
        $stream = new Stream("php://memory", "w+");
        $stream->write($bytes);
        $stream->rewind();
        return new Response($stream, 200, [
            "Content-Type" => "image/jpeg",
            "Content-Disposition" => 'inline; filename="photo.jpg"',
            "X-Content-Type-Options" => "nosniff",
            "Cross-Origin-Resource-Policy" => "same-origin",
            "Referrer-Policy" => "no-referrer",
            "Cache-Control" => "private, no-store",
            "Content-Security-Policy" =>
                "sandbox; default-src 'none'; frame-ancestors 'none'",
        ]);
    }

    public function discard(
        ServerRequest $request,
        ?string $id = null,
    ): ResponseInterface {
        $this->photos->discard(
            $id === null ? null : new EntityId($id),
            $id !== null,
            FormInput::from($request)->id("draft_id"),
        );
        return new JsonResponse(["discarded" => true]);
    }

    public function importReview(
        #[\SensitiveParameter] ServerRequest $request,
    ): ResponseInterface {
        [$bytes, $filename] = $this->upload($request, PhotoPolicy::MAX_ARCHIVE);
        $form = FormInput::from($request);
        return new JsonResponse(
            $this->imports->review(
                $bytes,
                $filename,
                $form->text("match", 8),
                $form->bodyText("reason"),
            ),
        );
    }
}
