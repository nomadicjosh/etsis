<?php

declare(strict_types=1);

namespace Application\Http\Controller;

use Application\Http\FormInput;
use Domain\Person\Service\PersonProfileEditor;
use Domain\Person\Service\PersonContactService;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\User\Service\PersonAccountEditor;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Qubus\Http\ServerRequest;

final readonly class SisPersonEditorController
{
    public function __construct(
        private PersonProfileEditor $people,
        private PersonAccountEditor $accounts,
        private PersonContactService $contacts,
        private \Domain\Person\Service\PersonHistoryReview $history,
    ) {
    }

    public function history(ServerRequest $request, string $id): ResponseInterface
    {
        $form = FormInput::from($request);
        return new JsonResponse($this->history->page(
            new EntityId($id),
            $form->optionalInteger('after') ?? 0,
            $form->optionalInteger('through_version')
        ));
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

    public function contact(string $id): ResponseInterface
    {
        return new JsonResponse($this->contacts->staffView(new EntityId($id)));
    }

    public function saveContact(ServerRequest $request, string $id): ResponseInterface
    {
        $f = FormInput::from($request);
        $body = $request->getParsedBody();
        foreach (['preferred_name', 'phone', 'address'] as $key) {
            if (!is_array($body) || !is_string($body[$key] ?? null)) {
                throw new \InvalidArgumentException('Contact fields must be supplied.');
            }
        }
        $this->contacts->staffSave(
            new EntityId($id),
            $body['preferred_name'],
            $body['phone'],
            $body['address'],
            $f->integer('version')
        );
        return new JsonResponse(['status' => 'saved']);
    }

    public function signIn(ServerRequest $request, string $id): ResponseInterface
    {
        $form = FormInput::from($request);
        $this->accounts->signIn(
            new EntityId($id),
            $form->requiredBoolean('sign_in_enabled'),
            $form->integer('version'),
            $form->id('operation_id'),
            $form->bodyText('reason')
        );
        return new JsonResponse(['status' => 'saved']);
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
