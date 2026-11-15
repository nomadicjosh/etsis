<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\HumanResources\Enum\HrLibraryKind;
use Domain\HumanResources\JobDefinition;
use Domain\HumanResources\PayGrade;
use Domain\HumanResources\Service\HrLibraryService;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisHrLibraryController
{
    public function __construct(private HrLibraryService $service)
    {
    }
    public function page(ServerRequest $request, string $kind): ResponseInterface
    {
        $input = FormInput::from($request);
        return new JsonResponse($this->service->page(
            $this->kind($kind),
            $input->search(),
            $input->optionalId('after')
        ));
    }
    public function save(ServerRequest $request, string $kind): ResponseInterface
    {
        $f = FormInput::from($request);
        $category = $this->kind($kind);
        $value = $category === HrLibraryKind::PayGrades
        ? new PayGrade(
            $f->id('id'),
            $f->text('code', 40),
            $f->text('title'),
            strtoupper($f->text('currency', 3)),
            $f->text('basis', 8),
            $f->integer('minimum_minor'),
            $f->integer('maximum_minor'),
            $f->requiredBoolean('active')
        )
        : new JobDefinition(
            $f->id('id'),
            $f->text('code', 40),
            $f->text('title'),
            strtoupper($f->text('currency', 3)),
            $f->text('basis', 8),
            $f->requiredBoolean('active'),
            $f->optionalId('pay_grade_id'),
            $f->optionalInteger('rate_minor'),
            $f->optionalInteger('weekly_minutes')
        );
        return new JsonResponse($this->service->save($value, $f->optionalInteger('version')));
    }
    public function get(string $kind, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->get($this->kind($kind), new EntityId($id)));
    }
    private function kind(string $kind): HrLibraryKind
    {
        return HrLibraryKind::tryFrom($kind) ?? throw new InvalidArgumentException('Choose an HR setup category.');
    }
}
