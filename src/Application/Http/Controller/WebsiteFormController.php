<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Codefy\Framework\Http\Middleware\Csrf\CsrfTokenMiddleware;
use Codefy\Framework\Http\RequestContext;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Service\WebsiteFormInboxService;
use Domain\WebsiteForms\Service\WebsiteFormSubmissionService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class WebsiteFormController
{
    public function __construct(
        private WebsiteFormSubmissionService $submissions,
        private WebsiteFormInboxService $inbox,
        private \Domain\Shared\Security\StudentAccess $access,
        private \Domain\WebsiteForms\Service\WebsiteFormRoutingService $routing,
        private \Domain\WebsiteForms\Service\NewsletterService $newsletters,
    ) {
    }

    public function prepare(ServerRequest $request, string $page, string $form): ResponseInterface
    {
        $locale = new FormInput($request->getQueryParams())->text('locale', 12);
        $csrf = (string) RequestContext::get()->getAttribute(CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE);
        return new JsonResponse($this->submissions->prepare($page, $form, $locale, $csrf) + [
            'csrf' => $csrf, 'csrf_header' => \Codefy\Framework\Helpers\config('csrf.header', 'X-CSRF-Token'),
        ]);
    }

    public function submit(ServerRequest $request, string $page, string $form): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['answers'] ?? null)) {
            throw new \InvalidArgumentException();
        }
        $f = FormInput::from($request);
        return new JsonResponse($this->submissions->submit(
            $page,
            $form,
            (string) RequestContext::get()->getAttribute(CsrfTokenMiddleware::CSRF_SESSION_ATTRIBUTE),
            $f->text('ticket', 8192),
            $body['answers'],
            $f->search('website_url', 200)
        ));
    }

    public function preview(ServerRequest $request): ResponseInterface
    {
        $actor = $this->access;
        $expected = $actor->requirePermission('vihzhuo:manage');
        $body = $request->getParsedBody();
        if (!is_array($body) || !is_array($body['definition'] ?? null)) {
            throw new \InvalidArgumentException();
        }
        $definition = new \Domain\WebsiteForms\ValueObject\WebsiteFormDefinition($body['definition']);
        $this->routing->validateDepartment($definition->facts['department_id']);
        $this->newsletters->validateBinding($definition->facts['newsletter']);
        $html = (string) \Codefy\Framework\Helpers\view('framework::frontend/website/form', [
            'definition' => $definition->facts, 'instance' => \Application\View\WebsiteFormControls::id('preview'),
            'endpoint' => '', 'locale' => 'en', 'preview' => true,
        ])->getBody();
        if ($actor->requirePermission('vihzhuo:manage')->value !== $expected->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException();
        }
        return new JsonResponse(['html' => $html]);
    }

    public function listing(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->inbox->page($f->optionalId('after'), $f->search('state', 8) ?: 'all'));
    }

    public function screen(): ResponseInterface
    {
        $this->inbox->page();
        return \Codefy\Framework\Helpers\view('framework::backend/manager/site', [
            'mode' => 'form-inbox', 'replyOnly' => true, 'canAdminister' => $this->routing->canAdminister(),
            'canManageNewsletters' => $this->newsletters->canManage(),
        ]);
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->inbox->show(new EntityId($id)));
    }

    public function retryNewsletter(ServerRequest $request, string $id): ResponseInterface
    {
        return new JsonResponse($this->inbox->retryNewsletter(
            new EntityId($id),
            FormInput::from($request)->integer('version', 0, PHP_INT_MAX)
        ));
    }

    public function change(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        return new JsonResponse($this->inbox->change(
            new EntityId($id),
            $f->integer('version', 0, PHP_INT_MAX),
            $f->text('action', 8)
        ));
    }
}
