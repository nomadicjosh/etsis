<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\WebsiteForms\Service\WebsiteFormRoutingService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class WebsiteFormAccessController
{
    public function __construct(private WebsiteFormRoutingService $routing)
    {
    }

    public function screen(): ResponseInterface
    {
        $this->routing->authorizeAdministration();
        return \Codefy\Framework\Helpers\view('framework::backend/manager/site', [
            'mode' => 'form-access', 'replyOnly' => true, 'canAdminister' => true,
        ]);
    }

    public function choices(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->routing->choices(
            $f->text('kind', 12),
            $f->search('search', 80),
            $f->optionalText('after', 191)
        ));
    }

    public function forms(ServerRequest $request): ResponseInterface
    {
        return new JsonResponse($this->routing->forms(new FormInput($request->getQueryParams())->text('page', 191)));
    }

    public function members(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->routing->members(
            $f->text('kind', 12),
            $f->text('scope', 191),
            $f->search('form', 40),
            $f->optionalId('after')
        ));
    }

    public function assign(ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('expected', $body) || !array_key_exists('grant', $body)) {
            throw new \InvalidArgumentException();
        }
        $f = FormInput::from($request);
        $this->routing->assign(
            $f->text('kind', 12),
            $f->text('scope', 191),
            $f->search('form', 40),
            $f->id('user_id'),
            $f->boolean('grant'),
            $f->optionalId('expected')
        );
        return new JsonResponse(['saved' => true]);
    }
}
