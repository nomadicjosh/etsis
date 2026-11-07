<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\PersonProfileEditor;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\PersonAccountEditor;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPersonEditorController
{
    public function __construct(private PersonProfileEditor $people, private PersonAccountEditor $accounts)
    {
    }

    public function profile(string $id): ResponseInterface
    {
        return new JsonResponse($this->people->view(new EntityId($id)));
    }

    public function saveProfile(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->people->save(
            new EntityId($id),
            $f->text('given_name'),
            $f->text('family_name'),
            $f->boolean('directory_restricted', true),
            $f->text('revision', 64)
        );

        return new JsonResponse(['status' => 'saved']);
    }

    public function account(string $id): ResponseInterface
    {
        return new JsonResponse($this->accounts->view(new EntityId($id)));
    }

    public function email(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $this->accounts->email(new EntityId($id), $f->text('email'), $f->integer('version'));

        return new JsonResponse(['status' => 'saved']);
    }

    public function password(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $password = $f->password();
        if (!hash_equals($password, $f->password('confirm_password'))) {
            throw new ValidationException([['field' => 'confirm_password', 'message' => 'The passwords must match.']]);
        }
        $body = $request->getParsedBody();
        $current = is_array($body) && isset($body['current_password']) ? $f->password('current_password') : null;
        $this->accounts->password(new EntityId($id), $password, $f->integer('version'), $current);

        return new JsonResponse(['status' => 'saved']);
    }
}
