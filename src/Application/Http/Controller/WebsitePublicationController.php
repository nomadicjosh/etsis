<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Portal\Service\PagePublicationService;
use Domain\Portal\Service\NavigationService;
use Domain\Portal\ValueObject\NavigationTree;
use Domain\Portal\ValueObject\PublicationWindow;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class WebsitePublicationController
{
    public function __construct(private PagePublicationService $pages, private NavigationService $menus)
    {
    }

    public function pages(ServerRequest $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        return new JsonResponse($this->pages->directory(is_string($query['after'] ?? null) ? $query['after'] : null));
    }

    public function page(string $id): ResponseInterface
    {
        return new JsonResponse($this->pages->get($id));
    }

    public function reviewPage(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->pages->review(
            $id,
            $f->text('action', 12),
            $f->text('revision', 64),
            $this->window($request),
            $this->note($request)
        ));
    }

    public function confirmPage(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->pages->confirm(
            $id,
            $f->id('operation_id'),
            $f->text('action', 12),
            $f->text('revision', 64),
            $this->window($request),
            $this->note($request),
            $f->text('review_token', 8192)
        ));
    }

    private function window(ServerRequest $request): PublicationWindow
    {
        $data = $this->structured($request, 'window');
        if (
            count($data) !== 2 || array_diff(array_keys($data), ['visible_from', 'visible_until']) !== []
            || !(is_string($data['visible_from']) || $data['visible_from'] === null)
            || !(is_string($data['visible_until']) || $data['visible_until'] === null)
        ) {
            throw new \InvalidArgumentException('Use an explicit publication window.');
        }
        return new PublicationWindow($data['visible_from'], $data['visible_until']);
    }

    public function menu(string $slot): ResponseInterface
    {
        return new JsonResponse($this->menus->get($slot));
    }

    public function saveMenu(ServerRequest $request, string $slot): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->menus->save(
            $slot,
            new NavigationTree($this->structured($request, 'items')),
            $f->text('revision', 64)
        ));
    }

    public function reviewMenu(ServerRequest $request, string $slot): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->menus->review($slot, $f->text('revision', 64)));
    }

    public function publishMenu(ServerRequest $request, string $slot): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->menus->publish(
            $slot,
            $f->id('operation_id'),
            $f->text('revision', 64),
            $f->text('review_token', 8192)
        ));
    }
    private function structured(ServerRequest $request, string $key): array
    {
        $body = $request->getParsedBody();
        if (
            !is_array($body) || !is_array($body[$key] ?? null)
            || strlen(json_encode($body[$key], JSON_THROW_ON_ERROR)) > 1048576
        ) {
            throw new \InvalidArgumentException('Use a bounded explicit structured value.');
        }
        return $body[$key];
    }

    private function note(ServerRequest $request): ?string
    {
        $body = $request->getParsedBody();
        $note = is_array($body) ? ($body['note'] ?? null) : null;
        if ($note !== null && !is_string($note)) {
            throw new \InvalidArgumentException('Use a text review note.');
        }
        return $note;
    }
}
