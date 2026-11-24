<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Application\View\CampusRenderer;
use Domain\Portal\Service\ProgramDiscoveryService;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

use function Codefy\Framework\Helpers\trans;

final readonly class ProgramDiscoveryController
{
    public function __construct(private ProgramDiscoveryService $service, private CampusRenderer $renderer)
    {
    }

    public function page(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        $search = $f->search('q', 80);
        $after = $f->optionalId('after');
        return $this->renderer->render(
            'programs',
            trans('Explore academic programs'),
            $this->service->page($search, $after) + [
                'search' => $search, 'after' => $after?->value, 'hasPrevious' => $after !== null,
            ]
        );
    }

    public function detail(ServerRequest $request, string $id): ResponseInterface
    {
        try {
            $program = $this->service->detail(new EntityId($id));
        } catch (RecordNotFoundException) {
            return $this->renderer->render('not-found', trans('Program unavailable'))->withStatus(404);
        }
        $f = new FormInput($request->getQueryParams());
        $return = array_filter(
            ['q' => $f->search('q', 80), 'after' => $f->optionalId('after')?->value],
            static fn (?string $v): bool => $v !== null && $v !== ''
        );
        return $this->renderer->render('program', $program['title'], ['program' => $program,
            'backUrl' => '/portal/programs/' . ($return === [] ? '' : '?' . http_build_query($return))]);
    }

    public function current(string $id): ResponseInterface
    {
        return new JsonResponse($this->service->current(new EntityId($id)));
    }

    public function choices(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->service->choices(
            new EntityId($id),
            new FormInput($request->getQueryParams())->optionalId('after'),
        ));
    }

    public function review(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->review(
            new EntityId($id),
            $f->requiredNullableId('catalog_id'),
            $f->requiredBoolean('visible'),
            $f->integer('version'),
        ));
    }

    public function commit(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->service->commit(
            new EntityId($id),
            $f->requiredNullableId('catalog_id'),
            $f->requiredBoolean('visible'),
            $f->integer('version'),
            $f->id('operation_id'),
            $f->text('revision', 64),
            $f->text('review_token', 8192),
        ));
    }
}
