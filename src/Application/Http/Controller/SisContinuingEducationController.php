<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Service\CatalogEditor;
use Domain\Registration\ValueObject\OfferingCeuChoice;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Qubus\Http\ServerRequest;

final readonly class SisContinuingEducationController
{
    public function __construct(private CatalogEditor $catalog)
    {
    }

    public function show(string $kind, string $id): JsonResponse
    {
        $this->kind($kind);
        return new JsonResponse($this->catalog->continuingEducationSettings($kind, new EntityId($id)));
    }

    public function save(ServerRequest $request, string $kind, string $id): JsonResponse
    {
        $this->kind($kind);
        $input = FormInput::from($request);
        $mode = $input->text('mode', 12);
        $milli = $mode === 'fixed' ? $input->integer('ceu_milli', 0, 999999) : $input->optionalInteger('ceu_milli');
        $choice = new OfferingCeuChoice($mode, $milli);
        $key = new EntityId($id);
        $this->catalog->continuingEducation($kind, $key, $choice, $input->text('revision', 64));
        return new JsonResponse($this->catalog->continuingEducationSettings($kind, $key));
    }

    private function kind(string $kind): void
    {
        if (!in_array($kind, ['courses', 'sections'], true)) {
            throw new \InvalidArgumentException('Choose a course or section CEU draft.');
        }
    }
}
