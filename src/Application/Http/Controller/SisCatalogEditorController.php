<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Catalog\Course;
use Domain\Catalog\Program;
use Domain\Catalog\Service\CatalogEditor;
use Domain\Finance\ValueObject\Money;
use Domain\Registration\Section;
use Domain\Registration\Term;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisCatalogEditorController
{
    public function __construct(private CatalogEditor $service)
    {
    }

    public function show(string $kind, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->view($kind, new EntityId($id)));
    }

    public function save(ServerRequest $request, string $kind, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $key = new EntityId($id);
        $revision = $f->text('revision', 64);
        switch ($kind) {
            case 'courses':
                $this->service->course(new Course(
                    $key,
                    $f->text('code', 80),
                    $f->text('title'),
                    $f->integer('credits_milli', 0, 999999),
                    $f->boolean('active')
                ), $revision);
                break;
            case 'programs':
                $this->service->program(new Program($key, $f->text('code', 80), $f->text('title')), $revision);
                break;
            case 'terms':
                $this->service->term($f->text('code', 22), new Term(
                    $key,
                    new DateRange($f->date('starts_on'), $f->date('ends_on')),
                    $f->date('add_drop_ends_on')
                ), $revision);
                break;
            case 'sections':
                $existing = $this->service->view($kind, $key);
                $this->service->section(
                    new Section(
                        $key,
                        new EntityId((string) $existing['course_id']),
                        new EntityId((string) $existing['term_id']),
                        $f->optionalId('faculty_user_id'),
                        $f->text('code', 80),
                        $f->integer('capacity', 1, 100000),
                        $f->boolean('active'),
                        $f->boolean('web_registration'),
                        new Money($f->integer('fee_minor'), $f->text('currency', 3))
                    ),
                    $revision
                );
                break;
            default:
                throw new InvalidArgumentException('Unknown catalog record.');
        }

        return new JsonResponse(['status' => 'saved']);
    }
}
