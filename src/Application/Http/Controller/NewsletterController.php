<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Service\NewsletterService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class NewsletterController
{
    public function __construct(private NewsletterService $newsletters)
    {
    }

    public function screen(): ResponseInterface
    {
        $this->newsletters->directory();
        return \Codefy\Framework\Helpers\view('framework::backend/manager/site', [
            'mode' => 'newsletters', 'replyOnly' => true, 'canAdminister' => false,
        ]);
    }

    public function listing(ServerRequest $request): ResponseInterface
    {
        $f = new FormInput($request->getQueryParams());
        return new JsonResponse($this->newsletters->directory($f->optionalId('after'), $f->boolean('choices')));
    }

    public function show(string $id): ResponseInterface
    {
        return new JsonResponse($this->newsletters->show(new EntityId($id)));
    }

    public function save(ServerRequest $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('version', $body) || !array_key_exists('enabled', $body)) {
            throw new \InvalidArgumentException();
        }
        $f = FormInput::from($request);
        $settings = [];
        foreach (['provider' => 12, 'url' => 2048, 'list' => 120] as $key => $maximum) {
            if (isset($body[$key])) {
                $settings[$key] = $f->text($key, $maximum);
            }
        }
        $settings['secret'] = ($body['secret'] ?? '') === '' ? '' : $f->password('secret');
        $settings['username'] = $f->search('username', 191);
        $settings['idempotent_receiver'] = $f->boolean('idempotent_receiver');
        return new JsonResponse($this->newsletters->save(
            $f->requiredNullableId('id'),
            $f->text('name', 120),
            $f->boolean('enabled'),
            $settings,
            $f->optionalInteger('version')
        ));
    }
}
